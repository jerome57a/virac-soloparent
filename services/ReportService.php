<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/SmsLog.php';

/** Numbers for the admin dashboard and Analytics page, plus CSV downloads. */
class ReportService
{
    private PDO $pdo;
    private Application $applications;
    private Report $reports;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->applications = new Application($pdo);
        $this->reports = new Report($pdo);
    }

    public function dashboardStats(): array
    {
        $users = new User($this->pdo);
        $sms = new SmsLog($this->pdo);
        $count = fn(string $where) => $this->applications->countWhere($where);
        return [
            'totalApplicants'   => $users->countWhere("role = 'applicant'"),
            'activeUsers'       => $users->countWhere('is_active = 1'),
            'totalApplications' => $count('1=1'),
            'pendingNew'        => $count("type = 'new' AND status = 'Pending Review'"),
            'underVerification' => $count("status = 'Under Verification'"),
            'approvedNew'       => $count("type = 'new' AND status = 'Approved'"),
            'rejectedApps'      => $count("status = 'Rejected'"),
            'forRevision'       => $count("status = 'For Revision'"),
            'totalRenewals'     => $count("type = 'renewal'"),
            'pendingRenewals'   => $count("type = 'renewal' AND status = 'Pending Review'"),
            'expiringIds'       => $count("status = 'ID Released' AND id_expires_at IS NOT NULL AND id_expires_at <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)"),
            'smsSent'           => $sms->countByStatus('sent'),
            'smsFailed'         => $sms->countByStatus('failed'),
        ];
    }

    public function analytics(): array
    {
        $count = fn(string $where) => $this->applications->countWhere($where);
        $monthly = $this->reports->monthly(12);
        $byBarangay = $this->reports->byBarangay(15);

        $apptTotal = $this->reports->countInterviews();
        $apptSelfBooked = $this->reports->countInterviews('scheduled_by IS NULL');
        $slotUtil = $this->reports->slotUtilization();

        return [
            'totals' => [
                'applicants' => (new User($this->pdo))->countWhere("role = 'applicant'"),
                'new' => $count("type = 'new'"),
                'renewals' => $count("type = 'renewal'"),
                'approved' => $count("status IN ('Approved','ID Released')"),
                'rejected' => $count("status = 'Rejected'"),
            ],
            'monthly' => $monthly,
            'monthlyMax' => max(1, ...array_column($monthly, 'n') ?: [0]),
            'byBarangay' => $byBarangay,
            'barangayMax' => max(1, ...array_column($byBarangay, 'n') ?: [0]),
            'bySex' => $this->reports->bySex(),
            'byCivilStatus' => $this->reports->byCivilStatus(),
            'byCategory' => $this->reports->byCategory(),
            'expiringIds' => $this->reports->expiringIds(50),
            'appointments' => [
                'total' => $apptTotal,
                'today' => $this->reports->countInterviews('DATE(scheduled_at) = CURDATE()'),
                'upcoming' => $this->reports->countInterviews('scheduled_at > NOW()'),
                'completed' => $this->reports->countInterviews('result IS NOT NULL'),
                'selfBooked' => $apptSelfBooked,
                'staffScheduled' => $apptTotal - $apptSelfBooked,
            ],
            'slotUtil' => $slotUtil,
            'slotUtilPct' => $slotUtil['total_capacity'] > 0 ? round($slotUtil['total_booked'] / $slotUtil['total_capacity'] * 100) : 0,
        ];
    }

    /** export_csv.php types: 'applications' or 'users'. Returns [header row, data rows], or null for an unknown type. */
    public function fullExport(string $type): ?array
    {
        if ($type === 'applications') {
            return [
                ['Reference No', 'Type', 'Full Name', 'Category', 'Status', 'Mobile', 'Email', 'Reviewed By', 'Submitted At'],
                array_map(fn($r) => [
                    $r['reference_no'], $r['type'] === 'renewal' ? 'Renewal' : 'New Application', $r['full_name'], $r['category_name'],
                    $r['status'], $r['mobile'], $r['email'], $r['reviewer_name'] ?? '', $r['submitted_at'],
                ], $this->applications->allForExport()),
            ];
        }
        if ($type === 'users') {
            return [
                ['Username', 'Full Name', 'Email', 'Mobile', 'Role', 'Status', 'Created At'],
                array_map(fn($r) => [
                    $r['username'], $r['full_name'], $r['email'], $r['mobile'] ?? '', $r['role'], $r['is_active'] ? 'Active' : 'Deactivated', $r['created_at'],
                ], (new User($this->pdo))->allForExport()),
            ];
        }
        return null;
    }

    /** Sends a CSV download (UTF-8 with BOM so Excel renders accented characters correctly). */
    public function streamCsv(string $filename, array $header, array $rows): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF");
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
    }
}
