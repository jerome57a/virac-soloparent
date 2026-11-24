<?php
/**
 * A child with a disability (PWD) in the new application and the renewal (SYNTHETIC data, disposable database): the Yes/No answer, the
 * server-side block when no child qualifies (RA 11861), the extra required PWD ID or medical certificate, the saved flag, and the staff checklist.
 *
 *   php database/test_child_pwd_rules.php   (isolated test environment only)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
$testDb = 'solo_review_test_' . bin2hex(random_bytes(6));
validate_temporary_database_target($testDb, 'solo_review_test_');
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../controllers/StaffController.php';
require_once __DIR__ . "/../models/Document.php";
require_once __DIR__ . '/../models/SmsLog.php';
require_once __DIR__ . '/../includes/sms-attention.php';

function sa_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }

/** Inserts one row, filling every other NOT NULL column that has no default with a harmless value. Returns the new id. */
function sa_row(PDO $pdo, string $table, array $given): int
{
    $values = $given;
    foreach ($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll() as $c) {
        $name = $c['Field'];
        if (array_key_exists($name, $values) || str_contains(strtolower($c['Extra']), 'auto_increment') || str_contains(strtolower($c['Extra']), 'generated')) { continue; }
        if ($c['Null'] === 'YES' || $c['Default'] !== null) { continue; }
        $type = strtolower($c['Type']);
        $values[$name] = match (true) {
            str_starts_with($type, 'enum(') => (preg_match("/^enum\\('([^']*)'/", $type, $m) ? $m[1] : ''),
            str_contains($type, 'json') => '{}',
            str_starts_with($type, 'datetime'), str_starts_with($type, 'timestamp') => '2026-01-01 00:00:00',
            str_starts_with($type, 'date') => '2026-01-01',
            str_starts_with($type, 'time') => '00:00:00',
            (bool) preg_match('/int|decimal|float|double|year|bit/', $type) => 1,
            default => 'x',
        };
    }
    $cols = array_map(fn($c) => '`' . $c . '`', array_keys($values));
    $pdo->prepare('INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')')->execute(array_values($values));
    return (int) $pdo->lastInsertId();
}

