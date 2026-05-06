<?php
/**
 * Installation Script for PhD Time-Off Portal
 * Run this script once to setup the database
 * DELETE THIS FILE after installation for security
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if already installed
if (file_exists(__DIR__ . '/.installed')) {
    die('System is already installed. Delete .installed file to reinstall.');
}

$errors = [];
$success = [];
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;

// Step 1: Check requirements
if ($step === 1) {
    $title = "Step 1: System Requirements Check";

    // Check PHP version
    $phpVersion = phpversion();
    if (version_compare($phpVersion, '7.4.0', '>=')) {
        $success[] = "PHP Version: $phpVersion ✓";
    } else {
        $errors[] = "PHP Version $phpVersion is too old. Required: 7.4+";
    }

    // Check required extensions
    $required_extensions = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'json'];
    foreach ($required_extensions as $ext) {
        if (extension_loaded($ext)) {
            $success[] = "Extension $ext: Loaded ✓";
        } else {
            $errors[] = "Extension $ext: Not loaded";
        }
    }

    // Check folder permissions
    $folders_to_check = [
        __DIR__ . '/logs' => 'Logs Directory',
        __DIR__ . '/public/uploads' => 'Uploads Directory'
    ];

    foreach ($folders_to_check as $folder => $name) {
        if (is_writable($folder)) {
            $success[] = "$name: Writable ✓";
        } else {
            $errors[] = "$name: Not writable (chmod 777 required)";
        }
    }
}

// Step 2: Database Configuration
if ($step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = "Step 2: Database Configuration";

    $dbHost = $_POST['db_host'] ?? 'localhost';
    $dbName = $_POST['db_name'] ?? 'phd_timeoff';
    $dbUser = $_POST['db_user'] ?? '';
    $dbPass = $_POST['db_pass'] ?? '';

    try {
        $dsn = "mysql:host=$dbHost;charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create database
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $success[] = "Database '$dbName' created successfully ✓";

        // Select database
        $pdo->exec("USE `$dbName`");

        // Read and execute schema
        $schema = file_get_contents(__DIR__ . '/database/schema.sql');
        $statements = array_filter(array_map('trim', explode(';', $schema)));

        foreach ($statements as $statement) {
            if (!empty($statement) && !preg_match('/^(CREATE DATABASE|USE)/i', $statement)) {
                $pdo->exec($statement);
            }
        }

        $success[] = "Database schema created successfully ✓";

        // Update config file
        $configContent = "<?php\n";
        $configContent .= "/**\n * Database Configuration\n * Contains database connection settings and PDO connection\n */\n\n";
        $configContent .= "define('DB_HOST', " . var_export($dbHost, true) . ");\n";
        $configContent .= "define('DB_NAME', " . var_export($dbName, true) . ");\n";
        $configContent .= "define('DB_USER', " . var_export($dbUser, true) . ");\n";
        $configContent .= "define('DB_PASS', " . var_export($dbPass, true) . ");\n";
        $configContent .= "define('DB_CHARSET', 'utf8mb4');\n\n";
        $configContent .= file_get_contents(__DIR__ . '/config/database.php');
        $configContent = preg_replace('/define\(\'DB_[A-Z]+\',.*?\);\\n/', '', $configContent, 5);

        file_put_contents(__DIR__ . '/config/database.php', $configContent);
        $success[] = "Configuration file updated ✓";

        // Mark as installed
        file_put_contents(__DIR__ . '/.installed', date('Y-m-d H:i:s'));

        $step = 3;

    } catch (PDOException $e) {
        $errors[] = "Database Error: " . $e->getMessage();
    }
}

// Get current configuration for Step 2 form
if ($step === 2 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $title = "Step 2: Database Configuration";
    require_once __DIR__ . '/config/database.php';
}

