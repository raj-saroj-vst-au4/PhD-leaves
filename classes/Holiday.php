<?php
/**
 * Holiday Class
 * Handles all holiday-related operations
 */

class Holiday {
    private $db;

    public function __construct($db = null) {
        $this->db = $db ?? getDBConnection();
    }

    /**
     * Create new holiday
     */
    public function create($data) {
        try {
            // Validate required fields
            if (empty($data['holiday_name']) || empty($data['holiday_date'])) {
                throw new Exception("Holiday name and date are required");
            }

            // Validate date format
            if (!$this->isValidDate($data['holiday_date'])) {
                throw new Exception("Invalid date format. Use YYYY-MM-DD");
            }

            // Check for duplicate holiday on same date
            if ($this->existsOnDate($data['holiday_date'])) {
                throw new Exception("A holiday already exists on this date");
            }

            $sql = "INSERT INTO holidays (holiday_name, holiday_date, is_working_day, created_by)
                    VALUES (:holiday_name, :holiday_date, :is_working_day, :created_by)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':holiday_name' => trim($data['holiday_name']),
                ':holiday_date' => $data['holiday_date'],
                ':is_working_day' => isset($data['is_working_day']) ? (int)$data['is_working_day'] : 0,
                ':created_by' => $data['created_by'] ?? null
            ]);

            $holidayId = $this->db->lastInsertId();

            // Log action
            $this->logAction($data['created_by'], 'holiday_created', $holidayId,
                "Holiday '{$data['holiday_name']}' created for date {$data['holiday_date']}");