$live = $pdo;
$created = false;
$exitCode = 0;
try {
    $live->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4');
    $created = true;
    $test = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $testDb . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $test->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($live->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $test->exec($live->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1]);
    }
    $pdo = $test;
    $GLOBALS['pdo'] = $test;
    sa_assert(SMSService::testMode(), 'These checks require the test SMS provider.');
    set_setting($test, 'sms_enabled', '1');
    foreach (['interview_scheduled' => 'MSWDO {ref} {when}', 'appointment_cancelled' => 'MSWDO {ref} {when} {reason}', 'appointment_missed' => 'MSWDO {ref} {when}'] as $key => $body) {
        $test->prepare('INSERT INTO sms_templates (template_key, label, body) VALUES (?, ?, ?)')->execute([$key, $key, $body]);
    }
    require_once __DIR__ . '/../includes/data.php';
    require_once __DIR__ . '/../services/ApplicationService.php';
    require_once __DIR__ . '/../services/RenewalService.php';
    require_once __DIR__ . '/../services/SoloParentChildRules.php';
    require_once __DIR__ . '/../models/Document.php';
    $test->exec('SET FOREIGN_KEY_CHECKS=0');
    // The throwaway copy may come from an older schema: add the column before anything asks whether it exists.
    $hasColumn = (int) $test->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'application_children' AND COLUMN_NAME = 'has_disability'")->fetchColumn();
    if (!$hasColumn) {
        $test->exec('ALTER TABLE application_children ADD COLUMN has_disability TINYINT(1) NOT NULL DEFAULT 0');
    }
    $today = date('Y-m-d');
    $years = fn(int $n) => date('Y-m-d', strtotime("$today -$n years -40 days"));

    // ---- The rules ----
    sa_assert(SoloParentChildRules::proofRequirement([['name' => 'A', 'birthdate' => $years(10)]], $today) === null, 'A child of 10 needs no proof.');
    $proof = SoloParentChildRules::proofRequirement([['name' => 'Juan', 'birthdate' => $years(30), 'disability' => true]], $today);
    sa_assert($proof && $proof['doc_key'] === SoloParentChildRules::PROOF_KEY && $proof['is_required'] === 1 && str_contains($proof['reason'], 'Juan (age 30)'), 'A child of 30 with a disability needs the proof, and the reason names the child.');
    sa_assert(SoloParentChildRules::proofRequirement([['name' => 'Juan', 'birthdate' => $years(30)]], $today) === null, 'A child of 30 without the tick needs no proof (and does not qualify).');
    sa_assert(RenewalService::DISABILITY_PROOF_KEY === SoloParentChildRules::PROOF_KEY, 'The renewal and the new application use the same proof document.');

    // ---- A user, a category and the standard requirement ----
    $catId = sa_row($test, 'categories', ['name' => 'PWD fixture ' . bin2hex(random_bytes(2))]);
    sa_row($test, 'document_requirements', ['category_id' => null, 'doc_key' => 'valid_id_fx', 'label' => 'Valid ID', 'is_required' => 1, 'sort_order' => 1]);
    $uid = sa_row($test, 'users', ['full_name' => 'Pwd Parent', 'role' => 'applicant', 'username' => 'pwd_' . bin2hex(random_bytes(3)), 'is_active' => 1, 'mobile' => '09171234567']);
    $user = $test->query("SELECT * FROM users WHERE id = $uid")->fetch();
    $service = new ApplicationService($test);
    $draft = $service->freshDraft($user);
    sa_assert($draft['hasPwdChild'] === false && $draft['children'][0]['disability'] === false, 'A fresh draft starts with the answer No.');
    $draft['categoryId'] = $catId;
    $post = fn(string $answer, array $kids, array $ticks) => ['has_pwd_child' => $answer, 'child_name' => array_column($kids, 0), 'child_birthdate' => array_column($kids, 1), 'child_disability' => $ticks];

    // ---- Step 2: the answer, the tick and the server-side block ----
    $d = $service->collectStepInput(2, $draft, $post('0', [['Maria', $years(10)]], [0 => '1']));
    sa_assert($d['hasPwdChild'] === false && $d['children'][0]['disability'] === false, 'With the answer No a posted tick is ignored.');
    $d = $service->collectStepInput(2, $draft, $post('1', [['Maria', $years(10)], ['Juan', $years(30)]], [1 => '1']));
    sa_assert($d['hasPwdChild'] === true && $d['children'][0]['disability'] === false && $d['children'][1]['disability'] === true, 'With the answer Yes the tick is kept for that child only.');
    sa_assert($service->validateStep(2, $d) === [], 'A child of 10 and a child of 30 with a disability: step 2 is valid.');

    $none = $service->collectStepInput(2, $draft, $post('1', [['Pedro', $years(30)]], []));
    $errors = $service->validateStep(2, $none);
    sa_assert(($errors['children'] ?? '') === SoloParentChildRules::NONE_QUALIFIES, 'A child of 30 without a disability: no child qualifies, the server refuses step 2: ' . json_encode($errors));
    $noNo = $service->collectStepInput(2, $draft, $post('0', [['Pedro', $years(30)]], [0 => '1']));
    sa_assert(isset($service->validateStep(2, $noNo)['children']), 'Answering No cannot be bypassed by posting a tick: still refused.');
    $yesNoTick = $service->collectStepInput(2, $draft, $post('1', [['Maria', $years(10)]], []));
    sa_assert(($service->validateStep(2, $yesNoTick)['pwd'] ?? '') === SoloParentChildRules::ANSWERED_YES_NO_TICK, 'Answer Yes but nobody ticked: asked to tick or answer No.');
    $exactly22 = $service->collectStepInput(2, $draft, $post('0', [['Ana', date('Y-m-d', strtotime("$today -22 years"))]], []));
    sa_assert($service->validateStep(2, $exactly22) === [], 'A child who is exactly 22 qualifies.');

    // ---- Step 3: the extra required document ----
    $keysFor = fn(array $dr) => array_column($service->documentRequirements($dr), 'doc_key');
    sa_assert(!in_array(SoloParentChildRules::PROOF_KEY, $keysFor($draft + ['children' => [['name' => 'M', 'birthdate' => $years(10), 'disability' => false]]]), true), 'No proof document for a child of 10.');
    sa_assert(in_array(SoloParentChildRules::PROOF_KEY, $keysFor($d), true), 'The proof document is a requirement when a child over 22 has a disability.');
    $docErrors = $service->documentErrors($d);
    sa_assert(str_contains($docErrors['documents'] ?? '', SoloParentChildRules::PROOF_LABEL), 'Submitting without the proof is refused and names it: ' . json_encode($docErrors));

    // ---- Saved: the flag, and the staff checklist ----
    $insert = new ReflectionMethod(ApplicationService::class, 'insertApplication');
    $d['fullName'] = 'Pwd Parent'; $d['barangay'] = 'Test'; $d['address'] = 'Test'; $d['contact'] = '09171234567'; $d['email'] = ''; $d['birthdate'] = '1990-01-01'; $d['sex'] = 'Female'; $d['civilStatus'] = 'Single'; $d['documents'] = [];
    [$applicationId] = $insert->invoke($service, $user, $d);
    $saved = $test->query("SELECT full_name, has_disability FROM application_children WHERE application_id = $applicationId ORDER BY id")->fetchAll();
    sa_assert(count($saved) === 2 && (int) $saved[0]['has_disability'] === 0 && (int) $saved[1]['has_disability'] === 1, 'The disability flag is saved for the right child: ' . json_encode($saved));
    $checklist = (new Document($test))->checklistForReview($applicationId, $catId, false);
    $row = array_values(array_filter($checklist, fn($r) => $r['doc_key'] === SoloParentChildRules::PROOF_KEY));
    sa_assert($row && (int) $row[0]['is_required'] === 1 && $row[0]['label'] === SoloParentChildRules::PROOF_LABEL && $row[0]['verification_status'] === 'Pending', 'Staff see the PWD ID or medical certificate as a required, pending document.');
    $test->exec("DELETE FROM application_children WHERE application_id = $applicationId AND has_disability = 1");
    $plain = (new Document($test))->checklistForReview($applicationId, $catId, false);
    sa_assert(!array_filter($plain, fn($r) => $r['doc_key'] === SoloParentChildRules::PROOF_KEY), 'No such row when no child needs it.');

    // ---- Renewal: the same rules on the server ----
    $renewal = new RenewalService($test);
    $source = ['id' => $applicationId, 'full_name' => 'Pwd Parent', 'birthdate' => '1990-01-01', 'sex' => 'Female', 'category_id' => $catId, 'barangay' => 'Test', 'address' => 'Test', 'mobile' => '09171234567', 'email' => '',
        'civil_status' => 'Single', 'children' => [['full_name' => 'Maria', 'birthdate' => $years(10), 'has_disability' => 0]]];
    $rdraft = $renewal->freshDraft($source);
    sa_assert($rdraft['hasPwdChild'] === false, 'A renewal starts with the answer No when no child is marked.');
    $rpost = fn(string $answer, array $kids) => ['has_pwd_child' => $answer, 'children' => $kids];
    $r1 = $renewal->collectStepInput(1, $rdraft, $rpost('0', [['name' => 'Pedro', 'birthdate' => $years(30), 'disability' => '1']]), $source);
    sa_assert($r1['hasPwdChild'] === false && $r1['children'][0]['disability'] === false, 'Renewal: with the answer No a posted tick is ignored.');
    sa_assert(($renewal->validateStep(1, $r1)['children'] ?? '') === SoloParentChildRules::NONE_QUALIFIES, 'Renewal: no qualifying child is refused on the server.');
    $r2 = $renewal->collectStepInput(1, $rdraft, $rpost('1', [['name' => 'Pedro', 'birthdate' => $years(30), 'disability' => '1']]), $source);
    sa_assert(!isset($renewal->validateStep(1, $r2)['children']) && !isset($renewal->validateStep(1, $r2)['pwd']), 'Renewal: a child over 22 with the tick qualifies.');
    sa_assert(in_array(SoloParentChildRules::PROOF_KEY, array_column($renewal->documentRequirements($source, $r2), 'doc_key'), true), 'Renewal: the proof document is asked.');
    $r3 = $renewal->collectStepInput(1, $rdraft, $rpost('1', [['name' => 'Maria', 'birthdate' => $years(10)]]), $source);
    sa_assert(($renewal->validateStep(1, $r3)['pwd'] ?? '') === SoloParentChildRules::ANSWERED_YES_NO_TICK, 'Renewal: answer Yes but nobody ticked is refused.');
    echo "PASS: child with a disability: the answer and tick, the server blocks when no child qualifies (new application and renewal), the PWD ID or medical certificate is required, the flag is saved, and staff see it.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
