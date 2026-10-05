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

// auth.php has already opened the session (hardened cookie flags); no-op here.
startAppSession();

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
        // Sits under Leaves rather than Manage: a pending request HOLDS a leave
        // application, so this is where an admin looks when a student asks why
        // theirs has not reached anyone.
        ['label' => 'Guide/TA Requests', 'icon' => 'fa-user-edit',   'url' => '/admin/guide_ta_requests.php'],
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
<body class="bg-gray-50 text-gray-800">
<?php
/*
 * Navigation for every role, as data. One definition drives BOTH the desktop
 * bar and the mobile list, so the two cannot drift apart (they used to be
 * hand-duplicated per role). Admin keeps its grouped menus ($adminNav above).
 */
$roleNav = [
    'student' => [
        ['label' => 'Dashboard',   'icon' => 'fa-home',          'url' => '/student/dashboard.php'],
        ['label' => 'Apply Leave', 'icon' => 'fa-paper-plane',   'url' => '/student/apply_leave.php'],
        ['label' => 'History',     'icon' => 'fa-history',       'url' => '/student/leave_history.php'],
        ['label' => 'Holidays',    'icon' => 'fa-calendar-alt',  'url' => '/student/holidays.php'],
        ['label' => 'Attendance',  'icon' => 'fa-user-check',    'url' => '/student/attendance.php'],
    ],
    'faculty' => [
        ['label' => 'Dashboard',   'icon' => 'fa-home',          'url' => '/faculty/dashboard.php'],
        ['label' => 'Approvals',   'icon' => 'fa-check-circle',  'url' => '/faculty/pending_approvals.php'],
        ['label' => 'Attendance',  'icon' => 'fa-user-check',    'url' => '/faculty/attendance_approvals.php'],
        ['label' => 'My Students', 'icon' => 'fa-user-graduate', 'url' => '/faculty/my_students.php'],
    ],
    'hod' => [
        ['label' => 'Dashboard',   'icon' => 'fa-home',          'url' => '/hod/dashboard.php'],
        ['label' => 'Approvals',   'icon' => 'fa-check-circle',  'url' => '/hod/pending_approvals.php'],
        ['label' => 'Leaves',      'icon' => 'fa-list',          'url' => '/hod/all_leaves.php'],
        ['label' => 'Holidays',    'icon' => 'fa-calendar-alt',  'url' => '/hod/holidays.php'],
        ['label' => 'Analytics',   'icon' => 'fa-chart-bar',     'url' => '/hod/analytics.php'],
    ],
    'admin' => $adminNav,
];
$navItems = $roleNav[$userRole] ?? [];

