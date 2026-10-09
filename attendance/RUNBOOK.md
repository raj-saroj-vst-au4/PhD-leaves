# PhD Attendance — Operations Runbook

Face-verified daily attendance for SOM PhD students, bolted onto the existing
**PhD Time-Off** portal. The PHP portal owns login, the roster, and the holiday
calendar; a **Python/FastAPI backend in Docker** owns the marking gates, the
face engine, and the evidence trail. They share the `phd_timeoff` database and
trust each other through short-lived HMAC tickets.

## How a mark is judged (the gates, in order)

1. **Ticket** — the caller is a logged-in portal user (HMAC ticket from PHP).
2. **WiFi / IP** — request arrives from an allow-listed WAN IP. Apache sets
   `X-Client-IP` from the real peer address; the backend trusts only that,
   never `X-Forwarded-For`. This gate applies to **both marking and face
   enrollment** — a leaked enrollment OTP is useless off the office WiFi.
3. **Time** — server clock inside today's window (per-weekday default, per-date
   override, holiday calendar, emergency extension). IST throughout.
4. **Device** — only when `require_enrolled_device` = `1`: the `att_device`
   cookie matches the phone bound at enrollment. **Off on production since
   Oct 2026** (any phone or browser may mark): the cookie was lost too often —
   private tabs, a second browser or Safari profile, in-app browsers, cleared
   site data, new phones — and each loss meant an office re-enrollment. With it
   off, a bound device is still recorded on the attempt and record when the
   cookie is present; a challenge issued to a bound device must still be
   answered from it (`DEVICE_MISMATCH`). Enrollment still binds the phone, so
   switching it back on (Configuration → Modes → *Only from the enrolled
   phone*) needs no re-enrollment. The trade-off: someone holding a student's
   password and a recorded video is no longer stopped by a device check — the
   WiFi, window, face match, cross-match, liveness and review still apply.
5. **Strict code** (optional) — rotating 6-digit office-screen code.
6. **Not already marked / attempt budget.** The budget (`max_attempts_per_day`)
   counts failed attempts **and results sent to review**, and is claimed
   atomically before any face work, so parallel requests cannot overshoot it.
   Server-side problems never count: `ENGINE_ERROR`, `SERVER_ERROR`, refused
   reservations (`TOO_MANY_ATTEMPTS`) and attempts left `VERIFYING` by a crash
   (after 5 minutes). The office's "failed attempts" figures leave them out too.
