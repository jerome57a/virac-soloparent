<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/InterviewDateFeature.php';

/**
 * The two work queues MSWD staff act on after document verification, shared by the staff dashboard and Appointment
 * Management so both always show the same list:
 *
 *  - readyToSchedule(): every required document is verified and no interview date exists yet (staff schedule one with
 *    Schedule Date and Send SMS);
 *  - idReleaseQueue(): approved applications whose ID has not been handed over yet, with the scheduled release if any.
 *
 * Read-only. Plain reads, nothing locked.
 */
class SchedulingQueueService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Applications ready for an interview date, oldest first: documents all verified, no BOOKED/COMPLETED interview, and
     * not already invited (an invited applicant is "waiting for the applicant to choose a date", listed separately).
     *
     * @return array<int, array{id: int, reference_no: string, full_name: string, submitted_at: string, appointment_status: ?string}>
     */
    public function readyToSchedule(int $limit = 100, ?string $type = null): array
    {
        $byType = in_array($type, ['new', 'renewal'], true) ? " AND a.type = '$type'" : '';
        // Cheap prefilter in SQL (at least one document and none unverified); the exact rule (every REQUIRED document for the
        // category, expiry included) is then checked per candidate with Document::outstandingRequired().
        $rows = $this->pdo->query(
            "SELECT a.id, a.reference_no, a.full_name, a.type, a.submitted_at, i.status AS appointment_status
             FROM applications a
             LEFT JOIN appointments i ON i.application_id = a.id
             WHERE a.status IN ('Pending Review','Under Verification','For Revision','Interview Scheduled')
               AND a.renewal_simplified = 0 AND (i.id IS NULL OR i.status IN ('MISSED','CANCELLED'))$byType
               AND EXISTS (SELECT 1 FROM application_documents d WHERE d.application_id = a.id)
               AND NOT EXISTS (SELECT 1 FROM application_documents d WHERE d.application_id = a.id AND d.verification_status <> 'VERIFIED')
             ORDER BY a.submitted_at, a.id LIMIT 200"
        )->fetchAll();
        $invited = [];
        if (InterviewDateFeature::enabled($this->pdo)) {
            $invited = array_flip(array_map('intval', $this->pdo->query("SELECT application_id FROM interview_invitations WHERE status = 'OPEN'")->fetchAll(PDO::FETCH_COLUMN)));
        }
        $applications = new Application($this->pdo);
        $documents = new Document($this->pdo);
        $out = [];
        foreach ($rows as $row) {
            if (isset($invited[(int) $row['id']])) {
                continue;
            }
            $full = $applications->findForReview((int) $row['id']);
            if ($full === null || $documents->outstandingRequired($full) !== []) {
                continue;
            }
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** How many applications are approved and still waiting for their ID to be handed over. */
    public function idReleaseCount(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'Approved'")->fetchColumn();
    }

    /**
     * Approved applications waiting for the ID handover: ones with no release scheduled yet first, then the soonest release.
     *
     * @return array<int, array{id: int, reference_no: string, full_name: string, release_at: ?string}>
     */
    public function idReleaseQueue(int $limit = 5): array
    {
        return $this->pdo->query(
            "SELECT a.id, a.reference_no, a.full_name, r.scheduled_at AS release_at
             FROM applications a LEFT JOIN id_release_schedules r ON r.application_id = a.id AND r.released_at IS NULL
             WHERE a.status = 'Approved'
             ORDER BY (r.scheduled_at IS NULL) DESC, r.scheduled_at, a.updated_at LIMIT " . max(1, $limit)
        )->fetchAll();
    }
}
