<?php
/**
 * Common Header File
 * Included in all pages for consistent layout and navigation
 */

// Required includes
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
 * Enforce the session HERE, before a single byte of HTML is emitted.
 *
 * Every page that includes this file requires a login, but each one called
 * requireRole() AFTER including it — by which point the doctype and the whole
 * navbar had already been sent. requireLogin()'s redirect therefore could not be
 * issued ("headers already sent") and only its exit() took effect, so an expired
 * session rendered a truncated page: navbar visible, no content, no redirect.
 * Clicking any nav link produced the same stub, which read as "the buttons are
 * dead" rather than "you have been logged out".
 *
 * Running it before output means an expired or missing session lands on the login
 * page with ?timeout=1, from any page, first click.
 */
requireLogin();

// Generate CSRF token for forms
$csrfToken = generateCSRFToken();

// Get current user info
$userName = getCurrentUserName();
$userRole = getCurrentUserRole();
$userEmail = getCurrentUserEmail();

// Set default page title if not set
if (!isset($pageTitle)) {
    $pageTitle = SITE_NAME;
} else {
    $pageTitle = $pageTitle . ' - ' . SITE_NAME;
}

/*
 * Admin navigation, grouped.
 *
 * Eight flat links overflowed the bar and wrapped. One definition drives BOTH
 * the desktop dropdowns and the mobile list, so the two cannot drift apart —
 * they previously duplicated every link by hand. Grouping also surfaces the
 * monthly-leaves and adjacent-leaves pages, which had no nav entry at all.
 */
$adminNav = [
    ['label' => 'Dashboard', 'icon' => 'fa-home', 'url' => '/admin/dashboard.php'],
    ['label' => 'Leaves', 'icon' => 'fa-file-alt', 'items' => [
        ['label' => 'Pending Approvals', 'icon' => 'fa-check-circle', 'url' => '/admin/pending_approvals.php'],
        ['label' => 'All Applications',  'icon' => 'fa-list',         'url' => '/admin/leave_management.php'],
        ['label' => 'Monthly View',      'icon' => 'fa-calendar-alt', 'url' => '/admin/monthly_leaves.php'],
        ['label' => 'Adjacent Leaves',   'icon' => 'fa-compress-arrows-alt', 'url' => '/admin/adjacent_leaves.php'],
    ]],
    ['label' => 'Attendance', 'icon' => 'fa-user-check', 'items' => [
        ['label' => 'Office Dashboard', 'icon' => 'fa-tachometer-alt',  'url' => '/admin/attendance.php'],
        ['label' => 'Monthly Sign-off', 'icon' => 'fa-clipboard-check', 'url' => '/admin/attendance_approvals.php'],
        ['label' => 'Code Screen',      'icon' => 'fa-tv',              'url' => '/admin/attendance_display.php', 'blank' => true],
    ]],
    ['label' => 'Manage', 'icon' => 'fa-cog', 'items' => [
        ['label' => 'Users',     'icon' => 'fa-users',        'url' => '/admin/users.php'],
        ['label' => 'Holidays',  'icon' => 'fa-calendar-day', 'url' => '/admin/holidays.php'],
        ['label' => 'Analytics', 'icon' => 'fa-chart-bar',    'url' => '/admin/analytics.php'],
    ]],
];

