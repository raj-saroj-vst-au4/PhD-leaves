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

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900">Holidays</h1>
    <p class="text-gray-600 mt-2">Institute holidays and working days</p>
</div>

<!-- Statistics -->
<div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 mb-6">
    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs sm:text-sm text-gray-600 uppercase tracking-wide">Total Holidays</p>
                <p class="text-2xl sm:text-3xl font-bold text-blue-600 mt-1"><?php echo count($holidays); ?></p>
            </div>
            <div class="p-3 bg-blue-100 rounded-lg">
                <i class="fas fa-calendar-alt text-2xl sm:text-3xl text-blue-600"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs sm:text-sm text-gray-600 uppercase tracking-wide">Non-Working Days</p>
                <p class="text-2xl sm:text-3xl font-bold text-green-600 mt-1"><?php echo count(array_filter($holidays, fn($h) => !$h['is_working_day'])); ?></p>
            </div>
            <div class="p-3 bg-green-100 rounded-lg">
                <i class="fas fa-calendar-check text-2xl sm:text-3xl text-green-600"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs sm:text-sm text-gray-600 uppercase tracking-wide">Working Days</p>
                <p class="text-2xl sm:text-3xl font-bold text-yellow-600 mt-1"><?php echo count(array_filter($holidays, fn($h) => $h['is_working_day'])); ?></p>
            </div>
            <div class="p-3 bg-yellow-100 rounded-lg">
                <i class="fas fa-briefcase text-2xl sm:text-3xl text-yellow-600"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-md p-4 sm:p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs sm:text-sm text-gray-600 uppercase tracking-wide">Selected Year</p>
                <p class="text-2xl sm:text-3xl font-bold text-purple-600 mt-1"><?php echo $year; ?></p>
            </div>
            <div class="p-3 bg-purple-100 rounded-lg">
                <i class="fas fa-calendar text-2xl sm:text-3xl text-purple-600"></i>
            </div>
        </div>
    </div>
</div>

<!-- Year Selector -->
<div class="bg-white rounded-lg shadow-md p-4 mb-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <label class="text-sm font-medium text-gray-700">Select Year:</label>
        <div class="flex flex-wrap gap-2">
            <?php for ($y = date('Y') - 1; $y <= date('Y') + 2; $y++): ?>
                <a href="?year=<?php echo $y; ?>"
                   class="px-4 py-2 <?php echo $year == $y ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'; ?> rounded-lg text-sm font-medium transition-colors">
                    <?php echo $y; ?>
                </a>
            <?php endfor; ?>
        </div>
        <p class="text-sm text-gray-600">
            <i class="fas fa-info-circle mr-1"></i>
            <?php echo count($holidays); ?> holidays in <?php echo $year; ?>
        </p>
    </div>
</div>

<!-- Holidays Display -->
<?php if (empty($holidays)): ?>
    <div class="bg-white rounded-lg shadow-md p-12 text-center">
        <i class="fas fa-calendar-times text-6xl text-gray-300 mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-900 mb-2">No Holidays for <?php echo $year; ?></h3>
        <p class="text-gray-600">No holidays have been added by SOM Office for this year yet.</p>
    </div>
<?php else: ?>
    <!-- Holidays by Month -->
    <div class="space-y-6">
        <?php
        $monthOrder = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        foreach ($monthOrder as $month):
            if (!isset($holidaysByMonth[$month])) continue;
            $monthHolidays = $holidaysByMonth[$month];
        ?>
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="bg-gradient-to-r from-blue-500 to-blue-600 text-white px-6 py-4">
                    <h3 class="text-lg font-semibold">
                        <i class="fas fa-calendar-day mr-2"></i><?php echo $month; ?> <?php echo $year; ?>
                        <span class="ml-2 text-sm opacity-90">(<?php echo count($monthHolidays); ?> holidays)</span>
                    </h3>
                </div>
                <div class="p-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($monthHolidays as $holiday): ?>
                            <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                                <div class="mb-2">
                                    <h4 class="font-semibold text-gray-900"><?php echo sanitize($holiday['holiday_name']); ?></h4>
                                    <p class="text-sm text-gray-600 mt-1">
                                        <i class="fas fa-calendar mr-1"></i><?php echo formatDate($holiday['holiday_date']); ?>
                                    </p>
                                    <p class="text-xs text-gray-500 mt-1">
                                        <?php echo date('l', strtotime($holiday['holiday_date'])); ?>
                                    </p>
                                </div>
                                <div class="mt-3">
                                    <?php if ($holiday['is_working_day']): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                            <i class="fas fa-briefcase mr-1"></i>Working Day
                                        </span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                            <i class="fas fa-home mr-1"></i>Holiday
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
