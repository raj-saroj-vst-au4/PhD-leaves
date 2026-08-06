#!/bin/bash
# ============================================================================
# Attack-test matrix for the marking gates. Run against a dev/staging instance
# AFTER enrollment of one test student. Every row asserts that a specific
# attack dies at the right gate with the right code.
#
# Usage:
#   BASE=https://devlinux.som.iitb.ac.in/phdtimeoff/attendance/api \
#   TICKET=<student ticket>  DEVICE_COOKIE=<att_device value>  ./gate-matrix.sh
#
# Get TICKET from a logged-in browser (att-root data-ticket attribute) and
# DEVICE_COOKIE from browser devtools after enrolling the test phone.
# ============================================================================
set -u
BASE="${BASE:-http://127.0.0.1:3070/api}"
TICKET="${TICKET:-}"
DEVICE_COOKIE="${DEVICE_COOKIE:-}"
PASS=0; FAIL=0

check() {
    local name="$1" expected_status="$2" expected_code="$3"; shift 3
    local out status code
    out=$(curl -sS -w '\n%{http_code}' "$@" 2>/dev/null)
    status=$(echo "$out" | tail -1)
    code=$(echo "$out" | head -n -1 | grep -o '"code":"[A-Z_]*"' | head -1 | cut -d'"' -f4)
    if [ "$status" = "$expected_status" ] && { [ -z "$expected_code" ] || [ "$code" = "$expected_code" ]; }; then
        echo "PASS  $name  ($status $code)"; PASS=$((PASS+1))
    else
        echo "FAIL  $name  expected $expected_status/$expected_code got $status/$code"; FAIL=$((FAIL+1))
    fi
}

AUTH=(-H "Authorization: Bearer $TICKET")
COOKIE=(-H "Cookie: att_device=$DEVICE_COOKIE")

echo "== identity =="
check "no ticket -> 401 NO_TICKET"            401 NO_TICKET            -X POST "$BASE/mark/challenge"
check "garbage ticket -> 401 TICKET_INVALID"  401 TICKET_INVALID       -X POST "$BASE/mark/challenge" -H "Authorization: Bearer abc.def"

echo "== network gate =="
# From a machine NOT on the office WiFi, with a spoofed header: Apache must
# overwrite it, so the backend still sees the real (unlisted) IP.
check "spoofed X-Client-IP -> 403 IP_NOT_ALLOWED" 403 IP_NOT_ALLOWED   -X POST "$BASE/mark/challenge" "${AUTH[@]}" "${COOKIE[@]}" -H "X-Client-IP: 10.119.4.27"

echo "== device gate (run from an allowed IP) =="
check "no device cookie -> 401 DEVICE_NOT_ENROLLED" 401 DEVICE_NOT_ENROLLED -X POST "$BASE/mark/challenge" "${AUTH[@]}"

echo "== enrollment WiFi gate (leaked OTP can't enroll remotely) =="
check "enroll off-wifi -> 403 IP_NOT_ALLOWED" 403 IP_NOT_ALLOWED \
    -X POST "$BASE/enroll/capture" "${AUTH[@]}" -H "X-Client-IP: 203.0.113.9" \
    -F token=bogus-otp -F "frames=@/dev/null;type=image/jpeg"

echo "== challenge replay =="
check "bogus challenge token -> 401 CHALLENGE_INVALID" 401 CHALLENGE_INVALID \
    -X POST "$BASE/mark" "${AUTH[@]}" "${COOKIE[@]}" -F token=deadbeef -F "frames=@/dev/null;type=image/jpeg"

echo "== jobs endpoints =="
check "jobs without token -> 401" 401 BAD_JOB_TOKEN -X POST "$BASE/jobs/purge-frames"
echo "   (also verify through Apache: /phdtimeoff/attendance/api/jobs/* must be 403 for everyone)"

echo
echo "Manual rows (need a phone / window control):"
cat <<'EOF'
  - after cutoff:        set today's override end in the past -> Mark => 403 OUTSIDE_WINDOW
  - non-working day:     override working=no                  -> Mark => 403 NON_WORKING_DAY
  - second mark:         mark once, mark again                -> 409 ALREADY_MARKED
  - revoked device:      admin revoke, then Mark              -> 401 DEVICE_REVOKED
  - printed photo:       hold a photo up                      -> 422 (LIVENESS/POSE fail) + evidence stored
  - phone-screen photo:  photo of the student on 2nd phone    -> 422 + evidence stored
  - wrong face:          friend marks on your account         -> 422 CROSSMATCH + office sees who
  - strict mode:         enable, type stale/wrong code        -> 403 STRICT_CODE_INVALID
  - append-only:         UPDATE/DELETE att_records as attendance_app -> SQL error 1142/45000
EOF

echo; echo "$PASS passed, $FAIL failed"
exit $((FAIL > 0))
