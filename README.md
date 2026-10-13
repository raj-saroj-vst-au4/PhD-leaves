# PhD Time-Off Portal

Leave application portal for PhD students at IIT Bombay's School of Management. Students apply for personal, medical, or academic leave; applications route through the faculty guide, the TA coordinator, the HOD, and finally the admin. Email notifications and balance bookkeeping are handled automatically.

## Tech stack

- **PHP 7.4+** (PDO/MySQL)
- **MySQL 5.7+** / MariaDB
- **PHPMailer** for SMTP email (`composer`)
- **Tailwind CSS**, jQuery, DataTables, Chart.js, FontAwesome (vendored under `public/vendor/`)
- Apache with `mod_rewrite`, `mod_headers`, `mod_deflate`

## Repository layout

```
.
├── admin/                  Admin pages (user management, holidays, analytics)
├── api/                    JSON endpoints used by the dashboards
├── classes/                Domain classes (Leave, User, Approval, Holiday)
├── config/                 Site, DB, and mail configuration (DB+mail are gitignored)
├── cron/                   Scheduled jobs (see "Cron jobs" below)
├── database/               schema.sql, migrations/, create_admin.php, ad-hoc backups (gitignored)
├── faculty/                Guide / TA coordinator pages
├── hod/                    HOD pages
├── student/                Student pages
├── includes/               Auth, helpers, EmailService, email templates
├── .private/               Uploaded documents, error/cron logs, report CSVs (never web-served; gitignored)
├── public/                 Static assets and vendored libs
├── vendor/                 Composer-managed PHP deps (gitignored)
├── composer.json
├── index.php               Front controller / role redirect
└── .htaccess               Apache rules and security headers
```

## Approval workflow

A leave application moves through these statuses (`leave_applications.status`):

```
pending_guide → pending_ta → pending_hod → pending_admin → approved
                                                         ↘ rejected / cancelled
```

- **Guide** approves or rejects on behalf of the student's research supervisor (`students.faculty_guide_id`).
- **TA coordinator** confirms no teaching-duty conflict (`students.ta_coordinator_id`).
- **HOD** records the payment status (paid / partially paid / unpaid).
- **Admin** finalises and may attach a signed document.

A **student** can cancel their own application from Leave History, pending or approved, until the day before it starts (with a reason). An approved leave's days go back to the balance, except academic leave deducted before the latest half-yearly reset. Everyone who has approved it or is reviewing it is emailed. After that, only the admin can cancel, and only while it is pending.

Leave types: `personal` (15/yr), `medical` (5/yr), `academic` (25/yr after 12 months from joining). Constants live in [config/constants.php](config/constants.php).

## Setup

### 1. Install dependencies

```bash
composer install
```

### 2. Create configuration files

`config/database.php` and `config/mail.php` are gitignored because they hold credentials. Copy the committed example files and fill in your local values:

```bash
cp config/database-example.php config/database.php
cp config/mail-example.php     config/mail.php
```

Then edit each file and replace the `CHANGE_ME` placeholders. Site-wide constants (domain, base URL, allowed email domain, leave allocations) are in [config/constants.php](config/constants.php) and **are** committed.

### 3. Create the database

Load the schema, then every migration in `database/migrations/` in filename (date) order. The migrations are idempotent, so re-running one is harmless.

```bash
mysql -u root -p -e "CREATE DATABASE phd_timeoff CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p phd_timeoff < database/schema.sql
for f in database/migrations/*.sql; do mysql -u root -p phd_timeoff < "$f"; done
```

### 4. Create the first admin (command line)

There is no web installer. Create the first admin account from a shell on the server:

```bash
php database/create_admin.php "Full Name" name@iitb.ac.in
```

It prints a one-time sign-up link (valid for `TOKEN_EXPIRY_HOURS`); open it to set the password. Further users are invited from **Admin → User Management**.

### 5. Web server

Point the document root (or an alias) at the project. Make sure `.private/` and its subdirectories (`logs/`, `medical_documents/`, `dean_ap_documents/`) are writable by the PHP user **and** by the user whose crontab runs the cron jobs. Production runs nginx, which ignores `.htaccess`: it must refuse dot-paths (`location ~ /\.`) so `.private/` is never served. On Apache, the `.htaccess` expects `RewriteBase /phdtimeoff`; adjust if you mount it elsewhere.

## Cron jobs

