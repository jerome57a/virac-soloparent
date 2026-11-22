<?php
require_once __DIR__ . '/SoloParentChildRules.php';
/**
 * Automated verification checks for a submitted application. The system checks the form data and the uploaded files
 * against fixed rules and reports, per check, PASS, FLAG (a person should look) or FAIL (must be fixed), with the
 * reason in plain words. It RECOMMENDS only: the MSWD social worker still decides each document and the application.
 *
 * Verdict: 'ready' (every check passed), 'review' (at least one flag) or 'fix' (at least one failure).
 *
 * evaluate() is a pure function of the data it is given (no database, no files), so every rule can be tested offline.
 * forApplication() loads that data for one application and supplies the real image-quality check.
 */
class AutoVerificationService
{
    public const PASS = 'pass';
    public const FLAG = 'flag';
    public const FAIL = 'fail';

    /** RA 11861: a dependent is not more than 22 years old; an older child qualifies only with a disability (SoloParentChildRules). */
    public const CHILD_MAX_AGE = SoloParentChildRules::MAX_AGE;
    public const MIN_APPLICANT_AGE = 18;
    public const MAX_APPLICANT_AGE = 100;
    /** An applicant cannot have been a parent before this age. */
    public const MIN_PARENT_AGE = 12;
    /** Image rules: shortest side in pixels, sharpness (variance of the Laplacian on a 400px-wide copy), mean brightness 0-255. */
    public const MIN_IMAGE_SIDE = 600;
    public const MIN_SHARPNESS = 100.0;
    public const MIN_BRIGHTNESS = 40.0;
    public const MAX_BRIGHTNESS = 250.0;
    /** OCR name matching: the share of a name's words that must appear on the document, and the least text that counts as readable. */
    public const NAME_MATCH_MIN = 0.5;
    public const OCR_MIN_CHARS = 15;
    private const OCR_TIMEOUT_SECONDS = 25;
    /** All the documents of one application together: after this many seconds the rest are not read. */
    public const OCR_TOTAL_BUDGET_SECONDS = 20;
    /** Words in a name that say nothing about who it is. Ignored while other words remain. */
    private const NAME_FILLER = ['DE', 'DEL', 'DELA', 'LA', 'LOS', 'Y', 'JR', 'SR', 'II', 'III'];

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /**
     * Loads one application's data and evaluates it.
     *
     * @return array{verdict: string, checks: array<int, array>, counts: array{pass: int, flag: int, fail: int}}
     */
    public function forApplication(array $app, array $children, array $checklist, bool $forApplicant = false, bool $withOcr = false): array
    {
        $sameApplication = [];
        $otherApplications = [];
        $shas = [];
        foreach ($checklist as $row) {
            $doc = $row['uploaded_document'] ?? null;
            if ($doc && !empty($doc['sha256'])) {
                $sameApplication[$doc['sha256']][] = $row['doc_key'];
                $shas[$doc['sha256']] = true;
            }
        }
        // Comparisons with other people's applications are for staff only: an applicant is never told about other accounts.
        if ($this->pdo && $shas && !$forApplicant) {
            $marks = implode(',', array_fill(0, count($shas), '?'));
            $stmt = $this->pdo->prepare("SELECT d.sha256, COUNT(*) FROM application_documents d JOIN applications a ON a.id = d.application_id WHERE d.sha256 IN ($marks) AND a.user_id <> ? GROUP BY d.sha256");
            $stmt->execute([...array_keys($shas), (int) $app['user_id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$sha, $count]) {
                $otherApplications[$sha] = (int) $count;
            }
        }
        $samePerson = 0;
        if ($this->pdo && !$forApplicant) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM applications WHERE user_id <> ? AND birthdate = ? AND LOWER(TRIM(full_name)) = LOWER(TRIM(?)) AND status <> 'Rejected'");
            $stmt->execute([(int) $app['user_id'], $app['birthdate'], $app['full_name']]);
            $samePerson = (int) $stmt->fetchColumn();
        }
        if ($this->pdo && ($app['type'] ?? '') === 'renewal' && !empty($app['renewal_of_id']) && !isset($app['previous_civil_status'])) {
            $previous = $this->pdo->prepare('SELECT civil_status FROM applications WHERE id = ?');
            $previous->execute([(int) $app['renewal_of_id']]);
            $value = $previous->fetchColumn();
            if ($value !== false) {
                $app['previous_civil_status'] = (string) $value;
            }
        }
        return self::evaluate($app, $children, $checklist, [
            'sameApplication' => $sameApplication, 'otherApplications' => $otherApplications, 'samePerson' => $samePerson,
        ], fn(array $doc): ?array => $this->imageQuality($doc), null,
            $withOcr && self::ocrAvailable() ? $this->budgetedOcr() : null);
    }

    /**
     * Evaluates a submitted application from the database and saves the result (see ApplicationAutoCheck::record).
     * $trigger: 'submitted' or 'review'. Returns the result, or null when the application cannot be found.
     */
    public function snapshot(int $applicationId, string $trigger): ?array
    {
        if (!$this->pdo) {
            return null;
        }
        require_once __DIR__ . '/../models/Application.php';
        require_once __DIR__ . '/../models/Document.php';
        require_once __DIR__ . '/../models/ApplicationAutoCheck.php';
        $applications = new Application($this->pdo);
        $app = $applications->findForReview($applicationId);
        if (!$app) {
            return null;
        }
        $result = $this->forApplication($app, $applications->children($applicationId),
            (new Document($this->pdo))->checklistForReview($applicationId, $app['category_id'] !== null ? (int) $app['category_id'] : null, ($app['type'] ?? '') === 'renewal'),
            false, true); // reading the documents' text (OCR) is slow, so it is done here, once, and the result is saved
        (new ApplicationAutoCheck($this->pdo))->record($applicationId, $result, $trigger);
        return $result;
    }

    /**
     * The same checks on an application that is still a draft (the applicant's review step), before anything is submitted.
     * Staged files are read from the applicant's own draft folder; nothing about other applicants is compared or shown.
     *
     * @param array $draft   the wizard draft (fullName, birthdate, contact, children, documents, emergency fields)
     * @param array $docDefs the category's requirements (doc_key, label, is_required)
     */
    public function forDraft(int $userId, array $draft, array $docDefs): array
    {
        $app = [
            'type' => 'new', 'user_id' => $userId, 'full_name' => trim((string) ($draft['fullName'] ?? '')),
            'birthdate' => (string) ($draft['birthdate'] ?? ''), 'mobile' => (string) ($draft['contact'] ?? ''),
            'emergency_contact_name' => (string) ($draft['emergencyName'] ?? ''), 'emergency_contact_phone' => (string) ($draft['emergencyPhone'] ?? ''),
            'eligibility_status' => 'Not Assessed',
        ];
        $children = [];
        foreach (($draft['children'] ?? []) as $child) {
            if (trim((string) ($child['name'] ?? '')) === '' && trim((string) ($child['birthdate'] ?? '')) === '') {
                continue;
            }
            $children[] = ['full_name' => (string) ($child['name'] ?? ''), 'birthdate' => (string) ($child['birthdate'] ?? ''), 'has_disability' => !empty($child['disability'])];
        }
        $checklist = [];
        foreach ($docDefs as $def) {
            $row = ['doc_key' => $def['doc_key'], 'label' => $def['label'], 'is_required' => (int) !empty($def['is_required'])];
            $meta = $draft['documents'][$def['doc_key']] ?? null;
            if (is_array($meta) && !empty($meta['path']) && defined('STORAGE_PATH') && str_starts_with((string) $meta['path'], STORAGE_PATH)) {
                $row['uploaded_document'] = [
                    'mime' => (string) ($meta['mime'] ?? ''), 'sha256' => (string) ($meta['sha256'] ?? ''),
                    'stored_path' => ltrim(substr((string) $meta['path'], strlen(STORAGE_PATH)), '/\\'),
                ];
            }
            $checklist[] = $row;
        }
        return $this->forApplication($app, $children, $checklist, true);
    }

    /**
     * The rules, over plain data.
     *
     * @param array $app        the application row (birthdate, full_name, mobile, type, eligibility_status, emergency_contact_*)
     * @param array $children   rows with full_name and birthdate
     * @param array $checklist  Document::checklistForReview() rows (is_required, label, doc_key, uploaded_document?)
     * @param array $duplicates ['sameApplication' => sha => [doc_key...], 'otherApplications' => sha => count, 'samePerson' => int]
     * @param callable $quality fn(array $uploadedDocument): ?array{issues: string[]} -- null when the file cannot be measured
     */
    public static function evaluate(array $app, array $children, array $checklist, array $duplicates, callable $quality, ?string $today = null, ?callable $ocr = null): array
    {
        $today ??= date('Y-m-d');
        $checks = [];
        $add = function (string $key, string $scope, string $result, string $label, string $message, array $extra = []) use (&$checks): void {
            $checks[] = ['key' => $key, 'scope' => $scope, 'result' => $result, 'label' => $label, 'message' => $message] + $extra;
        };

        // Required documents are all uploaded.
        $missing = [];
        $onPaper = [];
        foreach ($checklist as $row) {
            if (empty($row['is_required']) || !empty($row['uploaded_document'])) {
                continue;
            }
            if (($row['verification_status'] ?? '') === 'Verified') {
                continue; // A renewal keeps still-valid evidence staff already verified earlier: no new upload needed.
            }
            if (!empty($row['applicant_has'])) {
                // An application from before uploads existed: the applicant declared they hold the paper document and staff check
                // it in person. There is no file for the system to check, so this is neither missing nor verified.
                $onPaper[] = (string) ($row['label'] ?? $row['doc_key']);
                continue;
            }
            $missing[] = (string) ($row['label'] ?? $row['doc_key']);
        }
        if ($missing) {
            foreach ($missing as $label) {
                $add('required_documents', 'application', self::FAIL, 'Required document', 'Missing: ' . $label . '.');
            }
        } else {
            $add('required_documents', 'application', self::PASS, 'Required documents', 'Every required document is uploaded' . ($onPaper ? ' or declared on paper.' : '.'));
        }
        if ($onPaper) {
            $add('paper_documents', 'application', self::PASS, 'Paper documents', count($onPaper) . ' required document' . (count($onPaper) === 1 ? ' was' : 's were')
                . ' declared as held on paper (no file uploaded), so the system cannot check ' . (count($onPaper) === 1 ? 'it' : 'them') . '. MSWDO staff verify in person.');
        }

        // Applicant's birthdate.
        $birth = self::date((string) ($app['birthdate'] ?? ''));
        if ($birth === null || $birth > $today) {
            $add('birthdate', 'application', self::FAIL, 'Solo Parent birthdate', 'The birthdate is missing, not a real date or in the future.');
        } else {
            $age = self::years($birth, $today);
            if ($age < self::MIN_APPLICANT_AGE || $age > self::MAX_APPLICANT_AGE) {
                $add('birthdate', 'application', self::FLAG, 'Solo Parent age', 'Age ' . $age . ' is outside the expected range (' . self::MIN_APPLICANT_AGE . '-' . self::MAX_APPLICANT_AGE . '). Please confirm the birthdate.');
            } else {
                $add('birthdate', 'application', self::PASS, 'Solo Parent age', 'Age ' . $age . ' is within the expected range.');
            }
        }

        // Children (a new application needs at least one dependent).
        // A renewal from before the interview change (documents only) kept the old ID's children; newer records need at least one.
        if (($app['type'] ?? 'new') === 'new' || empty($app['renewal_simplified'])) {
            if (!$children) {
                $add('children', 'application', self::FLAG, 'Dependents', 'No children are listed. A solo parent needs at least one dependent.');
            }
        }
        foreach ($children as $i => $child) {
            $name = trim((string) ($child['full_name'] ?? '')) ?: 'Child ' . ($i + 1);
            $cb = self::date((string) ($child['birthdate'] ?? ''));
            if ($cb === null || $cb > $today) {
                $add('children', 'application', self::FAIL, 'Child birthdate', $name . ': the birthdate is missing, not a real date or in the future.');
            } elseif ($birth !== null && ($cb <= $birth || self::years($birth, $cb) < self::MIN_PARENT_AGE)) {
                $add('children', 'application', self::FLAG, 'Child birthdate', $name . ' would have been born when the parent was under ' . self::MIN_PARENT_AGE . '. Please check both birthdates.');
            } elseif (self::years($cb, $today) > self::CHILD_MAX_AGE) {
                $add('children', 'application', self::FLAG, 'Child age', SoloParentChildRules::hasDisability($child)
                    ? $name . ' is ' . self::years($cb, $today) . ' and marked with a disability: qualifies under RA 11861 only with a PWD ID or medical certificate. Check it.'
                    : $name . ' is ' . self::years($cb, $today) . ', over ' . self::CHILD_MAX_AGE . ' and not marked with a disability: no longer a qualifying child (RA 11861).');
            } else {
                $add('children', 'application', self::PASS, 'Child', $name . ' is within the dependent age range.');
            }
        }

        // RA 11861: at least one qualifying child (22 or younger, or older with a disability).
        if ($children && !SoloParentChildRules::anyQualifies($children, $today)) {
            $add('qualifying_child', 'application', self::FLAG, 'Qualifying child', 'No child qualifies (22 or younger, or over 22 with a disability). This application may not be eligible: check at the interview.');
        }
        // A renewal whose civil status changed to Married since the last ID.
        if (($app['type'] ?? '') === 'renewal' && isset($app['previous_civil_status'])
            && ($app['civil_status'] ?? '') === 'Married' && $app['previous_civil_status'] !== 'Married') {
            $add('civil_status', 'application', self::FLAG, 'Civil status', 'Changed to Married since the last ID (was ' . $app['previous_civil_status'] . '). A Solo Parent who married or lives with a partner may no longer qualify (RA 11861): check at the interview.');
        }

        // The same person on another account.
        if (!empty($duplicates['samePerson'])) {
            $add('duplicate_person', 'application', self::FLAG, 'Possible duplicate', 'Another account has an application with the same name and birthdate.');
        }

        // Contact details.
        if (!preg_match('/^(09\d{9}|\+639\d{9})$/', preg_replace('/[\s().-]/', '', (string) ($app['mobile'] ?? '')))) {
            $add('contact', 'application', self::FLAG, 'Mobile number', 'The mobile number is not a valid Philippine number.');
        }
        if (($app['type'] ?? 'new') === 'new' && (trim((string) ($app['emergency_contact_name'] ?? '')) === '' || trim((string) ($app['emergency_contact_phone'] ?? '')) === '')) {
            $add('contact', 'application', self::FLAG, 'Emergency contact', 'The emergency contact name or number is missing.');
        }

        // The applicant's own eligibility pre-check.
        if (($app['eligibility_status'] ?? '') === 'Not Eligible') {
            $add('eligibility', 'application', self::FLAG, 'Eligibility', 'The eligibility assessment on file says Not Eligible.');
        }

        // Each uploaded file.
        foreach ($checklist as $row) {
            $doc = $row['uploaded_document'] ?? null;
            if (!$doc) {
                continue;
            }
            $label = (string) ($row['label'] ?? $row['doc_key']);
            $scope = (string) $row['doc_key'];
            $problems = [];
            $sha = (string) ($doc['sha256'] ?? '');
            if ($sha !== '') {
                $others = array_values(array_diff($duplicates['sameApplication'][$sha] ?? [], [$row['doc_key']]));
                if ($others) {
                    $problems[] = 'The same file is also used for ' . implode(', ', $others) . '.';
                }
                if (!empty($duplicates['otherApplications'][$sha])) {
                    $problems[] = 'The same file appears in another Solo Parent\'s application.';
                }
            }
            $measured = $quality($doc);
            foreach (($measured['issues'] ?? []) as $issue) {
                $problems[] = $issue;
            }
            if ($problems) {
                $add('file', $scope, self::FLAG, $label, implode(' ', $problems));
            } else {
                $add('file', $scope, self::PASS, $label, $measured === null ? 'File is valid (quality is not measured for this type).' : 'File is readable and not a duplicate.');
            }

            // Does the text on the document contain the applicant's (or a child's) name? Only when OCR is available.
            $text = $ocr !== null && $measured !== null ? $ocr($doc) : null;
            if ($text !== null) {
                $names = array_merge([(string) ($app['full_name'] ?? '')], array_map(fn($c) => (string) ($c['full_name'] ?? ''), $children));
                $best = 0.0;
                foreach ($names as $name) {
                    $best = max($best, self::nameScore($text, $name));
                }
                $extra = ['sha' => $sha];
                if (strlen(preg_replace('/[^A-Za-z0-9]/', '', $text)) < self::OCR_MIN_CHARS) {
                    $add('ocr_name', $scope, self::FLAG, $label . ' (text)', 'Almost no text could be read from this document. Check that it is a clear photo.', $extra);
                } elseif ($best >= self::NAME_MATCH_MIN) {
                    $add('ocr_name', $scope, self::PASS, $label . ' (name)', 'A name from the application was found on the document.', $extra);
                } else {
                    $add('ocr_name', $scope, self::FLAG, $label . ' (name)', 'No name from the application (the Solo Parent\'s or a child\'s) was clearly found on this document. Check that it belongs to the Solo Parent.', $extra);
                }
            }
        }

        $counts = [self::PASS => 0, self::FLAG => 0, self::FAIL => 0];
        foreach ($checks as $check) {
            $counts[$check['result']]++;
        }
        return [
            'verdict' => $counts[self::FAIL] ? 'fix' : ($counts[self::FLAG] ? 'review' : 'ready'),
            'checks' => $checks,
            'counts' => $counts,
        ];
    }

    private static function date(string $value): ?string
    {
        $d = DateTime::createFromFormat('!Y-m-d', substr($value, 0, 10));
        return $d && $d->format('Y-m-d') === substr($value, 0, 10) ? $d->format('Y-m-d') : null;
    }

    // ---- Reading the documents' text (OCR, optional) ----

    /** Upper-case ASCII letters and digits separated by single spaces ("Dela Cruz, María" => "DELA CRUZ MARIA"). */
    public static function normalizeText(string $text): string
    {
        // An explicit accent table: iconv's transliteration differs between platforms (on Windows it can turn "Í" into "?").
        $text = strtr(mb_strtoupper($text), [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'Å' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ñ' => 'N', 'Ç' => 'C', 'Ý' => 'Y',
        ]);
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9]+/', ' ', $text)));
    }