// Current page, so the open group and the current item can be highlighted —
// with submenus you otherwise lose all sense of where you are.
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$navIsCurrent = function ($url) use ($currentScript) {
    return basename($url) === $currentScript;
};
$navGroupIsCurrent = function ($group) use ($navIsCurrent) {
    if (!isset($group['items'])) {
        return $navIsCurrent($group['url']);
    }
    foreach ($group['items'] as $it) {
        if ($navIsCurrent($it['url'])) {
            return true;
        }
    }
    return false;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="PhD Time-Off Management System for IIT Bombay">
    <meta name="author" content="IIT Bombay">
    <title><?php echo sanitize($pageTitle); ?></title>

    <!-- TailwindCSS (Local) -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/vendor/tailwind/tailwind.min.css">

    <!-- jQuery (Local) -->
    <script src="<?php echo BASE_URL; ?>/public/vendor/jquery/jquery-3.7.1.min.js"></script>

    <!-- Chart.js (Local) -->
    <script src="<?php echo BASE_URL; ?>/public/vendor/chartjs/chart.umd.min.js"></script>

    <!-- DataTables CSS (Local) -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/vendor/datatables/jquery.dataTables.min.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/vendor/datatables/responsive.dataTables.min.css">

    <!-- DataTables JS (Local) -->
    <script src="<?php echo BASE_URL; ?>/public/vendor/datatables/jquery.dataTables.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/public/vendor/datatables/dataTables.responsive.min.js"></script>

    <!-- Font Awesome (Local) -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/vendor/fontawesome/css/all.min.css">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo asset_url('public/css/custom.css'); ?>">

    <!-- Page-specific styles (mirrors $pageScripts in footer.php) -->
    <?php if (isset($pageStyles)): ?>
        <?php echo $pageStyles; ?>
    <?php endif; ?>

    <!-- CSRF Token Meta Tag -->
    <meta name="csrf-token" content="<?php echo $csrfToken; ?>">
</head>
<body class="bg-gray-50">

    <?php if (isImpersonating()): ?>
    <!-- Impersonation Banner -->
    <div class="bg-yellow-500 text-black border-b-2 border-yellow-700 relative z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div class="text-sm flex items-center">
                <i class="fas fa-user-secret mr-2"></i>
                <span>
                    Admin <strong><?php echo sanitize(getImpersonatorName() ?? 'unknown'); ?></strong>
                    is impersonating <strong><?php echo sanitize(getCurrentUserName() ?? ''); ?></strong>
                    (<?php echo sanitize(ucfirst(getCurrentUserRole() ?? '')); ?>)
                </span>
            </div>
            <form method="POST" action="<?php echo BASE_URL; ?>/stop_impersonation.php" class="inline">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <button type="submit" class="px-3 py-1 bg-black text-yellow-400 rounded text-sm font-medium hover:bg-gray-800 transition-colors">
                    <i class="fas fa-sign-out-alt mr-1"></i>Return to admin
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Navigation Bar -->
    <nav class="bg-blue-600 shadow-lg relative z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <!-- Logo and Brand -->
                <div class="flex items-center">
                    <div class="flex-shrink-0 flex items-center">
                        <a href="<?php echo BASE_URL; ?>/index.php" class="text-white text-xl font-bold">
                            <i class="fas fa-graduation-cap mr-2"></i>
                            PhD Time-Off Portal
                        </a>
                    </div>
                </div>

                <?php if (isLoggedIn()): ?>
                <!-- Desktop Navigation -->
                <div class="hidden md:flex md:items-center md:space-x-4">
                    <?php if ($userRole === 'student'): ?>
                        <a href="<?php echo BASE_URL; ?>/student/dashboard.php" class="nav-link text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium">
                            <i class="fas fa-home mr-1"></i> Dashboard
                        </a>
                        <a href="<?php echo BASE_URL; ?>/student/apply_leave.php" class="nav-link text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium">
                            <i class="fas fa-paper-plane mr-1"></i> Apply Leave
                        </a>
                        <a href="<?php echo BASE_URL; ?>/student/leave_history.php" class="nav-link text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium">
                            <i class="fas fa-history mr-1"></i> History
                        </a>
                        <a href="<?php echo BASE_URL; ?>/student/holidays.php" class="nav-link text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium">
                            <i class="fas fa-calendar-alt mr-1"></i> Holidays
                        </a>
                        <a href="<?php echo BASE_URL; ?>/student/attendance.php" class="nav-link text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium">
                            <i class="fas fa-user-check mr-1"></i> Attendance
                        </a>
                    <?php elseif ($userRole === 'faculty'): ?>
                        <a href="<?php echo BASE_URL; ?>/faculty/dashboard.php" class="nav-link text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium">
                            <i class="fas fa-home mr-1"></i> Dashboard
                        </a>
                        <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php" class="nav-link text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium">
                            <i class="fas fa-check-circle mr-1"></i> Approvals
                        </a>
                        <a href="<?php echo BASE_URL; ?>/faculty/attendance_approvals.php" class="nav-link text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium">
                            <i class="fas fa-user-check mr-1"></i> Attendance
                        </a>
                        <a href="<?php echo BASE_URL; ?>/faculty/my_students.php" class="nav-link text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium">
                            <i class="fas fa-user-graduate mr-1"></i> My Students
                        </a>
                    <?php elseif ($userRole === 'hod'): ?>
                        <a href="<?php echo BASE_URL; ?>/hod/dashboard.php" class="nav-link text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium">
                            <i class="fas fa-home mr-1"></i> Dashboard
                        </a>
                        <a href="<?php echo BASE_URL; ?>/hod/pending_approvals.php" class="nav-link text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium">
                            <i class="fas fa-check-circle mr-1"></i> Approvals
                        </a>
                        <a href="<?php echo BASE_URL; ?>/hod/all_leaves.php" class="nav-link text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium">
                            <i class="fas fa-list mr-1"></i> Leaves
                        </a>
                        <a href="<?php echo BASE_URL; ?>/hod/holidays.php" class="nav-link text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium">
                            <i class="fas fa-calendar-alt mr-1"></i> Holidays
                        </a>
                        <a href="<?php echo BASE_URL; ?>/hod/analytics.php" class="nav-link text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium">
                            <i class="fas fa-chart-bar mr-1"></i> Analytics
                        </a>
                    <?php elseif ($userRole === 'admin'): ?>
                        <?php foreach ($adminNav as $gi => $group): ?>
                            <?php if (!isset($group['items'])): ?>
                                <a href="<?php echo BASE_URL . $group['url']; ?>"
                                   class="nav-link text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium <?php echo $navGroupIsCurrent($group) ? 'bg-blue-700' : ''; ?>">
                                    <i class="fas <?php echo $group['icon']; ?> mr-1"></i> <?php echo $group['label']; ?>
                                </a>
                            <?php else: ?>
                                <div class="relative">
                                    <button type="button"
                                            class="nav-menu-btn flex items-center text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium <?php echo $navGroupIsCurrent($group) ? 'bg-blue-700' : ''; ?>"
                                            data-nav-menu="nav-menu-<?php echo $gi; ?>"
                                            aria-expanded="false" aria-haspopup="true">
                                        <i class="fas <?php echo $group['icon']; ?> mr-1"></i>
                                        <span><?php echo $group['label']; ?></span>
                                        <i class="fas fa-chevron-down ml-2 text-xs"></i>
                                    </button>
                                    <div id="nav-menu-<?php echo $gi; ?>" role="menu"
                                         class="nav-menu hidden absolute left-0 z-50 mt-2 w-56 rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 py-1">
                                        <?php foreach ($group['items'] as $item): ?>
                                            <a href="<?php echo BASE_URL . $item['url']; ?>"
                                               <?php echo !empty($item['blank']) ? 'target="_blank" rel="noopener"' : ''; ?>
                                               role="menuitem"
                                               class="block px-4 py-2 text-sm hover:bg-gray-100 <?php echo $navIsCurrent($item['url']) ? 'bg-blue-50 text-blue-800 font-semibold' : 'text-gray-700'; ?>">
                                                <i class="fas <?php echo $item['icon']; ?> mr-2 text-gray-400"></i><?php echo $item['label']; ?>
                                                <?php if (!empty($item['blank'])): ?>
                                                    <i class="fas fa-external-link-alt ml-1 text-xs text-gray-400"></i>
                                                <?php endif; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <!-- User Profile Dropdown -->
                    <div class="relative ml-3">
                        <button type="button" class="flex items-center text-white hover:bg-blue-700 px-3 py-2 rounded-md text-sm font-medium" id="user-menu-button" aria-expanded="false" aria-haspopup="true">
                            <i class="fas fa-user-circle mr-2"></i>
                            <span><?php echo sanitize($userName); ?></span>
                            <i class="fas fa-chevron-down ml-2"></i>
                        </button>

                        <!-- Dropdown menu -->
                        <div class="hidden absolute right-0 z-50 mt-2 w-56 origin-top-right rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none" id="user-menu" role="menu" aria-orientation="vertical" aria-labelledby="user-menu-button" tabindex="-1">
                            <div class="px-4 py-3 border-b">
                                <p class="text-sm text-gray-900 font-semibold"><?php echo sanitize($userName); ?></p>
                                <p class="text-xs text-gray-500"><?php echo sanitize($userEmail); ?></p>
                                <p class="text-xs text-gray-500 mt-1">
                                    <span class="inline-block px-2 py-1 bg-blue-100 text-blue-800 rounded">
                                        <?php echo ucfirst($userRole); ?>
                                    </span>
                                </p>
                            </div>
                            <div class="py-1">
                                <a href="<?php echo BASE_URL; ?>/profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem">
                                    <i class="fas fa-user mr-2"></i> My Profile
                                </a>
                                <a href="<?php echo BASE_URL; ?>/change-password.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem">
                                    <i class="fas fa-key mr-2"></i> Change Password
                                </a>
                                <a href="<?php echo BASE_URL; ?>/logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100" role="menuitem">
                                    <i class="fas fa-sign-out-alt mr-2"></i> Logout
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Mobile menu button -->
                <div class="flex items-center md:hidden">
                    <button type="button" class="inline-flex items-center justify-center p-2 rounded-md text-white hover:bg-blue-700 focus:outline-none" id="mobile-menu-button">
                        <span class="sr-only">Open main menu</span>
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                </div>
                <?php else: ?>
                <!-- Not logged in -->
                <div class="flex items-center">
                    <a href="<?php echo BASE_URL; ?>/login.php" class="text-white hover:bg-blue-700 px-4 py-2 rounded-md text-sm font-medium">
                        <i class="fas fa-sign-in-alt mr-1"></i> Login
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isLoggedIn()): ?>
        <!-- Mobile menu -->
        <div class="hidden md:hidden" id="mobile-menu">
            <div class="px-2 pt-2 pb-3 space-y-1">
                <?php if ($userRole === 'student'): ?>
                    <a href="<?php echo BASE_URL; ?>/student/dashboard.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-home mr-2"></i> Dashboard
                    </a>
                    <a href="<?php echo BASE_URL; ?>/student/apply_leave.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-paper-plane mr-2"></i> Apply Leave
                    </a>
                    <a href="<?php echo BASE_URL; ?>/student/leave_history.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-history mr-2"></i> History
                    </a>
                    <a href="<?php echo BASE_URL; ?>/student/holidays.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-calendar-alt mr-2"></i> Holidays
                    </a>
                    <a href="<?php echo BASE_URL; ?>/student/attendance.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-user-check mr-2"></i> Attendance
                    </a>
                <?php elseif ($userRole === 'faculty'): ?>
                    <a href="<?php echo BASE_URL; ?>/faculty/dashboard.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-home mr-2"></i> Dashboard
                    </a>
                    <a href="<?php echo BASE_URL; ?>/faculty/pending_approvals.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-check-circle mr-2"></i> Approvals
                    </a>
                    <a href="<?php echo BASE_URL; ?>/faculty/attendance_approvals.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-user-check mr-2"></i> Attendance
                    </a>
                    <a href="<?php echo BASE_URL; ?>/faculty/my_students.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-user-graduate mr-2"></i> My Students
                    </a>
                <?php elseif ($userRole === 'hod'): ?>
                    <a href="<?php echo BASE_URL; ?>/hod/dashboard.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-home mr-2"></i> Dashboard
                    </a>
                    <a href="<?php echo BASE_URL; ?>/hod/pending_approvals.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-check-circle mr-2"></i> Approvals
                    </a>
                    <a href="<?php echo BASE_URL; ?>/hod/all_leaves.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-list mr-2"></i> Leaves
                    </a>
                    <a href="<?php echo BASE_URL; ?>/hod/holidays.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-calendar-alt mr-2"></i> Holidays
                    </a>
                    <a href="<?php echo BASE_URL; ?>/hod/analytics.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-chart-bar mr-2"></i> Analytics
                    </a>
                <?php elseif ($userRole === 'admin'): ?>
                    <?php foreach ($adminNav as $group): ?>
                        <?php if (!isset($group['items'])): ?>
                            <a href="<?php echo BASE_URL . $group['url']; ?>"
                               class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium <?php echo $navGroupIsCurrent($group) ? 'bg-blue-700' : ''; ?>">
                                <i class="fas <?php echo $group['icon']; ?> mr-2"></i> <?php echo $group['label']; ?>
                            </a>
                        <?php else: ?>
                            <?php /* A heading plus indented links: no collapsing, so there is no
                                     JS to fail on a phone and every destination stays one tap away. */ ?>
                            <p class="px-3 pt-3 pb-1 text-xs font-semibold uppercase tracking-wider text-blue-200">
                                <i class="fas <?php echo $group['icon']; ?> mr-2"></i><?php echo $group['label']; ?>
                            </p>
                            <?php foreach ($group['items'] as $item): ?>
                                <a href="<?php echo BASE_URL . $item['url']; ?>"
                                   <?php echo !empty($item['blank']) ? 'target="_blank" rel="noopener"' : ''; ?>
                                   style="padding-left:2.25rem"
                                   class="block text-white hover:bg-blue-700 pr-3 py-2 rounded-md text-base font-medium <?php echo $navIsCurrent($item['url']) ? 'bg-blue-700' : ''; ?>">
                                    <i class="fas <?php echo $item['icon']; ?> mr-2 text-blue-200"></i><?php echo $item['label']; ?>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- User info in mobile menu -->
                <div class="border-t border-blue-500 pt-4 pb-3">
                    <div class="px-3 mb-3">
                        <p class="text-sm text-white font-semibold"><?php echo sanitize($userName); ?></p>
                        <p class="text-xs text-blue-200"><?php echo sanitize($userEmail); ?></p>
                        <p class="text-xs text-blue-200 mt-1">
                            <span class="inline-block px-2 py-1 bg-blue-500 rounded">
                                <?php echo ucfirst($userRole); ?>
                            </span>
                        </p>
                    </div>
                    <a href="<?php echo BASE_URL; ?>/profile.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-user mr-2"></i> My Profile
                    </a>
                    <a href="<?php echo BASE_URL; ?>/change-password.php" class="block text-white hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-key mr-2"></i> Change Password
                    </a>
                    <a href="<?php echo BASE_URL; ?>/logout.php" class="block text-red-200 hover:bg-blue-700 px-3 py-2 rounded-md text-base font-medium">
                        <i class="fas fa-sign-out-alt mr-2"></i> Logout
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </nav>

    <!-- Main Content Area -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- Flash Message Display Area -->
        <div id="flash-message-container">
            <?php
            if ($flash = getFlashMessage()) {
                $colors = [
                    'success' => ['bg' => 'bg-green-50', 'border' => 'border-green-200', 'text' => 'text-green-800', 'icon' => 'fa-check-circle'],
                    'error' => ['bg' => 'bg-red-50', 'border' => 'border-red-200', 'text' => 'text-red-800', 'icon' => 'fa-exclamation-circle'],
                    'warning' => ['bg' => 'bg-yellow-50', 'border' => 'border-yellow-200', 'text' => 'text-yellow-800', 'icon' => 'fa-exclamation-triangle'],
                    'info' => ['bg' => 'bg-blue-50', 'border' => 'border-blue-200', 'text' => 'text-blue-800', 'icon' => 'fa-info-circle']
                ];

                $color = $colors[$flash['type']] ?? $colors['info'];

                echo '<div class="flash-message mb-6 p-4 rounded-lg ' . $color['bg'] . ' border ' . $color['border'] . '" role="alert">';
                echo '<div class="flex items-center">';
                echo '<i class="fas ' . $color['icon'] . ' ' . $color['text'] . ' mr-3"></i>';
                echo '<p class="text-sm ' . $color['text'] . ' flex-1">' . sanitize($flash['message']) . '</p>';
                echo '<button type="button" class="ml-3 inline-flex text-gray-400 hover:text-gray-600" onclick="this.parentElement.parentElement.remove()">';
                echo '<i class="fas fa-times"></i>';
                echo '</button>';
                echo '</div>';
                echo '</div>';
            }
            ?>
        </div>

        <!-- Loading Spinner (hidden by default) -->
        <div id="loading-spinner" class="loading-spinner hidden">
            <div class="spinner"></div>
        </div>
