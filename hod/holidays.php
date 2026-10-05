<?php
/**
 * HOD - Holidays (View Only)
 * View institute holidays and working days
 */

$pageTitle = 'Holidays';
require_once __DIR__ . '/../includes/header.php';
requireRole('hod');

require_once __DIR__ . '/../classes/Holiday.php';

$holidayClass = new Holiday();

// Get year filter
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$holidays = $holidayClass->getByYear($year);

// Group holidays by month
$holidaysByMonth = [];
foreach ($holidays as $holiday) {
    $month = date('F', strtotime($holiday['holiday_date']));
    if (!isset($holidaysByMonth[$month])) {
        $holidaysByMonth[$month] = [];
    }
    $holidaysByMonth[$month][] = $holiday;
}
?>

<div class="page-header">
    <div>
        <p class="page-eyebrow">HOD</p>
        <h1 class="page-title">Holidays</h1>
        <p class="page-subtitle">Institute holidays and working days</p>
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
        <i class="fas fa-info-circle text-gray-400"></i>
        <?php echo count($holidays); ?> holidays in <?php echo $year; ?>
    </p>
</div>

<!-- Statistics -->
<div class="mb-8 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-brand"><i class="fas fa-calendar-alt"></i></span>
        <div>
            <p class="stat-label">Total Holidays</p>
            <p class="stat-value"><?php echo count($holidays); ?></p>
        </div>
    </div>

    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-emerald"><i class="fas fa-calendar-check"></i></span>
        <div>
            <p class="stat-label">Non-Working Days</p>
            <p class="stat-value"><?php echo count(array_filter($holidays, fn($h) => !$h['is_working_day'])); ?></p>
        </div>
    </div>

    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-amber"><i class="fas fa-briefcase"></i></span>
        <div>
            <p class="stat-label">Working Days</p>
            <p class="stat-value"><?php echo count(array_filter($holidays, fn($h) => $h['is_working_day'])); ?></p>
        </div>
    </div>

    <div class="stat-tile flex-col gap-3 sm:flex-row sm:gap-4">
        <span class="icon-chip chip-violet"><i class="fas fa-calendar"></i></span>
        <div>
            <p class="stat-label">Selected Year</p>
            <p class="stat-value"><?php echo $year; ?></p>
        </div>
    </div>
</div>

<!-- Holidays Display -->
<?php if (empty($holidays)): ?>
    <div class="card p-6">
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-calendar-times"></i></div>
            <p class="empty-state-title">No Holidays for <?php echo $year; ?></p>
            <p class="empty-state-description">No holidays have been added by SOM Office for this year yet.</p>
        </div>
    </div>
<?php else: ?>
    <!-- Holidays by Month -->
    <div class="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php
        $monthOrder = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        foreach ($monthOrder as $month):
            if (!isset($holidaysByMonth[$month])) continue;
            $monthHolidays = $holidaysByMonth[$month];
        ?>
            <section class="card overflow-hidden">
                <div class="card-header py-3.5">
                    <h2 class="card-title"><?php echo $month; ?> <?php echo $year; ?></h2>
                    <span class="badge badge-neutral"><?php echo count($monthHolidays); ?> <?php echo count($monthHolidays) === 1 ? 'holiday' : 'holidays'; ?></span>
                </div>
                <ul class="divide-y divide-gray-100">
                    <?php foreach ($monthHolidays as $holiday): ?>
                        <?php $isPast = strtotime($holiday['holiday_date']) < strtotime('today'); ?>
                        <li class="flex items-center gap-4 px-5 py-3.5 sm:px-6">
                            <div class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl ring-1 ring-inset <?php echo $isPast ? 'bg-gray-50 text-gray-400 ring-gray-200' : 'bg-blue-50 text-blue-700 ring-blue-100'; ?>">
                                <span class="text-[10px] font-semibold uppercase leading-none tracking-wide"><?php echo date('M', strtotime($holiday['holiday_date'])); ?></span>
                                <span class="mt-1 text-lg font-semibold leading-none"><?php echo date('d', strtotime($holiday['holiday_date'])); ?></span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="break-words text-sm font-medium text-gray-900"><?php echo sanitize($holiday['holiday_name']); ?></h3>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    <?php echo date('l', strtotime($holiday['holiday_date'])); ?> &middot; <?php echo formatDate($holiday['holiday_date']); ?>
                                </p>
                                <div class="mt-1.5">
                                    <?php if ($holiday['is_working_day']): ?>
                                        <span class="badge badge-pending">
                                            <i class="fas fa-briefcase"></i>Working Day
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-neutral">
                                            <i class="fas fa-home"></i>Holiday
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
