<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/EligibilityAssessment.php';
require_once __DIR__ . '/SMSService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/InterviewCalendarService.php';
require_once __DIR__ . '/InterviewInvitationService.php';

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
        // Rejecting (with its required reason) does not need a successful interview; corrections and approval do.
        if (isset(self::DECISIONS[$newStatus]) && $newStatus !== 'Rejected' && empty($app['renewal_simplified'])
            && !Appointment::interviewSuccessful($this->appointments->interviewFor((int) $app['id']))) {
            return 'Record a successful interview before requesting corrections or approving this application.';
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
            if (isset(self::DECISIONS[$newStatus]) && $newStatus !== 'Rejected' && empty($current['renewal_simplified'])
                && !Appointment::interviewSuccessful($this->appointments->interviewFor($id))) {
                $this->pdo->rollBack();
                return 'Record a successful interview before requesting corrections or approving this application.';
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
     * In-app notifications are sent only after a successful commit; a failed
     * notification never undoes a committed verification. This method never
     * sends SMS: the correction SMS is a separate, staff-confirmed step.
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
            $edited[$documentId] = ['status' => $status, 'remarks' => $remarks, 'expected' => $expected, 'expected_remarks' => $expectedRemarks,
                'version' => is_string($row['expected_version'] ?? null) ? $row['expected_version'] : ''];
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
                    } elseif ($row['version'] === '' || !hash_equals(Document::versionToken($doc), $row['version'])) {
                        // The file itself changed (uploaded or replaced) after this page was loaded, even if status and remarks look the same.
                        $errors[$documentId] = 'A newer file was uploaded for this document after you opened this page, so your decision was not saved. Reload the application and inspect the latest upload before verifying.';
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
                            $notify[] = fn() => create_notification(
                                $this->pdo, (int) $app['user_id'], 'correction',
                                'Your document "' . $label . '" for ' . $app['reference_no'] . ' ' . ($verdict === 'REJECTED' ? 'was rejected' : 'needs correction') . ': '
                                    . rtrim($remarkText, ' .') . '. Please open Supporting Documents and upload a replacement file.',
                                $applicationId
                            );
                        }
                    }
                    // No correction SMS is sent here. Staff preview and explicitly confirm it afterwards
                    // (correctionSmsPreview() / sendCorrectionSms()); the in-app notice above is still sent.
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

    /**
     * The current correction batch: this application's documents now in NEEDS_CORRECTION, by ascending id
     * (legacy REJECTED documents are not part of it). With $lock the rows are locked FOR UPDATE; call that
     * only inside a transaction that already holds the application row.
     */
    private function correctionBatch(int $applicationId, bool $lock = false): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM application_documents WHERE application_id = ? AND verification_status = 'NEEDS_CORRECTION' ORDER BY id" . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$applicationId]);
        return $stmt->fetchAll();
    }

    /**
     * What a correction SMS would say and to whom, for a batch: the document labels, the number staff are shown
     * (normalised when valid, else the raw value), that number only when valid, and the exact rendered message.
     * Preview and send both build it here so they cannot disagree.
     *
     * @return array{labels: array, recipient: string, number: ?string, message: string}
     */
    private function correctionContext(array $app, array $batch, SMSService $sms): array
    {
        $labels = array_map(fn($d) => $this->documents->requirementLabel($d['doc_key']) ?? $d['doc_key'], $batch);
        $raw = $sms->recipientForApplication((int) $app['id'], (string) $app['mobile']);
        $number = SMSService::normalizeNumber($raw);
        return [
            'labels' => $labels,
            'recipient' => $number ?? $raw,
            'number' => $number,
            'message' => $batch ? $sms->correctionMessage((string) $app['full_name'], (string) $app['reference_no'], $labels) : '',
        ];
    }

    /**
     * Identifies exactly what staff previewed: the application, every flagged document's file version, status and
     * verification time, the exact rendered message and the exact recipient. Only a hash is ever shown or stored,
     * never the message or number. If the template, the recipient or any document changes, the token changes and
     * the confirmation is rejected as stale.
     */
    private function correctionToken(int $applicationId, array $batch, string $applicantName, string $message, string $recipient): string
    {
        $parts = [];
        foreach ($batch as $doc) {
            $parts[] = implode(':', [(int) $doc['id'], Document::versionToken($doc), $doc['verification_status'], (string) ($doc['verified_at'] ?? '')]);
        }
        return hash('sha256', implode('|', [$applicationId, implode(';', $parts), hash('sha256', $applicantName), hash('sha256', $message), hash('sha256', $recipient)]));
    }

    /**
     * SMS log rows for this batch, newest first: correction texts logged at or after the batch's newest
     * verification, in either provider mode (callers filter by mode where it matters).
     *
     * Known limitation (no schema change allowed): sent_at and verified_at are whole-second values and a log row
     * does not store the batch token, so a batch saved in the very same second as an earlier send cannot be told
     * apart from it. The comparison is deliberately inclusive (>=) so that case errs towards blocking (a false
     * "already sent"), never towards a duplicate. A document with no verified_at counts as the oldest possible
     * time, which also errs towards blocking.
     */
    private function correctionLogs(int $applicationId, array $batch): array
    {
        $since = '1000-01-01 00:00:00';
        foreach ($batch as $doc) {
            if (!empty($doc['verified_at']) && $doc['verified_at'] > $since) {
                $since = $doc['verified_at'];
            }
        }
        $stmt = $this->pdo->prepare("SELECT id, status, error, test_mode, sent_at FROM sms_logs WHERE application_id = ? AND template_key = 'document_correction' AND sent_at >= ? ORDER BY id DESC");
        $stmt->execute([$applicationId, $since]);
        return $stmt->fetchAll();
    }

    /**
     * The first log that blocks a new send in the CURRENT provider mode: any PENDING or SENT row logged in the
     * same mode (test or real). A test-mode row never blocks a real send and vice versa; FAILED never blocks.
     */
    private function correctionBlockingLog(array $logs): ?array
    {
        $mode = SMSService::testMode() ? 1 : 0;
        foreach ($logs as $log) {
            if ((int) $log['test_mode'] === $mode && $log['status'] !== 'FAILED') {
                return $log;
            }
        }
        return null;
    }

    /** The staff-facing reason a batch is blocked by $log (a PENDING or SENT row of the current mode). */
    private function correctionBlockMessage(array $log, bool $sending): string
    {
        if ($log['status'] === 'PENDING') {
            return ($sending ? 'No second SMS was sent. ' : '')
                . 'A send for this batch (SMS log #' . (int) $log['id'] . ') is PENDING: its outcome was never recorded, so it is not known whether the SMS reached the provider. '
                . 'This batch stays blocked and cannot be re-sent from this page. It needs separate manual investigation.';
        }
        return !empty($log['test_mode'])
            ? ($sending ? 'No duplicate was logged. ' : '') . 'This batch was already logged in test mode (SMS log #' . (int) $log['id'] . '); nothing was delivered to a phone.'
            : ($sending ? 'No duplicate was sent. ' : '') . 'The correction SMS for this batch was already sent (SMS log #' . (int) $log['id'] . ').';
    }

    /** Writes the staff audit entry for a correction SMS. A failure is logged safely and reported, never thrown. */
    private function auditCorrectionSms(array $staff, int $applicationId, string $details, int $logId, string $outcome): bool
    {
        try {
            $this->audit($staff, 'Correction SMS', 'application', $applicationId, $details);
            return true;
        } catch (Throwable $e) {
            // No message text: it can contain SQL with names or numbers. The class, code and ids are enough to find it.
            error_log('Correction SMS audit entry missing: application ' . $applicationId . ', sms_log #' . $logId . ', outcome ' . $outcome . ', ' . get_class($e) . ' code ' . $e->getCode());
            return false;
        }
    }

    /**
     * Read-only preview of the correction SMS for the current batch. Never sends, logs or changes anything.
     * Returns has_batch, documents (labels), token, applicant, reference, recipient, message, length, test_mode,
     * enabled, last_log (newest log of this batch or null) and block (null when it may be sent, else the reason).
     */
    public function correctionSmsPreview(array $app): array
    {
        $applicationId = (int) $app['id'];
        $sms = new SMSService($this->pdo);
        $batch = $this->correctionBatch($applicationId);
        $ctx = $this->correctionContext($app, $batch, $sms);
        $number = $ctx['number'];
        $message = $ctx['message'];
        $logs = $this->correctionLogs($applicationId, $batch);
        $preview = [
            'has_batch' => (bool) $batch,
            'documents' => $ctx['labels'],
            'token' => $this->correctionToken($applicationId, $batch, (string) $app['full_name'], $message, $ctx['recipient']),
            'applicant' => (string) $app['full_name'],
            'reference' => (string) $app['reference_no'],
            'recipient' => $ctx['recipient'],
            'message' => $message,
            'length' => mb_strlen($message),
            'test_mode' => SMSService::testMode(),
            'enabled' => $sms->templateEnabled('document_correction'),
            'last_log' => $logs[0] ?? null,
            'block' => null,
        ];
        $blocking = $this->correctionBlockingLog($logs);
        if (!in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
            $preview['block'] = 'Documents can no longer be changed for this application, so a correction SMS cannot be sent.';
        } elseif (!$batch) {
            $preview['block'] = 'No document is currently marked Needs Correction. Save a Needs Correction verification first.';
        } elseif (!$preview['enabled']) {
            $preview['block'] = 'SMS notifications, or the Document Needs Correction message, are switched off in Notification Settings. Nothing can be sent.';
        } elseif ($number === null) {
            $preview['block'] = 'The applicant\'s mobile number is not a valid Philippine number. Correct it before sending.';
        } elseif ($preview['length'] > SMSService::CORRECTION_MAX_LENGTH) {
            $preview['block'] = 'The message is ' . $preview['length'] . ' characters, over the ' . SMSService::CORRECTION_MAX_LENGTH . ' character limit. Names are never cut, so it cannot be sent as is.';
        } elseif ($blocking) {
            $preview['block'] = $this->correctionBlockMessage($blocking, false);
        }
        return $preview;
    }

    /**
     * Sends the correction SMS for the batch the staff member previewed, once. Under the application-then-
     * documents lock order it re-reads the batch, compares $token, refuses if SMS is off, the text is too long
     * or this batch already has a PENDING/SENT log, then commits a PENDING log row (the reservation) BEFORE
     * contacting the provider. A FAILED result does not block a new confirmed send.
     *
     * @return array{outcome: string, message: string, test_mode: bool}  outcome: SENT, FAILED, DISABLED, PENDING, BLOCKED or ERROR
     */
    public function sendCorrectionSms(array $staff, array $app, string $token): array
    {
        if (!can($staff, 'applications.process')) {
            return ['outcome' => 'ERROR', 'message' => 'You do not have permission to send correction SMS.', 'test_mode' => false];
        }
        $applicationId = (int) $app['id'];
        $sms = new SMSService($this->pdo);
        $fail = static fn(string $outcome, string $message, bool $testMode = false) => ['outcome' => $outcome, 'message' => $message, 'test_mode' => $testMode];
        try {
            $this->pdo->beginTransaction();
            // Lock order: application row, then its documents by ascending id. Keep every plain (consistent) read
            // below this point: the duplicate check relies on the snapshot starting after the application lock.
            $lock = $this->pdo->prepare('SELECT status FROM applications WHERE id = ? FOR UPDATE');
            $lock->execute([$applicationId]);
            $status = $lock->fetchColumn();
            $batch = $this->correctionBatch($applicationId, true);
            $ctx = $this->correctionContext($app, $batch, $sms);
            $labels = $ctx['labels'];
            $number = $ctx['number'];
            $message = $ctx['message'];

            if ($status === false || !in_array($status, Application::IN_PROGRESS_STATUSES, true)) {
                $this->pdo->rollBack();
                return $fail('BLOCKED', 'Documents can no longer be changed for this application, so no SMS was sent.');
            }
            if (!$batch) {
                $this->pdo->rollBack();
                return $fail('BLOCKED', 'No document is marked Needs Correction any more, so no SMS was sent.');
            }
            if ($token === '' || !hash_equals($this->correctionToken($applicationId, $batch, (string) $app['full_name'], $message, $ctx['recipient']), $token)) {
                $this->pdo->rollBack();
                return $fail('BLOCKED', 'The documents, the applicant name, the message wording or the recipient number changed after you previewed. No SMS was sent; preview it again.');
            }
            if (!$sms->templateEnabled('document_correction')) {
                $this->pdo->rollBack();
                return $fail('DISABLED', 'SMS is switched off in Notification Settings, so the correction SMS was NOT sent. The applicant has only the in-app notice.');
            }
            if ($number === null) {
                $this->pdo->rollBack();
                return $fail('BLOCKED', 'The applicant\'s mobile number is not valid, so no SMS was sent.');
            }
            if (mb_strlen($message) > SMSService::CORRECTION_MAX_LENGTH) {
                $this->pdo->rollBack();
                return $fail('BLOCKED', 'The message is longer than ' . SMSService::CORRECTION_MAX_LENGTH . ' characters and would have to be cut, so no SMS was sent.');
            }
            $blocking = $this->correctionBlockingLog($this->correctionLogs($applicationId, $batch));
            if ($blocking) {
                $this->pdo->rollBack();
                return $fail($blocking['status'] === 'PENDING' ? 'PENDING' : 'BLOCKED', $this->correctionBlockMessage($blocking, true), !empty($blocking['test_mode']));
            }
            $logId = $sms->reserveLog($applicationId, $number, $message, 'document_correction');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('Correction SMS reservation failed for application ' . $applicationId . ': ' . $e->getMessage());
            return $fail('ERROR', 'The correction SMS could not be prepared because of a system error. No SMS was sent.');
        }

        // Reservation committed: from here on a repeat click is blocked by the PENDING row.
        // The SMS log row is the source of truth from here on. The audit entry is written afterwards and a failure
        // to write it is reported as a note only; it never changes the outcome the log recorded.
        $docs = implode(', ', $labels);
        $testMode = SMSService::testMode();
        try {
            $result = $sms->dispatchReserved($logId, $number, $message);
        } catch (Throwable $e) {
            error_log('Correction SMS outcome not recorded for sms_log #' . $logId . ': ' . get_class($e) . ' code ' . $e->getCode());
            $audited = $this->auditCorrectionSms($staff, $applicationId, $app['reference_no'] . ' · ' . $docs . ' · PENDING (outcome not recorded) · log #' . $logId, $logId, 'PENDING');
            return $fail('PENDING', 'The SMS may have been handed to the provider, but its outcome could not be recorded, so it stays PENDING (SMS log #' . $logId . '). '
                . 'This batch stays blocked and cannot be re-sent from this page; it needs separate manual investigation.'
                . ($audited ? '' : ' The audit entry also could not be written; the SMS log is the record.'), $testMode);
        }
        $audited = $this->auditCorrectionSms($staff, $applicationId,
            $app['reference_no'] . ' · ' . $docs . ' · ' . $result['status'] . ($result['test_mode'] ? ' (test mode)' : '') . ' · log #' . $logId, $logId, $result['status']);
        $auditNote = $audited ? '' : ' The audit entry could not be written; the SMS log #' . $logId . ' is the record.';
        if ($result['status'] === 'SENT') {
            return $fail('SENT', ($result['test_mode']
                ? 'Test mode: the correction SMS was logged but NOT delivered to any phone (SMS provider is "test").'
                : 'The SMS provider accepted the correction SMS; delivery to the phone is not confirmed here.') . $auditNote, (bool) $result['test_mode']);
        }
        return $fail('FAILED', 'The correction SMS FAILED: ' . ($result['error'] ?: 'provider error') . '. You can confirm it again after checking the number and settings.' . $auditNote, (bool) $result['test_mode']);
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
            ? 'Interview booked. Record the result after the interview.'
            : ($appt && $appt['status'] === 'MISSED' ? 'Interview missed. Send a new interview booking invitation.' : 'Send an interview booking invitation and wait for the applicant to book a date, then record the result after the interview.'));
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
        // Business rule: a result can be recorded or changed only while every required document is Verified and
        // unexpired. An existing appointment is never touched when a document later becomes unverified; only the
        // result is held back. Lock order matches scheduling, verification and replacement: the application row,
        // then this application's document rows in ascending id. No plain read happens before those locks, so the
        // reads that follow see the committed, current state and a replacement cannot slip in before the write.
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM applications WHERE id = ? FOR UPDATE');
            $lock->execute([$applicationId]);
            $app = $lock->fetch();
            if (!$app) {
                $this->pdo->rollBack();
                return 'There is no booked appointment to record a result for.';
            }
            $docIds = $this->pdo->prepare('SELECT id FROM application_documents WHERE application_id = ? ORDER BY id FOR UPDATE');
            $docIds->execute([$applicationId]);
            $docIds->fetchAll();
            // The gate comes first, before every appointment check, so it also covers an identical-result resubmission.
            $outstanding = $this->documents->outstandingRequired($app);
            if ($outstanding) {
                $this->pdo->rollBack();
                return 'The interview result cannot be recorded or changed until all required documents are verified. Still outstanding: '
                    . $this->outstandingDocumentList($outstanding) . '. Verify them first, then record the result.';
            }
            $appt = $this->appointments->interviewFor($applicationId);
            if (!$appt || !in_array($appt['status'], ['BOOKED', 'COMPLETED'], true)) {
                $this->pdo->rollBack();
                return 'There is no booked appointment to record a result for.';
            }
            if ($appt['status'] === 'COMPLETED' && trim((string) ($appt['result'] ?? '')) === trim($result)) {
                $this->pdo->rollBack();
                return null;
            }
            if ($appt['status'] === 'BOOKED' && strtotime((string) $appt['scheduled_at']) > time()) {
                $this->pdo->rollBack();
                return 'Record the interview result on or after its scheduled time.';
            }
            $this->appointments->recordResult($applicationId, $result);
            $this->applications->addHistory($applicationId, $app['status'], mb_substr('Interview result recorded: ' . trim($result), 0, 500), $staff['full_name'], (int) $staff['id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $this->audit($staff, 'Interview Result Recorded', 'application', $applicationId, $result);
        return null;
    }

    /** "Label (status); Label (status)" for required documents that still block, using the labels staff see on the page. */
    private function outstandingDocumentList(array $outstanding): string
    {
        return implode('; ', array_map(function ($item) {
            $upload = $item['uploaded_document'] ?? null;
            $expired = !empty($item['expires_at']) && $item['expires_at'] < date('Y-m-d');
            return $item['label'] . ' (' . ($expired ? 'expired' : ($upload ? document_status_label((string) $upload['verification_status']) : 'not uploaded')) . ')';
        }, $outstanding));
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
