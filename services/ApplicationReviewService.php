<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/EligibilityAssessment.php';
require_once __DIR__ . '/QrSlipService.php';
require_once __DIR__ . '/SMSService.php';
require_once __DIR__ . '/NotificationService.php';

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
    /** Statuses that are MSWD decisions, recorded in application_decisions as well as the status history. */
    private const DECISIONS = ['Approved' => 'APPROVED', 'Rejected' => 'REJECTED', 'For Revision' => 'NEEDS_CORRECTION'];

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

    /**
     * Moves the application through the controlled workflow
     * (Application::TRANSITIONS). A reason is required to request
     * corrections or reject; approval requires every item on
     * approvalChecklist() to pass. $expectedStatus is the status the staff
     * member was looking at -- if it changed meanwhile nothing is saved.
     * Every change is written to the status history; approve / reject /
     * correction are also stored as decisions. Returns an error, or null.
     */
    public function updateStatus(array $staff, array $app, string $newStatus, string $remarks, string $expectedStatus = ''): ?string
    {
        if (!in_array($newStatus, Application::STATUSES, true)) {
            return 'Select a valid status.';
        }
        if ($expectedStatus !== '' && $expectedStatus !== $app['status']) {
            return 'This application was updated by someone else (now ' . $app['status'] . '). Review it again before deciding.';
        }
        if (!in_array($newStatus, Application::TRANSITIONS[$app['status']] ?? [], true)) {
            return $newStatus === $app['status']
                ? 'The application is already ' . $newStatus . '.'
                : 'An application that is ' . $app['status'] . ' cannot be moved to ' . $newStatus . '.';
        }
        $remarks = trim($remarks);
        if (mb_strlen($remarks) > 500) {
            return 'Remarks are too long (up to 500 characters).';
        }
        if ($remarks === '' && in_array($newStatus, Application::REASON_REQUIRED, true)) {
            return $newStatus === 'Rejected'
                ? 'Give the reason for rejecting -- it is sent to the applicant.'
                : 'Say what the applicant needs to correct -- it is sent to them.';
        }
        if ($newStatus === 'Approved') {
            $unmet = array_filter($this->approvalChecklist($app), fn($item) => !$item['ok']);
            if ($unmet) {
                return 'Cannot approve yet: ' . implode('; ', array_map(fn($item) => $item['label'] . ($item['detail'] ? ' (' . $item['detail'] . ')' : ''), $unmet)) . '.';
            }
        }
        $id = (int) $app['id'];
        $validityYears = $newStatus === 'ID Released'
            ? (int) get_setting($this->pdo, 'id_validity_years', (string) ID_VALIDITY_YEARS)
            : null;

        $reason = $remarks !== '' ? $remarks : null;
        $this->pdo->beginTransaction();
        try {
            $this->applications->setReviewedStatus($id, $newStatus, (int) $staff['id'], $validityYears);
            $this->applications->addHistory($id, $newStatus, $reason, $staff['full_name'], (int) $staff['id']);
            if (isset(self::DECISIONS[$newStatus])) {
                $this->applications->recordDecision($id, self::DECISIONS[$newStatus], $reason, (int) $staff['id']);
            }
            // A rejected application no longer needs its booked appointment: free the seat.
            $appt = $this->appointments->interviewFor($id);
            if ($newStatus === 'Rejected' && $appt && $appt['status'] === 'BOOKED') {
                $this->appointments->cancel((int) $appt['id'], (int) $staff['id'], 'Application rejected');
            }
            // The QR slip follows the decision: it expires with the released ID, and a rejection revokes it.
            $expires = $this->pdo->prepare('SELECT id_expires_at FROM applications WHERE id = ?');
            $expires->execute([$id]);
            (new QrSlipService($this->pdo))->onStatusChange($app, $newStatus, $expires->fetchColumn() ?: null);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        (new SMSService($this->pdo))->notifyStatusChange($id, $app['mobile'], $app['reference_no'], $newStatus, $remarks);
        create_notification(
            $this->pdo, (int) $app['user_id'], self::NOTIFICATION_TYPES[$newStatus] ?? 'status_change',
            'Application ' . $app['reference_no'] . ' is now ' . $newStatus . '.' . ($remarks !== '' ? ' Note: ' . $remarks : ''),
            $id
        );
        $this->audit($staff, self::AUDIT_ACTIONS[$newStatus] ?? 'Status Change', 'application', $id, $app['reference_no'] . ' → ' . $newStatus);
        return null;
    }

    /** Verdicts the applicant must be told the reason for. */
    private const REMARKS_REQUIRED = ['NEEDS_CORRECTION', 'REJECTED'];

    /**
     * Sets one document's verification status. Every change is recorded in
     * the document's status trail (who, when, from -> to, remarks), stamps
     * the verifier on the document, writes the audit log, and tells the
     * applicant (in-app and by SMS) when a document needs correction or was
     * rejected.
     *
     * $expectedStatus is the status the staff member saw when they chose the
     * verdict; if someone else changed it meanwhile nothing is saved, so a
     * verdict is never made on stale information.
     *
     * Returns an error message, or null on success (including "no change").
     */
    public function verifyDocument(array $staff, array $app, int $documentId, string $status, string $remarks, string $expectedStatus): ?string
    {
        if (!in_array($status, Document::VERIFICATION_STATUSES, true)) {
            return 'Choose a valid document status.';
        }
        if (!in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
            return 'Documents can no longer be changed: this application is ' . $app['status'] . '.';
        }
        $doc = $this->documents->findInApplication($documentId, (int) $app['id']);
        if (!$doc) {
            return 'That document is not part of this application.';
        }
        $remarks = trim($remarks);
        if (mb_strlen($remarks) > 500) {
            return 'Remarks are too long (up to 500 characters).';
        }
        if ($remarks === '' && in_array($status, self::REMARKS_REQUIRED, true)) {
            return 'Add a remark telling the applicant what is wrong with the document.';
        }
        if ($expectedStatus !== '' && $expectedStatus !== $doc['verification_status']) {
            return 'This document was updated by someone else (now ' . document_status_label($doc['verification_status']) . '). Review it again before saving.';
        }
        $remarks = $remarks !== '' ? $remarks : null;
        if ($status === $doc['verification_status'] && $remarks === $doc['staff_remarks']) {
            return null;
        }

        $this->pdo->beginTransaction();
        try {
            $this->documents->setVerification($documentId, $status, $remarks, (int) $staff['id']);
            $this->documents->addStatusHistory($documentId, (int) $app['id'], $doc['doc_key'], $doc['verification_status'], $status, $remarks, $staff);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $label = $this->documents->requirementLabel($doc['doc_key']) ?? $doc['doc_key'];
        $this->audit($staff, 'Document Verification', 'application_document', $documentId,
            $app['reference_no'] . ' · ' . $doc['doc_key'] . ': ' . $doc['verification_status'] . ' → ' . $status . ($remarks !== null ? ' (' . $remarks . ')' : ''));
        if (in_array($status, self::REMARKS_REQUIRED, true)) {
            create_notification(
                $this->pdo, (int) $app['user_id'], 'correction',
                'Your document "' . $label . '" for ' . $app['reference_no'] . ' ' . ($status === 'REJECTED' ? 'was rejected' : 'needs correction') . ': '
                    . rtrim($remarks, " .") . '. Please upload a new file under Upload Documents.',
                (int) $app['id']
            );
            (new SMSService($this->pdo))->notifyDocumentCorrection((int) $app['id'], $app['mobile'], $app['reference_no'], $label, (string) $remarks);
        }
        return null;
    }
    /**
     * Called after the applicant uploads a file. An application that is For
     * Revision goes back to Under Verification by itself once nothing is left
     * to fix -- no document still needs correction or is rejected, and no
     * required document is missing -- so staff don't have to resume the
     * review by hand. Returns true if it moved.
     */
    public function returnToReviewIfCorrected(array $applicant, array $app): bool
    {
        if ($app['status'] !== 'For Revision') {
            return false;
        }
        $id = (int) $app['id'];
        foreach ($this->documents->forReview($id, $app['category_id'] !== null ? (int) $app['category_id'] : null) as $d) {
            if (($d['id'] === null && $d['is_required']) || in_array($d['verification_status'] ?? '', self::REMARKS_REQUIRED, true)) {
                return false;
            }
        }

        $this->pdo->beginTransaction();
        try {
            if (!$this->applications->moveStatus($id, 'For Revision', 'Under Verification')) {
                $this->pdo->rollBack();
                return false;
            }
            $this->applications->addHistory($id, 'Under Verification', 'Corrections submitted by the applicant.', $applicant['full_name'], (int) $applicant['id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->audit($applicant, 'Corrections Submitted', 'application', $id, $app['reference_no'] . ' → Under Verification');
        create_notification($this->pdo, (int) $app['user_id'], 'status_change',
            'Your corrections for ' . $app['reference_no'] . ' were received. Your application is back with MSWD for review.', $id);
        (new NotificationService($this->pdo))->notifyStaff('corrections_submitted',
            $app['reference_no'] . ' (' . $app['full_name'] . ') submitted all requested corrections and is back in Under Verification.', $id);
        return true;
    }

    /**
     * What must be true before an application can be approved. Each item:
     * ['label' => ..., 'ok' => bool, 'detail' => what is missing, or ''].
     */
    public function approvalChecklist(array $app): array
    {
        $docs = $this->documents->forReview((int) $app['id'], $app['category_id'] !== null ? (int) $app['category_id'] : null);
        $missing = array_column(array_filter($docs, fn($d) => $d['id'] === null && $d['is_required']), 'label');
        $unverified = array_column(array_filter($docs, fn($d) => $d['id'] !== null && $d['verification_status'] !== 'VERIFIED'), 'label');
        $appt = $this->appointments->interviewFor((int) $app['id']);
        return [
            ['label' => 'All required documents uploaded', 'ok' => !$missing, 'detail' => implode(', ', $missing)],
            ['label' => 'All documents verified', 'ok' => !$unverified, 'detail' => implode(', ', $unverified)],
            ['label' => 'MSWD eligibility assessment: Eligible', 'ok' => $app['eligibility_status'] === 'Eligible', 'detail' => $app['eligibility_status'] === 'Eligible' ? '' : 'currently ' . $app['eligibility_status']],
            ['label' => 'No appointment still pending', 'ok' => !$appt || $appt['status'] !== 'BOOKED', 'detail' => $appt && $appt['status'] === 'BOOKED' ? 'record the interview result or cancel it' : ''],
        ];
    }

    /**
     * Records MSWD's official eligibility assessment (Eligible / Not
     * Eligible) as a new eligibility_assessments row -- earlier assessments
     * are kept -- and updates the application's current snapshot. Notes are
     * required when not eligible. Returns an error message, or null.
     */
    public function assessEligibility(array $staff, array $app, string $status, string $remarks): ?string
    {
        if (!in_array($status, ['Eligible', 'Not Eligible'], true)) {
            return 'Choose Eligible or Not Eligible.';
        }
        if (!in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
            return 'The assessment can only change while the application is being processed (it is ' . $app['status'] . ').';
        }
        $remarks = trim($remarks);
        if (mb_strlen($remarks) > 500) {
            return 'Assessment notes are too long (up to 500 characters).';
        }
        if ($remarks === '' && $status === 'Not Eligible') {
            return 'Explain why the applicant is not eligible.';
        }
        $notes = $remarks !== '' ? $remarks : null;
        $this->pdo->beginTransaction();
        try {
            (new EligibilityAssessment($this->pdo))->saveMswd((int) $app['id'], (int) $app['user_id'], $status === 'Eligible' ? 'ELIGIBLE' : 'NOT_ELIGIBLE', $notes, (int) $staff['id']);
            $this->applications->setEligibility((int) $app['id'], $status, $notes);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->audit($staff, 'Eligibility Assessment', 'application', (int) $app['id'], $app['reference_no'] . ' → ' . $status . ($notes ? ' (' . $notes . ')' : ''));
        return null;
    }

    /** Adds an internal case note (never shown to the applicant). Returns an error message, or null. */
    public function addNote(array $staff, array $app, string $note): ?string
    {
        $note = trim($note);
        if ($note === '') {
            return 'Write the note first.';
        }
        if (mb_strlen($note) > 1000) {
            return 'The note is too long (up to 1000 characters).';
        }
        $this->applications->addNote((int) $app['id'], $note, $staff);
        $this->audit($staff, 'Case Note Added', 'application', (int) $app['id'], $app['reference_no']);
        return null;
    }

    /** $scheduledAt is the raw datetime-local value (Y-m-d\TH:i). */
    public function scheduleInterview(array $staff, array $app, string $scheduledAt, string $location, string $notes): ?string
    {
        if (!in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
            return 'An interview can only be scheduled while the application is being processed (it is ' . $app['status'] . ').';
        }
        $dt = DateTime::createFromFormat('!Y-m-d\TH:i', $scheduledAt);
        if (!$dt || $dt->format('Y-m-d\TH:i') !== $scheduledAt) {
            return 'Select a valid interview date and time.';
        }
        if ($dt->getTimestamp() <= time()) {
            return 'The interview must be scheduled in the future.';
        }
        if ($location === '') {
            return 'Enter an interview location.';
        }
        if (mb_strlen($location) > 255) {
            return 'Location is too long (up to 255 characters).';
        }
        if (mb_strlen($notes) > 500) {
            return 'Notes are too long (up to 500 characters).';
        }
        $existing = $this->appointments->interviewFor((int) $app['id']);
        if ($existing && $existing['status'] === 'COMPLETED') {
            return 'The interview for this application is already completed.';
        }

        $id = (int) $app['id'];
        $scheduledAtSql = $dt->format('Y-m-d H:i:s');
        $when = $dt->format('M j, Y g:i A');

        $this->appointments->upsertStaffScheduled($id, $scheduledAtSql, $location, $notes !== '' ? $notes : null, (int) $staff['id']);
        $this->applications->setReviewedStatus($id, 'Interview Scheduled', (int) $staff['id']);
        $this->applications->addHistory($id, 'Interview Scheduled', 'Interview at ' . $location . ' on ' . $when, $staff['full_name'], (int) $staff['id']);

        (new SMSService($this->pdo))->notifyInterviewScheduled($id, $app['mobile'], $app['reference_no'], $scheduledAtSql, $location);
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
        if (mb_strlen($result) > 500) {
            return 'The result is too long (up to 500 characters).';
        }
        $appt = $this->appointments->interviewFor($applicationId);
        if (!$appt || !in_array($appt['status'], ['BOOKED', 'COMPLETED'], true)) {
            return 'There is no booked appointment to record a result for.';
        }
        if ($appt['status'] === 'BOOKED' && substr((string) $appt['scheduled_at'], 0, 10) > date('Y-m-d')) {
            return 'The interview is on ' . format_date_long(substr((string) $appt['scheduled_at'], 0, 10)) . '. Record its result on or after that day.';
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