// Step 3: Complete
if ($step === 3) {
    $title = "Installation Complete!";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PhD Time-Off Portal - Installation</title>
    <link rel="stylesheet" href="/phdtimeoff/public/vendor/tailwind/tailwind.min.css">
    <link rel="stylesheet" href="/phdtimeoff/public/vendor/fontawesome/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen py-12 px-4">

    <div class="max-w-4xl mx-auto">
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="inline-block p-4 bg-white rounded-full shadow-lg mb-4">
                <i class="fas fa-cog text-4xl text-blue-600"></i>
            </div>
            <h1 class="text-3xl font-bold text-gray-900 mb-2">PhD Time-Off Portal</h1>
            <p class="text-gray-600">Installation Wizard</p>
        </div>

        <!-- Progress Steps -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <div class="flex justify-between items-center">
                <div class="flex flex-col items-center flex-1">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center <?php echo $step >= 1 ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-600'; ?>">
                        <?php echo $step > 1 ? '<i class="fas fa-check"></i>' : '1'; ?>
                    </div>
                    <p class="text-xs mt-2 text-center">Requirements</p>
                </div>
                <div class="flex-1 h-1 <?php echo $step >= 2 ? 'bg-blue-600' : 'bg-gray-300'; ?>"></div>
                <div class="flex flex-col items-center flex-1">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center <?php echo $step >= 2 ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-600'; ?>">
                        <?php echo $step > 2 ? '<i class="fas fa-check"></i>' : '2'; ?>
                    </div>
                    <p class="text-xs mt-2 text-center">Database</p>
                </div>
                <div class="flex-1 h-1 <?php echo $step >= 3 ? 'bg-blue-600' : 'bg-gray-300'; ?>"></div>
                <div class="flex flex-col items-center flex-1">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center <?php echo $step >= 3 ? 'bg-green-600 text-white' : 'bg-gray-300 text-gray-600'; ?>">
                        <?php echo $step >= 3 ? '<i class="fas fa-check"></i>' : '3'; ?>
                    </div>
                    <p class="text-xs mt-2 text-center">Complete</p>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="bg-white rounded-lg shadow-md p-8">
            <h2 class="text-2xl font-semibold text-gray-900 mb-6"><?php echo $title; ?></h2>

            <!-- Errors -->
            <?php if (!empty($errors)): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <h3 class="font-semibold text-red-800 mb-2">
                        <i class="fas fa-exclamation-triangle mr-2"></i>Errors Found:
                    </h3>
                    <ul class="list-disc list-inside text-sm text-red-700">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Success Messages -->
            <?php if (!empty($success)): ?>
                <div class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg">
                    <h3 class="font-semibold text-green-800 mb-2">
                        <i class="fas fa-check-circle mr-2"></i>Success:
                    </h3>
                    <ul class="list-none text-sm text-green-700 space-y-1">
                        <?php foreach ($success as $msg): ?>
                            <li><?php echo htmlspecialchars($msg); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Step Content -->
            <?php if ($step === 1): ?>
                <div class="prose max-w-none">
                    <p class="text-gray-700 mb-4">
                        Before installing, please ensure your server meets all requirements listed above.
                    </p>

                    <?php if (!empty($errors)): ?>
                        <div class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                            <p class="text-sm text-yellow-800">
                                <strong>Note:</strong> Please fix the errors above before continuing.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="mt-6">
                            <a href="?step=2" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                                Continue to Database Setup <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($step === 2 && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
                <form method="POST" action="?step=2" class="space-y-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Database Host</label>
                        <input type="text" name="db_host" value="<?php echo htmlspecialchars(DB_HOST ?? 'localhost'); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Database Name</label>
                        <input type="text" name="db_name" value="<?php echo htmlspecialchars(DB_NAME ?? 'phd_timeoff'); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Database User</label>
                        <input type="text" name="db_user" value="<?php echo htmlspecialchars(DB_USER ?? ''); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500" required>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Database Password</label>
                        <input type="password" name="db_pass" value="<?php echo htmlspecialchars(DB_PASS ?? ''); ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <p class="text-xs text-gray-500 mt-1">Leave blank if no password</p>
                    </div>

                    <div class="flex items-center justify-between pt-4">
                        <a href="?step=1" class="text-gray-600 hover:text-gray-800">
                            <i class="fas fa-arrow-left mr-2"></i>Back
                        </a>
                        <button type="submit" class="px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                            Install Database <i class="fas fa-database ml-2"></i>
                        </button>
                    </div>
                </form>

            <?php elseif ($step === 3): ?>
                <div class="text-center">
                    <div class="inline-block p-6 bg-green-100 rounded-full mb-6">
                        <i class="fas fa-check-circle text-6xl text-green-600"></i>
                    </div>

                    <h3 class="text-2xl font-bold text-gray-900 mb-4">Installation Successful!</h3>
                    <p class="text-gray-700 mb-6">
                        PhD Time-Off Portal has been successfully installed.
                    </p>

                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6 text-left">
                        <h4 class="font-semibold text-blue-900 mb-3">Default Admin Credentials:</h4>
                        <div class="space-y-2 text-sm text-blue-800">
                            <p><strong>Email:</strong> admin@iitb.ac.in</p>
                            <p><strong>Password:</strong> admin123</p>
                        </div>
                    </div>

                    <div class="bg-red-50 border border-red-200 rounded-lg p-6 mb-6 text-left">
                        <h4 class="font-semibold text-red-900 mb-3">
                            <i class="fas fa-exclamation-triangle mr-2"></i>Important Security Steps:
                        </h4>
                        <ol class="list-decimal list-inside text-sm text-red-800 space-y-2">
                            <li>Delete this install.php file immediately</li>
                            <li>Change the admin password after first login</li>
                            <li>Configure SMTP settings in config/mail.php</li>
                            <li>Setup cron jobs for automated tasks</li>
                            <li>Set up SSL/HTTPS for production use</li>
                        </ol>
                    </div>

                    <div class="space-y-3">
                        <a href="/phdtimeoff/login.php" class="block w-full px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                            <i class="fas fa-sign-in-alt mr-2"></i>Go to Login Page
                        </a>
                        <a href="/phdtimeoff/README.md" class="block w-full px-6 py-3 bg-gray-200 text-gray-700 font-semibold rounded-lg hover:bg-gray-300 transition-colors">
                            <i class="fas fa-book mr-2"></i>Read Documentation
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="text-center mt-8 text-sm text-gray-600">
            <p>&copy; <?php echo date('Y'); ?> IIT Bombay. All rights reserved.</p>
        </div>
    </div>

</body>
</html>
