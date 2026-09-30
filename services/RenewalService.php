<?php
require_once __DIR__ . '/ApplicationService.php';

/**
 * Single-step ID Renewal: a session-backed draft of re-uploaded standard
 * documents that, on submit, creates an applications row with
 * type='renewal' copying the details of the ID being renewed.
 */
class RenewalService
{
    private PDO $pdo;
    private Application $applications;
    private Document $documents;
    private ApplicationService $applicationService;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->applications = new Application($pdo);
        $this->documents = new Document($pdo);
        $this->applicationService = new ApplicationService($pdo);
    }

    public function files(): DocumentValidationService
    {
        return $this->applicationService->files();
    }

    /** The applicant's most recent released ID, or null if they have nothing to renew yet. */
    public function sourceApplication(array $user): ?array
    {
        return $this->applications->latestReleasedNew((int) $user['id']);
    }

    public function getDraft(): array
    {
        if (empty($_SESSION['renewal_draft'])) {
            $_SESSION['renewal_draft'] = ['documents' => [], 'docErrors' => []];
        }
        return $_SESSION['renewal_draft'];
    }

    public function saveDraft(array $draft): void
    {
        $_SESSION['renewal_draft'] = $draft;
    }

    public function clearDraft(): void
    {
        unset($_SESSION['renewal_draft']);
        $this->files()->deleteDir($this->tmpDir());
    }

    public function tmpDir(): string
    {
        return STORAGE_PATH . '/tmp/renewal_' . session_id();
    }

    /** Standard requirements only -- a renewal re-confirms residency/ID, not the original qualifying circumstance. */
    public function documentRequirements(): array
    {
        return $this->documents->standardRequirements();
    }

    /** Errors keyed like the draft's docErrors ('doc_<key>') plus 'consent'. */
    public function validate(array $draft, bool $consent): array
    {
        $errors = [];
        foreach ($this->documentRequirements() as $d) {
            if ($d['is_required'] && empty($draft['documents'][$d['doc_key']])) {
                $errors['doc_' . $d['doc_key']] = 'This document is required.';
            }
        }
        if (!$consent) {
            $errors['consent'] = 'Please confirm to submit.';
        }
        return $errors;
    }

    /**
     * Texts every applicant whose released ID expires within the configured
     * window (default 30 days) and hasn't been reminded yet, then records it
     * in renewal_reminders so the same expiry is never reminded twice. Run
     * daily by database/send_renewal_reminders.php or manually from admin
     * settings. Returns ['checked' => int, 'sent' => reference numbers].
     */
    public function sendDueReminders(): array
    {
        $days = (int) get_setting($this->pdo, 'renewal_reminder_days', '30');
        $days = $days > 0 ? $days : 30;

        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.user_id, a.reference_no, a.mobile, a.id_expires_at
             FROM applications a
             WHERE a.status = 'ID Released'
               AND a.id_expires_at IS NOT NULL
               AND a.id_expires_at >= CURDATE()
               AND a.id_expires_at <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
               AND NOT EXISTS (SELECT 1 FROM renewal_reminders r WHERE r.application_id = a.id)"
        );
        $stmt->execute([$days]);
        $due = $stmt->fetchAll();

        $sms = new SmsService($this->pdo);
        $sentRefs = [];
        foreach ($due as $app) {
            $sms->notifyRenewalReminder((int) $app['id'], $app['mobile'], $app['reference_no'], $app['id_expires_at']);
            create_notification(
                $this->pdo, (int) $app['user_id'], 'renewal_reminder',
                'Your Solo Parent ID (' . $app['reference_no'] . ') expires on ' . date('M j, Y', strtotime($app['id_expires_at'])) . '. Please renew soon.',
                (int) $app['id']
            );
            $this->pdo->prepare('INSERT INTO renewal_reminders (application_id) VALUES (?)')->execute([(int) $app['id']]);
            $sentRefs[] = $app['reference_no'];
        }

        return ['checked' => count($due), 'sent' => $sentRefs];
    }

    /** Creates the renewal application + document rows. Returns the new reference number. */
    public function submit(array $user, array $source, array $documents): string
    {
        $this->pdo->beginTransaction();
        try {
            $refNumber = generate_reference_no($this->pdo);
            $applicationId = $this->applications->create($refNumber, (int) $user['id'], 'renewal', $source, date('Y-m-d H:i:s'));
            $this->applicationService->storeDraftDocuments($applicationId, $documents);
            $this->applications->addHistory($applicationId, 'Pending Review', 'Renewal submitted online.', $source['full_name']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->clearDraft();

        audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Renewal Submission', 'application', $applicationId, $refNumber);
        $this->applicationService->auditDocuments($user, $applicationId, $documents);
        (new SmsService($this->pdo))->notifyRenewalSubmitted($applicationId, $source['mobile'], $refNumber);
        create_notification($this->pdo, (int) $user['id'], 'submission', 'Your renewal request ' . $refNumber . ' has been received and is Pending Review.', $applicationId);

        return $refNumber;
    }
}