7. **Face** — random pose-challenge liveness, identity match vs the student's
   template, and cross-match against the whole cohort (so marking on someone
   else's account is caught and identified). Borderline → **pending review**
   (never a silent absence); clear fail → recorded with photos as evidence.
   - (This is the default protocol. Liveness v2 — one named turn and a
     blink — is described in its own section below.)
   - The challenge is one of 10 sequences of 3–4 steps, drawn uniformly with a
     CSPRNG per attempt: *centre*, a *turn* to either side, and a turn to the
     *other* side. Sides are never named (left/right was mis-scored in
     production); "other" is checked as the opposite sign within the burst. It
     is bound to a single-use token for this student (and phone, when one is
   bound — see gate 4), expires after
     2 minutes, and a burst that arrives faster than the steps can be performed
     is refused (`CHALLENGE_TOO_FAST`). Issue is capped at 4 a minute and
     3 × the attempt budget a day (`TOO_MANY_CHALLENGES`), so a client cannot
     re-roll until it draws a sequence its photos fit.
   - The phone sends exactly two frames per step, in order; each step is judged
     on its own two frames only. Frames must be JPEGs of at most 1920 px a
     side: baseline with a single scan (what the page's camera capture
     produces), or progressive with no more than 16 scans. Anything else —
     scan bombs, PNG, oversized headers — is refused before decoding.
   - Burst content: two byte-identical frames are refused (`DUPLICATE_FRAMES`);
     a frame this student has submitted before, in any earlier mark or at
     enrollment, fails the attempt (`REPLAYED_FRAME`; SHA-256s are kept in
     `att_frame_hashes`, no image content); a turn frame that is the mirror
     image of another turn step's frame fails it (`MIRRORED_FRAMES`).
   - **Every** usable frame must match the enrolled template (the weakest frame
     decides, not the median), all frames must be the same person
     (`MIXED_FACES`), and every frame is cross-matched against the cohort.
   - Face work runs on a small worker pool off the web server's event loop. If
     every slot stays busy for 30 s the student gets `SERVER_BUSY`; nothing was
     judged, the challenge is still unused, and the page resends the same
     frames automatically while the challenge has time left.
   - **What the pose challenge can and cannot do.** It raises the bar against
     casual replay: holding up a photo or a phone screen, replaying a recorded
     clip, re-sending an earlier burst, or one photo plus its mirror image. It
     **cannot** stop a modified client that fabricates frames to order — the
     phone's uploads are just JPEGs, and someone holding a few photos of the
     student at different angles (slightly re-cropped each time) can assemble
     a burst for any of the 10 sequences. Against that the real controls are
     the others in this list: the bound phone (if gate 4 is on), the office
     WiFi and time window,
     identity and cross-match on every frame, office review of the stored
     evidence — and strict mode, which requires physical presence at the
     office screen.

Every failure is logged with a stable code (see `ops/test/gate-matrix.sh`).

## Liveness v2 (behind a flag — off by default)

A second challenge protocol, for students on the flag. Everything in gate 7
above still applies (token, timing floor, issue caps, frame-hash replay,
duplicate and mirrored frames, identity on every frame, cross-match, budget);
what changes is the challenge and how the phone captures it.

**What the student does (a few seconds).** Look straight, then — in a random
order — turn the head to *their* LEFT **or** RIGHT (the server picks one at
random) and blink slowly: 3 steps, 6 frames, 4 possible sequences. (The first
pilot also asked for the other side and a colour flash; it took too long.)
Each step is captured automatically once the pose is actually *held* (≥400 ms,
steady, face big enough, sharp enough, light enough): the phone never sends a
frame of a moving head, which is what caused every `POSE_CHALLENGE_FAILED` in
the first production week. The phone uses Google MediaPipe Face Landmarker
(vendored in `public/vendor/mediapipe/`, see its README.txt — no CDN, no
telemetry); `public/js/liveness.js` runs it — loaded in the background as soon
as the page shows Mark Attendance — and falls back to timed prompts if it
cannot load. Enrollment for these students is guided the same way: straight
×2, left ×2, right ×2, chin up, chin down (8 frames; `enrollment_min_frames` 5).
Phone diagnostics: add `?lvdebug=1` to the page URL to see the live
measurements (turn, blink score, size, light), `?lvcpu=1` to skip the GPU.

**How the server judges it** (`faces.py`, "Liveness v2"):
- *Side* is named and judged from keypoint geometry, not the model's yaw sign
  (which is what broke named sides before): the nose tip's offset from the eye
  midpoint along the eye line, in eye distances; positive = the student's left
  in the raw (un-mirrored) frame. Turn ≥ 0.11 (≈15°), centre ≤ 0.085 (≈12°).
  Verified on a real photo (insightface's t1.jpg: a man visibly turned to his
  right reads -0.47; the model's yaw sign would have called it "left"), end to
  end in the browser harness, and on the pilot (attempt #41: left
  +0.39/+0.38, right -0.37/-0.38).
- *Blink*: the phone uploads the exact frame its tracker scored shut (at the
  peak of the closure) and an open frame after it. The server re-scores both
  with the **same model** — the Python `mediapipe` package's Face Landmarker,
  `eyeBlinkLeft/Right` blendshapes (0 open … 1 shut), mean of both eyes:
  shut ≥ `blink_closed_min` (0.45), open ≤ `blink_open_max` (0.40), and at
  least `blink_min_diff` (0.25) apart → seen. A "shut" frame as open as the
  open one (< 0.30 and within 0.10) → `BLINK_CHALLENGE_FAILED`. Anything in
  between → `blink_unclear` (a soft reason, below). If mediapipe or its model
  is missing, the older landmark measures (eye aspect ratio + dark iris area)
  decide instead and the log says so. The shut-eye frame is not held to the
  match threshold, only to "same person" and the cross-match.
- *One turn, so no second turn to mirror*: the single-turn form of the
  "turned photo + its flip" fake is a photo turned the other way, flipped. The
  face then turns the right way but its background is mirrored relative to the
  straight-on frames; when the background is asymmetric enough to tell, that
  fails as `MIRRORED_FRAMES` (`detail.flipped_turn`).
- *Passive anti-spoof*: MiniFASNetV2 (print / live / replay) on the two centre
  frames — a soft reason only (spoof probability ≥ `antispoof_review_threshold`);
  it never fails or passes a mark by itself.
- *Soft reasons and `review_skip_match`*: `blink_unclear`, `antispoof` (and,
  if the flash is on, `flash_weak` / `flash_incomplete`) send a mark that would
  otherwise pass to office review (`LIVENESS_REVIEW`) — **unless its identity
  match** (`att_attempts.match_score`, the weakest open-eye frame) **is at
  least `review_skip_match` (0.65)**: then it passes, with
  `detail.review_skipped` saying which doubts were waved through and at what
  score. Hard failures — wrong or no turn, a definite no-blink, cross-match,
  mixed faces, mirrored/replayed/duplicate frames, too fast — always fail. v1
  is unaffected (its review band, 0.32–0.42, is below the match threshold).
- *Colour flash — off* (`flash_enabled` = `0`): the code is still there; `1`
  adds it as a third randomly placed step (7 colours, ~3 s; skin colour change
  must follow the screen's). The pilot found it slow and, in the office light,
  too weak to judge (correlation 0.90, signal 0.0077 < 0.008).
- Measurements for tuning are in `att_attempts.detail`: `turn_ratio`, the
  blink step's MediaPipe scores, `antispoof`, `review_reasons`,
  `review_skipped`.
- Server time: ~3 s for the 6 frames on the dev box (the first centre frame
  gets the full analysis; the rest a 320 px detection without the 3D
  landmarks; ArcFace on every frame is ~2 s of it, MediaPipe adds ~0.15 s).
  v1's 6-8 frames take ~4-5 s, so v2 is not heavier at the 09:00 rush.

**Switching it on.**
```sql
-- one student first (students.id, not users.id):
SELECT s.id, s.roll_number, u.name FROM students s JOIN users u ON u.id=s.user_id WHERE u.email='…';
INSERT INTO att_settings (skey, svalue) VALUES ('liveness_v2_students', '[123]')
  ON DUPLICATE KEY UPDATE svalue=VALUES(svalue);
-- everyone:
INSERT INTO att_settings (skey, svalue) VALUES ('liveness_v2', '1')
  ON DUPLICATE KEY UPDATE svalue=VALUES(svalue);
```
or in the office UI: Attendance → Configuration → *Liveness v2 for everyone* /
*Liveness v2 pilot students* (audited as `settings.updated`). The pilot list is
honoured while `liveness_v2` is `0`. The protocol is fixed when a challenge is
issued, so flipping the flag never affects an attempt in flight. A student on
v2 who was enrolled with the ring sweep does not need to re-enroll.

**Tuning knobs** (`att_settings`, also in Configuration):
| key | default | meaning |
|---|---|---|
| `review_skip_match` | 0.65 | a mark held only for a soft doubt passes at this match or above |
| `blink_closed_min` | 0.45 | MediaPipe blink score (mean of both eyes) the shut frame must reach |
| `blink_open_max` | 0.40 | ...the open frame must stay under |
| `blink_min_diff` | 0.25 | ...and how far apart they must be |
| `antispoof_review_threshold` | 0.90 | MiniFASNet spoof probability that counts as a soft doubt |
| `flash_enabled` | 0 | `1` adds the colour flash step back |
| `flash_min_signal` / `flash_min_correlation` | 0.008 / 0.75 | flash judging, only if it is on |
| `require_enrolled_device` | 1 if the row is missing; the 2026_10_09 migration seeds 0 | `1` limits marking to the phone bound at enrollment (gate 4) |

Calibrate on the pilot account: mark a few times, then read the attempts —
`SELECT id, verdict, fail_code, match_score, JSON_EXTRACT(detail,'$.review_reasons'), JSON_EXTRACT(detail,'$.review_skipped'), JSON_EXTRACT(detail,'$.steps') FROM att_attempts WHERE student_id=… ORDER BY id DESC LIMIT 5;`
The blink step shows `"closed": [l, r], "opened": [l, r]`. If real blinks
score, say, 0.40-0.45 shut, lower `blink_closed_min` a little; if open eyes
looking at the phone sit above 0.40, raise `blink_open_max`.

**Rolling back.** `liveness_v2` = `0` and `liveness_v2_students` = `[]`: the
next challenge is v1 and the page runs the original guide. No data migration,
nothing to undo; v2 attempts stay on record with `challenge_spec` `v2:…`.

## Layout

```
attendance/
├── backend/            FastAPI app (Docker)
│   ├── app/            config, db, auth, timewindow, faces, marking, reports, main
│   ├── tests/          unit tests (window engine, challenge/judgement, uploads,
│   │                   budget/engine/pool concurrency, report roster,
│   │                   liveness v2) — pytest
│   ├── Dockerfile      python:3.12-slim + InsightFace (model baked in)
│   └── requirements.txt
├── docker-compose.yml  backend only, binds 127.0.0.1:3070
├── .env                secrets (gitignored) — DB, SHARED_SECRET, JOB_TOKEN, SMTP
├── ops/
│   ├── grants.sql      append-only DB user
│   ├── apache-attendance.conf   proxy + X-Client-IP + job lockdown
│   ├── systemd/        report / purge / health units + timers
│   ├── bin/attendance-health.sh
│   └── test/gate-matrix.sh
└── data/               frames/ (biometric evidence) + reports/ (gitignored)

# In the PHP tree:
config/attendance.php          bridge secret + att_ticket()   (gitignored, mode 644
                               so the web server user can read it)
config/attendance-example.php  template
attapi/index.php + .htaccess   PHP forwarder to the loopback backend (the default
                               transport; sets the trusted X-Client-IP)
api/attendance_ticket.php      mints fresh tickets for the browser
student/attendance.php         student page  + public/js/attendance.js
                               + faceguide.js (v1 guide, face-api) + liveness.js
                               (v2 guide, public/vendor/mediapipe/)
admin/attendance.php           office dashboard + public/js/attendance-admin.js
admin/attendance_display.php   strict-mode office screen
database/migrations/2026_07_27_attendance.sql
database/migrations/2026_09_23_attendance_backend.sql   (RESTRICT FKs, challenge timing)
database/migrations/2026_10_07_liveness_v2.sql          (seeds the liveness v2 settings)
```

## First-time install

### 0. Prerequisites
- **HTTPS** on the portal host. Browsers refuse camera access without a secure
  context (localhost is exempt for desk testing). Production must be TLS.
- Docker + Compose (present on both dev and prod).
- The router's **WAN IP as this server sees it** (add it in step 5).

### 1. Database
```bash
# From the PHP root, as a DB user that can create tables/triggers (root):
mysql -u root -p phd_timeoff < database/migrations/2026_07_27_attendance.sql
```
The migration is idempotent. It creates `att_*` tables, seeds Mon–Fri 09:00–10:30,
and installs append-only triggers on `att_records`/`att_audit`. Then apply the
later attendance migrations in date order (`2026_08_06_attendance_approvals.sql`,
`2026_09_23_attendance_backend.sql`); each is safe to re-run.

> If you see *"You do not have the SUPER privilege and binary logging is
> enabled"*, the tables were created but the triggers were skipped. Fix once:
> ```sql
> SET GLOBAL log_bin_trust_function_creators = 1;   -- as root
> ```
> then re-run the migration (safe). The GRANTs in step 2 are the primary
> append-only control; the triggers are backup.

### 2. DB user (append-only)
Edit the password in `ops/grants.sql`, then:
```bash
mysql -u root -p < attendance/ops/grants.sql
# verify enforcement — both MUST error:
mysql -u attendance_app -p phd_timeoff -e "UPDATE att_records SET version=version"   # 1142
mysql -u attendance_app -p phd_timeoff -e "DELETE FROM att_audit LIMIT 1"            # 1142
```

### 3. Bridge secret (PHP ⇄ Python)
```bash
cp config/attendance-example.php config/attendance.php
chmod 600 config/attendance.php
# set ATT_SHARED_SECRET to: openssl rand -hex 32
```

### 4. Backend env + run
```bash
cd attendance
cp .env.example .env && chmod 600 .env
# fill: DB_PASSWORD (= attendance_app password),
#       SHARED_SECRET (= ATT_SHARED_SECRET from step 3),
#       JOB_TOKEN (openssl rand -hex 32), SMTP_* , EMAIL_FROM
docker compose up -d --build
curl -s http://127.0.0.1:3070/api/healthz          # {"ok":true,...}
```
> If the build fails with *"server gave HTTP response to HTTPS client"* while
> fetching the base image, the host has a registry mirror/proxy quirk. Build
> against the already-cached base instead:
> `docker build --pull=false -t phdtimeoff-attendance:latest ./backend` then
> `docker compose up -d`.
> The first build downloads the ~280 MB face model; keep a few GB free.

### 5. Web transport — nothing to configure (default)
The browser talks to the backend through **`/phdtimeoff/attapi/`**, a small PHP
forwarder (`attapi/index.php`). It needs no web-server changes and no root, so
the module deploys identically on Apache (dev) and nginx (production). It sets
`X-Client-IP` from `REMOTE_ADDR` and never forwards a client-supplied one, so
the office-WiFi gate cannot be spoofed. Requirements: `mod_rewrite` +
`AllowOverride All` (Apache, already on) or the nginx equivalent, and PHP curl.

*Optional:* to let the web server proxy directly instead (marginally faster for
the ~1 MB capture upload), include `attendance/ops/apache-attendance.conf` in
the vhost and set `ATT_API_BASE` in `config/attendance.php` to
`BASE_URL . '/attendance/api'`. nginx equivalent:
```nginx
location /phdtimeoff/attendance/api/jobs { deny all; }
location /phdtimeoff/attendance/api/ {
    proxy_set_header X-Client-IP $remote_addr;   # overwrite, don't trust client
    proxy_pass http://127.0.0.1:3070/api/;
}
location /phdtimeoff/attendance/ { deny all; }   # never serve the source dir
```

Verify either way (must return 401 JSON, not a blank page or 404):
```bash
curl -s http://HOST/phdtimeoff/attapi/netcheck
```

### 6. Configure (as office/admin, in the UI)
Attendance → Configuration:
- **Add the router's WAN IP** to the allowlist (until then, nobody can mark).
- Adjust the weekly window if 09:00–10:30 isn't right.
- Set **report recipients** (JSON array of emails).
- Leave strict mode off for launch.

### 7. Host jobs (systemd)
```bash
sudo mkdir -p /etc/attendance
sudo tee /etc/attendance/jobs.env >/dev/null <<EOF
JOB_TOKEN=<same as attendance/.env>
SMTP_HOST=...
SMTP_PORT=587
SMTP_SECURE=false
SMTP_USER=...
SMTP_PASSWORD=...
EMAIL_FROM=phd-attendance@som.iitb.ac.in
ALERT_TO=somoffice@iitb.ac.in
EOF
sudo chmod 600 /etc/attendance/jobs.env
sudo cp ops/systemd/* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now attendance-report.timer attendance-purge.timer attendance-health.timer
```

## Daily / routine operations

- **Enroll a student** (in office): Attendance → Today → *enroll code* next to
  the name → read the one-time code to the student → they open **Attendance →
  One-time enrollment** on *their own phone*, **connected to the office WiFi**,
  enter the code, follow the head-turn steps. That phone is now bound (it only
  matters while `require_enrolled_device` is `1`; otherwise they can mark from
  any phone or browser on the office WiFi). Two
  independent controls guard enrollment: the OTP (proves the office started it)
  and the office-WiFi source IP (proves the phone is physically here). A leaked
  code is useless off the office network, so no one can enroll a face remotely.
  Still confirm identity in person — that is the human trust anchor.
- **Lost/changed phone**: nothing to do while `require_enrolled_device` is `0`
  — they sign in on the new phone and mark. With it `1`: *revoke* on their row
  (reason required), then issue a fresh enroll code. (Revoke also erases the
  face template, so only use it when re-enrollment is really wanted.)
- **Pending reviews**: Attendance → Reviews. Look at the photos, Approve
  (marks present) or Reject (reason required). Do it the same day.
- **Someone couldn't mark (WiFi/power/server)**: Today → *Extend today* (reason
  logged). Nobody loses a day to an outage.
- **Manual/excused/void**: override on the student's row; reason mandatory;
  shows as its own category in the report — never disguised as a real mark.

## The monthly report
- Auto-generated **1st, 08:00 IST**, previous month, emailed to recipients, and
  archived (XLSX + SHA-256) under `data/reports/`. Regeneration makes a new
  version; old files are never overwritten (stipends were paid against them).
- Present = self-marked or manual; **Excused = office-excused OR an approved
  leave** in the leave system (auto-pulled, so approved leave never counts as
  absence); Absent = a working day with none of the above.
- The roster is everyone **on the roll during the month**: a student who joined
  mid-month is accountable from their `joining_date`, and a student deactivated
  mid-month still appears for the days before they left. Days off the roll —
  before joining, and from a deactivation day up to and including the day of a
  reactivation — show as **N** and are not counted as working days for that
  student (the "On roll" column says why). A day with an actual mark always
  counts. `users` has no deactivation date, so the dates come from the
  portal's deactivation/reactivation entries in `system_logs`, else
  `users.updated_at`.
- Generate/download any month on demand from Attendance → Reports.

## Troubleshooting
- **Blank attendance page**: a PHP fatal, almost always `config/attendance.php`
  unreadable by the web server user. It must be mode 644 (`chmod 644
  config/attendance.php`). Confirm in `/var/log/apache2/error.log`.
- **"Could not reach the attendance service"**: the container is down or the
  transport is misconfigured. Check in order:
  `curl -s localhost:3070/api/healthz` (backend up?) →
  `curl -s http://HOST/phdtimeoff/attapi/netcheck` (should be 401 JSON, not 404)
  → browser devtools for the failing request.
- **healthz says "Internal Server Error" and logs show "Can't connect to MySQL
  server on 'localhost' (Connection refused)"**: MySQL/MariaDB restarted after
  the container started (outage of 2026-08-01, both hosts). The old compose
  bind-mounted the socket *file*, which keeps pointing at the deleted inode
  across a mysqld restart; since 2026-08-05 the compose mounts the
  `/var/run/mysqld` *directory* so this fixes itself. If it recurs anyway:
  `docker restart attendance-backend`.
- **Students get "The server is busy" (`SERVER_BUSY`)**: every face-engine slot
  stayed occupied for `FACE_QUEUE_TIMEOUT` (30 s). Nothing was counted against
  them. If it recurs at the 09:00 rush, raise `FACE_WORKERS` in `.env` (per
  uvicorn worker; default 2) on a box with spare cores, and restart.
- **Genuine students failing with `MIXED_FACES`**: the frames of one burst are
  compared with each other; the default floor is 0.35. Lower it with
  `INSERT INTO att_settings (skey, svalue) VALUES ('burst_consistency_threshold','0.30')
  ON DUPLICATE KEY UPDATE svalue=VALUES(svalue);` — and look at the frames
  first: two different people in one burst is exactly what it is for.
- **Access denied for user ... @172.x.x.x**: the DB user is granted for
  `localhost` only while the container is on the docker bridge. Either apply
  `ops/grants.sql` (creates `attendance_app@'%'`, the production path) or use
  the dev overlay: `docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d`.
- **Health alert email**: `docker compose -f attendance/docker-compose.yml ps`
  and `logs`. MySQL down shows here too (healthz pings the DB).
- **Everyone gets "not on office WiFi"**: the router's WAN IP changed. Today
  view banner flags it; add the new IP and deactivate the old (both audited).
  Failed attempts log the seen IP so you can read the new one off the audit tab.
- **iOS**: students should use **Safari directly** (not an installed PWA) —
  iOS partitions storage, so a device cookie set in Safari is invisible inside
  a home-screen web app. This only matters while `require_enrolled_device`
  is `1`; then, if a token is lost, re-enroll.
- **Face model didn't download at build**: mount a `/models` volume with the
  `buffalo_l` pack, or rebuild with network access.

## Backups
The portal's existing DB backup already covers `att_*` (same schema). Also back
up `attendance/data/reports/` — those archived XLSX files are the payment record.
`data/frames/` is transient (60-day retention) and need not be backed up.

## Security notes / threat model
- Passwords are **not** the anti-fraud control — the face + WiFi + window are.
- The one residual hole (an absent student tunneling their *own* live face
  through an accomplice's on-campus phone) is closed by **strict mode**.
- `att_records` and `att_audit` are append-only at the GRANT level (and triggers):
  corrections are new superseding rows, so the full history is always provable.
- Biometric data never leaves the server; frames auto-purge at 60 days; the
  enrollment consent line is shown before capture.
- **Face templates are erased, not just flagged.** Revoking (the office's
  *revoke*, or a re-enrollment superseding the old template) wipes the stored
  embeddings at once; the row stays as the record of who enrolled whom and why
  it ended. The nightly purge (`/api/jobs/purge-frames`) also revokes and erases
  the template and phone binding of any student whose portal account is
  deactivated, and erases any revoked template still holding embeddings. A
  reactivated student re-enrolls at the office.
- **A student with attendance history cannot be hard-deleted.** `att_records`
  and `att_month_approvals` reference students with `ON DELETE RESTRICT`
  (migration `2026_09_23_attendance_backend.sql`): a delete fails with MySQL
  error 1451 and removes nothing. Deactivate leavers instead. (Before this,
  deleting a user cascaded through `students` and silently wiped their
  attendance — InnoDB does not fire the append-only triggers for cascades.)
