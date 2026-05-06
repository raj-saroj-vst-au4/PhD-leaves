<?php
/**
 * PHPMailer Configuration (example)
 *
 * Copy this file to `config/mail.php` and fill in your SMTP credentials.
 * `config/mail.php` is gitignored so secrets stay out of version control.
 */

define('SMTP_HOST', 'smtp-auth.iitb.ac.in');
define('SMTP_PORT', 587);
define('SMTP_USER', 'CHANGE_ME');
define('SMTP_PASS', 'CHANGE_ME');
define('SMTP_FROM_EMAIL', 'CHANGE_ME@iitb.ac.in');
define('SMTP_FROM_NAME', 'PhD Time-Off Portal');
define('SMTP_ENCRYPTION', 'tls');

// Email Templates Directory
define('EMAIL_TEMPLATES_DIR', __DIR__ . '/../includes/email_templates/');
