<?php
require_once __DIR__ . '/Model.php';

/**
 * The appointments (interviews) staff schedule. Status: BOOKED -> COMPLETED |
 * MISSED | CANCELLED. Older rows may still carry a slot_id; new schedules are
 * saved without one (slot_id NULL).
 */
class Appointment extends Model
{
    public const STATUSES = ['BOOKED', 'COMPLETED', 'CANCELLED', 'MISSED'];

    /** New results start with an explicit outcome; earlier free-text results remain readable. */
    public static function interviewOutcome(?array $appointment): ?string
    {
        $result = trim((string) ($appointment['result'] ?? ''));
        foreach (['Successful', 'Unsuccessful'] as $outcome) {
            if ($result === $outcome || str_starts_with($result, $outcome . ': ')) {
                return $outcome;
            }
        }
        return null;
    }

    public static function interviewSuccessful(?array $appointment): bool
    {
        return $appointment && $appointment['status'] === 'COMPLETED'
            && trim((string) ($appointment['result'] ?? '')) !== '' && !empty($appointment['result_recorded_at'])
            && self::interviewOutcome($appointment) !== 'Unsuccessful';
    }

    /** Staff (re)schedule an interview directly (no slot); clears any old result/reminder/cancellation. */
    public function upsertStaffScheduled(int $applicationId, string $scheduledAt, string $location, ?string $notes, int $staffId): void
    {
        $this->pdo->prepare(
            'INSERT INTO appointments (application_id, slot_id, scheduled_at, location, notes, scheduled_by, reminder_sent_at)
             VALUES (?, NULL, ?, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE slot_id = NULL, scheduled_at = VALUES(scheduled_at), location = VALUES(location), status = \'BOOKED\',
                 notes = VALUES(notes), scheduled_by = VALUES(scheduled_by), result = NULL, result_recorded_at = NULL,
                 reminder_sent_at = NULL, interview_sms_log_id = NULL, cancelled_at = NULL, cancelled_by = NULL, cancel_reason = NULL'
        )->execute([$applicationId, $scheduledAt, $location, $notes, $staffId]);
    }

    public function recordResult(int $applicationId, string $result): void
    {
        $this->pdo->prepare("UPDATE appointments SET result = ?, result_recorded_at = NOW(), status = 'COMPLETED' WHERE application_id = ?")
            ->execute([$result, $applicationId]);
    }

    /** Marks a BOOKED appointment CANCELLED (frees its seat). $cancelledBy is the user who cancelled. */
    public function cancel(int $appointmentId, int $cancelledBy, string $reason): void
    {
        $this->pdo->prepare("UPDATE appointments SET status = 'CANCELLED', cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ? WHERE id = ? AND status = 'BOOKED'")
            ->execute([$cancelledBy, $reason, $appointmentId]);
    }

    public function markMissed(int $appointmentId): void
    {
        $this->pdo->prepare("UPDATE appointments SET status = 'MISSED' WHERE id = ? AND status = 'BOOKED'")->execute([$appointmentId]);
    }

    public function interviewFor(int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM appointments WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        return $stmt->fetch() ?: null;
    }

    public function linkSmsLog(int $applicationId, ?int $smsLogId): void
    {
        $this->pdo->prepare('UPDATE appointments SET interview_sms_log_id = ? WHERE application_id = ?')
            ->execute([$smsLogId, $applicationId]);
    }

    public function interviewSms(int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT s.* FROM appointments i JOIN sms_logs s ON s.id = i.interview_sms_log_id WHERE i.application_id = ?');
        $stmt->execute([$applicationId]);
        return $stmt->fetch() ?: null;
    }

    /** One appointment with its application's reference, applicant and contact details. */
    public function findWithApplication(int $appointmentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.*, a.reference_no, a.full_name, a.user_id, a.mobile, a.status AS application_status
             FROM appointments i JOIN applications a ON a.id = i.application_id WHERE i.id = ?'
        );
        $stmt->execute([$appointmentId]);
        return $stmt->fetch() ?: null;
    }

    /** Next $limit booked interviews after today; today's day sheet is listed separately. */
    public function upcomingInterviews(int $limit): array
    {
        return $this->pdo->query(
            "SELECT i.*, a.reference_no, a.full_name FROM appointments i
             JOIN applications a ON a.id = i.application_id
             WHERE i.scheduled_at >= DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND i.status = 'BOOKED' ORDER BY i.scheduled_at ASC LIMIT " . (int) $limit
        )->fetchAll();
    }

    /** Today's appointments in any status except CANCELLED (the staff day sheet). */
    public function todaysInterviews(): array
    {
        return $this->pdo->query(
            "SELECT i.*, a.reference_no, a.full_name, a.id AS application_id
             FROM appointments i JOIN applications a ON a.id = i.application_id
             WHERE DATE(i.scheduled_at) = CURDATE() AND i.status <> 'CANCELLED' ORDER BY i.scheduled_at"
        )->fetchAll();
    }

    /** Booked appointments after today (up to 100), grouped by Y-m-d date. */
    public function laterInterviewsByDate(): array
    {
        $rows = $this->pdo->query(
            "SELECT i.*, a.reference_no, a.full_name, a.id AS application_id
             FROM appointments i JOIN applications a ON a.id = i.application_id
             WHERE DATE(i.scheduled_at) > CURDATE() AND i.status = 'BOOKED'
             ORDER BY i.scheduled_at LIMIT 100"
        )->fetchAll();
        $byDate = [];
        foreach ($rows as $iv) {
            $byDate[date('Y-m-d', strtotime($iv['scheduled_at']))][] = $iv;
        }
        return $byDate;
    }

    /** Appointments whose time has passed but are still BOOKED: staff must record the result or mark them missed. Oldest first. */
    public function awaitingOutcome(): array
    {
        return $this->pdo->query(
            "SELECT i.*, a.reference_no, a.full_name, a.id AS application_id
             FROM appointments i JOIN applications a ON a.id = i.application_id
             WHERE i.status = 'BOOKED' AND i.scheduled_at < NOW() ORDER BY i.scheduled_at LIMIT 100"
        )->fetchAll();
    }

    /** Includes cancelled/no-show applicants so replacement scheduling has a visible next step. */
    public function awaitingStaffSchedule(): array
    {
        return $this->pdo->query("SELECT a.id, a.reference_no, a.full_name, i.status AS appointment_status
            FROM applications a LEFT JOIN appointments i ON i.application_id = a.id
            WHERE a.status IN ('Pending Review','Under Verification','For Revision','Interview Scheduled')
              AND a.renewal_simplified = 0 AND (i.id IS NULL OR i.status IN ('MISSED','CANCELLED'))
            ORDER BY a.submitted_at,a.id LIMIT 100")->fetchAll();
    }

    /** BOOKED appointments within the next $hours that haven't been reminded yet. */
    public function dueForReminder(int $hours): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.id AS interview_id, i.scheduled_at, i.location, a.id AS application_id, a.user_id, a.reference_no, a.mobile
             FROM appointments i JOIN applications a ON a.id = i.application_id
             WHERE i.status = 'BOOKED'
               AND i.scheduled_at > NOW()
               AND i.scheduled_at <= DATE_ADD(NOW(), INTERVAL ? HOUR)
               AND i.reminder_sent_at IS NULL"
        );
        $stmt->execute([$hours]);
        return $stmt->fetchAll();
    }

    public function markReminded(int $interviewId): void
    {
        $this->pdo->prepare('UPDATE appointments SET reminder_sent_at = NOW() WHERE id = ?')->execute([$interviewId]);
    }
}
