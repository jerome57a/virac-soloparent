<?php
/**
 * Document verification queue, with SYNTHETIC rows in a disposable database:
 *   - applications are listed oldest application first (not by when a document was sent);
 *   - a re-upload is marked; first uploads are not;
 *   - the counts behind the dashboard tile and the menu badge.
 *
 *   php database/test_document_verification_queue.php   (isolated test environment only)
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

    $mkApp = function (string $ref, string $name, string $submitted, string $status = 'Under Verification') use ($test): int {
        return sa_row($test, 'applications', ['reference_no' => $ref, 'full_name' => $name, 'status' => $status, 'type' => 'new', 'submitted_at' => $submitted]);
    };
    $mkDoc = function (int $app, string $key, string $sentAt) use ($test): int {
        return sa_row($test, 'application_documents', ['application_id' => $app, 'doc_key' => $key, 'original_name' => 'x.pdf', 'stored_path' => 'x', 'mime' => 'application/pdf', 'size_bytes' => 1, 'verification_status' => 'FOR_VERIFICATION', 'uploaded_at' => $sentAt]);
    };
    $actor = ['id' => null, 'full_name' => 'Solo Parent', 'role' => 'applicant'];
    $docs = new Document($test);

    $a = $mkApp('Q-A', 'Anna Alpha', '2026-01-03 08:00:00');   // sent first, on 11-20
    $b = $mkApp('Q-B', 'Ben Bravo', '2026-01-01 08:00:00');    // applied earliest of all, but re-uploaded last
    $c = $mkApp('Q-C', 'Cara Charlie', '2026-01-02 08:00:00'); // sent second
    $mkDoc($a, 'barangay_cert', '2026-11-20 09:00:00');
    $mkDoc($c, 'barangay_cert', '2026-11-20 10:00:00');
    $bDoc = $mkDoc($b, 'barangay_cert', '2026-11-20 11:00:00');
    // Ben's file was replaced: the trail note and the new upload time are what the queue reads.
    $test->prepare('INSERT INTO document_status_history (document_id, application_id, doc_key, from_status, to_status, remarks, actor_name, actor_role, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$bDoc, $b, 'barangay_cert', 'NEEDS_CORRECTION', 'FOR_VERIFICATION', Document::RESUBMIT_NOTE, 'Solo Parent', 'applicant', '2026-11-20 11:00:00']);

    $queue = $docs->verificationQueue(10);
    sa_assert(array_column($queue, 'reference_no') === ['Q-B', 'Q-C', 'Q-A'], 'The queue is not oldest application first: ' . implode(',', array_column($queue, 'reference_no')));
    sa_assert((int) $queue[0]['resent_docs'] === 1 && (int) $queue[1]['resent_docs'] === 0 && (int) $queue[2]['resent_docs'] === 0, 'Only the re-upload must be marked.');

    // A later re-upload goes to the end; an earlier arrival stays ahead of it.
    $d = $mkApp('Q-D', 'Dina Delta', '2026-01-04 08:00:00');
    $mkDoc($d, 'barangay_cert', '2026-11-20 12:00:00');
    sa_assert(array_column($docs->verificationQueue(10), 'reference_no') === ['Q-B', 'Q-C', 'Q-A', 'Q-D'], 'A newer application must be after the older ones.');

    // Two waiting documents: the application stays where its OLDEST waiting document puts it, and both are counted.
    $mkDoc($d, 'valid_id', '2026-11-19 08:00:00');
    $queue = $docs->verificationQueue(10);
    sa_assert($queue[3]['reference_no'] === 'Q-D' && (int) $queue[3]['pending_docs'] === 2, 'Both waiting documents must be counted.');

    // Verified documents and finished applications leave the queue.
    $test->exec("UPDATE application_documents SET verification_status = 'VERIFIED' WHERE application_id = $a");
    $test->exec("UPDATE applications SET status = 'Approved' WHERE id = $c");
    sa_assert(array_column($docs->verificationQueue(10), 'reference_no') === ['Q-B', 'Q-D'], 'Verified or finished work must leave the queue.');

    $backlog = $docs->verificationBacklog();
    sa_assert((int) $backlog['applications'] === 2 && (int) $backlog['documents'] === 3 && (int) $backlog['resent_applications'] === 1, 'Backlog counts are wrong: ' . json_encode($backlog));
    sa_assert($docs->latestSentAt() === '2026-11-20 12:00:00', 'latestSentAt must be the newest waiting document.');
    $counts = staff_doc_waiting_counts();
    sa_assert($counts['applications'] === 2 && $counts['resent'] === 1, 'The menu badge counts are wrong.');

    // Admin dashboard "Waiting more than 7 days": Pending Review / Under Verification with no change for 7 days.
    // Application::search lists applications with their category, so the fixtures need a real one.
    $catId = sa_row($test, 'categories', ['name' => 'Queue fixture ' . bin2hex(random_bytes(2))]);
    $test->exec("UPDATE applications SET category_id = $catId");
    $fresh = $mkApp('Q-FRESH', 'Fresh Fiona', date('Y-m-d H:i:s'));
    $stale = (new Application($test))->search(['waiting' => 7], 1, 50);
    $staleRefs = array_column($stale['rows'], 'reference_no');
    sa_assert(in_array('Q-D', $staleRefs, true) && in_array('Q-B', $staleRefs, true), 'Old applications still under verification must be listed as waiting.');
    sa_assert(!in_array('Q-FRESH', $staleRefs, true) && !in_array('Q-C', $staleRefs, true), 'A fresh application and an approved one must not be listed as waiting.');
    $test->prepare("INSERT INTO application_status_history (application_id, status, changed_by) VALUES (?, 'Under Verification', 'x')")->execute([$d]);
    sa_assert(!in_array('Q-D', array_column((new Application($test))->search(['waiting' => 7], 1, 50)['rows'], 'reference_no'), true), 'A recent status change must take it off the waiting list.');
    sa_assert((new Application($test))->search(['waiting' => 0], 1, 50)['total'] > count($staleRefs) - 1, 'Without the filter every application is listed.');

    echo "PASS: verification queue lists the oldest application first, re-uploads are marked, and the tile, badge and alert counts agree.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
