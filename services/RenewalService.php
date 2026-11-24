<?php
require_once __DIR__ . '/ApplicationService.php';
require_once __DIR__ . '/../models/RenewalReminder.php';
require_once __DIR__ . '/../models/RenewalRequirement.php';
require_once __DIR__ . '/SoloParentChildRules.php';

/**
 * ID Renewal: a single confirmation form using the current released ID,
 * category renewal requirements and accepted, unexpired documents. The draft is
 * pre-filled from that record and saved to the account (application_drafts,
 * kind 'renewal'), so it survives logout. Identity fields (name, birthdate,
 * sex, category) and the emergency contact are retained; contact details,
 * civil status and the children (with "child with a disability", RA 11861)
 * may be updated. On submit it creates an applications row with type
 * 'renewal' whose renewal_of_id points at the ID it renews. Every renewal
 * goes through an interview, like a new application, so MSWDO can confirm
 * the Solo Parent still qualifies.
 *
 * Timing: renewal opens on the expiration date (there is no early window: the Solo Parent
 * logs in on or after that day), and it closes renewal_grace_days (default 365) after
 * expiry, after which the Solo Parent applies again as a new application.
 */
class RenewalService
{
    public const STEP_META = [
        1 => ['Renew ID', 'Confirm your current record and provide only the category renewal documents or expired replacements.'],
    ];
    /** Fields that must stay as on the ID being renewed (a change of these needs a new application). */
    public const LOCKED_FIELDS = ['fullName', 'birthdate', 'sex', 'categoryId'];
    /** The extra document for a child over 22 with a disability (RA 11861). */
    public const DISABILITY_PROOF_KEY = SoloParentChildRules::PROOF_KEY;

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
     * When the ID can be renewed: from the expiration date ('open' and 'submitOpen' are both true from that day) until
     * renewal_grace_days after it; 'closed': that period ended (a new application is needed). Also expiresAt, expired,
     * opensOn and submitFrom (both = expiresAt), closesOn, graceDays. An ID without an expiry date (older records) can always be renewed.
     */
    public function window(array $source, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $graceDays = max(1, (int) get_setting($this->pdo, 'renewal_grace_days', '365'));
        $expiresAt = $source['id_expires_at'] ?: null;
        if (!$expiresAt) {
            return ['open' => true, 'submitOpen' => true, 'closed' => false, 'expiresAt' => null, 'expired' => false, 'opensOn' => null,
                'submitFrom' => null, 'closesOn' => null, 'graceDays' => $graceDays];
        }
        $closesOn = date('Y-m-d', strtotime($expiresAt . " +$graceDays days"));
        $closed = $today > $closesOn;
        return [
            'open' => !$closed && $today >= $expiresAt,
            'submitOpen' => !$closed && $today >= $expiresAt,
            'closed' => $closed,
            'expiresAt' => $expiresAt,
            'expired' => $expiresAt < $today,
            'opensOn' => $expiresAt,
            'submitFrom' => $expiresAt,
            'closesOn' => $closesOn,
            'graceDays' => $graceDays,
        ];
    }

    /**
     * The documents this renewal needs: the category renewal list and expired replacements (RenewalRequirement::plan), plus a PWD ID
     * or medical certificate when a child over 22 is marked as having a disability (RA 11861).
     */
    public function planFor(array $source, ?array $draft = null): array
    {
        $plan = (new RenewalRequirement($this->pdo))->plan($source);
        $children = $draft['children'] ?? array_map(fn($c) => ['birthdate' => $c['birthdate'], 'disability' => !empty($c['has_disability'])], $source['children'] ?? []);
        if (SoloParentChildRules::needsDisabilityProof($children, date('Y-m-d'))
            && !in_array(self::DISABILITY_PROOF_KEY, array_column($plan['fresh'], 'doc_key'), true)) {
            $plan['fresh'][] = ['doc_key' => self::DISABILITY_PROOF_KEY, 'label' => 'PWD ID or medical certificate of the child with a disability',
                'is_required' => 1, 'source_checklist_id' => null, 'reason' => 'Needed because a child over 22 is marked as having a disability (RA 11861).'];
        }
        return $plan;
    }

