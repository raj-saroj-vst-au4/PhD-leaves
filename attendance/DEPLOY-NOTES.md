# Production deployment notes — PhD Attendance (chanakya, 10.127.1.1)

Deployed 2026-07-29. Portal URL: https://www.som.iitb.ac.in/phdtimeoff/

## Layout on production (differs from dev — read this first)

| What | Where |
|---|---|
| Portal (PHP) | `/var/www/html/phdtimeoff` (nginx + PHP-FPM) |
| Attendance backend | **`/home/r4id/phd-attendance`** — deliberately OUTSIDE the webroot |
| Backend URL | `127.0.0.1:3070` (loopback only, host networking) |
| Browser → API | `/phdtimeoff/attapi/index.php?p=…` (PHP forwarder, no nginx config) |
| Secrets | `/home/r4id/phd-attendance/.env` (600), `config/attendance.php` (644) |
| Backup + revert | `/home/r4id/backups/attendance-deploy-20260729-1445/` |

**Why the backend is outside the webroot:** nginx ignores `.htaccess`, so while it
lived under the document root its source was publicly downloadable — verified:
`…/phdtimeoff/attendance/backend/app/config.py` returned HTTP 200. After the move
the same URLs return 404. Never place it back under `/var/www/html`.

## Revert

```bash
bash /home/r4id/backups/attendance-deploy-20260729-1445/REVERT.sh            # files only
bash /home/r4id/backups/attendance-deploy-20260729-1445/REVERT.sh --with-db  # + database
```
Only additive changes were made to the existing portal: four nav links in
`includes/header.php` and new `att_*` tables. Nothing existing was modified or
deleted.

## Real client IP — RESOLVED 2026-07-29

The office-WiFi gate is live. Previously every student appeared as 10.127.1.21
because HAProxy :443 runs in `mode tcp` (HTTPS shares the port with TURN), so it
could add no headers and opened a fresh connection from 127.0.0.1 to httpd.

Fixed on 10.127.1.21 with the PROXY protocol:

| Change | File |
|---|---|
| `server nginx 127.0.0.1:8444 check send-proxy-v2` | `/etc/haproxy/haproxy.cfg` |
| parallel `:8444` vhost with `RemoteIPProxyProtocol On` | `/etc/httpd/conf.d/som-ssl-proxyproto.conf` |
| `RequestHeader set X-Real-IP "expr=%{REMOTE_ADDR}"` | `/etc/httpd/conf/som-proxy-rules.conf` |
| SELinux: `semanage port -a -t http_port_t -p tcp 8444` | — |
| `define('ATT_REAL_IP_HEADER', 'X-Real-IP');` | chanakya `config/attendance.php` |

The original `:8443` listener is untouched and still running, so rollback is one
command: `bash /home/r4id/som-realip-fix.sh --rollback` (then unset
ATT_REAL_IP_HEADER). Backups in `/root/som-realip-backup-*` on 10.127.1.21.

**SELinux gotcha (cost an outage the first time):** Rocky's `http_port_t` covers
80/443/8443 but not 8444. Without the `semanage` label httpd cannot bind the new
port and **refuses to start at all**, taking :8443 down with it. `httpd -t` still
says "Syntax OK" because binding happens at startup, not at parse. Also note a
new `Listen` needs `systemctl restart`, never `reload`. Both are handled by the
script now, which also self-heals if httpd fails to come back.

Verified end-to-end through https://www.som.iitb.ac.in: 10/10 browser-path tests
pass, and a 5-case spoof suite confirms a client cannot forge `X-Real-IP`,
`X-Forwarded-For` or `X-Client-IP` to fake being on the office network — Apache
overwrites the header with the true peer address.

## Production quirks that shaped the deployment

| Quirk | Consequence |
|---|---|
| **Rootless Docker** (context `rootless`, root dir `/home/r4id/.local/share/docker`) | `network_mode: host` is a *separate* namespace — it can reach neither the host's MariaDB nor publish a port. The compose file therefore uses bridge networking with `127.0.0.1:3070:8000` (rootlesskit forwards it to the real host loopback). |
| MariaDB binds `127.0.0.1` only | The container talks to the DB over the **unix socket** (`/var/run/mysqld/mysqld.sock`, bind-mounted). No database restart, works under rootless too. |
| Rootless uid mapping | Container-root maps to `r4id`, so `ATT_CONTAINER_USER=0:0` in `.env` lets it write the bind-mounted `data/` dirs. On dev (rootful) that value is `1000:1000`. |
| No root / sudo for `r4id` | Scheduled jobs run from **r4id's crontab**, not systemd timers. `loginctl enable-linger` is on, so the rootless daemon (and `restart: unless-stopped`) survives reboots. |
| nginx + PHP-FPM | `.htaccess` is ignored — hence the backend lives outside the webroot and the forwarder routes on `?p=` instead of PATH_INFO. |

Scheduled jobs (`crontab -l`): monthly report 1st 08:00, nightly purge+cleanup
03:00, health probe every 5 min. All verified working. Health alerts append to
`~/.local/state/attendance-health/alerts.log` until `SMTP_HOST`/`ALERT_TO` are
set in `.env`.

## Rebuilding the image on production (proxy required)

chanakya reaches the internet only through IITB's authenticated proxy, and
Docker builds do not inherit it — a plain `docker compose build` fails at
`apt-get` with exit 100. Pass the host's proxy settings through instead:

```bash
cd /home/r4id/phd-attendance
set -a; . /etc/environment; set +a
docker build \
  --build-arg http_proxy="$http_proxy" \
  --build-arg https_proxy="$https_proxy" \
  --build-arg no_proxy="$no_proxy" \
  -t phdtimeoff-attendance:latest ./backend
docker compose up -d
```
Reading the values from `/etc/environment` keeps the proxy password out of your
shell history. The proxy is only needed at build time (to fetch apt packages,
Python wheels and the face model); at runtime the container talks solely to
127.0.0.1.