All five scripts are idempotent — running them more than once on the same trigger is safe. Each one decides for itself whether today is its day, so a schedule only has to *include* that day: the two month-end jobs are scheduled daily from the 20th to the 31st and exit unless today is the (2nd-)last working day. "Working day" means Monday–Friday, minus holidays in the `holidays` table, plus any weekend day that table declares working. Crontab entries — production paths: the portal is at `/var/www/html/phdtimeoff` and the jobs run from `r4id`'s crontab (adjust the path for another checkout):

```cron
# Reset leave balances on Jan 1 and Jul 1
0 0 1 1,7 * /usr/bin/php /var/www/html/phdtimeoff/cron/reset_leave_balances.php

# Monthly attendance reminder to students (acts only on the 2nd-last working day)
0 9 20-31 * * /usr/bin/php /var/www/html/phdtimeoff/cron/monthly_reminder.php

# Monthly leave report to admins (acts only on the last working day, at 17:00)
0 17 20-31 * * /usr/bin/php /var/www/html/phdtimeoff/cron/monthly_report.php

# 11:00 reminder to guides/TAs about leaves still pending at their stage (skips non-working days)
0 11 * * * /usr/bin/php /var/www/html/phdtimeoff/cron/daily_pending_reminder.php

# Monthly attendance sign-off: opens the previous month on the 1st (after the
# attendance report at 08:00); on other days it only retries emails that failed
30 8 * * * /usr/bin/php /var/www/html/phdtimeoff/cron/monthly_attendance_approval.php
```

The monthly report used to be scheduled `0 8 1 * *`: on the 1st the script found it was not the last working day and exited, so it never ran.

`reset_leave_balances.php` also takes options for running it by hand:

```bash
# Catch up a missed reset: must be the most recent 1 January or 1 July, not in the future.
# Preview first (shows each student's balances before/after, changes and sends nothing):
php /var/www/html/phdtimeoff/cron/reset_leave_balances.php --date=2026-07-01 --dry-run
php /var/www/html/phdtimeoff/cron/reset_leave_balances.php --date=2026-07-01

# Apply the reset without emailing the students (combine with --date, or run on 1 Jan / 1 Jul)
php /var/www/html/phdtimeoff/cron/reset_leave_balances.php --date=2026-07-01 --no-email
```

A second run for the same date finds nothing to do and emails nobody.

Each script writes a per-day log `cron_*.log` under `.private/logs/` (`LOG_DIR`) and an entry to the `system_logs` table; the monthly report also leaves its CSV (`monthly_report_YYYY-MM.csv`) there. The attendance module's scheduled jobs are described in `attendance/DEPLOY-NOTES.md` and `attendance/RUNBOOK.md`.

## Database

Schema is in [database/schema.sql](database/schema.sql). Key tables:

| Table                | Purpose                                              |
|----------------------|------------------------------------------------------|
| `users`              | All accounts (student / faculty / hod / admin)       |
| `students`           | Roll number, joining date, guide & TA pointers       |
| `leave_balances`     | Per-student running balances per leave type          |
| `leave_applications` | One row per request, with stage-wise statuses        |
| `holidays`           | Holidays and declared working days (cron day checks) |
| `leave_adjustments`  | Manual balance adjustments by admin                  |
| `email_logs`         | Audit trail of every send attempt                    |
| `system_logs`        | Audit trail of cron runs and admin actions           |
| `monthly_reminders`  | Tracks which months have been reminded               |

A small in-code migration runner in `getDBConnection()` adds the `supporting_document` column on the fly if missing — see [config/database.php](config/database.php).

## Tests

```bash
composer test                # full PHPUnit suite
composer test:unit           # unit tests only
composer test:integration    # integration tests
composer test:coverage       # HTML coverage report under coverage/
```

## Security notes

- `config/database.php`, `config/mail.php`, and `proddump.sql` are gitignored — never commit them.
- `.private/` holds student-supplied PDFs, logs and report CSVs and is gitignored. Back it up out-of-band; do not check it in. Documents are served only through `api/files/download.php`, which checks who is asking.
- Production nginx ignores `.htaccess` and serves every non-PHP file under the webroot as-is; keep non-public files out of the webroot or behind a filesystem permission, not an Apache rule.
- Students must sign up with an email under `ALLOWED_DOMAIN` (`iitb.ac.in`).
- Sessions time out after 1 hour (`SESSION_TIMEOUT`).
- There is no web installer; the first admin is created with `php database/create_admin.php` (CLI only).
