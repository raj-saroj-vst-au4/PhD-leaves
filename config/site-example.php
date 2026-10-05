<?php
/**
 * Per-host overrides (example).
 *
 * Copy to `config/site.php` on a host whose settings differ from production —
 * typically a dev box. `config/site.php` is gitignored; config/constants.php
 * loads it first and only falls back to the production values for constants
 * that are not already defined here. Production itself needs no site.php.
 */

// Where this copy of the portal is reached (no trailing slash). Plain http://
// also switches off the HTTPS redirect (FORCE_HTTPS derives from it).
define('SITE_DOMAIN', 'http://10.127.1.23');

// Proxies that terminate TLS in front of this host (none on a dev box).
// define('TLS_TERMINATING_PROXIES', []);

// Private data directory, if it should live outside the web root.
// define('PRIVATE_DIR', '/srv/phdtimeoff-private/');
