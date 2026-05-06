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
├── database/               schema.sql + ad-hoc backups (gitignored)
├── faculty/                Guide / TA coordinator pages
├── hod/                    HOD pages
├── student/                Student pages
├── includes/               Auth, helpers, EmailService, email templates
├── logs/                   Cron + error logs (gitignored)
├── public/                 Static assets, vendored libs, user uploads
├── vendor/                 Composer-managed PHP deps (gitignored)
├── composer.json
├── install.php             First-run installer (delete after use)
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

```bash
mysql -u root -p -e "CREATE DATABASE phd_timeoff CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p phd_timeoff < database/schema.sql
```

### 4. Run the installer (first time only)

Visit `https://your-host/phdtimeoff/install.php` and follow the steps. The installer creates the initial admin user and writes a `.installed` marker. **Delete `install.php` afterwards.**

### 5. Web server

Point the document root (or an alias) at the project. The `.htaccess` expects `RewriteBase /phdtimeoff`; adjust if you mount it elsewhere. Make sure `logs/` and `public/uploads/` are writable by the PHP user.

## Cron jobs

All four scripts are idempotent — running them more than once on the same trigger is safe. Suggested crontab entries:

```cron
# Reset leave balances on Jan 1 and Jul 1
0 0 1 1,7 * /usr/bin/php /srv/www/phdtimeoff/cron/reset_leave_balances.php

# Monthly attendance reminder to students (fires only on the 2nd-last working day)
0 9 25-31 * * /usr/bin/php /srv/www/phdtimeoff/cron/monthly_reminder.php

# Monthly leave report to admins
0 8 1 * * /usr/bin/php /srv/www/phdtimeoff/cron/monthly_report.php

# Daily 11:00 reminder to guides/TAs about leaves still pending at their stage
0 11 * * * /usr/bin/php /srv/www/phdtimeoff/cron/daily_pending_reminder.php
```

Each script writes a per-day log under `logs/cron_*.log` and an entry to the `system_logs` table.

## Database

Schema is in [database/schema.sql](database/schema.sql). Key tables:

| Table                | Purpose                                              |
|----------------------|------------------------------------------------------|
| `users`              | All accounts (student / faculty / hod / admin)       |
| `students`           | Roll number, joining date, guide & TA pointers       |
| `leave_balances`     | Per-student running balances per leave type          |
| `leave_applications` | One row per request, with stage-wise statuses        |
| `holidays`           | Working-day calendar used for monthly reminder logic |
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
- `public/uploads/medical_documents/` contains student-supplied PDFs and is gitignored. Back it up out-of-band; do not check it in.
- Apache `.htaccess` blocks direct access to `composer.json`, `composer.lock`, `.env`, and `.gitignore`.
- Students must sign up with an email under `ALLOWED_DOMAIN` (`iitb.ac.in`).
- Sessions time out after 1 hour (`SESSION_TIMEOUT`).
- Delete `install.php` after first-time setup.
