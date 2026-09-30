<?php
require_once __DIR__ . '/Model.php';

/**
 * Aggregate, read-only queries behind the admin Analytics page and the
 * filterable Reports page. Every filter value is bound as a parameter.
 */
class Report extends Model
{
    public const TYPES = [
        'application' => 'Application Report',
        'applicant'   => 'Applicant Report',
        'approval'    => 'Approval Report',
        'rejection'   => 'Rejection Report',
        'renewal'     => 'Renewal Report',
        'barangay'    => 'Barangay Distribution',
        'appointment' => 'Appointment Report',
        'expiring'    => 'Expiring ID Report',
    ];

    private const ROW_LIMIT = 500;

    /**
     * Runs one report type. $from/$to are Y-m-d (ignored if invalid), $q a
     * free-text search. Returns ['rows', 'columns' => key => label,
     * 'dateFieldLabel' => what $from/$to filter on].
     */
    public function run(string $type, string $from, string $to, string $q): array
    {
        $def = $this->definition($type);
        $sql = $def['sql'];
        $params = $def['params'] ?? [];
        $dateOnly = $def['dateOnly'] ?? false;
        if (is_ymd($from)) {
            $sql .= " AND {$def['dateColumn']} >= ?";
            $params[] = $dateOnly ? $from : "$from 00:00:00";
        }
        if (is_ymd($to)) {
            $sql .= " AND {$def['dateColumn']} <= ?";
            $params[] = $dateOnly ? $to : "$to 23:59:59";
        }
        if ($q !== '') {
            $sql .= ' AND (' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $def['searchColumns'])) . ')';
            foreach ($def['searchColumns'] as $_) {
                $params[] = "%$q%";
            }
        }
        $sql .= ($def['groupBy'] ?? '') . " ORDER BY {$def['orderBy']} LIMIT " . self::ROW_LIMIT;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(), 'columns' => $def['columns'], 'dateFieldLabel' => $def['dateFieldLabel'] ?? 'Submitted'];
    }

    public function rowLimit(): int
    {
        return self::ROW_LIMIT;
    }

    private function definition(string $type): array
    {
        switch ($type) {
            case 'applicant':
                return [
                    'sql' => "SELECT u.full_name, u.username, u.email, u.mobile, u.created_at,
                                (SELECT COUNT(*) FROM applications a WHERE a.user_id = u.id) AS app_count
                              FROM users u WHERE u.role = 'applicant'",
                    'dateColumn' => 'u.created_at', 'dateFieldLabel' => 'Registered',
                    'searchColumns' => ['u.full_name', 'u.username', 'u.email'],
                    'orderBy' => 'u.created_at DESC',
                    'columns' => ['full_name' => 'Full Name', 'username' => 'Username', 'email' => 'Email', 'mobile' => 'Mobile', 'app_count' => 'Applications', 'created_at' => 'Registered'],
                ];
            case 'approval':
            case 'rejection':
                return [
                    'sql' => 'SELECT a.reference_no, a.full_name, a.barangay, c.name AS category_name, h.changed_at AS decided_at, h.changed_by, h.remarks
                              FROM status_history h
                              JOIN applications a ON a.id = h.application_id
                              JOIN categories c ON c.id = a.category_id
                              WHERE h.status = ?',
                    'params' => [$type === 'approval' ? 'Approved' : 'Rejected'],
                    'dateColumn' => 'h.changed_at', 'dateFieldLabel' => 'Decided',
                    'searchColumns' => ['a.reference_no', 'a.full_name'],
                    'orderBy' => 'h.changed_at DESC',
                    'columns' => ['reference_no' => 'Reference No', 'full_name' => 'Full Name', 'barangay' => 'Barangay', 'category_name' => 'Category', 'decided_at' => 'Decided', 'changed_by' => 'Decided By', 'remarks' => 'Remarks'],
                ];
            case 'renewal':
                return [
                    'sql' => "SELECT a.reference_no, a.full_name, a.barangay, c.name AS category_name, a.status, a.submitted_at
                              FROM applications a JOIN categories c ON c.id = a.category_id WHERE a.type = 'renewal'",
                    'dateColumn' => 'a.submitted_at',
                    'searchColumns' => ['a.reference_no', 'a.full_name'],
                    'orderBy' => 'a.submitted_at DESC',
                    'columns' => ['reference_no' => 'Reference No', 'full_name' => 'Full Name', 'barangay' => 'Barangay', 'category_name' => 'Category', 'status' => 'Status', 'submitted_at' => 'Submitted'],
                ];
            case 'barangay':
                return [
                    'sql' => "SELECT barangay,
                                COUNT(*) AS total,
                                SUM(type = 'new') AS new_count,
                                SUM(type = 'renewal') AS renewal_count,
                                SUM(status IN ('Approved','ID Released')) AS approved_count
                              FROM applications WHERE 1=1",
                    'dateColumn' => 'submitted_at',
                    'searchColumns' => ['barangay'],
                    'groupBy' => ' GROUP BY barangay',
                    'orderBy' => 'total DESC',
                    'columns' => ['barangay' => 'Barangay', 'total' => 'Total', 'new_count' => 'New', 'renewal_count' => 'Renewal', 'approved_count' => 'Approved'],
                ];
            case 'appointment':
                return [
                    'sql' => "SELECT a.reference_no, a.full_name, i.scheduled_at, i.location, i.result,
                                CASE WHEN i.scheduled_by IS NULL THEN 'Applicant' ELSE 'Staff' END AS booked_by
                              FROM interviews i JOIN applications a ON a.id = i.application_id WHERE 1=1",
                    'dateColumn' => 'i.scheduled_at', 'dateFieldLabel' => 'Scheduled',
                    'searchColumns' => ['a.reference_no', 'a.full_name'],
                    'orderBy' => 'i.scheduled_at DESC',
                    'columns' => ['reference_no' => 'Reference No', 'full_name' => 'Full Name', 'scheduled_at' => 'Scheduled', 'location' => 'Location', 'booked_by' => 'Booked By', 'result' => 'Result'],
                ];
            case 'expiring':
                return [
                    'sql' => "SELECT reference_no, full_name, barangay, id_expires_at
                              FROM applications WHERE status = 'ID Released' AND id_expires_at IS NOT NULL",
                    'dateColumn' => 'id_expires_at', 'dateOnly' => true, 'dateFieldLabel' => 'Expires',
                    'searchColumns' => ['reference_no', 'full_name'],
                    'orderBy' => 'id_expires_at',
                    'columns' => ['reference_no' => 'Reference No', 'full_name' => 'Full Name', 'barangay' => 'Barangay', 'id_expires_at' => 'Expires'],
                ];
            default:
                return [
                    'sql' => 'SELECT a.reference_no, a.full_name, a.type, a.status, a.barangay, c.name AS category_name, a.submitted_at
                              FROM applications a JOIN categories c ON c.id = a.category_id WHERE 1=1',
                    'dateColumn' => 'a.submitted_at',
                    'searchColumns' => ['a.reference_no', 'a.full_name'],
                    'orderBy' => 'a.submitted_at DESC',
                    'columns' => ['reference_no' => 'Reference No', 'full_name' => 'Full Name', 'type' => 'Type', 'status' => 'Status', 'barangay' => 'Barangay', 'category_name' => 'Category', 'submitted_at' => 'Submitted'],
                ];
        }
    }

    // ---- Analytics ----

    /** Applications per month (rows of ym, n) over the last $months months. */
    public function monthly(int $months): array
    {
        return $this->pdo->query(
            "SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS ym, COUNT(*) AS n FROM applications
             WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL " . (int) $months . ' MONTH)
             GROUP BY ym ORDER BY ym'
        )->fetchAll();
    }

    public function byBarangay(int $limit): array
    {
        return $this->pdo->query('SELECT barangay, COUNT(*) AS n FROM applications GROUP BY barangay ORDER BY n DESC LIMIT ' . (int) $limit)->fetchAll();
    }

    public function bySex(): array
    {
        return $this->pdo->query('SELECT sex, COUNT(*) AS n FROM applications GROUP BY sex')->fetchAll();
    }

    public function byCivilStatus(): array
    {
        return $this->pdo->query('SELECT civil_status, COUNT(*) AS n FROM applications GROUP BY civil_status ORDER BY n DESC')->fetchAll();
    }

    public function byCategory(): array
    {
        return $this->pdo->query('SELECT c.name, COUNT(*) AS n FROM applications a JOIN categories c ON c.id = a.category_id GROUP BY c.name ORDER BY n DESC')->fetchAll();
    }

    /** Released IDs expiring within 60 days (including already expired), soonest first. */
    public function expiringIds(int $limit): array
    {
        return $this->pdo->query(
            "SELECT reference_no, full_name, id_expires_at FROM applications
             WHERE status = 'ID Released' AND id_expires_at IS NOT NULL AND id_expires_at <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
             ORDER BY id_expires_at LIMIT " . (int) $limit
        )->fetchAll();
    }

    /** Count of interviews matching a fixed, code-supplied WHERE clause (never user input). */
    public function countInterviews(string $where = '1=1'): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM interviews WHERE $where")->fetchColumn();
    }

    /** ['total_capacity', 'total_booked'] across today-or-later slots. */
    public function slotUtilization(): array
    {
        return $this->pdo->query(
            'SELECT COALESCE(SUM(s.capacity), 0) AS total_capacity,
                    (SELECT COUNT(*) FROM interviews i JOIN appointment_slots s2 ON s2.id = i.slot_id WHERE s2.slot_date >= CURDATE()) AS total_booked
             FROM appointment_slots s WHERE s.slot_date >= CURDATE()'
        )->fetch();
    }

    // ---- System monitoring ----

    /** table name => row count, for every table in the database. */
    public function tableRowCounts(): array
    {
        $counts = [];
        foreach ($this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $counts[$table] = (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        }
        return $counts;
    }
}
