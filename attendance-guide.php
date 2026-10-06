<?php
/**
 * How-to: register your face for attendance (one-time enrollment).
 *
 * Public, read-only page: no login required, so a student can open it from a
 * link before they have ever signed in. It reads nothing from the database and
 * accepts no input. auth.php is loaded only for the HTTPS redirect and to know
 * whether to offer "Go to Attendance" or "Sign in".
 */

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Register your face for attendance';
$signedIn = isLoggedIn();
$img = function ($file) {
    return asset_url('public/images/attendance-guide/' . $file);
};

$steps = [
    [
        'img'   => '01-sign-in-dashboard.jpg',
        'title' => 'Sign in to the portal on your phone',
        'body'  => 'Open <strong>www.som.iitb.ac.in/phdtimeoff</strong> in Safari (iPhone) or Chrome (Android) on the phone you will use for attendance, and sign in with your IITB email and portal password. You land on your dashboard.',
        'tip'   => 'Use your own phone — the phone you enroll with becomes your attendance device.',
    ],
    [
        'img'   => '02-menu-attendance.jpg',
        'title' => 'Open the menu and tap Attendance',
        'body'  => 'Tap the <strong>menu button (☰)</strong> at the top right, then tap <strong>Attendance</strong>.',
    ],
    [
        'img'   => '03-allow-camera.jpg',
        'title' => 'Check your status and tap “Allow camera”',
        'body'  => 'The Attendance page shows your status. You should see <strong>On SOM office WiFi</strong> in green and <strong>Enrollment pending</strong> in amber. If a <em>Camera access needed</em> box appears, tap <strong>Allow camera</strong>.',
        'tip'   => 'Not seeing “On SOM office WiFi”? Connect to the office WiFi and reload the page — enrollment only works there.',
    ],
    [
        'img'   => '04-browser-allow.jpg',
        'title' => 'Let your browser use the camera',
        'body'  => 'Your browser asks whether <em>www.som.iitb.ac.in</em> may use your camera. Tap <strong>Allow</strong>.',
        'tip'   => 'Tapped “Don’t allow” by mistake? See “The camera won’t open” under Troubleshooting below.',
    ],
    [
        'img'   => '05-enter-code.jpg',
        'title' => 'Enter the one-time code from the office',
        'body'  => 'Scroll down to <strong>One-time enrollment</strong>. A staff member at the SOM office gives you a <strong>6-digit code</strong>. Type it in the box and tap <strong>Start enrollment</strong>.',
        'tip'   => 'Entering the code records your consent to face-verified attendance (see “Your photos and privacy” below).',
    ],
    [
        'img'   => null,
        'title' => 'Follow the camera guide',
        'body'  => 'The camera opens full screen. Hold the phone at eye level, about an arm’s length away, with your whole face inside the circle.'
                 . '<ol class="mt-3 list-decimal space-y-1.5 pl-5">'
                 . '<li><strong>Look straight ahead</strong> to begin.</li>'
                 . '<li><strong>Slowly move your head in a circle</strong> — left, up, right, down. Each part of the ring lights up as it is captured.</li>'
                 . '<li>Keep going until the message reads <strong>6/6 captured</strong>; the app then shows “Building your face profile…”.</li>'
                 . '</ol>'
                 . '<p class="mt-3">On some phones the live guide is replaced by simple timed prompts — look straight, turn left, turn right, tilt up, tilt down, look straight again. Just follow each one.</p>',
        'tip'   => 'Good, even light on your face helps. Remove sunglasses and masks; regular glasses are fine.',
    ],
    [
        'img'   => '06-enrolled.jpg',
        'title' => 'You’re enrolled',
        'body'  => 'You’ll see <strong>“Enrollment complete. This phone is now your attendance device.”</strong> The amber <em>Enrollment pending</em> badge disappears and the enrollment box is gone. That’s it — you only do this once.',
    ],
    [
        'img'   => '07-mark-attendance.jpg',
        'title' => 'Every day after that: tap “Mark Attendance”',
        'body'  => 'During the marking window shown on the page, open <strong>Attendance</strong> on the same phone while on the office WiFi and tap <strong>Mark Attendance</strong>. Follow the short pose prompts (centre, turn) and you’re marked present.',
        'tip'   => 'The window and today’s status are always shown at the top of the Attendance page.',
    ],
];

