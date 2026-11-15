<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/EligibilityAssessment.php';
require_once __DIR__ . '/SMSService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/InterviewCalendarService.php';

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
    private const AUTO_ELIGIBILITY_NOTES = 'Automatically assessed eligible after all required documents were verified.';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->applications = new Application($pdo);
        $this->documents = new Document($pdo);
        $this->appointments = new Appointment($pdo);
    }

    /** Opening a pending application starts review once, even with concurrent staff views. */
    public function startReviewOnOpen(array $staff, array $app): void
    {
        if (!can($staff, 'applications.process')) {
            return;
        }
        if ($app['status'] !== 'Pending Review') {
            if (in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)
                && ($app['eligibility_remarks'] ?? null) === self::AUTO_ELIGIBILITY_NOTES) {
                $this->pdo->beginTransaction();
                try {
                    $this->syncEligibilityFromChecklist($staff, $app);
                    $this->pdo->commit();
                } catch (Throwable $e) {
                    $this->pdo->rollBack();
                    throw $e;
                }
            }
            return;
        }
        $id = (int) $app['id'];
        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare("UPDATE applications SET status = 'Under Verification', reviewed_by = ? WHERE id = ? AND status = 'Pending Review'");
            $update->execute([(int) $staff['id'], $id]);
            if ($update->rowCount() === 1) {
                $this->applications->addHistory($id, 'Under Verification', 'Review started when staff opened the application.', $staff['full_name'], (int) $staff['id']);
                $this->audit($staff, 'Status Change', 'application', $id, $app['reference_no'] . ' → Under Verification');
                create_notification($this->pdo, (int) $app['user_id'], 'status_change', 'MSWDO has started reviewing application ' . $app['reference_no'] . '.', $id);
                $this->syncEligibilityFromChecklist($staff, $app);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Clear legacy automatic eligibility; physical verification never decides eligibility. */
    private function syncEligibilityFromChecklist(array $staff, array $app): void
    {
        $id = (int) $app['id'];
        $lock = $this->pdo->prepare('SELECT * FROM applications WHERE id = ? FOR UPDATE');
        $lock->execute([$id]);
        $current = $lock->fetch();
        if ($current && in_array($current['status'], Application::IN_PROGRESS_STATUSES, true)
            && $current['eligibility_status'] === 'Eligible'
            && $current['eligibility_remarks'] === self::AUTO_ELIGIBILITY_NOTES) {
            $this->applications->setEligibility($id, 'Not Assessed', null);
            $this->applications->addHistory($id, $current['status'], 'Automatic eligibility cleared. An explicit MSWDO assessment is required.', $staff['full_name'], (int) $staff['id']);
            $this->audit($staff, 'Eligibility Assessment', 'application', $id, $current['reference_no'] . ' → Not Assessed (staff assessment required)');
        }
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
        if ($newStatus === 'ID Released') {
            return 'Use the ID release section to confirm that the ID was handed to the applicant.';
        }
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
        if (isset(self::DECISIONS[$newStatus]) && empty($app['renewal_simplified'])
            && !Appointment::interviewSuccessful($this->appointments->interviewFor((int) $app['id']))) {
            return 'Record a successful interview before requesting corrections, approving, or rejecting this application.';
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

        $reason = $remarks !== '' ? $remarks : null;
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM applications WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $current = $lock->fetch();
            if (!$current || $current['status'] !== $app['status']) {
                $this->pdo->rollBack();
                return 'The application changed. Refresh before recording a decision.';
            }
            if (isset(self::DECISIONS[$newStatus]) && empty($current['renewal_simplified'])
                && !Appointment::interviewSuccessful($this->appointments->interviewFor($id))) {
                $this->pdo->rollBack();
                return 'Record a successful interview before deciding on this application.';
            }
            if ($newStatus === 'Approved' && array_filter($this->approvalChecklist($current), fn($item) => !$item['ok'])) {
                $this->pdo->rollBack();
                return 'Complete all required documents and the interview before approval.';
            }
            $this->applications->setReviewedStatus($id, $newStatus, (int) $staff['id']);
            $this->applications->addHistory($id, $newStatus, $reason, $staff['full_name'], (int) $staff['id']);
            if (isset(self::DECISIONS[$newStatus])) {
                $this->applications->recordDecision($id, self::DECISIONS[$newStatus], $reason, (int) $staff['id']);
            }
            // A rejected application no longer needs its booked appointment: free the seat.
            $appt = $this->appointments->interviewFor($id);
            if ($newStatus === 'Rejected' && $appt && $appt['status'] === 'BOOKED') {
                $this->appointments->cancel((int) $appt['id'], (int) $staff['id'], 'Application rejected');
            }
            $expires = $this->pdo->prepare('SELECT id_expires_at FROM applications WHERE id = ?');
            $expires->execute([$id]);
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

    /** Every status application_documents can hold. Staff may only assign Document::VERIFICATION_STATUSES. */
    public const STORED_STATUSES = ['UPLOADED', 'VALIDATING', 'VALID', 'INVALID', 'FOR_VERIFICATION', 'VERIFIED', 'NEEDS_CORRECTION', 'REJECTED'];

    /**
     * Saves the verification of several documents of one application as ONE
     * transaction: every edited document is re-read and locked, all edits are
     * validated, and then statuses, status history, verifier and audit entries
     * are written together. Any failure rolls the whole batch back.
     * Notifications and SMS are sent only after a successful commit; a failed
     * notification never undoes a committed verification.
     *
     * $rows maps document id => ['status', 'remarks', 'expected', 'expected_remarks']:
     * the chosen verdict plus the status and remarks the staff member saw.
     * A row equal to what was shown is untouched and ignored entirely. Every
     * other row must carry the expected state, which must still match the
     * database (otherwise someone else changed it and nothing is saved).
     *
     * @return array{errors: array<int|string,string>, changed: int, notify_failures: int}
     */
    public function saveVerification(array $staff, array $app, array $rows): array
    {
        if (!can($staff, 'applications.process')) {
            return ['errors' => ['checklist' => 'You do not have permission to verify documents.'], 'changed' => 0, 'notify_failures' => 0];
        }
        $norm = static fn($v) => trim((string) ($v ?? ''));
        $errors = [];
        $edited = [];
        foreach ($rows as $documentId => $row) {
            $documentId = (int) $documentId;
            $expected = (string) ($row['expected'] ?? '');
            if (!in_array($expected, self::STORED_STATUSES, true) || !isset($row['expected_remarks']) || !is_string($row['expected_remarks'])) {
                $errors[$documentId] = 'The original status of this document is missing or invalid. Reload the page and try again.';
                continue;
            }
            $status = (string) ($row['status'] ?? '');
            $remarks = $norm($row['remarks'] ?? '');
            $expectedRemarks = $norm($row['expected_remarks']);
            if ($status === $expected && $remarks === $expectedRemarks) {
                continue; // untouched
            }
            $edited[$documentId] = ['status' => $status, 'remarks' => $remarks, 'expected' => $expected, 'expected_remarks' => $expectedRemarks];
        }
        ksort($edited); // fixed lock order

        $applicationId = (int) $app['id'];
        $labels = [];
        $notify = [];
        if ($edited) {
            $this->pdo->beginTransaction();
            try {
                $lock = $this->pdo->prepare('SELECT status FROM applications WHERE id = ? FOR UPDATE');
                $lock->execute([$applicationId]);
                $currentStatus = $lock->fetchColumn();
                $editable = $currentStatus !== false && in_array($currentStatus, Application::IN_PROGRESS_STATUSES, true);
                $docLock = $this->pdo->prepare('SELECT * FROM application_documents WHERE id = ? AND application_id = ? FOR UPDATE');
                $docs = [];
                foreach ($edited as $documentId => $row) {
                    $docLock->execute([$documentId, $applicationId]);
                    $doc = $docLock->fetch();
                    if (!$doc) {
                        $errors[$documentId] = 'That document is not part of this application.';
                        continue;
                    }
                    $docs[$documentId] = $doc;
                    $labels[$documentId] = $this->documents->requirementLabel($doc['doc_key']) ?? $doc['doc_key'];
                    if (!$editable) {
                        $errors[$documentId] = 'Documents can no longer be changed: this application is ' . ($currentStatus !== false ? $currentStatus : 'unavailable') . '.';
                    } elseif ($row['expected'] !== $doc['verification_status'] || $row['expected_remarks'] !== $norm($doc['staff_remarks'])) {
                        $errors[$documentId] = 'This document was updated by someone else (now ' . document_status_label($doc['verification_status']) . '). Review it again before saving.';
                    } elseif (!in_array($row['status'], Document::VERIFICATION_STATUSES, true)) {
                        $errors[$documentId] = in_array($row['status'], self::STORED_STATUSES, true)
                            ? 'This status cannot be assigned by staff (' . document_status_label($row['status']) . '). Choose Pending, Verified or Needs Correction.'
                            : 'Choose a valid document status.';
                    } elseif (mb_strlen($row['remarks']) > 500) {
                        $errors[$documentId] = 'Remarks are too long (up to 500 characters).';
                    } elseif ($row['remarks'] === '' && in_array($row['status'], self::REMARKS_REQUIRED, true)) {
                        $errors[$documentId] = 'Add a remark telling the applicant what is wrong with the document.';
                    }
                }
                if (!$errors) {
                    $flagged = [];
                    foreach ($edited as $documentId => $row) {
                        $doc = $docs[$documentId];
                        $remarks = $row['remarks'] !== '' ? $row['remarks'] : null;
                        $this->documents->setVerification($documentId, $row['status'], $remarks, (int) $staff['id']);
                        $this->documents->addStatusHistory($documentId, $applicationId, $doc['doc_key'], $doc['verification_status'], $row['status'], $remarks, $staff);
                        $this->audit($staff, 'Document Verification', 'application_document', $documentId,
                            $app['reference_no'] . ' · ' . $doc['doc_key'] . ': ' . $doc['verification_status'] . ' → ' . $row['status'] . ($remarks !== null ? ' (' . $remarks . ')' : ''));
                        $label = $labels[$documentId];
                        $verdict = $row['status'];
                        $remarkText = $row['remarks'];
                        if ($verdict === 'VERIFIED') {
                            $notify[] = fn() => create_notification(
                                $this->pdo, (int) $app['user_id'], 'status_change',
                                'Your document "' . $label . '" for ' . $app['reference_no'] . ' has been verified by MSWDO.',
                                $applicationId
                            );
                        } elseif (in_array($verdict, self::REMARKS_REQUIRED, true)) {
                            $flagged[] = $label;
                            $notify[] = fn() => create_notification(
                                $this->pdo, (int) $app['user_id'], 'correction',
                                'Your document "' . $label . '" for ' . $app['reference_no'] . ' ' . ($verdict === 'REJECTED' ? 'was rejected' : 'needs correction') . ': '
                                    . rtrim($remarkText, ' .') . '. Please upload a new file under Upload Documents.',
                                $applicationId
                            );
                        }
                    }
                    if ($flagged) {
                        // One SMS per batch, however many documents were flagged.
                        $notify[] = function () use ($app, $applicationId, $flagged) {
                            $sms = (new SMSService($this->pdo))->notifyDocumentCorrection($applicationId, $app['mobile'], $app['reference_no'], implode(', ', $flagged), '');
                            if (($sms['status'] ?? '') === 'FAILED') {
                                throw new RuntimeException('Correction SMS failed: ' . ($sms['error'] ?? 'provider error'));
                            }
                        };
                    }
                    $this->pdo->commit();
                } else {
                    $this->pdo->rollBack();
                }
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                error_log('Save verification failed for application ' . $applicationId . ': ' . $e->getMessage());
                return ['errors' => ['checklist' => 'Verification could not be saved because of a system error. No document was changed.'], 'changed' => 0, 'notify_failures' => 0];
            }
        }

        if ($errors) {
            $names = [];
            foreach ($errors as $documentId => $unused) {
                $names[] = $labels[$documentId] ?? $this->labelFor((int) $documentId, $applicationId);
            }
            $errors['checklist'] = 'Verification was not saved and no document was changed. Please review: ' . implode('; ', array_unique($names)) . '.';
            return ['errors' => $errors, 'changed' => 0, 'notify_failures' => 0];
        }

        // Committed. Notification failures are reported, never rolled back.
        $failures = 0;
        foreach ($notify as $send) {
            try {
                $send();
            } catch (Throwable $e) {
                $failures++;
                error_log('Verification notification failed for application ' . $applicationId . ': ' . $e->getMessage());
            }
        }
        return ['errors' => [], 'changed' => count($edited), 'notify_failures' => $failures];
    }

    private function labelFor(int $documentId, int $applicationId): string
    {
        $doc = $this->documents->findInApplication($documentId, $applicationId);
        return $doc ? ($this->documents->requirementLabel($doc['doc_key']) ?? $doc['doc_key']) : 'Document #' . $documentId;
    }

    /** Record physical receipt of one outstanding required document. */
    public function receiveChecklistDocument(array $staff, array $app, string $docKey): ?string
    {
        if (!can($staff, 'applications.process')) {
            return 'You do not have permission to record document receipt.';
        }
        $id = (int) $app['id'];
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM applications WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $current = $lock->fetch();
            if (!$current || !in_array($current['status'], Application::IN_PROGRESS_STATUSES, true)) {
                $this->pdo->rollBack();
                return 'Documents can only be received while the application is being processed.';
            }
            $interview = $this->appointments->interviewFor($id);
            if (empty($current['renewal_simplified']) && (($interview['status'] ?? '') !== 'COMPLETED' || Appointment::interviewOutcome($interview) === null)) {
                $this->pdo->rollBack();
                return 'Record the completed interview result before receiving missing documents.';
            }
            $rows = $this->documents->checklistForReview($id, $current['category_id'] !== null ? (int) $current['category_id'] : null, $current['type'] === 'renewal');
            $item = array_column($rows, null, 'doc_key')[$docKey] ?? null;
            if (!$item || empty($item['id']) || empty($item['is_required'])) {
                $this->pdo->rollBack();
                return 'That required document is not part of this application.';
            }
            if ($item['verification_status'] === 'Verified' && (empty($item['expires_at']) || $item['expires_at'] >= date('Y-m-d'))) {
                $this->pdo->commit();
                return null;
            }
            $update = $this->pdo->prepare("UPDATE application_document_checklists SET verification_status = 'Verified', staff_remarks = NULL, expires_at = NULL, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND application_id = ?");
            $update->execute([(int) $staff['id'], (int) $item['id'], $id]);
            $description = mb_substr('Document received: ' . $item['label'] . '. Physical document received by staff.', 0, 500);
            $this->applications->addHistory($id, $current['status'], $description, $staff['full_name'], (int) $staff['id']);
            $this->audit($staff, 'Document Received', 'application', $id, $description);
            $this->pdo->commit();
            return null;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Confirm all required physical documents after the interview without changing accepted records. */
    public function confirmAllRequiredDocuments(array $staff, array $app): ?string
    {
        if (!can($staff, 'applications.process')) {
            return 'You do not have permission to confirm documents.';
        }
        $current = $this->applications->findForReview((int) $app['id']);
        if (!$current || !in_array($current['status'], Application::IN_PROGRESS_STATUSES, true)) {
            return 'Document confirmation is closed for this application.';
        }
        $interview = $this->appointments->interviewFor((int) $app['id']);
        if (empty($current['renewal_simplified']) && (($interview['status'] ?? '') !== 'COMPLETED' || Appointment::interviewOutcome($interview) === null)) {
            return 'Record the completed interview result before confirming documents.';
        }
        $rows = $this->documents->checklistForReview((int) $app['id'], $current['category_id'] !== null ? (int) $current['category_id'] : null, $current['type'] === 'renewal');
        $submitted = [];
        foreach ($rows as $row) {
            if (empty($row['is_required'])) {
                continue;
            }
            if (empty($row['id'])) {
                return 'A required checklist record is missing. Reload the application.';
            }
            $submitted[$row['id']] = ['status' => 'Verified', 'remarks' => $row['verification_status'] === 'Verified' ? (string) ($row['staff_remarks'] ?? '') : ''];
        }
        if (!$submitted) {
            return 'There are no required documents to confirm.';
        }
        return $this->reviewChecklistItems($staff, $current, $submitted);
    }

    /** Verify every applicable checklist document using the same history and eligibility workflow. */
    public function verifyAllChecklistItems(array $staff, array $app): ?string
    {
        $rows = $this->documents->checklistForReview((int) $app['id'],
            $app['category_id'] !== null ? (int) $app['category_id'] : null, ($app['type'] ?? '') === 'renewal');
        if (!$rows) {
            return 'There are no documents to verify.';
        }
        $submitted = [];
        foreach ($rows as $row) {
            if (!empty($app['renewal_simplified']) && isset($row['required_snapshot']) && !$row['required_snapshot'] && empty($row['is_required'])) {
                continue;
            }
            if (empty($row['id'])) {
                return 'A checklist record is missing. Reload the application and try again.';
            }
            $submitted[$row['id']] = ['status' => 'Verified', 'remarks' => ''];
        }
        return $this->reviewChecklistItems($staff, $app, $submitted, true);
    }

    /** Save checklist verdicts together; checklist rows are scoped to this application. */
    public function reviewChecklistItems(array $staff, array $app, $submitted, bool $includeOptional = false): ?string
    {
        if (!is_array($submitted) || !in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
            return 'Checklist review is closed or the submitted checklist is invalid.';
        }
        $appId = (int) $app['id'];
        $categoryId = $app['category_id'] !== null ? (int) $app['category_id'] : null;
        $required = array_values(array_filter(
            $this->documents->checklistForReview($appId, $categoryId, ($app['type'] ?? '') === 'renewal'),
            fn($row) => ($includeOptional || !empty($row['is_required'])) && !(!empty($app['renewal_simplified']) && isset($row['required_snapshot']) && !$row['required_snapshot'] && empty($row['is_required']))
        ));
        $expected = [];
        foreach ($required as $row) {
            if (empty($row['id'])) {
                return 'A required checklist record is missing. Reload the application and try again.';
            }
            $expected[(string) $row['id']] = $row;
        }
        if (count($submitted) !== count($expected)) {
            return 'The checklist changed or is incomplete. Reload the application and review every required document.';
        }

        $changes = [];
        foreach ($submitted as $id => $values) {
            $key = (string) $id;
            if (!ctype_digit($key) || !isset($expected[$key]) || !is_array($values)
                || !is_string($values['status'] ?? null) || !is_string($values['remarks'] ?? null)) {
                return 'The submitted checklist contains an invalid document.';
            }
            $row = $expected[$key];
            $status = $values['status'];
            if (!in_array($status, Document::CHECKLIST_STATUSES, true)) {
                return 'Choose a valid status for ' . $row['label'] . '.';
            }
            $remarks = trim($values['remarks']);
            if (mb_strlen($remarks) > 500) {
                return 'Remarks for ' . $row['label'] . ' are too long (up to 500 characters).';
            }
            if ($status === 'Needs Correction' && $remarks === '') {
                return 'Add a remark explaining what needs correction for ' . $row['label'] . '.';
            }
            $remarksValue = $remarks !== '' ? $remarks : null;
            $existingExpiry = $row['expires_at'] ?? '';
            // Staff no longer enter expiry dates. Verifying updated evidence must
            // not retain the expired date of the document it replaced.
            $expiresAt = $values['expires_at'] ?? ($status === 'Verified' && $existingExpiry !== '' && $existingExpiry < date('Y-m-d') ? '' : $existingExpiry);
            if (!is_string($expiresAt) || ($expiresAt !== '' && (!is_ymd($expiresAt) || !($date = DateTime::createFromFormat('!Y-m-d',$expiresAt)) || $date->format('Y-m-d') !== $expiresAt))) {
                return 'Enter a valid document expiry date for ' . $row['label'] . '.';
            }
            if ($status === 'Verified' && $expiresAt !== '' && $expiresAt < date('Y-m-d')) {
                return 'An expired document cannot be marked verified. Request an updated copy.';
            }
            $expiresAt = $expiresAt !== '' ? $expiresAt : null;
            if ($row['verification_status'] !== $status || $row['staff_remarks'] !== $remarksValue || ($row['expires_at'] ?? null) !== $expiresAt) {
                $changes[] = ['id' => (int) $key, 'key' => $row['doc_key'], 'label' => $row['label'],
                    'old_status' => $row['verification_status'], 'status' => $status,
                    'old_remarks' => $row['staff_remarks'], 'remarks' => $remarksValue,'expires_at'=>$expiresAt];
            }
        }
        try {
            $this->pdo->beginTransaction();
            $update = $this->pdo->prepare('UPDATE application_document_checklists SET verification_status = ?, staff_remarks = ?, expires_at = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND application_id = ?');
            $confirm = $this->pdo->prepare('SELECT verification_status, staff_remarks,expires_at FROM application_document_checklists WHERE id = ? AND application_id = ?');
            $applied = [];
            foreach ($changes as $change) {
                $update->execute([$change['status'], $change['remarks'],$change['expires_at'], (int) $staff['id'], $change['id'], $appId]);
                if ($update->rowCount() !== 1) {
                    $confirm->execute([$change['id'], $appId]);
                    $current = $confirm->fetch();
                    // A repeated POST may arrive after the first identical save committed.
                    if ($current && $current['verification_status'] === $change['status'] && $current['staff_remarks'] === $change['remarks'] && $current['expires_at'] === $change['expires_at']) {
                        continue;
                    }
                    throw new RuntimeException('Checklist row no longer belongs to the application.');
                }
                $applied[] = $change;
                $description = 'Document "' . $change['label'] . '": ' . $change['status']
                    . ($change['remarks'] ? ' — ' . mb_substr($change['remarks'], 0, 350) : '.') . ($change['expires_at'] ? ' Expires: ' . $change['expires_at'] . '.' : ' No expiry recorded.');
                $this->applications->addHistory($appId, $app['status'], mb_substr($description, 0, 500), $staff['full_name'], (int) $staff['id']);
            }
            $this->syncEligibilityFromChecklist($staff, $app);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('Checklist verification save failed for application ' . $appId . ': ' . $e->getMessage());
            return 'Could not save document verification. No changes were saved; please try again.';
        }

        foreach ($applied as $change) {
            audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Document Checklist Review', 'application', $appId, $change['key'] . ': ' . $change['old_status'] . ' → ' . $change['status']);
            if ($change['status'] === 'Needs Correction'
                && ($change['old_status'] !== 'Needs Correction' || $change['old_remarks'] !== $change['remarks'])) {
                create_notification($this->pdo, (int) $app['user_id'], 'correction', 'The checklist requirement "' . $change['label'] . '" for ' . $app['reference_no'] . ' needs correction: ' . rtrim((string) $change['remarks'], ' .'), $appId);
                (new SMSService($this->pdo))->notifyDocumentCorrection($appId, $app['mobile'], $app['reference_no'], $change['label'], (string) $change['remarks']);
            }
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
    private function hasReleasedRenewalSource(array $app): bool
    {
        $query = $this->pdo->prepare("SELECT 1 FROM applications WHERE id=? AND user_id=? AND status='ID Released'");
        $query->execute([$app['renewal_of_id'] ?? 0,$app['user_id']]);
        return (bool) $query->fetchColumn();
    }

    public function approvalChecklist(array $app): array
    {
        $unverified = array_column($this->documents->outstandingRequired($app), 'label');
        $requirements = $this->documents->checklistForReview((int) $app['id'], $app['category_id'] !== null ? (int) $app['category_id'] : null, $app['type'] === 'renewal');
        $requiredCount = count(array_filter($requirements, fn($item) => !empty($item['is_required'])));
        // Approval is blocked until each required upload or retained renewal record is verified.
        $missingCount = count(array_filter($requirements, [Document::class, 'needsPreparation']));
        $completeCount = $requiredCount - $missingCount;
        $documentSummary = $completeCount . ($completeCount === 1 ? ' document complete' : ' documents complete')
            . ', ' . $missingCount . ($missingCount === 1 ? ' document still missing.' : ' documents still missing.');
        if ($missingCount > 0) {
            $documentSummary .= ' See the highlighted items in the Required Documents Checklist. These must be received before approval and ID release scheduling.';
        }
        $appt = $this->appointments->interviewFor((int) $app['id']);
        $interviewDone = Appointment::interviewSuccessful($appt);
        $interviewDetail = $interviewDone ? '' : ($appt && $appt['status'] === 'BOOKED'
            ? 'Interview scheduled. Record the result after the interview.'
            : ($appt && $appt['status'] === 'MISSED' ? 'Interview missed. Schedule another interview.' : 'Schedule the interview, then record the result after it is completed.'));
        return [
            ['label' => 'Required documents', 'ok' => !$unverified,
                'detail' => $documentSummary],
            ['label' => !empty($app['renewal_simplified']) ? 'Existing released ID confirmed (no new interview)' : 'Successful interview recorded', 'ok' => !empty($app['renewal_simplified']) ? $this->hasReleasedRenewalSource($app) : (bool) $interviewDone, 'detail' => !empty($app['renewal_simplified']) ? '' : (Appointment::interviewOutcome($appt) === 'Unsuccessful' ? 'Interview marked Unsuccessful. Review the result or schedule another interview.' : $interviewDetail)],
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
            return 'Choose Yes or No.';
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
        $latest = (new EligibilityAssessment($this->pdo))->mswdForApplication((int) $app['id'])[0] ?? null;
        $expectedResult = $status === 'Eligible' ? 'ELIGIBLE' : 'NOT_ELIGIBLE';
        if ($latest && $latest['preliminary_result'] === $expectedResult && $latest['result_reason'] === $notes
            && $app['eligibility_status'] === $status && ($app['eligibility_remarks'] ?? null) === $notes) {
            return null;
        }
        $this->pdo->beginTransaction();
        try {
            (new EligibilityAssessment($this->pdo))->saveMswd((int) $app['id'], (int) $app['user_id'], $status === 'Eligible' ? 'ELIGIBLE' : 'NOT_ELIGIBLE', $notes, (int) $staff['id']);
            $this->applications->setEligibility((int) $app['id'], $status, $notes);
            $this->applications->addHistory((int) $app['id'], $app['status'], mb_substr('MSWD eligibility assessment: ' . $status . ($notes ? '. ' . $notes : '.'), 0, 500), $staff['full_name'], (int) $staff['id']);
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
    public function scheduleInterview(array $staff, array $app, string $scheduledAt, string $location, string $notes, string $messageType = 'automatic', string $customMessage = ''): ?string
    {
        if (!empty($app['renewal_simplified'])) {
            return 'This renewal uses document review and approval; a new interview is not required.';
        }
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
        if ($error = (new InterviewCalendarService($this->pdo))->timeError($dt->format('Y-m-d'), $dt->format('H:i'))) {
            return $error;
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
        if (!in_array($messageType, ['automatic', 'custom'], true)) {
            return 'Choose Automatic Message or Custom Message.';
        }
        $customMessage = trim($customMessage);
        if ($messageType === 'custom' && $customMessage === '') {
            return 'Enter the custom SMS message before scheduling.';
        }
        if ($messageType === 'custom' && mb_strlen($customMessage) > 500) {
            return 'The custom SMS message is too long (up to 500 characters).';
        }
        $id = (int) $app['id'];
        $scheduledAtSql = $dt->format('Y-m-d H:i:s');
        $when = $dt->format('M j, Y g:i A');

        $sms = new SMSService($this->pdo);
        $message = $messageType === 'custom' ? $customMessage : null;
        $automaticMessage = $messageType === 'automatic' ? $sms->interviewScheduleMessage($app['full_name'], $app['reference_no'], $scheduledAtSql, $location, $id) : null;
        if ($automaticMessage !== null && mb_strlen($automaticMessage) > 500) {
            return 'The automatic SMS exceeds 500 characters. Shorten the interview location and try again.';
        }
        $expectedMessage = $messageType === 'custom' ? $customMessage : $automaticMessage;
        $expectedKey = $messageType === 'custom' ? 'interview_custom' : 'interview_scheduled';
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT status FROM applications WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $currentStatus = $lock->fetchColumn();
            if ($currentStatus === false || !in_array($currentStatus, Application::IN_PROGRESS_STATUSES, true)) {
                $this->pdo->rollBack();
                return 'An interview can only be scheduled while the application is being processed. Refresh and review its current status.';
            }
            $existing = $this->appointments->interviewFor($id);
            if ($existing && $existing['status'] === 'COMPLETED') {
                $this->pdo->rollBack();
                return 'The interview for this application is already completed.';
            }
            $sameSchedule = $existing && $existing['status'] === 'BOOKED'
                && $existing['scheduled_at'] === $scheduledAtSql && $existing['location'] === $location
                && (string) ($existing['notes'] ?? '') === ($notes !== '' ? $notes : '');
            $latestSms = $this->appointments->interviewSms($id);
            // The application row lock makes repeated/concurrent clicks serialize here.
            if ($sameSchedule && $latestSms && $latestSms['message'] === $expectedMessage && $latestSms['template_key'] === $expectedKey) {
                $this->pdo->rollBack();
                if ($latestSms['provider_status'] === 'disabled') {
                    flash('The interview is saved, but its SMS was skipped because interview notifications are disabled. Enable notifications before using Retry SMS.');
                } elseif ($latestSms['status'] === 'FAILED') {
                    flash('The interview is saved, but its SMS failed. Use Retry SMS below to retry this same message.');
                } else {
                    flash(!empty($latestSms['test_mode']) ? 'Interview schedule saved. SMS was logged in test mode and was not sent.' : 'Interview schedule and SMS submission are already recorded; no duplicate text was sent.');
                }
                return null;
            }

            $this->appointments->upsertStaffScheduled($id, $scheduledAtSql, $location, $notes !== '' ? $notes : null, (int) $staff['id']);
            $this->applications->setReviewedStatus($id, 'Interview Scheduled', (int) $staff['id']);
            $this->applications->addHistory($id, 'Interview Scheduled', 'Interview at ' . $location . ' on ' . $when, $staff['full_name'], (int) $staff['id']);
            $smsResult = $sms->notifyInterviewScheduled($id, $app['mobile'], $app['reference_no'], $scheduledAtSql, $location, $app['full_name'], $message);
            $this->appointments->linkSmsLog($id, $smsResult['id'] !== null ? (int) $smsResult['id'] : null);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($smsResult['status'] === 'SENT') {
            flash(!empty($smsResult['test_mode']) ? 'Interview schedule saved. SMS was logged in test mode and was not sent.' : 'Interview schedule saved and SMS accepted by the configured provider.');
        } elseif (($smsResult['provider_status'] ?? '') === 'disabled') {
            flash('Interview schedule saved. SMS was not sent because interview notifications are disabled in settings.');
        } elseif ($smsResult['status'] === 'FAILED') {
            flash('Interview schedule saved, but SMS submission failed: ' . ($smsResult['error'] ?: 'provider error') . ' Use Retry SMS below to retry the same message.');
        } else {
            flash('Interview schedule saved. SMS was not sent.');
        }
        create_notification(
            $this->pdo, (int) $app['user_id'], 'appointment_confirmation',
            'An appointment for ' . $app['reference_no'] . ' has been scheduled: ' . $when . ' at ' . $location . '.',
            $id
        );
        $this->audit($staff, 'Interview Scheduling', 'application', $id, $location . ' on ' . $when . ' (' . strtoupper($messageType) . ' SMS)');
        return null;
    }

    public function recordInterviewOutcome(array $staff, int $applicationId, string $outcome, string $notes): ?string
    {
        if (!in_array($outcome, ['Successful', 'Unsuccessful'], true)) {
            return 'Choose Successful or Unsuccessful.';
        }
        $notes = trim($notes);
        return $this->recordInterviewResult($staff, $applicationId, $outcome . ($notes !== '' ? ': ' . $notes : ''));
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
        if ($appt['status'] === 'COMPLETED' && trim((string) ($appt['result'] ?? '')) === trim($result)) {
            return null;
        }
        if ($appt['status'] === 'BOOKED' && strtotime((string) $appt['scheduled_at']) > time()) {
            return 'Record the interview result on or after its scheduled time.';
        }
        $this->appointments->recordResult($applicationId, $result);
        $app = $this->applications->findForReview($applicationId);
        if ($app) {
            $this->applications->addHistory($applicationId, $app['status'], mb_substr('Interview result recorded: ' . trim($result), 0, 500), $staff['full_name'], (int) $staff['id']);
        }
        $this->audit($staff, 'Interview Result Recorded', 'application', $applicationId, $result);
        return null;
    }

    /** Fingerprint the persisted review so an old page cannot undo newer staff work. */
    public function reviewUndoToken(int $applicationId): string
    {
        $state = [];
        foreach ([
            'SELECT status, eligibility_status, eligibility_remarks FROM applications WHERE id = ?',
            'SELECT id, verification_status, staff_remarks, reviewed_by, reviewed_at FROM application_document_checklists WHERE application_id = ? ORDER BY id',
            'SELECT id, status, result, result_recorded_at, scheduled_at FROM appointments WHERE application_id = ? ORDER BY id',
            'SELECT id FROM application_status_history WHERE application_id = ? ORDER BY id DESC LIMIT 1',
        ] as $sql) {
            $query = $this->pdo->prepare($sql);
            $query->execute([$applicationId]);
            $state[] = $query->fetchAll();
        }
        return hash('sha256', json_encode($state));
    }

    /** Reopen a confirmed review, preserving earlier results in history and audit logs. */
    public function undoReview(array $staff, int $applicationId, string $section, string $expectedToken): ?string
    {
        if (!can($staff, 'applications.process') || !in_array($section, ['verification', 'eligibility', 'interview'], true)) {
            return 'That review cannot be undone.';
        }
        try {
            $this->pdo->beginTransaction();
            $lock = $this->pdo->prepare('SELECT * FROM applications WHERE id = ? FOR UPDATE');
            $lock->execute([$applicationId]);
            $app = $lock->fetch();
            // Lock dependent records before comparing and clearing their results.
            foreach (['application_document_checklists', 'appointments'] as $table) {
                $query = $this->pdo->prepare('SELECT id FROM ' . $table . ' WHERE application_id = ? FOR UPDATE');
                $query->execute([$applicationId]);
                $query->fetchAll();
            }
            if (!$app || !in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)
                || !hash_equals($this->reviewUndoToken($applicationId), $expectedToken)) {
                $this->pdo->rollBack();
                return 'The application changed or review is closed. Refresh before undoing a review.';
            }
            if ($section === 'verification') {
                $update = $this->pdo->prepare("UPDATE application_document_checklists SET verification_status = 'Pending', staff_remarks = NULL, reviewed_by = NULL, reviewed_at = NULL WHERE id = ? AND application_id = ?");
                $rows = $this->documents->checklistForReview($applicationId, $app['category_id'] !== null ? (int) $app['category_id'] : null, $app['type'] === 'renewal');
                foreach ($rows as $row) {
                    // Retained renewal documents were already accepted in an earlier application.
                    if (!empty($app['renewal_simplified']) && isset($row['required_snapshot']) && !$row['required_snapshot'] && empty($row['is_required'])) {
                        continue;
                    }
                    $update->execute([(int) $row['id'], $applicationId]);
                }
                $this->syncEligibilityFromChecklist($staff, $app);
            } elseif ($section === 'eligibility') {
                $this->applications->setEligibility($applicationId, 'Not Assessed', null);
            } else {
                $query = $this->pdo->prepare("UPDATE appointments SET status = 'BOOKED', result = NULL, result_recorded_at = NULL WHERE application_id = ? AND status = 'COMPLETED'");
                $query->execute([$applicationId]);
                if ($query->rowCount() !== 1) {
                    $this->pdo->rollBack();
                    return 'There is no confirmed interview result to undo.';
                }
            }
            $description = ucfirst($section) . ' review undone; ready for review again.';
            $this->applications->addHistory($applicationId, $app['status'], $description, $staff['full_name'], (int) $staff['id']);
            $this->audit($staff, 'Review Undone', 'application', $applicationId, $description);
            $this->pdo->commit();
            return null;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('Review undo failed: ' . $e->getMessage());
            return 'Could not undo the review. Please try again.';
        }
    }

    private function audit(array $staff, string $action, string $entityType, int $entityId, string $details): void
    {
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], $action, $entityType, $entityId, $details);
    }
}