    /** A new renewal draft, pre-filled from the ID being renewed (the renewal starts from the previous record). */
    public function freshDraft(array $source): array
    {
        $children = array_map(fn($c) => ['name' => $c['full_name'], 'birthdate' => $c['birthdate'], 'disability' => !empty($c['has_disability'])], $source['children']);
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
            'hasPwdChild' => (bool) array_filter($children, fn($c) => !empty($c['disability'])),
            'documents' => [],
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
        // Same rule as ApplicationService::rebuiltStagedFiles(): only a stored name this system generates (32 hex
        // characters and a permitted extension) is kept, with its path rebuilt inside this applicant's draft folder.
        // Anything else is dropped, so the applicant simply uploads that document again.
        $staged = is_array($draft['documents'] ?? null) ? $draft['documents'] : [];
        $draft['documents'] = [];
        foreach ($staged as $key => $meta) {
            $storedName = is_array($meta) ? basename((string) ($meta['stored_name'] ?? '')) : '';
            if (!is_string($key) || !preg_match('/\A[a-f0-9]{32}\.(?:jpe?g|png|pdf)\z/', $storedName)) {
                continue;
            }
            $draft['documents'][$key] = ['stored_name' => $storedName, 'path' => $this->draftDir((int) $user['id']) . '/' . $storedName] + $meta;
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
    public function documentRequirements(array $source, ?array $draft = null): array
    {
        return $this->planFor($source, $draft)['fresh'];
    }

    /** Merges the step's permitted fields from $post into the draft; locked fields always stay as on the previous record. */
    public function collectStepInput(int $step, array $draft, array $post, array $source): array
    {
        foreach (['barangay','address','email'] as $key) {
            if (isset($post[$key])) {
                $draft[$key] = is_string($post[$key]) ? mb_substr(trim($post[$key]),0,500) : '';
            }
        }
        if (isset($post['civilStatus'])) {
            $draft['civilStatus'] = is_string($post['civilStatus']) ? mb_substr(trim($post['civilStatus']), 0, 50) : '';
        }
        $draft = array_replace($draft,EmergencyContactService::draftFields($source));
        if (isset($post['children']) && is_array($post['children'])) {
            // The children as the Solo Parent updated them: blank rows are dropped; "with a disability" is a tick.
            $children = [];
            foreach ($post['children'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = is_string($row['name'] ?? null) ? mb_substr(trim($row['name']), 0, 150) : '';
                $birthdate = is_string($row['birthdate'] ?? null) ? trim($row['birthdate']) : '';
                if ($name === '' && $birthdate === '') {
                    continue;
                }
                $children[] = ['name' => $name, 'birthdate' => $birthdate, 'disability' => !empty($row['disability'])];
            }
            // The Yes/No question: with the answer No the ticks are ignored (they are hidden on the form).
            // When the answer is not sent at all (an older client), it is taken from the ticks; an explicit No always wins.
            $hasPwd = isset($post['has_pwd_child']) ? $post['has_pwd_child'] === '1' : (bool) array_filter($children, fn($c) => !empty($c['disability']));
            if (!$hasPwd) {
                foreach ($children as &$kid) { $kid['disability'] = false; }
                unset($kid);
            }
            $draft['hasPwdChild'] = $hasPwd;
            $draft['children'] = $children;
        }
        {
            // Only documents this renewal actually requires stay in the draft; per-file upload errors are per request.
            $allowed = array_column($this->documentRequirements($source, $draft), 'doc_key');
            $draft['documents'] = array_intersect_key($draft['documents'] ?? [], array_flip($allowed));
            $draft['docErrors'] = [];
            $draft['consent'] = !empty($post['consent']);
            $draft['step'] = 1;
        }
        return $this->lock($draft, $source);
    }

    /**
     * Validates one uploaded file with the same rules as every other applicant document
     * (DocumentValidationService) and stages it in the renewal draft folder, replacing any
     * earlier file for that requirement. A rejected file leaves the earlier one in place and
     * records the reason in $draft['docErrors']. The file is not verified; staff review it after submission.
     */
    public function attachDocument(array $user, array $source, array $draft, string $docKey, array $file): array
    {
        $allowed = array_column($this->documentRequirements($source, $draft), 'doc_key');
        if (!in_array($docKey, $allowed, true)) {
            $draft['docErrors'][$docKey] = 'That document is not required for this renewal.';
            return $draft;
        }
        $draft = $this->files()->attachToDraft($draft, $docKey, $file, $this->draftDir((int) $user['id']), true); // renewal documents are image-only (JPG/JPEG/PNG)
        if (isset($draft['documents'][$docKey]['stored_name'])) {
            $draft['documents'][$docKey]['path'] = $this->draftDir((int) $user['id']) . '/' . basename((string) $draft['documents'][$docKey]['stored_name']);
        }
        return $draft;
    }

    public function validateStep(int $step, array $draft): array
    {
        $errors = [];
        $source = $this->applications->findWithChildren((int) ($draft['sourceId'] ?? 0));
        if (!$source) {
            return ['renewal'=>'The existing ID record was not found.'];
        }
        $plan = $this->planFor($source, $draft);
        if (!$plan['configured']) {
            $errors['requirements'] = 'Renewal requirements for your category are not configured yet. Contact MSWDO.';
        }
        if (!in_array((string) ($draft['civilStatus'] ?? ''), defined('CIVIL_STATUS_OPTIONS') ? CIVIL_STATUS_OPTIONS : ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'], true)) {
            $errors['civilStatus'] = 'Select your civil status.';
        }
        $children = $draft['children'] ?? [];
        if (!$children) {
            $errors['children'] = 'List at least one child living with you and depending on you.';
        }
        foreach ($children as $i => $child) {
            $n = $i + 1;
            if (trim((string) ($child['name'] ?? '')) === '') {
                $errors['children'] = 'Child ' . $n . ': enter the full name.';
                break;
            }
            if (SoloParentChildRules::age((string) ($child['birthdate'] ?? ''), date('Y-m-d')) === null) {
                $errors['children'] = 'Child ' . $n . ' (' . $child['name'] . '): enter a real birthdate that is not in the future.';
                break;
            }
        }
        if (!isset($errors['children']) && $children) {
            if (!SoloParentChildRules::anyQualifies($children, date('Y-m-d'))) {
                $errors['children'] = SoloParentChildRules::NONE_QUALIFIES;
            } elseif (!empty($draft['hasPwdChild']) && !array_filter($children, fn($c) => !empty($c['disability']))) {
                $errors['pwd'] = SoloParentChildRules::ANSWERED_YES_NO_TICK;
            }
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
        $labels = array_column($plan['fresh'], 'label', 'doc_key');
        foreach (($draft['docErrors'] ?? []) as $key => $message) {
            $errors['upload_' . $key] = ($labels[$key] ?? $key) . ': ' . $message;
        }
        $missing = [];
        foreach ($plan['fresh'] as $requirement) {
            $meta = $draft['documents'][$requirement['doc_key']] ?? null;
            if (!$meta || empty($meta['path']) || !is_file($meta['path'])) {
                $missing[] = $requirement['label'];
            }
        }
        if ($missing) {
            $errors['documents'] = 'Upload a file for each required renewal document. Still needed: ' . implode(', ', $missing) . '.';
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
            'children' => array_map(fn($c) => ['full_name' => $c['name'], 'birthdate' => $c['birthdate'], 'has_disability' => !empty($c['disability'])], $draft['children']),
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
        $kids = fn(array $list) => implode('; ', array_map(fn($c) => $c['full_name'] . ' (' . $c['birthdate'] . (!empty($c['has_disability']) ? ', with a disability' : '') . ')', $list));
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
        $window = $this->window($source);
        if ($window['closed']) {
            throw new DomainException('The renewal period for this ID has ended. Please apply again as a new application.');
        }
        if (!$window['submitOpen']) {
            throw new DomainException('Renewal opens on ' . date('F j, Y', strtotime((string) $window['submitFrom'])) . ', the day your ID expires. Log in on that day to renew.');
        }
        $draft = $this->lock($draft, $source);
        $draft['contact'] = (string) $user['mobile'];
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
            $plan = $this->planFor($source, $draft);
            // Every document this renewal requires must have an uploaded file still present in the draft.
            $fresh = array_column($plan['fresh'], 'doc_key');
            $documents = array_intersect_key($draft['documents'] ?? [], array_flip($fresh));
            if (!$plan['configured'] || count($documents) !== count($fresh)
                || array_filter($documents, fn($meta) => empty($meta['path']) || !is_file($meta['path']))) {
                throw new DomainException('Renewal requirements changed or a document file is missing. Refresh the page and upload the required documents again.');
            }
            $refNumber = generate_reference_no($this->pdo);
            $applicationId = $this->applications->create($refNumber, (int) $user['id'], 'renewal', $fields, date('Y-m-d H:i:s'), (int) $source['id']);
            // A renewal is no longer documents-only: it gets an interview like a new application (renewal_simplified stays 0).
            foreach ($draft['children'] as $c) {
                $this->applications->addChild($applicationId, $c['name'], $c['birthdate'], !empty($c['disability']));
            }
            // Uploaded files are recorded as FOR_VERIFICATION and their snapshot rows stay Pending: staff verify them.
            (new RenewalRequirement($this->pdo))->snapshot($applicationId,$plan,array_fill_keys(array_keys($documents),true));
            $this->applicationService->storeDraftDocuments($applicationId, $documents, $user);
            $this->applications->addHistory($applicationId, 'Pending Review', 'Renewal of ' . $source['reference_no'] . ' submitted online. An interview will confirm the Solo Parent still qualifies (RA 11861).', $user['full_name'], (int) $user['id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->clearDraft($user);
        audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Renewal Submission', 'application', $applicationId, $refNumber . ' (renews ' . $source['reference_no'] . ')');
        $this->applicationService->auditDocuments($user, $applicationId, $documents);
        (new SMSService($this->pdo))->notifyRenewalSubmitted($applicationId, $fields['mobile'], $refNumber);
        create_notification($this->pdo, (int) $user['id'], 'submission', 'Your renewal request ' . $refNumber . ' has been received and is Pending Review.', $applicationId);
        (new NotificationService($this->pdo))->notifyStaff('new_renewal', 'New renewal request ' . $refNumber . ' from ' . $source['full_name'] . ' (renews ' . $source['reference_no'] . ').', $applicationId);
        try {
            // Save the system's recommendation as it stood at submission; the renewal is already saved, so a failure here is only logged.
            require_once __DIR__ . '/AutoVerificationService.php';
            (new AutoVerificationService($this->pdo))->snapshot($applicationId, 'submitted');
        } catch (Throwable $e) {
            error_log('Automated verification result not saved for the renewal: ' . $e->getMessage());
        }
        return $refNumber;
    }

    /**
     * IDs whose expiry-day SMS is due now: released IDs that expire TODAY (Manila date; the date MSWDO staff entered from the card),
     * of active accounts, with no renewal already filed, and not yet sent for this expiry (a FAILED attempt is retried the same day,
     * up to RenewalReminder::MAX_ATTEMPTS). Nothing is sent before the expiry date, and nothing after it: a day the system was not
     * running is shown to staff instead (expirySmsNotSent). An old advance reminder for the same expiry does not count.
     */
    public function dueForReminder(?string $today = null): array
    {
        $today ??= self::manilaToday();
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.user_id, a.reference_no, a.mobile, a.id_expires_at, 0 AS days_left
             FROM applications a
             JOIN users u ON u.id = a.user_id AND u.is_active = 1
             WHERE a.status = 'ID Released'
               AND a.id_expires_at = ?
               AND NOT EXISTS (SELECT 1 FROM applications r WHERE r.renewal_of_id = a.id AND r.status <> 'Rejected')
               AND NOT EXISTS (SELECT 1 FROM renewal_reminders rr WHERE rr.application_id = a.id AND rr.id_expires_at = a.id_expires_at
                               AND rr.days_before <= 0 AND (rr.status <> 'FAILED' OR rr.attempts >= ?))
             ORDER BY a.id"
        );
        $stmt->execute([$today, RenewalReminder::MAX_ATTEMPTS]);
        return $stmt->fetchAll();
    }

    /**
     * Sends each due expiry-day message: an in-app notification (once) plus one SMS (logged in sms_logs). Each is claimed before
     * sending so overlapping runs never send twice; an SMS that fails is marked FAILED and tried again by a later run the same day.
     * $trigger ('cron' or 'manual') is stored on each reminder. Returns ['checked' => int, 'sent' => refs, 'failed' => refs].
     */
    public function sendDueReminders(string $trigger = 'cron', ?string $today = null): array
    {
        $due = $this->dueForReminder($today);
        $sent = [];
        $failed = [];
        foreach ($due as $app) {
            $smsIgnored = null;
            $result = $this->sendExpiryMessage($app, $trigger, $smsIgnored);
            if ($result === 'sent') {
                $sent[] = $app['reference_no'];
            } elseif ($result === 'failed') {
                $failed[] = $app['reference_no'];
            }
        }
        return ['checked' => count($due), 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * Released IDs that expired in the last $days days (before today) and never got their expiry-day SMS: the system was not
     * running that day, or every attempt failed. Staff decide whether to press Send now; the system never sends it by itself.
     */
    public function expirySmsNotSent(int $days = 30, ?string $today = null): array
    {
        $today ??= self::manilaToday();
        $from = date('Y-m-d', strtotime($today . ' -' . max(1, $days) . ' days'));
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.user_id, a.reference_no, a.full_name, a.mobile, a.id_expires_at
             FROM applications a
             JOIN users u ON u.id = a.user_id AND u.is_active = 1
             WHERE a.status = 'ID Released' AND a.id_expires_at < ? AND a.id_expires_at >= ?
               AND NOT EXISTS (SELECT 1 FROM applications r WHERE r.renewal_of_id = a.id AND r.status <> 'Rejected')
               AND NOT EXISTS (SELECT 1 FROM renewal_reminders rr WHERE rr.application_id = a.id AND rr.id_expires_at = a.id_expires_at
                               AND rr.days_before <= 0 AND rr.status IN ('SENT', 'SENDING'))
             ORDER BY a.id_expires_at, a.id"
        );
        $stmt->execute([$today, $from]);
        return $stmt->fetchAll();
    }

    /** Staff pressed Send now for one ID in expirySmsNotSent(). Returns [error or null, SMS result or null]. */
    public function sendExpirySmsNow(array $staff, int $applicationId): array
    {
        $row = null;
        foreach ($this->expirySmsNotSent(366) as $candidate) {
            if ((int) $candidate['id'] === $applicationId) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null) {
            return ['This expiry SMS was already sent or is no longer needed. Refresh the page.', null];
        }
        $smsResult = null;
        $result = $this->sendExpiryMessage($row + ['days_left' => (int) floor((strtotime((string) $row['id_expires_at']) - strtotime(self::manilaToday())) / 86400)], 'manual', $smsResult);
        if ($result === 'busy') {
            return ['This SMS is being sent right now. Refresh the page in a moment.', null];
        }
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'ID Expiry SMS Sent Manually', 'application', $applicationId,
            $row['reference_no'] . ' · expired ' . $row['id_expires_at'] . ' · ' . (($smsResult['status'] ?? '') ?: 'not sent'));
        return [null, $smsResult];
    }

    /** One expiry message (in-app once, SMS once). Returns 'sent', 'failed' or 'busy'; $smsResult receives the SMS outcome. */
    private function sendExpiryMessage(array $app, string $trigger, ?array &$smsResult): string
    {
        $reminders = new RenewalReminder($this->pdo);
        $daysLeft = (int) ($app['days_left'] ?? 0);
        $reminderId = $trigger === 'manual'
            ? $reminders->claimManual((int) $app['id'], $app['id_expires_at'], (int) $app['user_id'], $daysLeft)
            : $reminders->claim((int) $app['id'], $app['id_expires_at'], (int) $app['user_id'], $daysLeft, $trigger);
        if ($reminderId === null) {
            return 'busy'; // another run has it, or it was already sent
        }
        try {
            $when = date('M j, Y', strtotime($app['id_expires_at']));
            $message = $daysLeft >= 0
                ? 'Your Solo Parent ID (' . $app['reference_no'] . ') expires today (' . $when . '). You can renew online starting today, or visit MSWDO.'
                : 'Your Solo Parent ID (' . $app['reference_no'] . ') expired on ' . $when . '. Please renew online or visit MSWDO.';
            $this->pdo->beginTransaction();
            $notificationId = $reminders->notificationFor($reminderId)
                ?? create_notification($this->pdo, (int) $app['user_id'], 'renewal_reminder', $message, (int) $app['id']);
            $smsResult = (new SMSService($this->pdo))->notifyRenewalReminder((int) $app['id'], $app['mobile'], $app['reference_no'], $app['id_expires_at']);
            if (($smsResult['status'] ?? '') === 'FAILED') {
                $reminders->markSmsFailed($reminderId, 'FAILED', $notificationId, (string) ($smsResult['error'] ?? 'SMS failed'));
                $this->pdo->commit();
                return 'failed';
            }
            $reminders->markSent($reminderId, (string) ($smsResult['status'] ?? ''), $notificationId);
            $this->pdo->commit();
            return 'sent';
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('ID expiry message for ' . $app['reference_no'] . ' failed: ' . $e->getMessage());
            $reminders->markFailed($reminderId, $e->getMessage());
            return 'failed';
        }
    }

    /** Today's date in Manila (the expiry dates are Manila calendar dates, whatever the database server's time zone). */
    public static function manilaToday(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
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
