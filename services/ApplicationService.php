<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/DocumentValidationService.php';
require_once __DIR__ . '/../includes/SmsService.php';

/**
 * The 4-step Apply Online wizard: a session-backed draft (with uploaded
 * files held in a per-session tmp folder) that only becomes real DB rows
 * on final submission.
 */
class ApplicationService
{
    public const STEP_META = [
        1 => ['Personal Info', 'Tell us about yourself. Fields marked * are required.'],
        2 => ['Children', 'List each child in your sole care.'],
        3 => ['Documents', 'Upload clear copies of your requirements.'],
        4 => ['Review', 'Check your details before submitting.'],
    ];

    private PDO $pdo;
    private Application $applications;
    private Document $documents;
    private DocumentValidationService $files;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->applications = new Application($pdo);
        $this->documents = new Document($pdo);
        $this->files = new DocumentValidationService($pdo);
    }

    public function files(): DocumentValidationService
    {
        return $this->files;
    }

    // ---- Draft ----

    public function freshDraft(array $user): array
    {
        return [
            'step' => 1,
            'fullName' => $user['full_name'] ?? '',
            'barangay' => '',
            'address' => '',
            'contact' => '',
            'email' => $user['email'] ?? '',
            'birthdate' => '',
            'sex' => '',
            'civilStatus' => '',
            'categoryId' => null,
            'children' => [['name' => '', 'birthdate' => '']],
            'documents' => [],
            'docErrors' => [],
            'consent' => false,
        ];
    }

    public function getDraft(array $user): array
    {
        if (empty($_SESSION['apply_draft'])) {
            $_SESSION['apply_draft'] = $this->freshDraft($user);
        }
        return $_SESSION['apply_draft'];
    }

    public function saveDraft(array $draft): void
    {
        $_SESSION['apply_draft'] = $draft;
    }

    public function clearDraft(): void
    {
        unset($_SESSION['apply_draft']);
        $this->files->deleteDir($this->tmpDir());
    }

    public function tmpDir(): string
    {
        return STORAGE_PATH . '/tmp/' . session_id();
    }

    /** Merges the fields belonging to $step out of $post into the draft (no validation). */
    public function collectStepInput(int $step, array $draft, array $post): array
    {
        if ($step === 1) {
            foreach (['fullName', 'barangay', 'address', 'contact', 'email', 'birthdate', 'sex', 'civilStatus'] as $f) {
                $draft[$f] = trim((string) ($post[$f] ?? ''));
            }
            $draft['categoryId'] = isset($post['categoryId']) && $post['categoryId'] !== '' ? (int) $post['categoryId'] : null;
        }
        if ($step === 2) {
            $children = [];
            $names = $post['child_name'] ?? [];
            $dates = $post['child_birthdate'] ?? [];
            $count = max(count($names), count($dates));
            for ($i = 0; $i < $count; $i++) {
                $children[] = [
                    'name' => trim((string) ($names[$i] ?? '')),
                    'birthdate' => trim((string) ($dates[$i] ?? '')),
                ];
            }
            $draft['children'] = $children ?: [['name' => '', 'birthdate' => '']];
        }
        if ($step === 4) {
            $draft['consent'] = !empty($post['consent']);
        }
        return $draft;
    }

    public function validateStep(int $step, array $draft): array
    {
        $errors = [];
        $today = today_iso();

        if ($step === 1) {
            $fullName = trim($draft['fullName']);
            if ($fullName === '') {
                $errors['fullName'] = 'Enter your full name.';
            } elseif (count(preg_split('/\s+/', $fullName)) < 2) {
                $errors['fullName'] = 'Enter your first and last name.';
            }
            if (trim($draft['barangay']) === '') {
                $errors['barangay'] = 'Enter your barangay.';
            }
            if (trim($draft['address']) === '') {
                $errors['address'] = 'Enter your complete address.';
            }
            $phone = normalize_mobile($draft['contact']);
            if ($phone === '') {
                $errors['contact'] = 'Enter your mobile number.';
            } elseif (!is_valid_ph_mobile($phone)) {
                $errors['contact'] = 'Use a valid PH mobile number, e.g. 09171234567.';
            }
            if (trim($draft['email']) === '') {
                $errors['email'] = 'Enter your email.';
            } elseif (!filter_var(trim($draft['email']), FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Enter a valid email address.';
            }
            if ($draft['birthdate'] === '') {
                $errors['birthdate'] = 'Select your birthdate.';
            } elseif ($draft['birthdate'] > $today) {
                $errors['birthdate'] = 'Birthdate cannot be in the future.';
            }
            if (!in_array($draft['sex'], ['Male', 'Female'], true)) {
                $errors['sex'] = 'Select an option.';
            }
            if (!in_array($draft['civilStatus'], CIVIL_STATUS_OPTIONS, true)) {
                $errors['civilStatus'] = 'Select your civil status.';
            }
            if (!$draft['categoryId'] || !$this->categoryExists((int) $draft['categoryId'])) {
                $errors['circumstance'] = 'Select your solo parent category.';
            }
        }

        if ($step === 2) {
            foreach ($draft['children'] as $i => $c) {
                if (trim($c['name']) === '') {
                    $errors['cn' . $i] = "Enter the child's full name.";
                }
                if ($c['birthdate'] === '') {
                    $errors['cd' . $i] = 'Select a birthdate.';
                } elseif ($c['birthdate'] > $today) {
                    $errors['cd' . $i] = 'Birthdate cannot be in the future.';
                }
            }
        }

        if ($step === 3) {
            foreach ($this->documents->requirementsFor($draft['categoryId']) as $d) {
                if ($d['is_required'] && empty($draft['documents'][$d['doc_key']])) {
                    $errors['doc_' . $d['doc_key']] = 'This document is required.';
                }
            }
        }

        if ($step === 4 && empty($draft['consent'])) {
            $errors['consent'] = 'Please confirm to submit.';
        }

        return $errors;
    }

    /** First step (1-4) that fails validation, with its errors, or null if the whole draft is valid. */
    public function firstInvalidStep(array $draft): ?array
    {
        for ($s = 1; $s <= 4; $s++) {
            $errors = $this->validateStep($s, $draft);
            if ($errors) {
                return ['step' => $s, 'errors' => $errors];
            }
        }
        return null;
    }

    private function categoryExists(int $categoryId): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM categories WHERE id = ?');
        $stmt->execute([$categoryId]);
        return (bool) $stmt->fetch();
    }

    // ---- Submission ----

    /** Creates the application + children + document rows from a valid draft. Returns the reference number. */
    public function submit(array $user, array $draft): string
    {
        $this->pdo->beginTransaction();
        try {
            $refNumber = generate_reference_no($this->pdo);
            $applicationId = $this->applications->create($refNumber, (int) $user['id'], 'new', [
                'full_name' => $draft['fullName'],
                'barangay' => $draft['barangay'],
                'address' => $draft['address'],
                'mobile' => normalize_mobile($draft['contact']),
                'email' => $draft['email'],
                'birthdate' => $draft['birthdate'],
                'sex' => $draft['sex'],
                'civil_status' => $draft['civilStatus'],
                'category_id' => $draft['categoryId'],
            ], date('Y-m-d H:i:s'));

            foreach ($draft['children'] as $c) {
                $this->applications->addChild($applicationId, $c['name'], $c['birthdate']);
            }
            $this->storeDraftDocuments($applicationId, $draft['documents']);
            $this->applications->addHistory($applicationId, 'Pending Review', 'Application submitted online.', $draft['fullName']);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->clearDraft();

        audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Application Submission', 'application', $applicationId, $refNumber);
        $this->auditDocuments($user, $applicationId, $draft['documents']);
        (new SmsService($this->pdo))->notifySubmitted($applicationId, $draft['contact'], $refNumber);
        create_notification($this->pdo, (int) $user['id'], 'submission', 'Your application ' . $refNumber . ' has been received and is Pending Review.', $applicationId);

        return $refNumber;
    }

    /** Moves a draft's tmp files into the application's permanent folder and records them. Call inside a transaction. */
    public function storeDraftDocuments(int $applicationId, array $documents): void
    {
        $destDir = STORAGE_PATH . '/' . $applicationId;
        if (!is_dir($destDir) && !mkdir($destDir, 0750, true) && !is_dir($destDir)) {
            throw new RuntimeException('Could not create storage folder for uploaded documents.');
        }
        foreach ($documents as $docKey => $meta) {
            if (!@rename($meta['path'], $destDir . '/' . $meta['stored_name'])) {
                throw new RuntimeException('Could not store an uploaded document. Please try again.');
            }
            $this->documents->create($applicationId, $docKey, $meta, $applicationId . '/' . $meta['stored_name']);
        }
    }

    public function auditDocuments(array $user, int $applicationId, array $documents): void
    {
        foreach ($documents as $docKey => $meta) {
            audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Document Upload', 'application', $applicationId, $docKey . ' — ' . $meta['original_name']);
        }
    }
}
