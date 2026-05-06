<?php
/**
 * Database Configuration (example)
 *
 * Copy this file to `config/database.php` and fill in your local credentials.
 * `config/database.php` is gitignored so secrets stay out of version control.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'phd_timeoff');
define('DB_USER', 'timeoff_user');
define('DB_PASS', 'CHANGE_ME');
define('DB_CHARSET', 'utf8mb4');

/**
 * Get PDO Database Connection
 * @return PDO
 */
function getDBConnection() {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

            // Run pending migrations
            runPendingMigrations($pdo);
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please contact administrator.");
        }
    }

    return $pdo;
}

/**
 * Run pending database migrations
 * @param PDO $pdo
 */
function runPendingMigrations($pdo) {
    static $migrationsRun = false;

    if ($migrationsRun) {
        return;
    }
    $migrationsRun = true;

    // Check if supporting_document column exists, if not add it
    try {
        $result = $pdo->query("SHOW COLUMNS FROM leave_applications LIKE 'supporting_document'");
        if ($result->rowCount() === 0) {
            $pdo->exec("ALTER TABLE leave_applications ADD COLUMN supporting_document VARCHAR(255) DEFAULT NULL");
        }
    } catch (PDOException $e) {
        // Log but don't fail - table might not exist yet during installation
        error_log("Migration check: " . $e->getMessage());
    }
}