$faq = [
    [
        'q' => 'It says I am not on the SOM office WiFi',
        'a' => 'Enrollment and marking only work on the office network. Connect your phone to the SOM office WiFi (turn mobile data off if your phone keeps switching back to it), then reload the Attendance page.',
    ],
    [
        'q' => 'The camera won’t open',
        'a' => '<strong>iPhone:</strong> Settings → Safari → Camera → Allow, then reload the page. '
             . '<strong>Android (Chrome):</strong> tap the icon left of the address bar → Permissions → Camera → Allow, then reload. '
             . 'Close other apps that may be using the camera, and make sure you opened the portal over <strong>https://</strong>.',
    ],
    [
        'q' => 'My code is not accepted',
        'a' => 'Codes are single-use and expire after a short time. Ask the office staff for a fresh code and enter all 6 digits.',
    ],
    [
        'q' => 'Enrollment failed or the guide couldn’t find my face',
        'a' => 'Move to better light, hold the phone steady at eye level, and keep your whole face inside the circle. Then ask the office for a new code and try again.',
    ],
    [
        'q' => 'It says “This phone is not your enrolled device”',
        'a' => 'Attendance can only be marked from the phone you enrolled with. If you have changed or reset your phone, visit the office to enroll again with the new one.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Step-by-step guide for PhD students to register their face for attendance on the PhD Time-Off Portal.">
    <title><?php echo sanitize($pageTitle) . ' - ' . SITE_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo asset_url('public/vendor/tailwind/tailwind.min.css'); ?>">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/public/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="<?php echo asset_url('public/css/custom.css'); ?>">
</head>
<body class="bg-gray-50 text-gray-800 antialiased">

    <!-- Top bar -->
    <header class="app-navbar sticky top-0 z-40 border-b border-gray-200/80">
        <div class="mx-auto flex h-16 max-w-5xl items-center justify-between gap-4 px-4 sm:px-6">
            <a href="<?php echo BASE_URL; ?>/index.php" class="flex shrink-0 items-center gap-3">
                <img src="<?php echo asset_url('public/images/som-iitb-logo.png'); ?>" alt="SJM School of Management, IIT Bombay"
                     width="463" height="200" class="h-9 w-auto sm:h-11">
                <span class="h-8 w-px bg-gray-200" aria-hidden="true"></span>
                <span class="leading-tight">
                    <span class="block text-sm font-semibold text-gray-900">PhD Time-Off</span>
                    <span class="hidden text-xs text-gray-500 sm:block">Leave &amp; attendance</span>
                </span>
            </a>
            <?php if ($signedIn && getCurrentUserRole() === 'student'): ?>
                <a href="<?php echo BASE_URL; ?>/student/attendance.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-user-check"></i> Go to Attendance
                </a>
            <?php elseif (!$signedIn): ?>
                <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-sign-in-alt"></i> Sign in
                </a>
            <?php endif; ?>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-12">

        <!-- Hero -->
        <section class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-700 via-blue-600 to-violet-600 px-6 py-8 text-white shadow-lg sm:px-10 sm:py-12">
            <div class="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/10 blur-2xl" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -bottom-20 left-1/3 h-56 w-56 rounded-full bg-violet-300/20 blur-2xl" aria-hidden="true"></div>
            <p class="relative text-xs font-semibold uppercase tracking-wider text-blue-100">Attendance · How-to guide</p>
            <h1 class="relative mt-2 text-2xl font-semibold tracking-tight sm:text-4xl">Register your face for attendance</h1>
            <p class="relative mt-3 max-w-2xl text-sm text-blue-50 sm:text-base">
                A one-time, five-minute setup at the SOM office. After this you mark attendance every day
                with a quick live photo from your phone.
            </p>
            <div class="relative mt-6 flex flex-wrap gap-2 text-xs font-medium">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1.5 ring-1 ring-inset ring-white/20"><i class="fas fa-clock"></i> About 5 minutes</span>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1.5 ring-1 ring-inset ring-white/20"><i class="fas fa-redo"></i> Only once</span>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1.5 ring-1 ring-inset ring-white/20"><i class="fas fa-map-marker-alt"></i> At the SOM office</span>
            </div>
        </section>

        <!-- Before you start -->
        <section class="mt-8 card">
            <div class="card-header justify-start">
                <span class="icon-chip chip-amber"><i class="fas fa-clipboard-check"></i></span>
                <div>
                    <h2 class="card-title">Before you start</h2>
                    <p class="card-subtitle">Have these ready and enrollment takes a few minutes.</p>
                </div>
            </div>
            <ul class="card-body grid gap-3 sm:grid-cols-2">
                <?php foreach ([
                    ['fa-mobile-alt', 'Your own phone', 'It becomes your attendance device — you will mark attendance from it every day.'],
                    ['fa-wifi', 'SOM office WiFi', 'Be in the SOM office and connected to its WiFi. Enrollment does not work elsewhere.'],
                    ['fa-key', 'Your portal sign-in', 'Your IITB email and portal password (from your invitation email).'],
                    ['fa-user-tie', 'A code from the office', 'Office staff give you a one-time 6-digit enrollment code.'],
                ] as [$icon, $title, $text]): ?>
                    <li class="flex items-start gap-3 rounded-xl bg-gray-50 p-4 ring-1 ring-inset ring-gray-100">
                        <span class="icon-chip-sm chip-brand"><i class="fas <?php echo $icon; ?>"></i></span>
                        <div>
                            <p class="text-sm font-semibold text-gray-900"><?php echo $title; ?></p>
                            <p class="mt-0.5 text-sm text-gray-600"><?php echo $text; ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <!-- Steps -->
        <section class="mt-12">
            <h2 class="text-xl font-semibold tracking-tight text-gray-900 sm:text-2xl">Step by step</h2>
            <p class="mt-1 text-sm text-gray-500">Screenshots are from an iPhone; Android looks much the same.</p>

            <ol class="mt-8 space-y-10 sm:space-y-14">
                <?php foreach ($steps as $i => $step): $n = $i + 1; ?>
                    <li class="grid items-center gap-6 md:grid-cols-5 md:gap-10" id="step-<?php echo $n; ?>">
                        <!-- Visual -->
                        <div class="md:col-span-2 <?php echo $i % 2 ? 'md:order-2' : ''; ?>">
                            <?php if ($step['img']): ?>
                                <a href="<?php echo $img($step['img']); ?>" target="_blank" rel="noopener"
                                   class="group mx-auto block w-56 sm:w-64" title="Open full size">
                                    <div class="overflow-hidden rounded-[2rem] border-[6px] border-gray-900 bg-gray-900 shadow-xl ring-1 ring-gray-900/10 transition group-hover:-translate-y-1 group-hover:shadow-2xl">
                                        <img src="<?php echo $img($step['img']); ?>" alt="Step <?php echo $n; ?>: <?php echo sanitize($step['title']); ?>"
                                             width="600" height="1301" loading="<?php echo $i < 2 ? 'eager' : 'lazy'; ?>"
                                             class="block h-auto w-full rounded-[1.5rem]">
                                    </div>
                                </a>
                            <?php else: ?>
                                <!-- Camera step: no screenshot, so an illustration of the ring guide -->
                                <div class="mx-auto flex w-56 flex-col items-center rounded-[2rem] border-[6px] border-gray-900 bg-gray-900 px-4 pb-8 pt-10 shadow-xl sm:w-64" aria-hidden="true">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-blue-300">Live face check</p>
                                    <p class="mt-1 text-center text-sm font-semibold text-white">Slowly move your head in a circle</p>
                                    <svg viewBox="0 0 200 200" class="mt-6 w-40">
                                        <?php
                                        // Six ring segments; four lit to show progress.
                                        for ($s = 0; $s < 6; $s++) {
                                            $a0 = deg2rad($s * 60 - 90 + 4); $a1 = deg2rad(($s + 1) * 60 - 90 - 4);
                                            $r = 88;
                                            $x0 = 100 + $r * cos($a0); $y0 = 100 + $r * sin($a0);
                                            $x1 = 100 + $r * cos($a1); $y1 = 100 + $r * sin($a1);
                                            $lit = $s < 4;
                                            printf('<path d="M%.1f %.1f A%d %d 0 0 1 %.1f %.1f" fill="none" stroke="%s" stroke-width="10" stroke-linecap="round"/>',
                                                $x0, $y0, $r, $r, $x1, $y1, $lit ? '#34d399' : '#475569');
                                        }
                                        ?>
                                        <circle cx="100" cy="100" r="70" fill="#1e293b"/>
                                        <!-- simple face -->
                                        <ellipse cx="100" cy="102" rx="34" ry="42" fill="#cbd5e1"/>
                                        <circle cx="88" cy="96" r="4" fill="#334155"/>
                                        <circle cx="112" cy="96" r="4" fill="#334155"/>
                                        <path d="M88 120 Q100 128 112 120" stroke="#334155" stroke-width="3" fill="none" stroke-linecap="round"/>
                                    </svg>
                                    <p class="mt-6 rounded-full bg-white/10 px-3 py-1 text-xs font-medium text-emerald-300">Keep going — 4/6 captured</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Text -->
                        <div class="md:col-span-3">
                            <div class="flex items-center gap-3">
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white shadow-sm"><?php echo $n; ?></span>
                                <h3 class="text-lg font-semibold tracking-tight text-gray-900 sm:text-xl"><?php echo $step['title']; ?></h3>
                            </div>
                            <div class="mt-3 text-sm leading-relaxed text-gray-600 sm:text-base"><?php echo $step['body']; ?></div>
                            <?php if (!empty($step['tip'])): ?>
                                <div class="alert alert-info mt-4">
                                    <i class="fas fa-lightbulb mt-0.5"></i>
                                    <p><?php echo $step['tip']; ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>

        <!-- Troubleshooting -->
        <section class="mt-14 card" id="troubleshooting">
            <div class="card-header justify-start">
                <span class="icon-chip chip-rose"><i class="fas fa-life-ring"></i></span>
                <div>
                    <h2 class="card-title">Troubleshooting</h2>
                    <p class="card-subtitle">Tap a question to see the answer.</p>
                </div>
            </div>
            <div class="divide-y divide-gray-100">
                <?php foreach ($faq as $item): ?>
                    <details class="group px-5 py-4 sm:px-6">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-sm font-semibold text-gray-900">
                            <?php echo $item['q']; ?>
                            <i class="fas fa-chevron-down text-xs text-gray-400 transition group-open:rotate-180"></i>
                        </summary>
                        <p class="mt-2 text-sm leading-relaxed text-gray-600"><?php echo $item['a']; ?></p>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Privacy -->
        <section class="mt-8 card">
            <div class="card-body flex items-start gap-4">
                <span class="icon-chip chip-slate"><i class="fas fa-lock"></i></span>
                <div class="text-sm leading-relaxed text-gray-600">
                    <h2 class="text-base font-semibold text-gray-900">Your photos and privacy</h2>
                    <p class="mt-1">
                        A live photo is captured every time you mark attendance and kept for 60 days. Your face
                        profile is erased if your enrollment is revoked or your portal account is deactivated.
                        Entering the enrollment code records your consent. Enrollment only works on the office
                        WiFi, so no one can enroll your face remotely.
                    </p>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <div class="mt-10 flex flex-col items-center gap-3 text-center">
            <?php if ($signedIn && getCurrentUserRole() === 'student'): ?>
                <a href="<?php echo BASE_URL; ?>/student/attendance.php" class="btn btn-primary btn-lg"><i class="fas fa-user-check"></i> Go to Attendance</a>
            <?php elseif (!$signedIn): ?>
                <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-primary btn-lg"><i class="fas fa-sign-in-alt"></i> Sign in to start</a>
            <?php endif; ?>
            <p class="text-sm text-gray-500">
                Still stuck? Visit the SOM office, or email
                <a href="mailto:<?php echo ADMIN_EMAIL; ?>" class="font-medium text-blue-700 hover:underline"><?php echo ADMIN_EMAIL; ?></a>.
            </p>
        </div>
    </main>

    <footer class="mt-8 border-t border-gray-200 bg-white">
        <div class="mx-auto max-w-5xl px-4 py-6 text-center text-sm text-gray-500 sm:px-6">
            &copy; <?php echo date('Y'); ?> IIT Bombay &middot; PhD Time-Off Portal
        </div>
    </footer>
</body>
</html>
