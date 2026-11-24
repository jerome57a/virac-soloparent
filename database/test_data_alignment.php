<?php
/**
 * The data alignment migration (services/DataAlignment.php, database/migrate_data_alignment.php) on SYNTHETIC data in a disposable
 * database:
 *   - the 1x1 ID pictures stop being a requirement (new application and renewal), no longer block approval, keep their uploaded file
 *     with a readable name, and a renewal never asks for them again (even when the old picture expired);
 *   - settings never saved are saved with their default, an administrator's own value is kept;
 *   - the expiry-day SMS wording is updated;
 *   - the unused mobile-code columns are dropped only when empty;
 *   - a check run changes nothing, a second run finds nothing to change, the review lists are reported.
 *
 *   php database/test_data_alignment.php   (isolated test environment only)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
$testDb = 'solo_review_test_' . bin2hex(random_bytes(6));
validate_temporary_database_target($testDb, 'solo_review_test_');
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/DataAlignment.php';
require_once __DIR__ . '/../models/RenewalRequirement.php';

function da_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }

/** Inserts one row, filling every other NOT NULL column that has no default with a harmless value. Returns the new id. */
function da_row(PDO $pdo, string $table, array $given): int
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
            default => 'x' . bin2hex(random_bytes(3)),
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
    $hasCol = fn(string $t, string $c) => (bool) $test->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND COLUMN_NAME = '$c'")->fetchColumn();
    // An older structure may lack these; the migration and the report need them as they are on the live database.
    if (!$hasCol('application_children', 'has_disability')) { $test->exec('ALTER TABLE application_children ADD COLUMN has_disability TINYINT(1) NOT NULL DEFAULT 0'); }
    if (!$hasCol('users', 'mobile_code')) { $test->exec('ALTER TABLE users ADD COLUMN mobile_code VARCHAR(255) NULL, ADD COLUMN mobile_code_expires_at DATETIME NULL, ADD COLUMN mobile_code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0'); }
    $quiet = function (string $line) {};
    $count = fn(string $sql) => (int) $test->query($sql)->fetchColumn();

    // Synthetic catalogue: one category with the picture and a birth certificate; its renewal list has the picture too.
    $test->exec("INSERT INTO roles (id, role_key, code, name) VALUES (1,'applicant','APP','Solo Parent'),(2,'mswd_social_worker','MSWD','MSWDO Staff'),(3,'admin','ADM','Admin')");
    $cat = da_row($test, 'categories', ['name' => 'Fixture category', 'is_active' => 1]);
    $ins = $test->prepare('INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order) VALUES (?, ?, ?, 1, ?)');
    $ins->execute([$cat, 'photo1x1', 'Two 1x1 ID pictures', 1]);
    $ins->execute([$cat, 'birthCert', 'Birth certificate', 2]);
    $test->prepare('INSERT INTO renewal_requirements (category_id, doc_key, label, always_refresh, sort_order) VALUES (?, ?, ?, 1, ?)')->execute([$cat, 'photo1x1', 'Two 1x1 ID pictures', 1]);
    $test->prepare('INSERT INTO renewal_requirements (category_id, doc_key, label, always_refresh, sort_order) VALUES (?, ?, ?, 1, ?)')->execute([$cat, 'soleCareAffidavit', 'Sole care affidavit', 2]);
    $user = da_row($test, 'users', ['role' => 'applicant', 'full_name' => 'Fixture Parent', 'is_active' => 1]);
    $newApp = da_row($test, 'applications', ['reference_no' => 'FX-NEW-1', 'user_id' => $user, 'type' => 'new', 'category_id' => $cat, 'status' => 'Under Verification', 'full_name' => 'Fixture Parent']);
    $docIns = fn(int $app, string $key, string $status) => da_row($test, 'application_documents', ['application_id' => $app, 'doc_key' => $key, 'original_name' => $key . '.jpg', 'stored_path' => 'x/' . $key . '.jpg', 'mime' => 'image/jpeg', 'verification_status' => $status, 'size_bytes' => 1000]);
    $photoDoc = $docIns($newApp, 'photo1x1', 'FOR_VERIFICATION');
    $docIns($newApp, 'birthCert', 'VERIFIED');
    $renewApp = da_row($test, 'applications', ['reference_no' => 'FX-REN-1', 'user_id' => $user, 'type' => 'renewal', 'category_id' => $cat, 'status' => 'Under Verification', 'full_name' => 'Fixture Parent']);
    $test->prepare('INSERT INTO application_document_checklists (application_id, doc_key, required_snapshot, requirement_label) VALUES (?, ?, 1, ?)')->execute([$renewApp, 'photo1x1', 'Two 1x1 ID pictures']);
    $test->prepare('INSERT INTO application_document_checklists (application_id, doc_key, required_snapshot, requirement_label) VALUES (?, ?, 1, ?)')->execute([$renewApp, 'soleCareAffidavit', 'Sole care affidavit']);
    // A child over 22 without a disability (report only) and an application whose account is gone (report only).
    da_row($test, 'application_children', ['application_id' => $newApp, 'full_name' => 'Old Child', 'birthdate' => date('Y-m-d', strtotime('-30 years')), 'has_disability' => 0]);
    da_row($test, 'applications', ['reference_no' => 'FX-ORPHAN', 'user_id' => 999999, 'category_id' => $cat, 'status' => 'Under Verification', 'full_name' => 'Gone']);
    // An administrator's own office name, the old expiry-day text, and a pending mobile code.
    $test->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('office_name', 'Custom Office')");
    $test->prepare('INSERT INTO sms_templates (template_key, label, body) VALUES (?, ?, ?)')->execute(['renewal_reminder', 'Renewal Reminder (auto)', 'MSWDO Virac: Your Solo Parent ID ({ref}) expires on {when}. Please visit MSWDO or apply for renewal online soon to avoid interruption.']);
    $test->exec("UPDATE users SET mobile_code = 'abc' WHERE id = $user");

    $docs = new Document($test);
    $app = fn(int $id) => $test->query("SELECT * FROM applications WHERE id = $id")->fetch();
    $outstandingKeys = fn(int $id) => array_column($docs->outstandingRequired($app($id)), 'doc_key');
    da_assert(in_array('photo1x1', $outstandingKeys($newApp), true), 'Before: the unverified picture blocks the new application.');
    da_assert(in_array('photo1x1', $outstandingKeys($renewApp), true), 'Before: the renewal checklist requires the picture.');

    // A check run changes nothing.
    $before = $count('SELECT COUNT(*) FROM document_requirements') . '/' . $count('SELECT COUNT(*) FROM system_settings') . '/' . $count("SELECT COUNT(*) FROM application_document_checklists WHERE required_snapshot = 1");
    $check = DataAlignment::run($test, false, $quiet);
    da_assert($check['changed']['requirements'] === 1 && $check['changed']['renewal_requirements'] === 1 && $check['changed']['snapshots'] === 1, 'The check run counts the work: ' . json_encode($check['changed']));
    da_assert($before === $count('SELECT COUNT(*) FROM document_requirements') . '/' . $count('SELECT COUNT(*) FROM system_settings') . '/' . $count("SELECT COUNT(*) FROM application_document_checklists WHERE required_snapshot = 1"), 'A check run changes nothing.');
    da_assert($hasCol('users', 'mobile_code'), 'A check run drops nothing.');

    // Apply.
    $lines = [];
    $result = DataAlignment::run($test, true, function ($l) use (&$lines) { $lines[] = $l; });
    da_assert($count("SELECT COUNT(*) FROM document_requirements WHERE doc_key = 'photo1x1'") === 0 && $count("SELECT COUNT(*) FROM document_requirements WHERE doc_key = 'birthCert'") === 1, 'The picture requirement is gone; the others stay.');
    da_assert($count("SELECT COUNT(*) FROM renewal_requirements WHERE doc_key = 'photo1x1'") === 0 && $count("SELECT COUNT(*) FROM renewal_requirements") === 1, 'The renewal picture requirement is gone; the others stay.');
    da_assert($count("SELECT COUNT(*) FROM application_documents WHERE id = $photoDoc") === 1, 'The uploaded picture is kept as history.');
    da_assert(!in_array('photo1x1', $outstandingKeys($newApp), true), 'After: the picture no longer blocks the new application.');
    da_assert(!in_array('photo1x1', $outstandingKeys($renewApp), true) && in_array('soleCareAffidavit', $outstandingKeys($renewApp), true), 'After: the renewal no longer requires the picture, but still requires the affidavit.');
    $shown = array_column($docs->checklistForReview($newApp, $cat), null, 'doc_key')['photo1x1'] ?? null;
    da_assert($shown && $shown['label'] === Document::RETIRED_LABELS['photo1x1'] && (int) $shown['is_required'] === 0, 'Staff see the old picture with a readable name, not required: ' . json_encode($shown['label'] ?? null));
    $snap = array_column($docs->checklistForReview($renewApp, $cat, true), null, 'doc_key')['photo1x1'] ?? null;
    da_assert($snap && $snap['label'] === Document::RETIRED_LABELS['photo1x1'] && (int) $snap['is_required'] === 0, 'The renewal snapshot row has the readable name and is not required.');
    da_assert($test->query("SELECT setting_value FROM system_settings WHERE setting_key = 'office_name'")->fetchColumn() === 'Custom Office', 'An administrator\'s own value is kept.');
    da_assert($test->query("SELECT setting_value FROM system_settings WHERE setting_key = 'renewal_grace_days'")->fetchColumn() === (string) setting_definition('renewal_grace_days')['default'], 'A never-saved setting is saved with its default.');
    da_assert(str_contains((string) $test->query("SELECT body FROM sms_templates WHERE template_key = 'renewal_reminder'")->fetchColumn(), 'expires today'), 'The expiry-day text is updated.');
    da_assert($hasCol('users', 'mobile_code') && $result['changed']['columns'] === 0 && preg_grep('/left in place/', $lines), 'A column that still holds a value is not dropped.');
    da_assert(array_column($result['review']['children'], 'reference_no') === ['FX-NEW-1'], 'The child over 22 without a disability is reported.');
    da_assert(array_column($result['review']['orphans'], 'reference_no') === ['FX-ORPHAN'], 'The application without an account is reported.');
    da_assert($count("SELECT COUNT(*) FROM applications WHERE reference_no = 'FX-ORPHAN'") === 1, 'Reported records are not changed.');

    // A second run finds nothing to change; once the code is cleared the columns are dropped.
    $again = DataAlignment::run($test, true, $quiet);
    da_assert($again['changed'] === ['requirements' => 0, 'renewal_requirements' => 0, 'snapshots' => 0, 'settings' => 0, 'columns' => 0], 'A second run changes nothing: ' . json_encode($again['changed']));
    $test->exec('UPDATE users SET mobile_code = NULL');
    $users = $count('SELECT COUNT(*) FROM users');
    $dropped = DataAlignment::run($test, true, $quiet);
    da_assert($dropped['changed']['columns'] === 3 && !$hasCol('users', 'mobile_code') && !$hasCol('users', 'mobile_code_attempts') && $count('SELECT COUNT(*) FROM users') === $users, 'Empty columns are dropped, every user kept.');
    da_assert($hasCol('users', 'mobile_verified_at') || !$hasCol('users', 'mobile_code'), 'Other user columns are untouched.');

    // A renewal never asks for the picture again, even when the old accepted picture has expired.
    $released = da_row($test, 'applications', ['reference_no' => 'FX-REL-1', 'user_id' => $user, 'type' => 'new', 'category_id' => $cat, 'status' => 'ID Released', 'full_name' => 'Fixture Parent']);
    $test->prepare("INSERT INTO application_document_checklists (application_id, doc_key, applicant_has, verification_status, expires_at) VALUES (?, 'photo1x1', 1, 'Verified', ?)")->execute([$released, date('Y-m-d', strtotime('-10 days'))]);
    $plan = (new RenewalRequirement($test))->plan($app($released));
    da_assert(!in_array('photo1x1', array_column($plan['fresh'], 'doc_key'), true), 'An expired old picture is not asked for again at renewal.');
    da_assert(in_array('soleCareAffidavit', array_column($plan['fresh'], 'doc_key'), true), 'The renewal still asks its own documents.');

    echo "PASS: data alignment: the 1x1 pictures leave the new-application and renewal lists, stop blocking approval, keep their file with a readable name and are never asked at renewal; unsaved settings get their default (an administrator's value is kept); the expiry text is updated; the mobile-code columns are dropped only when empty; check run and second run change nothing; review lists reported.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
