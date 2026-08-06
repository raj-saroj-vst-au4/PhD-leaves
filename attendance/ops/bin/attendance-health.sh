#!/bin/sh
# Health probe for the attendance backend. Run from a systemd timer every 5
# minutes. On 3 consecutive failures (~15 min, i.e. a real outage during the
# marking window) it emails the office THROUGH THE SMTP RELAY DIRECTLY —
# never through the app being probed. Sends a recovery mail when it comes back.
#
# Requires /etc/attendance/jobs.env with SMTP_HOST/SMTP_PORT/SMTP_USER/
# SMTP_PASSWORD/EMAIL_FROM/ALERT_TO.
set -u
# Config lives with the app; /etc/attendance/jobs.env is used when present.
ENV_FILE="${ATT_ENV_FILE:-/etc/attendance/jobs.env}"
[ -f "$ENV_FILE" ] || ENV_FILE="$(dirname "$0")/../../.env"
set -a; . "$ENV_FILE"; set +a

# Root-owned by default; falls back to the user's state dir when unprivileged.
STATE_DIR="${ATT_STATE_DIR:-/var/lib/attendance-health}"
mkdir -p "$STATE_DIR" 2>/dev/null || STATE_DIR="$HOME/.local/state/attendance-health"
mkdir -p "$STATE_DIR"
FAILS_FILE="$STATE_DIR/fails"
ALERTED_FILE="$STATE_DIR/alerted"

send_mail() {
    subject="$1"; body="$2"
    # No relay configured yet: record it rather than failing silently.
    if [ -z "${SMTP_HOST:-}" ] || [ -z "${ALERT_TO:-}" ]; then
        echo "$(date -Is) $subject :: $body" >> "$STATE_DIR/alerts.log"
        return 0
    fi
    python3 - "$subject" "$body" <<'PY'
import os, smtplib, sys
from email.message import EmailMessage
msg = EmailMessage()
msg["Subject"] = sys.argv[1]
msg["From"] = os.environ.get("EMAIL_FROM", "phd-attendance@som.iitb.ac.in")
msg["To"] = os.environ["ALERT_TO"]
msg.set_content(sys.argv[2])
host, port = os.environ["SMTP_HOST"], int(os.environ.get("SMTP_PORT", "587"))
if os.environ.get("SMTP_SECURE", "false").lower() == "true":
    s = smtplib.SMTP_SSL(host, port, timeout=30)
else:
    s = smtplib.SMTP(host, port, timeout=30); s.starttls()
with s:
    user = os.environ.get("SMTP_USER", "")
    if user: s.login(user, os.environ["SMTP_PASSWORD"])
    s.send_message(msg)
PY
}

if curl -fsS -m 10 http://127.0.0.1:3070/api/healthz >/dev/null 2>&1; then
    if [ -f "$ALERTED_FILE" ]; then
        send_mail "RECOVERED: PhD attendance service is back" \
            "The attendance backend on $(hostname) responds again at $(date). If this happened during the marking window, use 'Extend today' so no student is penalised."
        rm -f "$ALERTED_FILE"
    fi
    rm -f "$FAILS_FILE"
    exit 0
fi

fails=$(( $(cat "$FAILS_FILE" 2>/dev/null || echo 0) + 1 ))
echo "$fails" > "$FAILS_FILE"

if [ "$fails" -ge 3 ] && [ ! -f "$ALERTED_FILE" ]; then
    send_mail "ALERT: PhD attendance service is DOWN" \
        "The attendance backend on $(hostname) has failed health checks for ~15 minutes (as of $(date)). Students may be unable to mark attendance. Check: docker compose -f /srv/www/phdtimeoff/attendance/docker-compose.yml ps / logs. Remedy for students: Attendance -> Extend today."
    touch "$ALERTED_FILE"
fi
exit 1