    /**
     * How much of $name appears in $text, 0.0 to 1.0: the share of the name's words found among the text's words. A word of
     * five or more letters may differ by one letter (OCR misreads). Filler such as "DE" or "JR" is ignored while other words remain.
     */
    public static function nameScore(string $text, string $name): float
    {
        $words = array_values(array_filter(explode(' ', self::normalizeText($name)), fn($w) => strlen($w) >= 2));
        $significant = array_values(array_diff($words, self::NAME_FILLER));
        $words = $significant ?: $words;
        if (!$words) {
            return 0.0;
        }
        $found = array_flip(array_filter(explode(' ', self::normalizeText($text)), fn($w) => $w !== ''));
        $matched = 0;
        foreach ($words as $word) {
            if (isset($found[$word])) {
                $matched++;
            } elseif (strlen($word) >= 5) {
                foreach (array_keys($found) as $candidate) {
                    if (abs(strlen($candidate) - strlen($word)) <= 1 && levenshtein($candidate, $word) <= 1) {
                        $matched++;
                        break;
                    }
                }
            }
        }
        return $matched / count($words);
    }

    /** Path of the Tesseract program: the TESSERACT_PATH environment variable, else its usual Windows install folders; null if absent. */
    public static function ocrBinary(): ?string
    {
        $configured = getenv('TESSERACT_PATH');
        $candidates = array_filter([
            $configured !== false ? $configured : null,
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
        ]);
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        return null;
    }

