<?php
/**
 * Admin - Holiday Management
 * Manage institute holidays and working days
 */

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../classes/Holiday.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireRole('admin');

$holidayClass = new Holiday();
$userId = getCurrentUserId();

// Handle holiday creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    checkCSRF();

    try {
        $holidayName = sanitize($_POST['holiday_name'] ?? '');
        $holidayDate = sanitize($_POST['holiday_date'] ?? '');
        $isWorkingDay = isset($_POST['is_working_day']) ? 1 : 0;

        if (empty($holidayName) || empty($holidayDate)) {
            throw new Exception("Holiday name and date are required");
        }

        if (!isValidDate($holidayDate)) {
            throw new Exception("Invalid date format");
        }

        $data = [
            'holiday_name' => $holidayName,
            'holiday_date' => $holidayDate,
            'is_working_day' => $isWorkingDay,
            'created_by' => $userId
        ];

        $result = $holidayClass->create($data);

        if ($result) {
            setFlashMessage('success', 'Holiday added successfully');
        } else {
            throw new Exception("Failed to add holiday");
        }

        header('Location: ' . BASE_URL . '/admin/holidays.php?year=' . date('Y', strtotime($holidayDate)));
        exit();

    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
        header('Location: ' . BASE_URL . '/admin/holidays.php');
        exit();
    }
}

// Handle holiday deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    checkCSRF();

    try {
        $holidayId = (int)($_POST['holiday_id'] ?? 0);

        if ($holidayId <= 0) {
            throw new Exception("Invalid holiday ID");
        }

        $db = getDBConnection();
        $sql = "DELETE FROM holidays WHERE id = :id";
        $stmt = $db->prepare($sql);
        $result = $stmt->execute([':id' => $holidayId]);

        if ($result) {
            setFlashMessage('success', 'Holiday deleted successfully');
        } else {
            throw new Exception("Failed to delete holiday");
        }

    } catch (Exception $e) {
        setFlashMessage('error', $e->getMessage());
    }

    header('Location: ' . BASE_URL . '/admin/holidays.php');
    exit();
}

$pageTitle = 'Holiday Management';
require_once __DIR__ . '/../includes/header.php';

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
    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div class="mb-4 md:mb-0">
            <h1 class="text-3xl font-bold text-gray-900">Holiday Management</h1>
            <p class="text-gray-600 mt-2">Manage institute holidays and working days</p>
        </div>
        <button onclick="showAddModal()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            <i class="fas fa-plus mr-2"></i>Add Holiday
        </button>
    </div>
</div>

<!-- Statistics -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600 uppercase tracking-wide">Total Holidays</p>
                <p class="text-3xl font-bold text-blue-600 mt-1"><?php echo count($holidays); ?></p>
            </div>
            <div class="p-3 bg-blue-100 rounded-lg">
                <i class="fas fa-calendar-alt text-3xl text-blue-600"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600 uppercase tracking-wide">Non-Working Days</p>
                <p class="text-3xl font-bold text-green-600 mt-1"><?php echo count(array_filter($holidays, fn($h) => !$h['is_working_day'])); ?></p>
            </div>
            <div class="p-3 bg-green-100 rounded-lg">
                <i class="fas fa-calendar-check text-3xl text-green-600"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600 uppercase tracking-wide">Working Days</p>
                <p class="text-3xl font-bold text-yellow-600 mt-1"><?php echo count(array_filter($holidays, fn($h) => $h['is_working_day'])); ?></p>
            </div>
            <div class="p-3 bg-yellow-100 rounded-lg">
                <i class="fas fa-briefcase text-3xl text-yellow-600"></i>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow-md p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600 uppercase tracking-wide">Selected Year</p>
                <p class="text-3xl font-bold text-purple-600 mt-1"><?php echo $year; ?></p>
            </div>
            <div class="p-3 bg-purple-100 rounded-lg">
                <i class="fas fa-calendar text-3xl text-purple-600"></i>
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
        <p class="text-gray-600 mb-6">Add holidays to help with leave calculations</p>
        <button onclick="showAddModal()" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
            <i class="fas fa-plus mr-2"></i>Add First Holiday
        </button>
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
                                <div class="flex items-start justify-between mb-2">
                                    <div class="flex-1">
                                        <h4 class="font-semibold text-gray-900"><?php echo sanitize($holiday['holiday_name']); ?></h4>
                                        <p class="text-sm text-gray-600 mt-1">
                                            <i class="fas fa-calendar mr-1"></i><?php echo formatDate($holiday['holiday_date']); ?>
                                        </p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            <?php echo date('l', strtotime($holiday['holiday_date'])); ?>
                                        </p>
                                    </div>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this holiday?');" class="ml-2">
                                        <?php echo getCSRFField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="holiday_id" value="<?php echo $holiday['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 transition-colors">
                                            <i class="fas fa-trash text-sm"></i>
                                        </button>
                                    </form>
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

<!-- Add Holiday Modal -->
<div id="addModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-xl font-semibold text-gray-900">
                <i class="fas fa-plus-circle text-blue-600 mr-2"></i>Add Holiday
            </h3>
            <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <form method="POST" id="addHolidayForm" class="p-6">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="action" value="add">

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Holiday Name <span class="text-red-500">*</span>
                </label>
                <input type="text"
                       name="holiday_name"
                       required
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       placeholder="e.g., Republic Day">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Date <span class="text-red-500">*</span>
                </label>
                <input type="date"
                       name="holiday_date"
                       required
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div class="mb-6">
                <label class="flex items-center p-3 bg-yellow-50 border border-yellow-200 rounded-lg cursor-pointer hover:bg-yellow-100 transition-colors">
                    <input type="checkbox"
                           name="is_working_day"
                           class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 mr-3">
                    <div>
                        <span class="text-sm font-medium text-gray-900">Mark as working day</span>
                        <p class="text-xs text-gray-600">Holiday but classes/work continues</p>
                    </div>
                </label>
            </div>

            <div class="flex items-center justify-end space-x-3">
                <button type="button"
                        onclick="closeAddModal()"
                        class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                    <i class="fas fa-times mr-2"></i>Cancel
                </button>
                <button type="submit"
                        class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                    <i class="fas fa-plus mr-2"></i>Add Holiday
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showAddModal() {
    document.getElementById('addModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeAddModal() {
    document.getElementById('addModal').classList.add('hidden');
    document.body.style.overflow = 'auto';
    document.getElementById('addHolidayForm').reset();
}

// Close modal on escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeAddModal();
    }
});

// Close modal on backdrop click
document.getElementById('addModal').addEventListener('click', function(event) {
    if (event.target === this) {
        closeAddModal();
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
