<?php
/**
 * Create an admin account from the command line (first-time setup).
 *
 *   php database/create_admin.php "Full Name" name@iitb.ac.in
 *
 * Replaces the old web installer (install.php), which was left deployed and
 * handed anyone who opened ?step=2 the database host, user and password. The
 * account is created with an invitation token, exactly like an admin invite:
 * open the printed sign-up link to set the password (the link expires after
 * TOKEN_EXPIRY_HOURS). No password ever passes through this script.
 */

// CLI only: this file sits inside the webroot and production nginx runs any .php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/User.php';

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php database/create_admin.php \"Full Name\" name@" . ALLOWED_DOMAIN . "\n");
    exit(2);
}

$name = sanitize($argv[1]);   // stored HTML-escaped, like every name entered in the portal
$email = strtolower(trim($argv[2]));

if ($name === '' || !validateEmail($email) || !validateDomain($email)) {
    fwrite(STDERR, "A name and a valid @" . ALLOWED_DOMAIN . " email address are required.\n");
    exit(2);
}

try {
    $userClass = new User();
    if ($userClass->getByEmail($email)) {
        fwrite(STDERR, "An account with $email already exists.\n");
        exit(1);
    }

    $result = $userClass->create(['name' => $name, 'email' => $email, 'role' => 'admin']);

    echo "Admin account created for $email (user #{$result['user_id']}).\n";
    echo "Set its password within " . TOKEN_EXPIRY_HOURS . " hours at:\n";
    echo SITE_DOMAIN . BASE_URL . "/signup.php?token=" . $result['token'] . "\n";
    exit(0);

} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