// Initials for the avatar: first letter of the first two words of the name.
$userInitials = '';
foreach (array_slice(preg_split('/\s+/', trim(html_entity_decode((string) $userName, ENT_QUOTES, 'UTF-8'))), 0, 2) as $part) {
    $userInitials .= mb_strtoupper(mb_substr($part, 0, 1));
}
$userInitials = $userInitials !== '' ? $userInitials : '?';
?>

    <?php if (isImpersonating()): ?>
    <!-- Impersonation Banner -->
    <div class="relative z-50 bg-amber-400 text-amber-950">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div class="text-sm flex items-center gap-2">
                <i class="fas fa-user-secret"></i>
                <span>
                    Admin <strong><?php echo sanitize(getImpersonatorName() ?? 'unknown'); ?></strong>
                    is impersonating <strong><?php echo sanitize(getCurrentUserName() ?? ''); ?></strong>
                    (<?php echo sanitize(ucfirst(getCurrentUserRole() ?? '')); ?>)
                </span>
            </div>
            <form method="POST" action="<?php echo BASE_URL; ?>/stop_impersonation.php" class="inline">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-amber-950 px-3 py-1.5 text-xs font-semibold text-amber-100 hover:bg-black transition-colors">
                    <i class="fas fa-sign-out-alt"></i>Return to admin
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Navigation Bar -->
    <nav class="app-navbar sticky top-0 z-40 border-b border-gray-200/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between gap-4">
                <!-- Logo and Brand -->
                <a href="<?php echo BASE_URL; ?>/index.php" class="flex shrink-0 items-center gap-3 rounded-lg">
                    <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-blue-600 to-violet-600 text-white shadow-sm">
                        <i class="fas fa-graduation-cap"></i>
                    </span>
                    <span class="leading-tight">
                        <span class="block text-sm font-semibold text-gray-900">PhD Time-Off</span>
                        <span class="hidden sm:block text-xs text-gray-500">SJM School of Management</span>
                    </span>
                </a>

                <?php if (isLoggedIn()): ?>
                <!-- Desktop Navigation -->
                <div class="hidden lg:flex lg:flex-1 lg:items-center lg:justify-center lg:gap-1">
                    <?php foreach ($navItems as $gi => $group): ?>
                        <?php if (!isset($group['items'])): ?>
                            <a href="<?php echo BASE_URL . $group['url']; ?>"
                               class="nav-pill <?php echo $navGroupIsCurrent($group) ? 'nav-pill-active' : ''; ?>"
                               <?php echo $navGroupIsCurrent($group) ? 'aria-current="page"' : ''; ?>>
                                <i class="fas <?php echo $group['icon']; ?> text-xs opacity-80"></i> <?php echo $group['label']; ?>
                            </a>
                        <?php else: ?>
                            <div class="relative">
                                <button type="button"
                                        class="nav-menu-btn nav-pill <?php echo $navGroupIsCurrent($group) ? 'nav-pill-active' : ''; ?>"
                                        data-nav-menu="nav-menu-<?php echo $gi; ?>"
                                        aria-expanded="false" aria-haspopup="true">
                                    <i class="fas <?php echo $group['icon']; ?> text-xs opacity-80"></i>
                                    <span><?php echo $group['label']; ?></span>
                                    <i class="fas fa-chevron-down text-[10px] opacity-60"></i>
                                </button>
                                <div id="nav-menu-<?php echo $gi; ?>" role="menu"
                                     class="nav-menu menu-panel hidden absolute left-0 z-50 mt-2 w-60 animate-fade-in">
                                    <?php foreach ($group['items'] as $item): ?>
                                        <a href="<?php echo BASE_URL . $item['url']; ?>"
                                           <?php echo !empty($item['blank']) ? 'target="_blank" rel="noopener"' : ''; ?>
                                           role="menuitem"
                                           class="menu-item <?php echo $navIsCurrent($item['url']) ? 'menu-item-active' : ''; ?>">
                                            <i class="fas <?php echo $item['icon']; ?> w-4 text-center text-gray-400"></i>
                                            <span class="flex-1"><?php echo $item['label']; ?></span>
                                            <?php if (!empty($item['blank'])): ?>
                                                <i class="fas fa-external-link-alt text-[10px] text-gray-400"></i>
                                            <?php endif; ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <div class="flex items-center gap-2">
                    <!-- User Profile Dropdown -->
                    <div class="relative hidden lg:block">
                        <button type="button" class="flex items-center gap-2.5 rounded-full py-1 pl-1 pr-3 text-sm transition-colors hover:bg-gray-100" id="user-menu-button" aria-expanded="false" aria-haspopup="true">
                            <span class="avatar"><?php echo sanitize($userInitials); ?></span>
                            <span class="hidden xl:block text-left leading-tight">
                                <span class="block max-w-[10rem] truncate font-medium text-gray-900"><?php echo sanitize($userName); ?></span>
                                <span class="block text-xs text-gray-500"><?php echo sanitize(ucfirst($userRole)); ?></span>
                            </span>
                            <i class="fas fa-chevron-down text-[10px] text-gray-400"></i>
                        </button>

                        <!-- Dropdown menu -->
                        <div class="hidden menu-panel absolute right-0 z-50 mt-2 w-64 origin-top-right animate-fade-in focus:outline-none" id="user-menu" role="menu" aria-orientation="vertical" aria-labelledby="user-menu-button" tabindex="-1">
                            <div class="flex items-center gap-3 px-3 py-3">
                                <span class="avatar h-10 w-10 text-sm"><?php echo sanitize($userInitials); ?></span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-gray-900"><?php echo sanitize($userName); ?></p>
                                    <p class="truncate text-xs text-gray-500"><?php echo sanitize($userEmail); ?></p>
                                </div>
                            </div>
                            <div class="px-3 pb-2">
                                <span class="badge badge-brand"><?php echo sanitize(ucfirst($userRole)); ?></span>
                            </div>
                            <div class="my-1 border-t border-gray-100"></div>
                            <a href="<?php echo BASE_URL; ?>/profile.php" class="menu-item" role="menuitem">
                                <i class="fas fa-user w-4 text-center text-gray-400"></i> My Profile
                            </a>
                            <a href="<?php echo BASE_URL; ?>/change-password.php" class="menu-item" role="menuitem">
                                <i class="fas fa-key w-4 text-center text-gray-400"></i> Change Password
                            </a>
                            <div class="my-1 border-t border-gray-100"></div>
                            <a href="<?php echo BASE_URL; ?>/logout.php" class="menu-item text-rose-600 hover:bg-rose-50 hover:text-rose-700" role="menuitem">
                                <i class="fas fa-sign-out-alt w-4 text-center"></i> Sign out
                            </a>
                        </div>
                    </div>

                    <!-- Mobile menu button -->
                    <button type="button" class="btn-icon h-10 w-10 lg:hidden" id="mobile-menu-button" aria-controls="mobile-menu">
                        <span class="sr-only">Open main menu</span>
                        <i class="fas fa-bars text-lg"></i>
                    </button>
                </div>
                <?php else: ?>
                <!-- Not logged in -->
                <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt"></i> Sign in
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isLoggedIn()): ?>
        <!-- Mobile menu -->
        <div class="hidden border-t border-gray-200 bg-white lg:hidden" id="mobile-menu">
            <div class="space-y-0.5 px-3 pt-3 pb-3">
                <?php foreach ($navItems as $group): ?>
                    <?php if (!isset($group['items'])): ?>
                        <a href="<?php echo BASE_URL . $group['url']; ?>"
                           class="menu-item py-2.5 text-base <?php echo $navGroupIsCurrent($group) ? 'menu-item-active' : ''; ?>">
                            <i class="fas <?php echo $group['icon']; ?> w-5 text-center text-gray-400"></i> <?php echo $group['label']; ?>
                        </a>
                    <?php else: ?>
                        <?php /* A heading plus indented links: no collapsing, so there is no
                                 JS to fail on a phone and every destination stays one tap away. */ ?>
                        <p class="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wider text-gray-400">
                            <?php echo $group['label']; ?>
                        </p>
                        <?php foreach ($group['items'] as $item): ?>
                            <a href="<?php echo BASE_URL . $item['url']; ?>"
                               <?php echo !empty($item['blank']) ? 'target="_blank" rel="noopener"' : ''; ?>
                               class="menu-item py-2.5 text-base <?php echo $navIsCurrent($item['url']) ? 'menu-item-active' : ''; ?>">
                                <i class="fas <?php echo $item['icon']; ?> w-5 text-center text-gray-400"></i><?php echo $item['label']; ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- User info in mobile menu -->
            <div class="border-t border-gray-200 px-3 pt-4 pb-4">
                <div class="mb-3 flex items-center gap-3 px-3">
                    <span class="avatar h-10 w-10 text-sm"><?php echo sanitize($userInitials); ?></span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-gray-900"><?php echo sanitize($userName); ?></p>
                        <p class="truncate text-xs text-gray-500"><?php echo sanitize($userEmail); ?> · <?php echo sanitize(ucfirst($userRole)); ?></p>
                    </div>
                </div>
                <a href="<?php echo BASE_URL; ?>/profile.php" class="menu-item py-2.5 text-base">
                    <i class="fas fa-user w-5 text-center text-gray-400"></i> My Profile
                </a>
                <a href="<?php echo BASE_URL; ?>/change-password.php" class="menu-item py-2.5 text-base">
                    <i class="fas fa-key w-5 text-center text-gray-400"></i> Change Password
                </a>
                <a href="<?php echo BASE_URL; ?>/logout.php" class="menu-item py-2.5 text-base text-rose-600 hover:bg-rose-50 hover:text-rose-700">
                    <i class="fas fa-sign-out-alt w-5 text-center"></i> Sign out
                </a>
            </div>
        </div>
        <?php endif; ?>
    </nav>

    <!-- Main Content Area -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-10">

        <!-- Flash Message Display Area -->
        <div id="flash-message-container">
            <?php
            if ($flash = getFlashMessage()) {
                $styles = [
                    'success' => ['alert-success', 'fa-check-circle'],
                    'error' => ['alert-error', 'fa-exclamation-circle'],
                    'warning' => ['alert-warning', 'fa-exclamation-triangle'],
                    'info' => ['alert-info', 'fa-info-circle']
                ];

                [$alertClass, $alertIcon] = $styles[$flash['type']] ?? $styles['info'];

                echo '<div class="flash-message alert ' . $alertClass . ' mb-6 shadow-sm" role="alert">';
                echo '<i class="fas ' . $alertIcon . ' mt-0.5"></i>';
                echo '<p class="flex-1">' . sanitize($flash['message']) . '</p>';
                echo '<button type="button" class="-my-1 -mr-1 rounded-md p-1 opacity-60 hover:opacity-100" aria-label="Dismiss" onclick="this.parentElement.remove()">';
                echo '<i class="fas fa-times"></i>';
                echo '</button>';
                echo '</div>';
            }
            ?>
        </div>

        <!-- Loading Spinner (hidden by default) -->
        <div id="loading-spinner" class="loading-spinner hidden">
            <div class="spinner"></div>
        </div>
