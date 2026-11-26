<?php
/**
 * The dashboard's "New forms" list (SYNTHETIC data, disposable database): a Solo Parent stays in it after staff only open or review the
 * form, and leaves it when staff give the interview date or ask for a correction (Application::newFormCandidates).
 *
 *   php database/test_dashboard_new_forms.php   (isolated test environment only)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
$testDb = 'solo_review_test_' . bin2hex(random_bytes(6));
validate_temporary_database_target($testDb, 'solo_review_test_');
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/Application.php';

function nf_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }

$live = $pdo;
$created = false;
$exit = 0;
try {
    $live->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4');
    $created = true;
    $test = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $testDb . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $test->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['applications', 'application_status_history', 'appointments', 'application_documents', 'sms_logs'] as $table) {
        $test->exec($live->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1]);
    }
    $app = function (string $ref, string $status, string $submitted) use ($test): int {
        $cols = [];
        foreach ($test->query('SHOW COLUMNS FROM applications')->fetchAll() as $c) {
            if (str_contains(strtolower($c['Extra']), 'auto_increment') || str_contains(strtolower($c['Extra']), 'generated')) { continue; }
            if ($c['Null'] === 'YES' || $c['Default'] !== null) { continue; }
            $type = strtolower($c['Type']);
            $cols[$c['Field']] = match (true) {
                str_starts_with($type, 'enum(') => (preg_match("/^enum\\('([^']*)'/", $type, $m) ? $m[1] : ''),
                str_contains($type, 'json') => '{}',
                str_starts_with($type, 'datetime'), str_starts_with($type, 'timestamp') => '2026-01-01 00:00:00',
                str_starts_with($type, 'date') => '2026-01-01',
                str_starts_with($type, 'time') => '00:00:00',
                (bool) preg_match('/int|decimal|float|double|year|bit/', $type) => 1,
                default => 'x',
            };
        }
        $cols['reference_no'] = $ref; $cols['status'] = $status; $cols['submitted_at'] = $submitted; $cols['type'] = 'new'; $cols['full_name'] = 'Parent ' . $ref;
        $names = array_map(fn($c) => '`' . $c . '`', array_keys($cols));
        $test->prepare('INSERT INTO applications (' . implode(',', $names) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
        return (int) $test->lastInsertId();
    };
    $history = fn(int $id, string $status) => $test->prepare("INSERT INTO application_status_history (application_id, status, changed_by) VALUES (?, ?, 'x')")->execute([$id, $status]);

    $pendingOpen = $app('NF-PENDING', 'Pending Review', '2026-11-20 09:00:00');
    $reviewing = $app('NF-REVIEWING', 'Under Verification', '2026-11-21 09:00:00');           // staff opened and reviewed it, gave nothing yet
    $history($reviewing, 'Under Verification');
    $corrected = $app('NF-CORRECTION', 'Under Verification', '2026-11-22 09:00:00');           // staff asked for a correction once
    $history($corrected, 'For Revision'); $history($corrected, 'Under Verification');
    $waiting = $app('NF-WAITING', 'For Revision', '2026-11-23 09:00:00');                       // waiting for the Solo Parent
    $history($waiting, 'For Revision');
    $scheduled = $app('NF-SCHEDULED', 'Interview Scheduled', '2026-11-24 09:00:00');           // an interview date was given
    $test->prepare("INSERT INTO appointments (application_id, scheduled_at, status, location) VALUES (?, '2026-12-01 09:00:00', 'BOOKED', 'x')")->execute([$scheduled]);
    $approved = $app('NF-APPROVED', 'Approved', '2026-11-19 09:00:00');

    // Documents and texts are inserted with only the columns they need; every other NOT NULL column gets a harmless value.
    $insert = function (string $table, array $given) use ($test): void {
        $cols = $given;
        foreach ($test->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll() as $col) {
            if (array_key_exists($col['Field'], $cols) || str_contains(strtolower($col['Extra']), 'auto_increment') || str_contains(strtolower($col['Extra']), 'generated') || $col['Null'] === 'YES' || $col['Default'] !== null) { continue; }
            $type = strtolower($col['Type']);
            $cols[$col['Field']] = match (true) {
                str_starts_with($type, 'enum(') => (preg_match("/^enum\\('([^']*)'/", $type, $m) ? $m[1] : ''),
                str_starts_with($type, 'datetime'), str_starts_with($type, 'timestamp') => '2026-01-01 00:00:00',
                str_starts_with($type, 'date') => '2026-01-01',
                (bool) preg_match('/int|decimal|float|double|year|bit/', $type) => 1,
                default => 'x',
            };
        }
        $names = array_map(fn($c) => '`' . $c . '`', array_keys($cols));
        $test->prepare('INSERT INTO `' . $table . '` (' . implode(',', $names) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($cols));
    };
    // Staff marked a document Needs Correction (the text is not sent yet): the Solo Parent is waiting, so not in New forms.
    $marked = $app('NF-MARKED', 'Under Verification', '2026-11-25 09:00:00');
    $insert('application_documents', ['application_id' => $marked, 'verification_status' => 'NEEDS_CORRECTION']);
    // The correction text was sent and the Solo Parent has since sent the file again (the document is waiting for staff again).
    $textSent = $app('NF-TEXT-SENT', 'Under Verification', '2026-11-26 09:00:00');
    $insert('application_documents', ['application_id' => $textSent, 'verification_status' => 'FOR_VERIFICATION']);
    $insert('sms_logs', ['application_id' => $textSent, 'template_key' => 'document_correction', 'mobile' => '09990000000', 'message' => 'x']);

    $pdo = $test; $GLOBALS['pdo'] = $test;
    $refs = array_column((new Application($test))->newFormCandidates(), 'reference_no');
    nf_assert($refs === ['NF-PENDING', 'NF-REVIEWING'], 'New forms must be the two not acted on, oldest first (not one with a correction marked or its text sent): ' . json_encode($refs));
    // Reviewing again changes nothing: still there.
    $history($reviewing, 'Under Verification');
    nf_assert(array_column((new Application($test))->newFormCandidates(), 'reference_no') === ['NF-PENDING', 'NF-REVIEWING'], 'Opening it again must not remove it.');
    // Giving the interview date takes it off the list.
    $test->prepare("INSERT INTO appointments (application_id, scheduled_at, status, location) VALUES (?, '2026-12-02 09:00:00', 'BOOKED', 'x')")->execute([$reviewing]);
    nf_assert(array_column((new Application($test))->newFormCandidates(), 'reference_no') === ['NF-PENDING'], 'Giving the interview date must remove it.');
    // Asking for a correction takes it off the list.
    $history($pendingOpen, 'For Revision');
    nf_assert((new Application($test))->newFormCandidates() === [], 'Asking for a correction must remove it.');
    echo "PASS: New forms: a Solo Parent stays when staff only open or review the form, and leaves when the interview date is given or a correction is asked for.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    $exit = 1;
} finally {
    if ($created) { $live->exec('DROP DATABASE IF EXISTS `' . $testDb . '`'); }
}
exit($exit);
