<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../includes/SmsService.php';

/**
 * MSWD staff decisions on one application: status changes, document
 * verification, eligibility assessment, and interviews. Each action writes
 * the audit log and, where the applicant should know, an SMS + in-app
 * notification. Actions that can fail return an error message, else null.
 */
class ApplicationReviewService
{
    private const NOTIFICATION_TYPES = ['Approved' => 'approval', 'Rejected' => 'rejection', 'For Revision' => 'correction'];
    private const AUDIT_ACTIONS = ['Approved' => 'Application Approval', 'Rejected' => 'Application Rejection', 'For Revision' => 'Correction Request'];

    private PDO $pdo;
    private Application $applications;
    private Document $documents;
    private Appointment $appointments;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->applications = new Application($pdo);
        $this->documents = new Document($pdo);
        $this->appointments = new Appointment($pdo);
    }

    public function updateStatus(array $staff, array $app, string $newStatus, string $remarks): ?string
    {
        if (!in_array($newStatus, Application::STATUSES, true)) {
            return 'Select a valid status.';
        }
        $id = (int) $app['id'];
        $validityYears = $newStatus === 'ID Released'
            ? (int) get_setting($this->pdo, 'id_validity_years', (string) ID_VALIDITY_YEARS)
            : null;

        $this->applications->setReviewedStatus($id, $newStatus, (int) $staff['id'], $validityYears);
        $this->applications->addHistory($id, $newStatus, $remarks !== '' ? $remarks : null, $staff['full_name']);

        (new SmsService($this->pdo))->notifyStatusChange($id, $app['mobile'], $app['reference_no'], $newStatus, $remarks);
        create_notification(
            $this->pdo, (int) $app['user_id'], self::NOTIFICATION_TYPES[$newStatus] ?? 'status_change',
            'Application ' . $app['reference_no'] . ' is now ' . $newStatus . '.' . ($remarks !== '' ? ' Note: ' . $remarks : ''),
            $id
        );
        $this->audit($staff, self::AUDIT_ACTIONS[$newStatus] ?? 'Status Change', 'application', $id, $app['reference_no'] . ' → ' . $newStatus);
        return null;
    }

    /** Ignores invalid statuses and documents that don't belong to this application. */
    public function verifyDocument(array $staff, int $applicationId, int $documentId, string $status, string $remarks): void
    {
        if (!in_array($status, Document::VERIFICATION_STATUSES, true)) {
            return;
        }
        $doc = $this->documents->findInApplication($documentId, $applicationId);
        if (!$doc) {
            return;
        }
        $this->documents->setVerification($documentId, $status, $remarks !== '' ? $remarks : null);
        $this->audit($staff, 'Document Verification', 'application_document', $documentId, $doc['doc_key'] . ' → ' . $status);
    }

    /** Ignores invalid statuses. */
    public function assessEligibility(array $staff, array $app, string $status, string $remarks): void
    {
        if (!in_array($status, Application::ELIGIBILITY_STATUSES, true)) {
            return;
        }
        $this->applications->setEligibility((int) $app['id'], $status, $remarks !== '' ? $remarks : null);
        $this->audit($staff, 'Eligibility Assessment', 'application', (int) $app['id'], $app['reference_no'] . ' → ' . $status);
    }

    /** $scheduledAt is the raw datetime-local value (Y-m-d\TH:i). */
    public function scheduleInterview(array $staff, array $app, string $scheduledAt, string $location, string $notes): ?string
    {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $scheduledAt);
        if (!$dt) {
            return 'Select a valid interview date and time.';
        }
        if ($location === '') {
            return 'Enter an interview location.';
        }

        $id = (int) $app['id'];
        $scheduledAtSql = $dt->format('Y-m-d H:i:s');
        $when = $dt->format('M j, Y g:i A');

        $this->appointments->upsertStaffScheduled($id, $scheduledAtSql, $location, $notes !== '' ? $notes : null, (int) $staff['id']);
        $this->applications->setReviewedStatus($id, 'Interview Scheduled', (int) $staff['id']);
        $this->applications->addHistory($id, 'Interview Scheduled', 'Interview at ' . $location . ' on ' . $when, $staff['full_name']);

        (new SmsService($this->pdo))->notifyInterviewScheduled($id, $app['mobile'], $app['reference_no'], $scheduledAtSql, $location);
        create_notification(
            $this->pdo, (int) $app['user_id'], 'appointment_confirmation',
            'An appointment for ' . $app['reference_no'] . ' has been scheduled: ' . $when . ' at ' . $location . '.',
            $id
        );
        $this->audit($staff, 'Interview Scheduling', 'application', $id, $location . ' on ' . $when);
        return null;
    }

    public function recordInterviewResult(array $staff, int $applicationId, string $result): ?string
    {
        if ($result === '') {
            return 'Enter the interview result/notes before saving.';
        }
        $this->appointments->recordResult($applicationId, $result);
        $this->audit($staff, 'Interview Result Recorded', 'application', $applicationId, $result);
        return null;
    }

    private function audit(array $staff, string $action, string $entityType, int $entityId, string $details): void
    {
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], $action, $entityType, $entityId, $details);
    }
}
