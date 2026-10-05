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

// auth.php has already opened the session (hardened cookie flags); no-op here.
startAppSession();

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

<div class="page-header">
    <div>
        <p class="page-eyebrow">Admin</p>
        <h1 class="page-title">Holiday Management</h1>
        <p class="page-subtitle">Manage institute holidays and working days</p>
    </div>
    <div class="flex gap-2">
        <button onclick="showAddModal()" class="btn btn-primary">
            <i class="fas fa-plus"></i>Add Holiday
        </button>
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
            <p class="empty-state-description">Add holidays to help with leave calculations</p>
            <button onclick="showAddModal()" class="btn btn-primary mt-5">
                <i class="fas fa-plus"></i>Add First Holiday
            </button>
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
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this holiday?');" class="shrink-0">
                                <?php echo getCSRFField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="holiday_id" value="<?php echo $holiday['id']; ?>">
                                <button type="submit" class="btn-icon text-gray-400 hover:bg-rose-50 hover:text-rose-600" title="Delete holiday" aria-label="Delete holiday">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Add Holiday Modal -->
<div id="addModal" class="hidden fixed inset-0 bg-gray-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="modal-panel max-w-md">
        <div class="flex items-center justify-between gap-4 border-b border-gray-100 px-6 py-4">
            <div class="flex items-center gap-3">
                <span class="icon-chip-sm chip-brand"><i class="fas fa-calendar-plus"></i></span>
                <h3 class="text-lg font-semibold text-gray-900">Add Holiday</h3>
            </div>
            <button type="button" onclick="closeAddModal()" class="btn-icon" title="Close" aria-label="Close">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" id="addHolidayForm">
            <?php echo getCSRFField(); ?>
            <input type="hidden" name="action" value="add">

            <div class="space-y-5 px-6 py-5">
                <div>
                    <label for="holiday_name" class="form-label">
                        Holiday Name <span class="text-rose-500">*</span>
                    </label>
                    <input type="text"
                           id="holiday_name"
                           name="holiday_name"
                           required
                           class="form-input"
                           placeholder="e.g., Republic Day">
                </div>

                <div>
                    <label for="holiday_date" class="form-label">
                        Date <span class="text-rose-500">*</span>
                    </label>
                    <input type="date"
                           id="holiday_date"
                           name="holiday_date"
                           required
                           class="form-input">
                </div>

                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-amber-200 bg-amber-50/70 p-3.5 transition-colors hover:bg-amber-50">
                    <input type="checkbox"
                           name="is_working_day"
                           class="mt-0.5 h-4 w-4 rounded border-gray-300">
                    <span>
                        <span class="block text-sm font-medium text-gray-900">Mark as working day</span>
                        <span class="block text-xs text-gray-600">Holiday but classes/work continues</span>
                    </span>
                </label>
            </div>

            <div class="flex items-center justify-end gap-3 rounded-b-2xl border-t border-gray-100 bg-gray-50/70 px-6 py-4">
                <button type="button"
                        onclick="closeAddModal()"
                        class="btn btn-secondary">
                    Cancel
                </button>
                <button type="submit"
                        class="btn btn-primary">
                    <i class="fas fa-plus"></i>Add Holiday
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
