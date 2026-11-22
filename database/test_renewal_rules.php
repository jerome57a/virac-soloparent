<?php
/**
 * Renewal rules (SYNTHETIC data, disposable database):
 *   - timing: the form opens 30 days before expiry, submits from the expiration date, closes 365 days after (then a new application);
 *   - RA 11861 children: 22 or younger qualifies; over 22 only with a disability, which adds the PWD ID / medical certificate upload;
 *   - civil status and children can be updated; the automated check flags no qualifying child and a change to Married.
 *
 *   php database/test_renewal_rules.php   (isolated test environment only)
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
    require_once __DIR__ . '/../services/RenewalService.php';
    require_once __DIR__ . '/../services/AutoVerificationService.php';
    $test->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (explode(';', (string) preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/migrate_child_disability.sql'))) as $statement) {
        if (trim($statement) !== '') { $test->exec(trim($statement)); }
    }
    sa_assert(Application::childDisabilityReady($test), 'The has_disability column should exist after the migration.');

    $catId = sa_row($test, 'categories', ['name' => 'Renewal fixture ' . bin2hex(random_bytes(2))]);
    sa_row($test, 'renewal_requirements', ['category_id' => $catId, 'doc_key' => 'barangayCert', 'label' => 'Barangay certificate', 'always_refresh' => 1, 'sort_order' => 1]);
    $uid = sa_row($test, 'users', ['full_name' => 'Renewing Parent', 'role' => 'applicant', 'username' => 'rp_' . bin2hex(random_bytes(3)), 'is_active' => 1, 'mobile' => '09123456789']);
    $user = ['id' => $uid, 'full_name' => 'Renewing Parent', 'role' => 'applicant', 'mobile' => '09123456789'];
    $today = date('Y-m-d');
    $appId = sa_row($test, 'applications', ['reference_no' => 'REN-SRC', 'user_id' => $uid, 'full_name' => 'Renewing Parent', 'status' => 'ID Released', 'type' => 'new',
        'mobile' => '09123456789', 'category_id' => $catId, 'civil_status' => 'Single', 'id_expires_at' => $today, 'birthdate' => '1990-01-01', 'barangay' => 'San Isidro', 'address' => 'Purok 1']);
    $test->prepare('INSERT INTO application_children (application_id, full_name, birthdate) VALUES (?, ?, ?)')->execute([$appId, 'Young Child', date('Y-m-d', strtotime('-10 years'))]);
    $service = new RenewalService($test);
    $source = $service->sourceApplication($user);
    sa_assert($source !== null && (int) $source['id'] === $appId, 'The released ID should be the renewal source.');

    // ---- Timing ----
    $at = fn(string $rel) => date('Y-m-d', strtotime($today . ' ' . $rel));
    $w = $service->window($source, $at('-40 days'));
    sa_assert(!$w['open'] && !$w['submitOpen'] && !$w['closed'], '40 days before expiry the form is not open yet.');
    $w = $service->window($source, $at('-10 days'));
    sa_assert($w['open'] && !$w['submitOpen'], '10 days before expiry the Solo Parent can prepare but not submit.');
    $w = $service->window($source, $today);
    sa_assert($w['open'] && $w['submitOpen'], 'On the expiration date the renewal can be submitted.');
    $w = $service->window($source, $at('+366 days'));
    sa_assert($w['closed'] && !$w['open'] && !$w['submitOpen'], 'More than 365 days after expiry the renewal is closed.');
    $test->prepare('UPDATE applications SET id_expires_at = ? WHERE id = ?')->execute([$at('+5 days'), $appId]);
    $early = $service->sourceApplication($user);
    $refused = null;
    try { $service->submit($user, $early, $service->freshDraft($early)); } catch (DomainException $e) { $refused = $e->getMessage(); }
    sa_assert(is_string($refused) && str_contains($refused, 'the day your ID expires'), 'Submitting before the expiration date must be refused: ' . var_export($refused, true));
    // After the renewal period: a new application is allowed.
    $test->prepare('UPDATE applications SET id_expires_at = ? WHERE id = ?')->execute([$at('-400 days'), $appId]);
    sa_assert((new ApplicationService($test))->blockingApplication($user) === null, 'After the renewal period a new application must be allowed.');
    $test->prepare('UPDATE applications SET id_expires_at = ? WHERE id = ?')->execute([$at('-10 days'), $appId]);
    sa_assert((new ApplicationService($test))->blockingApplication($user) !== null, 'Within the renewal period a new application is still blocked (renew instead).');
    $source = $service->sourceApplication($user);

    // ---- The form: civil status and children ----
    $draft = $service->freshDraft($source);
    $post = ['barangay' => 'San Isidro', 'address' => 'Purok 1', 'civilStatus' => 'Married', 'consent' => '1', 'children' => [
        ['name' => 'Young Child', 'birthdate' => date('Y-m-d', strtotime('-10 years'))],
        ['name' => 'Grown Child', 'birthdate' => date('Y-m-d', strtotime('-30 years')), 'disability' => '1'],
        ['name' => '', 'birthdate' => ''],
    ]];
    $draft = $service->collectStepInput(1, $draft, $post, $source);
    sa_assert($draft['civilStatus'] === 'Married' && count($draft['children']) === 2 && $draft['children'][1]['disability'] === true, 'Civil status and children (with the tick) must be taken from the form; blank rows dropped.');
    sa_assert(in_array(RenewalService::DISABILITY_PROOF_KEY, array_column($service->documentRequirements($source, $draft), 'doc_key'), true), 'A child over 22 with a disability must add the PWD ID / medical certificate upload.');
    $noTick = $draft; $noTick['children'][1]['disability'] = false;
    sa_assert(!in_array(RenewalService::DISABILITY_PROOF_KEY, array_column($service->documentRequirements($source, $noTick), 'doc_key'), true), 'Without the tick no proof is asked.');
    $bad = $service->collectStepInput(1, $service->freshDraft($source), ['civilStatus' => 'Engaged', 'children' => [['name' => 'X', 'birthdate' => '2999-01-01']]], $source);
    $errors = $service->validateStep(1, $bad);
    sa_assert(isset($errors['civilStatus']) && isset($errors['children']), 'An unknown civil status and a future birthdate must be refused: ' . json_encode(array_keys($errors)));
    $none = $service->collectStepInput(1, $service->freshDraft($source), ['children' => [['name' => '', 'birthdate' => '']]], $source);
    sa_assert(isset($service->validateStep(1, $none)['children']), 'At least one child must be listed.');

    // ---- RA 11861 rules ----
    $on = $today;
    sa_assert(SoloParentChildRules::status(['birthdate' => date('Y-m-d', strtotime('-22 years -1 day'))], $on) === SoloParentChildRules::QUALIFIES, 'A child aged 22 qualifies.');
    sa_assert(SoloParentChildRules::status(['birthdate' => date('Y-m-d', strtotime('-23 years -1 day'))], $on) === SoloParentChildRules::OVER_AGE, 'A child aged 23 without a disability does not.');
    sa_assert(SoloParentChildRules::status(['birthdate' => date('Y-m-d', strtotime('-40 years')), 'has_disability' => 1], $on) === SoloParentChildRules::QUALIFIES_DISABILITY, 'A child over 22 with a disability qualifies.');

    // ---- Saved children keep the tick; the automated check on a renewal ----
    $renewalId = sa_row($test, 'applications', ['reference_no' => 'REN-NEW', 'user_id' => $uid, 'full_name' => 'Renewing Parent', 'status' => 'Pending Review', 'type' => 'renewal',
        'renewal_of_id' => $appId, 'renewal_simplified' => 0, 'mobile' => '09123456789', 'category_id' => $catId, 'civil_status' => 'Married', 'birthdate' => '1990-01-01']);
    $apps = new Application($test);
    $apps->addChild($renewalId, 'Grown Child', date('Y-m-d', strtotime('-30 years')), false);
    $kids = $apps->children($renewalId);
    sa_assert((int) $kids[0]['has_disability'] === 0, 'The disability tick must be stored.');
    $row = $test->query("SELECT * FROM applications WHERE id = $renewalId")->fetch();
    $checks = array_column((new AutoVerificationService($test))->forApplication($row, $kids, [])['checks'], null, 'key');
    sa_assert(isset($checks['qualifying_child']), 'A renewal whose only child is over 22 without a disability must be flagged.');
    sa_assert(isset($checks['civil_status']), 'A renewal that changed to Married must be flagged.');
    $apps->addChild($renewalId, 'Young Child', date('Y-m-d', strtotime('-10 years')), false);
    $checks = array_column((new AutoVerificationService($test))->forApplication($row, $apps->children($renewalId), [])['checks'], null, 'key');
    sa_assert(!isset($checks['qualifying_child']), 'With one qualifying child the flag must go.');

    // ---- The renewal interview: the current Solo Parent ID ----
    require_once __DIR__ . '/../services/ApplicationReviewService.php';
    $staffId = sa_row($test, 'users', ['full_name' => 'Staff Fixture', 'role' => 'mswd_social_worker', 'username' => 'staff_fx_' . bin2hex(random_bytes(3))]);
    $staff = ['id' => $staffId, 'full_name' => 'Staff Fixture', 'role' => 'mswd_social_worker'];
    $test->prepare("UPDATE applications SET status = 'Interview Scheduled' WHERE id = ?")->execute([$renewalId]);
    $test->prepare("INSERT INTO appointments (application_id, scheduled_at, location, status, scheduled_by) VALUES (?, DATE_SUB(NOW(), INTERVAL 1 DAY), 'Office', 'BOOKED', ?)")->execute([$renewalId, $staffId]);
    $review = new ApplicationReviewService($test);
    $withoutId = array_values(array_diff(array_keys(ApplicationReviewService::RENEWAL_RECHECK), ['current_id']));
    sa_assert(isset(ApplicationReviewService::RENEWAL_RECHECK['current_id']), 'The re-check must include the current Solo Parent ID.');
    $err = $review->recordInterviewOutcome($staff, $renewalId, 'Successful', '', $withoutId);
    sa_assert(is_string($err) && str_contains($err, 'ID not shown'), 'Without the ID tick or a reason a successful result must be refused: ' . var_export($err, true));
    $err = $review->recordInterviewOutcome($staff, $renewalId, 'Successful', '', $withoutId, 'Lost, showed an affidavit of loss');
    sa_assert($err === null, 'With the reason the interview can be recorded: ' . var_export($err, true));
    $result = (string) $test->query("SELECT result FROM appointments WHERE application_id = $renewalId")->fetchColumn();
    sa_assert(str_contains($result, 'not shown: Lost, showed an affidavit of loss'), 'The reason must be saved with the result: ' . $result);
    sa_assert((int) $test->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'Renewal Interview: ID Not Shown' AND entity_id = $renewalId")->fetchColumn() === 1, 'The missing ID must be audit-logged.');

    // ---- The SMS reminds a renewing Solo Parent to bring the ID; a new application is unchanged ----
    $sms = new SMSService($test);
    sa_assert(str_contains($sms->renewalInterviewNote($renewalId), 'Solo Parent ID') && $sms->renewalInterviewNote($appId) === '', 'Only a renewal interview text asks for the Solo Parent ID.');
    $preview = $sms->interviewDateAssignedMessage('VIRAC-SP-2026-0001', date('Y-m-d', strtotime('+3 days')), 'MSWDO Office', $renewalId);
    sa_assert(str_contains($preview, 'Solo Parent ID'), 'The staff preview of a renewal interview text must include the reminder.');
    sa_assert(!SMSService::compact() || mb_strlen($preview) <= 160, 'In compact wording the renewal interview text must stay one text (160 characters): ' . mb_strlen($preview));
    echo 'Renewal interview text (' . (SMSService::compact() ? 'compact' : 'full') . ' wording): ' . mb_strlen($preview) . " characters.\n";
    echo "PASS: renewal rules: prepare 30 days before, submit from the expiry date, close after 365 days (then a new application); RA 11861 children (22 or younger, or over 22 with a disability and proof); civil status and children updatable; no qualifying child and Married flagged; the renewal interview checks the current Solo Parent ID (or records why it was not shown) and its text asks for it.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
