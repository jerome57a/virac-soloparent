<?php
require_once __DIR__ . '/ApplicationService.php';
require_once __DIR__ . '/../models/RenewalReminder.php';
require_once __DIR__ . '/../models/RenewalRequirement.php';

/**
 * ID Renewal: a single confirmation form using the current released ID,
 * category renewal requirements and accepted, unexpired documents. The draft is
 * pre-filled from that record and saved to the account (application_drafts,
 * kind 'renewal'), so it survives logout. Identity fields (name, birthdate,
 * sex, category), civil status, emergency contact and children are retained;
 * contact details may be updated. On submit it creates an applications
 * row with type 'renewal' whose renewal_of_id points at the ID it renews.
 *
 * A renewal opens within the admin-configured window before expiry
 * (renewal_reminder_days, default 30) or any time after the ID expired.
 */
class RenewalService
{
    public const STEP_META = [
        1 => ['Renew ID', 'Confirm your current record and provide only the category renewal documents or expired replacements.'],
    ];
    /** Fields that must stay as on the ID being renewed (a change of these needs a new application). */
    public const LOCKED_FIELDS = ['fullName', 'birthdate', 'sex', 'categoryId'];

    private PDO $pdo;
    private Application $applications;
    private Document $documents;
    private ApplicationService $applicationService;
    private ApplicationDraft $drafts;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->applications = new Application($pdo);
        $this->documents = new Document($pdo);
        $this->applicationService = new ApplicationService($pdo);
        $this->drafts = new ApplicationDraft($pdo);
    }

    public function files(): DocumentValidationService
    {
        return $this->applicationService->files();
    }

    /** The applicant's current Solo Parent ID (their latest released record, new or renewal), with its children, or null. */
    public function sourceApplication(array $user): ?array
    {
        $current = $this->applications->latestReleased((int) $user['id']);
        return $current ? $this->applications->findWithChildren((int) $current['id']) + ['category_name' => $current['category_name']] : null;
    }

    /** A renewal already being processed -- only one may be in progress at a time. */
    public function renewalInProgress(array $user): ?array
    {
        return $this->applications->activeRenewal((int) $user['id']);
    }

    /**
     * When the ID can be renewed: ['open' => bool, 'expiresAt' => ?date, 'expired' => bool, 'opensOn' => ?date, 'windowDays' => int].
     * An ID without an expiry date (older records) can always be renewed.
     */
    public function window(array $source): array
    {
        $windowDays = max(1, (int) get_setting($this->pdo, 'renewal_reminder_days', '30'));
        $expiresAt = $source['id_expires_at'] ?: null;
        if (!$expiresAt) {
            return ['open' => true, 'expiresAt' => null, 'expired' => false, 'opensOn' => null, 'windowDays' => $windowDays];
        }
        $opensOn = date('Y-m-d', strtotime($expiresAt . " -$windowDays days"));
        return [
            'open' => date('Y-m-d') >= $opensOn,
            'expiresAt' => $expiresAt,
            'expired' => $expiresAt < date('Y-m-d'),
            'opensOn' => $opensOn,
            'windowDays' => $windowDays,
        ];
    }

    /** A new renewal draft, pre-filled from the ID being renewed (the renewal starts from the previous record). */
    public function freshDraft(array $source): array
    {
        $children = array_map(fn($c) => ['name' => $c['full_name'], 'birthdate' => $c['birthdate']], $source['children']);
        return [
            'sourceId' => (int) $source['id'],
            'step' => 1,
            'fullName' => $source['full_name'],
            'barangay' => $source['barangay'],
            'address' => $source['address'],
            'contact' => $source['mobile'],
            'email' => $source['email'],
            'birthdate' => $source['birthdate'],
            'sex' => $source['sex'],
            'civilStatus' => $source['civil_status'],
            'categoryId' => (int) $source['category_id'],
            'children' => $children ?: [['name' => '', 'birthdate' => '']],
            'documents' => [],
            'checkedDocuments' => [],
            'docErrors' => [],
            'consent' => false,
        ] + EmergencyContactService::draftFields($source);
    }

    /** The saved renewal draft for this ID, or a fresh pre-filled one (a draft started for an older ID is discarded). */
    public function getDraft(array $user, array $source): array
    {
        $saved = $this->drafts->find((int) $user['id'], 'renewal');
        if (!$saved || (int) ($saved['data']['sourceId'] ?? 0) !== (int) $source['id']) {
            if ($saved) {
                $this->clearDraft($user);
            }
            return ['contact' => (string) ($user['mobile'] ?? '')] + $this->freshDraft($source);
        }
        $draft = $saved['data'] + $this->freshDraft($source);
        foreach ($draft['documents'] as $key => $meta) {
            $draft['documents'][$key]['path'] = $this->draftDir((int) $user['id']) . '/' . basename((string) ($meta['stored_name'] ?? ''));
        }
        $draft['step'] = 1;
        $draft['contact'] = (string) ($user['mobile'] ?? ''); // the confirmed account number, never the form's
        return $this->lock($draft, $source);
    }

    /** Saves the draft; the first save is when the renewal application is created (audit-logged). */
    public function saveDraft(array $user, array $draft): void
    {
        if ($this->drafts->save((int) $user['id'], $draft, 'renewal')) {
            audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Application Created', 'user', (int) $user['id'], 'Renewal application draft started');
        }
    }

    public function clearDraft(array $user): void
    {
        $this->drafts->delete((int) $user['id'], 'renewal');
        $this->files()->deleteDir($this->draftDir((int) $user['id']));
    }

    public function draftDir(int $userId): string
    {
        return STORAGE_PATH . '/drafts/renewal_' . $userId;
    }

    /** Only category renewal documents and expired replacements; no full new-application checklist. */
    public function documentRequirements(array $source): array
    {
        return (new RenewalRequirement($this->pdo))->plan($source)['fresh'];
    }

    /** Merges the step's permitted fields from $post into the draft; locked fields always stay as on the previous record. */
    public function collectStepInput(int $step, array $draft, array $post, array $source): array
    {
        foreach (['barangay','address','email'] as $key) {
            if (isset($post[$key])) {
                $draft[$key] = is_string($post[$key]) ? mb_substr(trim($post[$key]),0,500) : '';
            }
        }
        $draft['civilStatus'] = $source['civil_status'];
        $draft = array_replace($draft,EmergencyContactService::draftFields($source));
        {
            $allowed = array_column($this->documentRequirements($source), 'doc_key');
            $selected = is_array($post['checklist'] ?? null) ? array_values(array_filter($post['checklist'], 'is_string')) : [];
            $draft['checkedDocuments'] = array_fill_keys(array_values(array_intersect($allowed, $selected)), true);
            $draft['consent'] = !empty($post['consent']);
            $draft['children'] = array_map(fn($child) => ['name'=>$child['full_name'],'birthdate'=>$child['birthdate']],$source['children']);
            $draft['step'] = 1;
        }
        return $this->lock($draft, $source);
    }

    public function validateStep(int $step, array $draft): array
    {
        $errors = [];
        $source = $this->applications->findWithChildren((int) ($draft['sourceId'] ?? 0));
        if (!$source) {
            return ['renewal'=>'The existing ID record was not found.'];
        }
        $plan = (new RenewalRequirement($this->pdo))->plan($source);
        if (!$plan['configured']) {
            $errors['requirements'] = 'Renewal requirements for your category are not configured yet. Contact MSWDO.';
        }
        foreach (['barangay'=>100,'address'=>255] as $key=>$max) {
            if (trim((string) ($draft[$key] ?? '')) === '' || mb_strlen($draft[$key]) > $max) {
                $errors[$key] = 'Enter a valid ' . $key . ' (up to ' . $max . ' characters).';
            }
        }
        if (!is_valid_ph_mobile((string) ($draft['contact'] ?? ''))) {
            $errors['contact'] = 'Confirm a valid mobile number on My Profile.';
        }
        if (!empty($draft['email']) && (!filter_var($draft['email'],FILTER_VALIDATE_EMAIL) || mb_strlen($draft['email']) > 150)) {
            $errors['email'] = 'Enter a valid email address or leave it blank.';
        }
        foreach ($plan['fresh'] as $requirement) {
            if (empty($draft['checkedDocuments'][$requirement['doc_key']])) {
                $errors['documents'] = 'Confirm availability of each required renewal document.';
            }
        }
        if (empty($draft['consent'])) {
            $errors['consent'] = 'Confirm the renewal declaration before submitting.';
        }
        return $errors;
    }

    /** The first step (1-4) with errors, or null if the whole renewal is valid. */
    public function firstInvalidStep(array $draft): ?array
    {
        $errors = $this->validateStep(1,$draft);
        return $errors ? ['step'=>1,'errors'=>$errors] : null;
    }

    /** What the applicant changed compared with the ID being renewed: field label => [old, new]. */
    public function changes(array $source, array $draft): array
    {
        return self::diff($source, [
            'barangay' => $draft['barangay'], 'address' => $draft['address'], 'mobile' => normalize_mobile($draft['contact']),
            'email' => $draft['email'], 'civil_status' => $draft['civilStatus'],
            'children' => array_map(fn($c) => ['full_name' => $c['name'], 'birthdate' => $c['birthdate']], $draft['children']),
        ]);
    }

    /** Field label => [previous, new] for the permitted fields that differ between two records. */
    public static function diff(array $previous, array $current): array
    {
        $labels = ['barangay' => 'Barangay', 'address' => 'Address', 'mobile' => 'Mobile number', 'email' => 'Email', 'civil_status' => 'Civil status'];
        $changes = [];
        foreach ($labels as $field => $label) {
            if ((string) $previous[$field] !== (string) $current[$field]) {
                $changes[$label] = [(string) $previous[$field], (string) $current[$field]];
            }
        }
        $kids = fn(array $list) => implode('; ', array_map(fn($c) => $c['full_name'] . ' (' . $c['birthdate'] . ')', $list));
        if ($kids($previous['children']) !== $kids($current['children'])) {
            $changes['Children'] = [$kids($previous['children']), $kids($current['children'])];
        }
        return $changes;
    }

    /**
     * Creates the renewal (linked to the ID it renews), its children and
     * documents from the draft. Returns the new reference number.
     */
    public function submit(array $user, array $source, array $draft): string
    {
        if ($this->renewalInProgress($user)) {
            throw new DomainException('You already have a renewal in progress.');
        }
        if (!$this->window($source)['open']) {
            throw new DomainException('This ID is not due for renewal yet.');
        }
        $draft = $this->lock($draft, $source);
        $draft['contact'] = (string) $user['mobile'];
        $draft['children'] = array_map(fn($child) => ['name'=>$child['full_name'],'birthdate'=>$child['birthdate']],$source['children']);
        if ($this->firstInvalidStep($draft)) {
            throw new DomainException('Check the renewal details and required documents before submitting.');
        }
        $fields = [
            'full_name' => $source['full_name'], 'birthdate' => $source['birthdate'], 'sex' => $source['sex'], 'category_id' => $source['category_id'],
            'barangay' => $draft['barangay'], 'address' => $draft['address'], 'mobile' => normalize_mobile($draft['contact']),
            'email' => $draft['email'], 'civil_status' => $draft['civilStatus'],
        ] + EmergencyContactService::applicationFields($draft);
        $documents = [];

        $this->pdo->beginTransaction();
        try {
            $owner = $this->pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
            $owner->execute([(int) $user['id']]);
            if ((int) $source['user_id'] !== (int) $user['id'] || $source['status'] !== 'ID Released' || $this->renewalInProgress($user)) {
                throw new DomainException('This ID cannot be renewed or a renewal is already in progress.');
            }
            $currentId = $this->applications->latestReleased((int) $user['id']);
            if (!$currentId || (int) $currentId['id'] !== (int) $source['id']) {
                throw new DomainException('Your current ID changed. Refresh the renewal page.');
            }
            $plan = (new RenewalRequirement($this->pdo))->plan($source);
            if (!$plan['configured'] || array_filter($plan['fresh'], fn($row)=>empty($draft['checkedDocuments'][$row['doc_key']]))) {
                throw new DomainException('Renewal requirements changed. Refresh and review them again.');
            }
            $refNumber = generate_reference_no($this->pdo);
            $applicationId = $this->applications->create($refNumber, (int) $user['id'], 'renewal', $fields, date('Y-m-d H:i:s'), (int) $source['id']);
            $this->pdo->prepare('UPDATE applications SET renewal_simplified=1 WHERE id=?')->execute([$applicationId]);
            foreach ($draft['children'] as $c) {
                $this->applications->addChild($applicationId, $c['name'], $c['birthdate']);
            }
            (new RenewalRequirement($this->pdo))->snapshot($applicationId,$plan,$draft['checkedDocuments'] ?? []);
            $this->applications->addHistory($applicationId, 'Pending Review', 'Renewal of ' . $source['reference_no'] . ' submitted online.', $user['full_name'], (int) $user['id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->clearDraft($user);
        (new QrSlipService($this->pdo))->issue(['id' => $applicationId, 'status' => 'Pending Review', 'id_expires_at' => null], (int) $user['id']);
        audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Renewal Submission', 'application', $applicationId, $refNumber . ' (renews ' . $source['reference_no'] . ')');
        $this->applicationService->auditDocuments($user, $applicationId, $documents);
        (new SMSService($this->pdo))->notifyRenewalSubmitted($applicationId, $fields['mobile'], $refNumber);
        create_notification($this->pdo, (int) $user['id'], 'submission', 'Your renewal request ' . $refNumber . ' has been received and is Pending Review.', $applicationId);
        (new NotificationService($this->pdo))->notifyStaff('new_renewal', 'New renewal request ' . $refNumber . ' from ' . $source['full_name'] . ' (renews ' . $source['reference_no'] . ').', $applicationId);
        return $refNumber;
    }

    /** Days before expiry that the reminder goes out (system_settings renewal_reminder_days, default 30). */
    public function reminderDays(): int
    {
        $days = (int) get_setting($this->pdo, 'renewal_reminder_days', '30');
        return $days > 0 ? $days : 30;
    }

    /**
     * IDs that should get a renewal reminder now: released IDs whose expiry is
     * within the reminder window -- or already past it by no more than the
     * same number of days (a catch-up if the job didn't run in time) -- of
     * active accounts, with no renewal already filed for them, and not yet
     * reminded for this expiry (or whose earlier attempt failed and may be
     * retried).
     */
    public function dueForReminder(): array
    {
        $days = $this->reminderDays();
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.user_id, a.reference_no, a.mobile, a.id_expires_at, DATEDIFF(a.id_expires_at, CURDATE()) AS days_left
             FROM applications a
             JOIN users u ON u.id = a.user_id AND u.is_active = 1
             WHERE a.status = 'ID Released'
               AND a.id_expires_at IS NOT NULL
               AND a.id_expires_at BETWEEN DATE_SUB(CURDATE(), INTERVAL ? DAY) AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
               AND NOT EXISTS (SELECT 1 FROM applications r WHERE r.renewal_of_id = a.id AND r.status <> 'Rejected')
               AND NOT EXISTS (SELECT 1 FROM renewal_reminders rr WHERE rr.application_id = a.id AND rr.id_expires_at = a.id_expires_at
                               AND (rr.status <> 'FAILED' OR rr.attempts >= ?))
             ORDER BY a.id_expires_at, a.id"
        );
        $stmt->execute([$days, $days, RenewalReminder::MAX_ATTEMPTS]);
        return $stmt->fetchAll();
    }

    /**
     * Sends each due renewal reminder: an in-app notification plus an SMS
     * (logged in sms_logs with the provider's result). Each reminder is
     * claimed before sending so overlapping runs never send twice, and one
     * failure doesn't stop the others -- it is recorded and retried by a
     * later run. $trigger ('cron' or 'manual') is stored on each reminder.
     * Returns ['checked' => int, 'sent' => refs, 'failed' => refs].
     */
    public function sendDueReminders(string $trigger = 'cron'): array
    {
        $due = $this->dueForReminder();
        $reminders = new RenewalReminder($this->pdo);
        $sms = new SMSService($this->pdo);
        $sent = [];
        $failed = [];
        foreach ($due as $app) {
            $daysLeft = (int) $app['days_left'];
            $reminderId = $reminders->claim((int) $app['id'], $app['id_expires_at'], (int) $app['user_id'], $daysLeft, $trigger);
            if ($reminderId === null) {
                continue; // another run has it
            }
            try {
                $when = date('M j, Y', strtotime($app['id_expires_at']));
                $message = $daysLeft >= 0
                    ? 'Your Solo Parent ID (' . $app['reference_no'] . ') expires on ' . $when . ($daysLeft === 0 ? ' (today)' : ' (in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ')') . '. Renew it online under ID Renewal.'
                    : 'Your Solo Parent ID (' . $app['reference_no'] . ') expired on ' . $when . '. Renew it online under ID Renewal to keep your benefits.';
                // Notification + SMS log together: a failed attempt leaves nothing behind, so a retry can't duplicate them.
                $this->pdo->beginTransaction();
                $notificationId = create_notification($this->pdo, (int) $app['user_id'], 'renewal_reminder', $message, (int) $app['id']);
                $delivery = $sms->notifyRenewalReminder((int) $app['id'], $app['mobile'], $app['reference_no'], $app['id_expires_at']);
                $reminders->markSent($reminderId, $delivery['status'], $notificationId);
                $this->pdo->commit();
                $sent[] = $app['reference_no'];
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                error_log('Renewal reminder for ' . $app['reference_no'] . ' failed: ' . $e->getMessage());
                $reminders->markFailed($reminderId, $e->getMessage());
                $failed[] = $app['reference_no'];
            }
        }
        return ['checked' => count($due), 'sent' => $sent, 'failed' => $failed];
    }
    /** Puts the locked identity fields back to the previous record's values. */
    private function lock(array $draft, array $source): array
    {
        $draft['fullName'] = $source['full_name'];
        $draft['birthdate'] = $source['birthdate'];
        $draft['sex'] = $source['sex'];
        $draft['categoryId'] = (int) $source['category_id'];
        return $draft;
    }
}
