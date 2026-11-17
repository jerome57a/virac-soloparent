<?php
require_once __DIR__ . '/Model.php';

/**
 * interview_invitations: one row per application (unique application_id). Staff open it, close it and reopen it; the
 * same row is reused, never duplicated. Data access only: every rule (permissions, documents, allowance) lives in
 * InterviewInvitationService. Transitions are guarded UPDATEs (WHERE status = ...) so a stale caller changes nothing.
 */
class InterviewInvitation extends Model
{
    public const STATUSES = ['OPEN', 'BOOKED', 'CLOSED'];
    /** The most reschedules an application can ever be allowed (the default is 1; staff grant the rest one at a time). */
    public const MAX_ALLOWED = 5;

    /** The invitation for an application, or null. With $lock it is read with FOR UPDATE (inside the caller's transaction). */
    public function find(int $applicationId, bool $lock = false): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM interview_invitations WHERE application_id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$applicationId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * Creates the invitation as OPEN if the application has none; an existing row is left exactly as it is (the no-op
     * update is not an error). True when a row was inserted (a no-op update changes no row, so it reports 0).
     */
    public function insertOpenIfMissing(int $applicationId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO interview_invitations (application_id, status, opened_by) VALUES (?, 'OPEN', ?)
             ON DUPLICATE KEY UPDATE application_id = application_id"
        );
        $stmt->execute([$applicationId, $userId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * OPEN (or, with $from = 'BOOKED', BOOKED) -> CLOSED. $closedAt is a Y-m-d H:i:s string from PHP (Manila); $userId is
     * null when the system closes it. True when an invitation in $from was closed.
     */
    public function markClosed(int $applicationId, ?int $userId, string $reason, string $closedAt, string $from = 'OPEN'): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE interview_invitations SET status = 'CLOSED', closed_by = ?, closed_at = ?, close_reason = ?
             WHERE application_id = ? AND status = ?"
        );
        $stmt->execute([$userId, $closedAt, $reason, $applicationId, $from]);
        return $stmt->rowCount() === 1;
    }

    /**
     * OPEN -> BOOKED (a date is reserved). With $counted, reschedules_used goes up by one in the same statement, and only
     * while the allowance is not used up; the counter changes only if this very UPDATE (and so its transaction) commits.
     * True when the invitation was OPEN (and, when counted, had allowance left).
     */
    public function markBooked(int $applicationId, bool $counted): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE interview_invitations SET status = 'BOOKED', reschedules_used = reschedules_used + ?
             WHERE application_id = ? AND status = 'OPEN' AND (? = 0 OR reschedules_used < reschedules_allowed)"
        );
        $n = $counted ? 1 : 0;
        $stmt->execute([$n, $applicationId, $n]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Invitations that are OPEN: the applicant has been invited and has not chosen a date yet. Oldest first. Only
     * applications still being processed are listed. Plain read, for the staff Appointment Management screen.
     */
    public function waitingForApplicant(int $limit = 100): array
    {
        return $this->pdo->query(
            "SELECT a.id, a.reference_no, a.full_name, i.opened_at, i.reschedules_used
             FROM interview_invitations i JOIN applications a ON a.id = i.application_id
             WHERE i.status = 'OPEN' AND a.status IN ('Pending Review','Under Verification','For Revision','Interview Scheduled')
             ORDER BY i.opened_at, i.id LIMIT " . max(1, min(500, $limit))
        )->fetchAll();
    }

    /** Records the invitation SMS (sms_logs id) on the invitation; the latest one wins. Never changes the status. */
    public function linkSms(int $applicationId, ?int $smsLogId): void
    {
        $this->pdo->prepare('UPDATE interview_invitations SET sms_log_id = ? WHERE application_id = ?')->execute([$smsLogId, $applicationId]);
    }

    /** BOOKED -> OPEN (the applicant cancelled and still has allowance). Never touches reschedules_used. True when it was BOOKED. */
    public function releaseBooking(int $applicationId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE interview_invitations SET status = 'OPEN' WHERE application_id = ? AND status = 'BOOKED'");
        $stmt->execute([$applicationId]);
        return $stmt->rowCount() === 1;
    }

    /** CLOSED -> OPEN, clearing the closing details. True when a CLOSED invitation was reopened. */
    public function markReopened(int $applicationId, int $userId, string $openedAt): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE interview_invitations SET status = 'OPEN', opened_by = ?, opened_at = ?, closed_by = NULL, closed_at = NULL, close_reason = NULL
             WHERE application_id = ? AND status = 'CLOSED'"
        );
        $stmt->execute([$userId, $openedAt, $applicationId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Allows one more reschedule. Only a CLOSED invitation whose allowance is used up, and only below the maximum; the
     * conditions are repeated in the WHERE so a stale caller changes nothing. True when one was added.
     */
    public function grantOneReschedule(int $applicationId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE interview_invitations SET reschedules_allowed = reschedules_allowed + 1
             WHERE application_id = ? AND status = 'CLOSED' AND reschedules_used >= reschedules_allowed AND reschedules_allowed < ?"
        );
        $stmt->execute([$applicationId, self::MAX_ALLOWED]);
        return $stmt->rowCount() === 1;
    }
}