## September 2026 audit fixes — rollout order

1. **Migration first:** `database/migrations/2026_09_23_attendance_backend.sql`,
   with the same account that applied `2026_07_27_attendance.sql` (it needs
   ALTER on `att_records`, `att_month_approvals`, `att_challenges`). Idempotent.
   It turns the student FKs of `att_records`/`att_month_approvals` into
   `ON DELETE RESTRICT`, adds `att_challenges.issued_at`, and creates
   `att_frame_hashes` (the replay check; hashes only). If the backend uses
   the `attendance_app` user, re-apply `ops/grants.sql` for its new grants.
2. **Backend and the student JS together** (rebuild the image as below, then
   copy `public/js/attendance.js` + `public/js/faceguide.js`). The challenge is
   now 3–4 random steps including "turn to the OTHER side", and the server
   checks each step in its own two frames; an old cached page would guess the
   side and use a 1-second timed fallback, so it fails more often. The new
   page also fetches the challenge only after the camera and face tracker are
   ready, and resends the same frames on `SERVER_BUSY`. The asset URLs are
   mtime-busted, so a normal copy is enough.
3. The first nightly purge after deploy revokes and erases the templates of
   already-deactivated students and erases every previously revoked template
   (`templates.purged` in the audit log shows the counts). No crontab change:
   it runs inside `/api/jobs/purge-frames`.

New optional `.env` knobs (defaults shown): `FACE_WORKERS=2`,
`FACE_QUEUE_TIMEOUT=30`, `MAX_BODY_BYTES=12582912`.

## Recommended hardening (needs MySQL root — not available to r4id)

The backend currently uses the portal's DB user because creating a dedicated one
requires root. Append-only integrity is **already enforced by triggers**, proven
on production (UPDATE and DELETE both rejected with error 1644). To also enforce
it at privilege level:

```bash
sudo mysql < /home/r4id/phd-attendance/ops/grants.local.sql   # creates attendance_app@localhost
# then set DB_USER=attendance_app and DB_PASSWORD=<the password in that file>
# in /home/r4id/phd-attendance/.env and restart the container
```

## Before go-live

1. ~~Fix the real-IP blocker~~ — done 2026-07-29.
2. **Verify the allow-listed IP is really the office WiFi.** The gate now sees
   true client addresses, so confirm what the server sees for a phone on the
   office WiFi rather than assuming. Easiest check: open the attendance page on
   a phone joined to that WiFi — the status pill says "On SOM office WiFi" only
   if the address matches. Anything allow-listed that is a desktop or a wider
   LAN range lets people mark from places you did not intend.
3. Fill `SMTP_*` in `.env` and set report recipients, or the monthly report
   cannot be emailed.
4. Enroll students in the office (Attendance → Today → *enroll code*; the student
   must be on the office WiFi).
5. Run a shadow week to calibrate the face thresholds before stipends depend on it.

## Liveness v2 — rollout (October 2026)

Ships **off**: with `liveness_v2` = `0` and an empty pilot list every student
gets exactly the current check, so the files can go out in any order (a new
page against an old backend sees no `liveness`/`protocol` field and runs the
original flow; an old page never receives a v2 challenge while the flag is
off). What v2 is and how it is judged: RUNBOOK, "Liveness v2".

1. **Migration** (optional — it only seeds the settings rows; the backend uses
   the same defaults without it), with the account that applied the earlier
   attendance migrations:
   `mysql -u root -p phd_timeoff < database/migrations/2026_10_07_liveness_v2.sql`
2. **Backend**: copy `attendance/backend/app/{faces,main,marking,config}.py`,
   `attendance/backend/Dockerfile` and `attendance/backend/tests/` to
   `/home/r4id/phd-attendance/backend/`, then rebuild **with the proxy build
   args** exactly as in "Rebuilding the image on production" above. The build
   now also downloads the anti-spoof model (1.7 MB from GitHub, SHA-256
   pinned in the Dockerfile). Check it landed before restarting:
   `docker run --rm phdtimeoff-attendance:latest sha256sum /models/antispoof/MiniFASNetV2.onnx`
   → `b32929ad…3907`. If the build printed "anti-spoof model download or
   checksum failed", v2 still works with that signal off (the backend logs
   "anti-spoof model … not found"). Then `docker compose up -d` and
   `curl -s 127.0.0.1:3070/api/healthz`.
3. **Portal files** (into `/var/www/html/phdtimeoff/`):
   `student/attendance.php`, `attendance-guide.php`,
   `public/js/liveness.js`, `public/js/attendance.js`,
   `public/js/attendance-admin.js`, `public/css/attendance.css`, and the
   whole new directory `public/vendor/mediapipe/` (~26 MB: the bundle, two
   WASM builds, the model). Asset URLs are mtime-busted. `tailwind.min.css` is
   unchanged.
4. *Optional*: if nginx's `mime.types` lacks `application/wasm wasm;`, add it
   — saves phones a second 11 MB download on first use (works either way).
5. **Pilot on one account**: find your `students.id` and set
   `liveness_v2_students` to `[<id>]` (Configuration tab, or the SQL in the
   RUNBOOK). Mark from your phone in the office; check the attempt's `detail`
   (RUNBOOK, "Calibrate in the office"). Then `liveness_v2` = `1` for everyone.
6. **Rollback**: `liveness_v2` = `0`, `liveness_v2_students` = `[]`. No files
   need reverting; the original flow is still on the page.
