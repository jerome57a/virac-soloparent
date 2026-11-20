<?php
require_once __DIR__ . '/Model.php';

/**
 * appointment_events: the append-only history of invitations and interview-date bookings (the database refuses UPDATE
 * and DELETE on it). Insert only. actor_name / actor_role are snapshots, as in audit_logs.
 */
class AppointmentEvent extends Model
{
    /** How many events of one type an application has (e.g. 'MISSED' = interviews the applicant did not attend). */
    public function countOf(int $applicationId, string $eventType): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM appointment_events WHERE application_id = ? AND event_type = ?');
        $stmt->execute([$applicationId, $eventType]);
        return (int) $stmt->fetchColumn();
    }

    /** Adds one event. $actor is the staff member (id, full_name, role); $source is APPLICANT, STAFF or SYSTEM. */
    public function record(int $applicationId, string $eventType, string $source, array $actor, ?string $reason = null, ?string $details = null, ?int $appointmentId = null, ?string $interviewDate = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO appointment_events (application_id, appointment_id, event_type, interview_date, source, actor_user_id, actor_name, actor_role, reason, details)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $applicationId,
            $appointmentId,
            $eventType,
            $interviewDate,
            $source,
            isset($actor['id']) ? (int) $actor['id'] : null,
            mb_substr((string) ($actor['full_name'] ?? ''), 0, 150),
            mb_substr((string) ($actor['role'] ?? ''), 0, 30),
            $reason !== null ? mb_substr($reason, 0, 255) : null,
            $details !== null ? mb_substr($details, 0, 500) : null,
        ]);
    }
}