            return [
                'success' => true,
                'holiday_id' => $holidayId,
                'message' => 'Holiday created successfully'
            ];

        } catch (PDOException $e) {
            logError("Holiday creation failed: " . $e->getMessage());
            throw new Exception("Failed to create holiday: " . $e->getMessage());
        }
    }

    /**
     * Update existing holiday
     */
    public function update($id, $data) {
        try {
            // Check if holiday exists
            $existing = $this->getById($id);
            if (!$existing) {
                throw new Exception("Holiday not found");
            }

            // Validate required fields
            if (empty($data['holiday_name']) || empty($data['holiday_date'])) {
                throw new Exception("Holiday name and date are required");
            }

            // Validate date format
            if (!$this->isValidDate($data['holiday_date'])) {
                throw new Exception("Invalid date format. Use YYYY-MM-DD");
            }

            // Check for duplicate holiday on same date (excluding current holiday)
            if ($data['holiday_date'] !== $existing['holiday_date'] && $this->existsOnDate($data['holiday_date'])) {
                throw new Exception("A holiday already exists on this date");
            }

            $sql = "UPDATE holidays
                    SET holiday_name = :holiday_name,
                        holiday_date = :holiday_date,
                        is_working_day = :is_working_day
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([
                ':holiday_name' => trim($data['holiday_name']),
                ':holiday_date' => $data['holiday_date'],
                ':is_working_day' => isset($data['is_working_day']) ? (int)$data['is_working_day'] : 0,
                ':id' => $id
            ]);

            if ($result) {
                // Log action
                $this->logAction($data['updated_by'] ?? null, 'holiday_updated', $id,
                    "Holiday ID $id updated: '{$data['holiday_name']}' on {$data['holiday_date']}");
            }

            return [
                'success' => true,
                'message' => 'Holiday updated successfully'
            ];

        } catch (PDOException $e) {
            logError("Holiday update failed: " . $e->getMessage());
            throw new Exception("Failed to update holiday: " . $e->getMessage());
        }
    }

    /**
     * Delete holiday
     */
    public function delete($id) {
        try {
            // Check if holiday exists
            $holiday = $this->getById($id);
            if (!$holiday) {
                throw new Exception("Holiday not found");
            }

            $sql = "DELETE FROM holidays WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute([':id' => $id]);

            if ($result) {
                // Log action
                $this->logAction(null, 'holiday_deleted', $id,
                    "Holiday '{$holiday['holiday_name']}' deleted (Date: {$holiday['holiday_date']})");
            }

            return [
                'success' => true,
                'message' => 'Holiday deleted successfully'
            ];

        } catch (PDOException $e) {
            logError("Holiday deletion failed: " . $e->getMessage());
            throw new Exception("Failed to delete holiday: " . $e->getMessage());
        }
    }

    /**
     * Get all holidays
     */
    public function getAll($includeWorkingDays = true) {
        try {
            $sql = "SELECT h.*, u.name as created_by_name
                    FROM holidays h
                    LEFT JOIN users u ON h.created_by = u.id";

            if (!$includeWorkingDays) {
                $sql .= " WHERE h.is_working_day = 0";
            }

            $sql .= " ORDER BY h.holiday_date ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();

        } catch (PDOException $e) {
            logError("Failed to get all holidays: " . $e->getMessage());
            throw new Exception("Failed to retrieve holidays");
        }
    }

    /**
     * Get holidays for a specific year
     */
    public function getByYear($year) {
        try {
            // Validate year
            if (!is_numeric($year) || $year < 2000 || $year > 2100) {
                throw new Exception("Invalid year");
            }

            $sql = "SELECT h.*, u.name as created_by_name
                    FROM holidays h
                    LEFT JOIN users u ON h.created_by = u.id
                    WHERE YEAR(h.holiday_date) = :year
                    ORDER BY h.holiday_date ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':year' => $year]);
            return $stmt->fetchAll();

        } catch (PDOException $e) {
            logError("Failed to get holidays by year: " . $e->getMessage());
            throw new Exception("Failed to retrieve holidays for year $year");
        }
    }

    /**
     * Get upcoming holidays
     */
    public function getUpcoming($limit = 10) {
        try {
            $sql = "SELECT h.*, u.name as created_by_name
                    FROM holidays h
                    LEFT JOIN users u ON h.created_by = u.id
                    WHERE h.holiday_date >= CURDATE()
                    ORDER BY h.holiday_date ASC
                    LIMIT :limit";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();

        } catch (PDOException $e) {
            logError("Failed to get upcoming holidays: " . $e->getMessage());
            throw new Exception("Failed to retrieve upcoming holidays");
        }
    }

    /**
     * Check if a specific date is a holiday
     */
    public function isHoliday($date) {
        try {
            // Validate date format
            if (!$this->isValidDate($date)) {
                throw new Exception("Invalid date format. Use YYYY-MM-DD");
            }

            $sql = "SELECT COUNT(*) as count FROM holidays
                    WHERE holiday_date = :date AND is_working_day = 0";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':date' => $date]);
            $result = $stmt->fetch();

            return $result['count'] > 0;

        } catch (PDOException $e) {
            logError("Failed to check holiday status: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Is $date a working day?
     *
     * A holidays row settles its date either way: is_working_day = 0 is a
     * holiday, is_working_day = 1 is a day declared WORKING even if it falls on a
     * Saturday or Sunday (a compensatory working day). Only a date with no row
     * falls back to the calendar, Monday-Friday working and the weekend off.
     *
     * Every "is this a working day" decision should come through here or
     * isWorkingDateWith(). The copies that checked `weekday && not in
     * holidays` never looked at is_working_day at all: a weekend declared
     * working was still treated as a day off (so a Friday + Monday split around
     * it was flagged as adjacent), and a holiday marked "work continues" was
     * treated as a holiday.
     *
     * @param string $date Y-m-d
     * @return bool
     */
    public function isWorkingDay($date) {
        if (!$this->isValidDate($date)) {
            throw new Exception("Invalid date format. Use YYYY-MM-DD");
        }
        return self::isWorkingDateWith($date, $this->getDayOverrides($date, $date));
    }

    /** The complement of isWorkingDay(): a weekend or a holiday, not overridden. */
    public function isNonWorkingDay($date) {
        return !$this->isWorkingDay($date);
    }

    /**
     * Every holidays row between two dates (inclusive) as [Y-m-d => bool], true
     * where the date is declared a working day and false where it is a holiday.
     * Fetch once and pass to isWorkingDateWith() when checking a run of days.
     */
    public function getDayOverrides($startDate, $endDate) {
        // MAX(): holidays has no unique key on the date, so should two rows ever
        // disagree, the explicit "this is a working day" declaration wins.
        $sql = "SELECT holiday_date, MAX(is_working_day) AS is_working_day
                FROM holidays
                WHERE holiday_date BETWEEN :start_date AND :end_date
                GROUP BY holiday_date";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);

        $overrides = [];
        while ($row = $stmt->fetch()) {
            $overrides[$row['holiday_date']] = (bool) $row['is_working_day'];
        }
        return $overrides;
    }

    /**
     * Working-day rule for one date given the overrides from getDayOverrides().
     * Pure (no query), so a loop over a range costs one query in total.
     *
     * @param string $date Y-m-d
     * @param array $overrides [Y-m-d => bool] from getDayOverrides()
     */
    public static function isWorkingDateWith($date, array $overrides) {
        if (array_key_exists($date, $overrides)) {
            return $overrides[$date];
        }
        return (int) (new DateTime($date))->format('N') <= 5;
    }

    /**
     * The working days between two dates (inclusive), ascending, as Y-m-d.
     * e.g. the 2nd-last working day of a month is
     *   $days = getWorkingDaysInRange($first, $last); $days[count($days) - 2]
     */
    public function getWorkingDaysInRange($startDate, $endDate) {
        if (!$this->isValidDate($startDate) || !$this->isValidDate($endDate)) {
            throw new Exception("Invalid date format. Use YYYY-MM-DD");
        }

        $overrides = $this->getDayOverrides($startDate, $endDate);
        $days = [];
        $current = new DateTime($startDate);
        $end = new DateTime($endDate);
        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            if (self::isWorkingDateWith($dateStr, $overrides)) {
                $days[] = $dateStr;
            }
            $current->modify('+1 day');
        }
        return $days;
    }

    /**
     * Get holiday by ID
     */
    public function getById($id) {
        try {
            $sql = "SELECT h.*, u.name as created_by_name
                    FROM holidays h
                    LEFT JOIN users u ON h.created_by = u.id
                    WHERE h.id = :id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $id]);
            return $stmt->fetch();

        } catch (PDOException $e) {
            logError("Failed to get holiday by ID: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if holiday exists on a specific date
     */
    private function existsOnDate($date) {
        $sql = "SELECT COUNT(*) as count FROM holidays WHERE holiday_date = :date";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':date' => $date]);
        $result = $stmt->fetch();
        return $result['count'] > 0;
    }

    /**
     * Validate date format
     */
    private function isValidDate($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Get holidays in date range
     */
    public function getInRange($startDate, $endDate) {
        try {
            // Validate dates
            if (!$this->isValidDate($startDate) || !$this->isValidDate($endDate)) {
                throw new Exception("Invalid date format. Use YYYY-MM-DD");
            }

            if (strtotime($endDate) < strtotime($startDate)) {
                throw new Exception("End date cannot be before start date");
            }

            $sql = "SELECT * FROM holidays
                    WHERE holiday_date BETWEEN :start_date AND :end_date
                    AND is_working_day = 0
                    ORDER BY holiday_date ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':start_date' => $startDate,
                ':end_date' => $endDate
            ]);
            return $stmt->fetchAll();

        } catch (PDOException $e) {
            logError("Failed to get holidays in range: " . $e->getMessage());
            throw new Exception("Failed to retrieve holidays in date range");
        }
    }

    /**
     * Get holiday count by year
     */
    public function getCountByYear($year) {
        try {
            $sql = "SELECT COUNT(*) as count FROM holidays
                    WHERE YEAR(holiday_date) = :year AND is_working_day = 0";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':year' => $year]);
            $result = $stmt->fetch();
            return $result['count'];

        } catch (PDOException $e) {
            logError("Failed to get holiday count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Bulk import holidays
     */
    public function bulkImport($holidays, $createdBy = null) {
        try {
            $this->db->beginTransaction();

            $imported = 0;
            $skipped = 0;
            $errors = [];

            foreach ($holidays as $index => $holiday) {
                try {
                    // Skip if already exists
                    if ($this->existsOnDate($holiday['holiday_date'])) {
                        $skipped++;
                        continue;
                    }

                    $sql = "INSERT INTO holidays (holiday_name, holiday_date, is_working_day, created_by)
                            VALUES (:holiday_name, :holiday_date, :is_working_day, :created_by)";

                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([
                        ':holiday_name' => trim($holiday['holiday_name']),
                        ':holiday_date' => $holiday['holiday_date'],
                        ':is_working_day' => $holiday['is_working_day'] ?? 0,
                        ':created_by' => $createdBy
                    ]);

                    $imported++;

                } catch (Exception $e) {
                    $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
                }
            }

            $this->db->commit();

            // Log action
            $this->logAction($createdBy, 'holidays_bulk_import', null,
                "Bulk import: $imported imported, $skipped skipped");

            return [
                'success' => true,
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
                'message' => "Successfully imported $imported holidays, skipped $skipped duplicates"
            ];

        } catch (PDOException $e) {
            $this->db->rollBack();
            logError("Bulk import failed: " . $e->getMessage());
            throw new Exception("Failed to import holidays: " . $e->getMessage());
        }
    }

    /**
     * Log action to system logs
     */
    private function logAction($userId, $action, $referenceId, $description) {
        $description .= $referenceId ? " (Ref ID: $referenceId)" : "";

        // logSystemAction records the real client IP (REMOTE_ADDR is the front
        // proxy for everyone on production) and the impersonation note. Used
        // only when it writes through this same connection, so the entry stays
        // in any transaction this class is part of.
        if (function_exists('logSystemAction') && $this->db === getDBConnection()) {
            logSystemAction($userId, $action, 'holidays', $description);
            return;
        }

        $sql = "INSERT INTO system_logs (user_id, action, module, description, ip_address)
                VALUES (:user_id, :action, :module, :description, :ip_address)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':module' => 'holidays',
            ':description' => $description,
            ':ip_address' => (function_exists('getClientIp') ? getClientIp() : null) ?? ($_SERVER['REMOTE_ADDR'] ?? null)
        ]);
    }
}
