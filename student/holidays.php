<?php
/**
 * Student Holidays Page
 * View all holidays
 */

$pageTitle = 'Holidays';
require_once __DIR__ . '/../includes/header.php';
requireRole('student');

require_once __DIR__ . '/../classes/Holiday.php';

$holidayClass = new Holiday();

// Get year filter
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$holidays = $holidayClass->getByYear($year);
?>

<div class="page-header">
    <div>
        <p class="page-eyebrow">Student</p>
        <h1 class="page-title">Holidays</h1>
        <p class="page-subtitle">View institute holidays</p>
    </div>
</div>

<!-- Year Selector -->
<div class="card mb-6 flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-center gap-3">
        <label for="holiday-year" class="text-sm font-medium text-gray-700">Year</label>
        <select id="holiday-year" onchange="window.location.href='?year='+this.value" class="form-select w-32 py-2">
            <?php for ($y = date('Y') - 1; $y <= date('Y') + 2; $y++): ?>
                <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <p class="flex items-center gap-2 text-sm text-gray-500">
        <i class="fas fa-calendar-alt text-gray-400"></i>
        <?php echo count($holidays); ?> holidays in <?php echo $year; ?>
    </p>
</div>

<!-- Holidays List -->
<?php if (empty($holidays)): ?>
    <div class="card p-6">
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-calendar-times"></i></div>
            <p class="empty-state-title">No holidays found for <?php echo $year; ?></p>
            <p class="empty-state-description">Holidays appear here once the SOM Office adds them.</p>
        </div>
    </div>
<?php else: ?>
    <?php
    // Display only: group the (date-ordered) list under month headings.
    $holidaysByMonthLabel = [];
    foreach ($holidays as $holiday) {
        $holidaysByMonthLabel[date('F Y', strtotime($holiday['holiday_date']))][] = $holiday;
    }
    ?>
    <div class="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach ($holidaysByMonthLabel as $monthLabel => $monthHolidays): ?>
            <section class="card overflow-hidden">
                <div class="card-header py-3.5">
                    <h2 class="card-title"><?php echo $monthLabel; ?></h2>
                    <span class="badge badge-neutral"><?php echo count($monthHolidays); ?> <?php echo count($monthHolidays) === 1 ? 'holiday' : 'holidays'; ?></span>
                </div>
                <ul class="divide-y divide-gray-100">
                    <?php foreach ($monthHolidays as $holiday): ?>
                        <?php
                        $isPast = strtotime($holiday['holiday_date']) < strtotime('today');
                        $isToday = date('Y-m-d', strtotime($holiday['holiday_date'])) === date('Y-m-d');
                        $dayOfMonth = date('d', strtotime($holiday['holiday_date']));
                        $month = date('M', strtotime($holiday['holiday_date']));
                        ?>
                        <li class="flex items-center gap-4 px-5 py-3.5 sm:px-6 <?php echo $isToday ? 'bg-blue-50/60' : ''; ?>">
                            <div class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl ring-1 ring-inset <?php echo $isPast ? 'bg-gray-50 text-gray-400 ring-gray-200' : 'bg-blue-50 text-blue-700 ring-blue-100'; ?>">
                                <span class="text-[10px] font-semibold uppercase leading-none tracking-wide"><?php echo $month; ?></span>
                                <span class="mt-1 text-lg font-semibold leading-none"><?php echo $dayOfMonth; ?></span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="break-words text-sm font-medium <?php echo $isPast ? 'text-gray-500' : 'text-gray-900'; ?>"><?php echo sanitize($holiday['holiday_name']); ?></h3>
                                <p class="mt-0.5 text-xs text-gray-500"><?php echo date('l, F j, Y', strtotime($holiday['holiday_date'])); ?></p>
                                <?php if ($isToday || !empty($holiday['is_working_day'])): ?>
                                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                                        <?php if ($isToday): ?>
                                            <span class="badge badge-brand">Today</span>
                                        <?php endif; ?>
                                        <?php if (!empty($holiday['is_working_day'])): ?>
                                            <span class="badge badge-pending"><i class="fas fa-briefcase"></i>Working day</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Info Box -->
<div class="alert alert-info mt-8">
    <i class="fas fa-info-circle mt-0.5"></i>
    <p>
        <strong class="font-semibold">Note:</strong> Holidays are excluded when calculating working days for leave applications. Weekends (Saturday &amp; Sunday) are also not counted as working days.
    </p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