    public static function ocrAvailable(): bool
    {
        return self::ocrBinary() !== null && function_exists('proc_open');
    }

    /**
     * The text Tesseract reads from one stored image, or null when OCR is unavailable, the file is outside the upload folder or the
     * run fails or times out. The text is held in memory only: its temporary output file is deleted at once and nothing is stored.
     */
    private function ocrText(array $doc): ?string
    {
        $binary = self::ocrBinary();
        if ($binary === null || !function_exists('proc_open') || !in_array($doc['mime'] ?? '', ['image/jpeg', 'image/png'], true) || !defined('STORAGE_PATH')) {
            return null;
        }
        $root = realpath(STORAGE_PATH);
        $path = realpath(STORAGE_PATH . '/' . ($doc['stored_path'] ?? ''));
        if ($root === false || $path === false || strncmp($path, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0 || !is_file($path)) {
            return null;
        }
        $base = tempnam(sys_get_temp_dir(), 'ocr');
        if ($base === false) {
            return null;
        }
        $process = @proc_open([$binary, $path, $base, '-l', 'eng', '--psm', '6'], [0 => ['file', 'NUL', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pipes);
        if (!is_resource($process)) {
            @unlink($base);
            return null;
        }
        $deadline = time() + self::OCR_TIMEOUT_SECONDS;
        $exit = null; // PHP reports the exit code only once, on the first status after the program ends
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            if (time() >= $deadline) {
                proc_terminate($process);
                break;
            }
            usleep(100000);
        }
        proc_close($process);
        $text = is_file($base . '.txt') ? (string) @file_get_contents($base . '.txt', false, null, 0, 200000) : null;
        @unlink($base . '.txt');
        @unlink($base);
        return $exit === 0 ? $text : null;
    }

    /**
     * The OCR reader for one evaluation, with a time budget: reading takes a few seconds per document, and this runs while the
     * applicant waits for the submission page, so once OCR_TOTAL_BUDGET_SECONDS is used up the remaining documents are simply
     * not read (no name check for them) instead of letting the request run into PHP's time limit.
     */
    private function budgetedOcr(): callable
    {
        $started = microtime(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::OCR_TOTAL_BUDGET_SECONDS + 30);
        }
        return function (array $doc) use ($started): ?string {
            return microtime(true) - $started > self::OCR_TOTAL_BUDGET_SECONDS ? null : $this->ocrText($doc);
        };
    }

    /**
     * Adds the OCR results saved with an earlier evaluation (reading the text is slow, so it is done once, at submission) to a live
     * result. A saved result counts only while the file it was read from is still the document's current file (same checksum).
     *
     * @param array $savedChecks the 'checks' of the latest saved result
     */
    public static function withSavedOcr(array $result, array $savedChecks, array $checklist): array
    {
        $current = [];
        foreach ($checklist as $row) {
            if (!empty($row['uploaded_document']['sha256'])) {
                $current[$row['doc_key']] = $row['uploaded_document']['sha256'];
            }
        }
        foreach ($savedChecks as $check) {
            if (($check['key'] ?? '') === 'ocr_name' && isset($current[$check['scope'] ?? '']) && ($check['sha'] ?? null) === $current[$check['scope']]) {
                $result['checks'][] = $check;
            }
        }
        $counts = [self::PASS => 0, self::FLAG => 0, self::FAIL => 0];
        foreach ($result['checks'] as $check) {
            $counts[$check['result']]++;
        }
        $result['counts'] = $counts;
        $result['verdict'] = $counts[self::FAIL] ? 'fix' : ($counts[self::FLAG] ? 'review' : 'ready');
        return $result;
    }

    /** Whole years from $from to $to (both Y-m-d). */
    private static function years(string $from, string $to): int
    {
        return (int) (new DateTime($from))->diff(new DateTime($to))->y;
    }

    /**
     * Measures an uploaded image (resolution, sharpness, brightness) and turns the numbers into plain-words issues. Null for
     * anything that is not a JPEG or PNG, or when the file cannot be read. Only files inside the upload folder are opened.
     *
     * @return ?array{issues: string[]}
     */
    private function imageQuality(array $doc): ?array
    {
        if (!in_array($doc['mime'] ?? '', ['image/jpeg', 'image/png'], true) || !defined('STORAGE_PATH') || !function_exists('imagecreatefromjpeg')) {
            return null;
        }
        $root = realpath(STORAGE_PATH);
        $path = realpath(STORAGE_PATH . '/' . ($doc['stored_path'] ?? ''));
        if ($root === false || $path === false || strncmp($path, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0 || !is_file($path)) {
            return ['issues' => ['The stored file could not be found.']];
        }
        $m = self::measureImage($path);
        return $m === null ? null : ['issues' => self::imageIssues($m)];
    }

    /** Plain-words problems for one measurement from measureImage(). */
    public static function imageIssues(array $m): array
    {
        $issues = [];
        if (min($m['width'], $m['height']) < self::MIN_IMAGE_SIDE) {
            $issues[] = 'Low resolution (' . $m['width'] . '×' . $m['height'] . ' px); text may be unreadable.';
        }
        if ($m['brightness'] !== null) {
            if ($m['brightness'] < self::MIN_BRIGHTNESS) {
                $issues[] = 'The image is very dark.';
            } elseif ($m['brightness'] > self::MAX_BRIGHTNESS) {
                $issues[] = 'The image is washed out or mostly white.';
            }
        }
        // A nearly blank dark or white image always measures as "not sharp"; one reason is enough.
        $extreme = $m['brightness'] !== null && ($m['brightness'] < self::MIN_BRIGHTNESS || $m['brightness'] > self::MAX_BRIGHTNESS);
        if (!$extreme && $m['sharpness'] !== null && $m['sharpness'] < self::MIN_SHARPNESS) {
            $issues[] = 'The image looks blurry.';
        }
        return $issues;
    }

    /**
     * The raw numbers behind the image checks, for one JPEG or PNG file: width, height, mean brightness (0-255) and sharpness
     * (variance of the Laplacian on a copy 400px wide; higher is sharper). brightness and sharpness are null when the pixels
     * cannot be read. Null when the file is not a readable JPEG/PNG. Also used by database/auto_verification_samples.php to
     * choose the limits from real photos.
     *
     * @return ?array{width: int, height: int, brightness: ?float, sharpness: ?float}
     */
    public static function measureImage(string $path): ?array
    {
        if (!function_exists('imagecreatefromjpeg')) {
            return null;
        }
        $info = @getimagesize($path);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] * $info[1] > 40_000_000) {
            return null;
        }
        [$w, $h] = $info;
        $result = ['width' => $w, 'height' => $h, 'brightness' => null, 'sharpness' => null];
        $image = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if (!$image) {
            return null;
        }
        $small = imagescale($image, 400);
        if (!$small) {
            return $result;
        }
        $sw = imagesx($small);
        $sh = imagesy($small);
        $gray = [];
        $sum = 0;
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $g = (int) round(0.299 * (($rgb >> 16) & 255) + 0.587 * (($rgb >> 8) & 255) + 0.114 * ($rgb & 255));
                $gray[$y][$x] = $g;
                $sum += $g;
            }
        }
        $result['brightness'] = $sum / max(1, $sw * $sh);
        $n = 0;
        $lapSum = 0.0;
        $lapSq = 0.0;
        for ($y = 1; $y < $sh - 1; $y++) {
            for ($x = 1; $x < $sw - 1; $x++) {
                $lap = 4 * $gray[$y][$x] - $gray[$y][$x - 1] - $gray[$y][$x + 1] - $gray[$y - 1][$x] - $gray[$y + 1][$x];
                $lapSum += $lap;
                $lapSq += $lap * $lap;
                $n++;
            }
        }
        if ($n > 0) {
            $result['sharpness'] = $lapSq / $n - ($lapSum / $n) ** 2;
        }
        return $result;
    }
}
