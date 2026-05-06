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

<div class="mb-6">
    <h1 class="text-3xl font-bold text-gray-900">Holidays</h1>
    <p class="text-gray-600 mt-2">View institute holidays</p>
</div>

<!-- Year Selector -->
<div class="bg-white rounded-lg shadow-md p-6 mb-6">
    <div class="flex items-center justify-between">
        <div class="flex items-center space-x-4">
            <label class="text-sm font-medium text-gray-700">Select Year:</label>
            <select onchange="window.location.href='?year='+this.value" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                <?php for ($y = date('Y') - 1; $y <= date('Y') + 2; $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="text-sm text-gray-600">
            <i class="fas fa-calendar-alt mr-2"></i>
            <?php echo count($holidays); ?> holidays in <?php echo $year; ?>
        </div>
    </div>
</div>

<!-- Holidays List -->
<div class="bg-white rounded-lg shadow-md overflow-hidden">
    <?php if (empty($holidays)): ?>
        <div class="p-12 text-center text-gray-500">
            <i class="fas fa-calendar-times text-6xl mb-4"></i>
            <p class="text-lg">No holidays found for <?php echo $year; ?></p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 p-6">
            <?php foreach ($holidays as $holiday): ?>
                <?php
                $isPast = strtotime($holiday['holiday_date']) < strtotime('today');
                $isToday = date('Y-m-d', strtotime($holiday['holiday_date'])) === date('Y-m-d');
                $cardClass = $isToday ? 'border-blue-500 bg-blue-50' : ($isPast ? 'opacity-60' : 'border-green-500');
                ?>
                <div class="border-l-4 <?php echo $cardClass; ?> p-4 bg-gray-50 rounded-lg">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <h3 class="font-semibold text-gray-900 mb-1"><?php echo sanitize($holiday['holiday_name']); ?></h3>
                            <p class="text-sm text-gray-600">
                                <i class="fas fa-calendar-day mr-2"></i>
                                <?php echo date('l, F j, Y', strtotime($holiday['holiday_date'])); ?>
                            </p>
                            <?php if ($isToday): ?>
                                <span class="inline-block mt-2 text-xs px-2 py-1 bg-blue-100 text-blue-800 rounded">Today</span>
                            <?php endif; ?>
                        </div>
                        <div class="ml-3">
                            <?php
                            $dayOfMonth = date('d', strtotime($holiday['holiday_date']));
                            $month = date('M', strtotime($holiday['holiday_date']));
                            ?>
                            <div class="text-center">
                                <div class="w-12 h-12 bg-white rounded-lg shadow flex flex-col items-center justify-center">
                                    <span class="text-xs text-gray-600"><?php echo $month; ?></span>
                                    <span class="text-lg font-bold text-gray-900"><?php echo $dayOfMonth; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Info Box -->
<div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
    <p class="text-sm text-blue-800">
        <i class="fas fa-info-circle mr-2"></i>
        <strong>Note:</strong> Holidays are excluded when calculating working days for leave applications. Weekends (Saturday & Sunday) are also not counted as working days.
    </p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
