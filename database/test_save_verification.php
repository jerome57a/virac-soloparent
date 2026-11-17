<?php
/**
 * Single "Save Verification" checks in a disposable, fully synthetic database.
 * The schema is built from database/schema.sql; no existing application or test database is
 * selected, read or copied. Requires the dedicated local test account described in
 * docs/database-tooling-safety.md (APP_ENV=testing, SMS_PROVIDER=test, DB_HOST, DB_USER, DB_PASS).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}
require __DIR__ . '/tool_safety.php';
$connection = require_isolated_temporary_database_environment();
$testDatabase = 'solo_review_test_' . bin2hex(random_bytes(6));
validate_temporary_database_target($testDatabase, 'solo_review_test_');
// config/config.php only defines constants and helpers; config/database.php (which connects to the
// configured application database) is deliberately never loaded.
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../services/ApplicationReviewService.php';
require_once __DIR__ . '/../controllers/StaffController.php';
require_once __DIR__ . '/../includes/csrf.php';
// Expected failures are logged to <TEST_STORAGE_ROOT>/logs (LOG_PATH under APP_ENV=testing), never the system temp folder or storage/logs.
if (!STORAGE_IS_TEST_ROOT || !storage_root_still_safe() || ini_set('error_log', LOG_PATH . '/save_verification_test.log') === false) {
    throw new RuntimeException('Refusing to run: the isolated test log folder is not available.');
}

function sv_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Fail closed: the isolated connection must never accept stacked statements.
if (!defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
    throw new RuntimeException('PDO::MYSQL_ATTR_MULTI_STATEMENTS is unavailable; cannot disable multi-statements on the test connection.');
}
$pdoOptions = [PDO::MYSQL_ATTR_MULTI_STATEMENTS => false, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
    // Same strict mode as config/database.php, so invalid values fail here as they would in the application.
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"];
$server = null; // server-level connection with no database selected; used only to create/drop $testDatabase
$created = false;
$exitCode = 0;
try {
    $server = new PDO('mysql:host=' . $connection['host'] . ';port=' . $connection['port'] . ';charset=utf8mb4', $connection['user'], $connection['pass'], $pdoOptions);
    create_temporary_database($server, $testDatabase, 'solo_review_test_');
    $created = true;
    $test = new PDO('mysql:host=' . $connection['host'] . ';port=' . $connection['port'] . ';dbname=' . $testDatabase . ';charset=utf8mb4', $connection['user'], $connection['pass'], $pdoOptions);
    // Application helpers (setting(), nav_notifications()) read the global connection; point it at the temporary database.
    $GLOBALS['pdo'] = $test;
    // Schema comes from schema.sql; any failure aborts immediately (the finally block still drops only $testDatabase).
    foreach (temporary_schema_executable_statements((string) file_get_contents(__DIR__ . '/schema.sql')) as $statement) {
        $test->exec($statement);
    }
    // Synthetic reference data only.
    $roleInsert = $test->prepare('INSERT INTO roles (id, role_key, code, name, description) VALUES (?, ?, ?, ?, ?)');
    foreach ([[1, 'admin', 'ADMINISTRATOR'], [2, 'mswd_social_worker', 'MSWD_STAFF'], [3, 'applicant', 'SOLO_PARENT']] as [$roleId, $roleKey, $roleCode]) {
        $roleInsert->execute([$roleId, $roleKey, $roleCode, 'Synthetic test role ' . $roleKey, 'Synthetic fixture for test_save_verification.php']);
    }
    $test->prepare('INSERT INTO categories (name, description, sort_order) VALUES (?, ?, 1)')->execute(['SYNTHETIC TEST CATEGORY', 'Synthetic fixture']);
    $syntheticCategory = (int) $test->lastInsertId();
    $requirementInsert = $test->prepare('INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order) VALUES (?, ?, ?, 1, ?)');
    foreach (['syntheticDocA', 'syntheticDocB', 'syntheticDocC'] as $order => $docKey) {
        $requirementInsert->execute([$syntheticCategory, $docKey, 'Synthetic required document ' . ($order + 1), $order + 1]);
    }
    $ins = $test->prepare('INSERT INTO users (full_name, email, username, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    $ids = [];
    foreach (['applicant', 'mswd_social_worker', 'admin'] as $role) {
        $ins->execute([$role, $role . '@example.test', $role, password_hash('fixture', PASSWORD_DEFAULT), $role]);
        $ids[$role] = (int) $test->lastInsertId();
    }
    $staff = ['id' => $ids['mswd_social_worker'], 'role' => 'mswd_social_worker', 'full_name' => 'Review fixture'];
    $category = (int) $test->query('SELECT category_id FROM document_requirements WHERE is_required = 1 AND category_id IS NOT NULL LIMIT 1')->fetchColumn();
    sv_assert($category > 0, 'A category with required documents is needed.');
    $keys = $test->query('SELECT doc_key FROM document_requirements WHERE is_required = 1 AND category_id = ' . $category . ' LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
    sv_assert(count($keys) >= 3, 'The fixture category needs at least three required documents.');

    $insApp = $test->prepare("INSERT INTO applications (reference_no,user_id,type,status,full_name,barangay,address,mobile,email,birthdate,sex,civil_status,category_id,consent_at,submitted_at) VALUES (?,?,'new','Under Verification','Fixture','Test','Test','09123456789','f@example.test','1990-01-01','Female','Single',?,NOW(),NOW())");
    $insDoc = $test->prepare("INSERT INTO application_documents (application_id,doc_key,original_name,stored_path,mime,size_bytes) VALUES (?,?,'file.pdf','x/file.pdf','application/pdf',10)");
    $apps = [];
    foreach (['A', 'B'] as $tag) {
        $insApp->execute(['SV-TEST-' . $tag, $ids['applicant'], $category]);
        $apps[$tag] = (int) $test->lastInsertId();
        foreach ($keys as $k) {
            $insDoc->execute([$apps[$tag], $k]);
        }
    }
    $docs = fn(int $appId) => array_column($test->query('SELECT id, doc_key, verification_status, staff_remarks, verified_by FROM application_documents WHERE application_id = ' . $appId . ' ORDER BY id')->fetchAll(), null, 'id');
    $count = fn(string $sql) => (int) $test->query($sql)->fetchColumn();
    $counts = fn() => [
        'history' => $count('SELECT COUNT(*) FROM document_status_history'),
        'audit' => $count("SELECT COUNT(*) FROM audit_logs WHERE action = 'Document Verification'"),
        'notifications' => $count('SELECT COUNT(*) FROM notifications'),
        'sms' => $count('SELECT COUNT(*) FROM sms_logs'),
    ];
    $aIds = array_keys($docs($apps['A']));
    $bBefore = $docs($apps['B']);
    $bFirst = array_key_first($bBefore);
    $applications = new Application($test);
    $service = new ApplicationReviewService($test);
    $appA = fn() => $applications->findForReview($apps['A']);
    // A posted row: the verdict plus the status/remarks the staff member saw. By default nothing was edited.
    $row = fn(string $status, string $remarks = '', string $expected = 'FOR_VERIFICATION', string $expectedRemarks = '') =>
        ['status' => $status, 'remarks' => $remarks, 'expected' => $expected, 'expected_remarks' => $expectedRemarks];
    // Every posted row also carries the FILE VERSION the page showed. Unless a case supplies its own, a row gets the token of the
    // file currently stored for its document id, which is what a staff page loaded just now would post. Cases about a changed
    // file (9b) set expected_version explicitly, so stale-file protection is never bypassed.
    $sv = function (array $who, array $app, array $rows) use ($service, $test): array {
        $fetch = $test->prepare('SELECT * FROM application_documents WHERE id = ?');
        foreach ($rows as $documentId => $postedRow) {
            if (is_array($postedRow) && !array_key_exists('expected_version', $postedRow)) {
                $fetch->execute([(int) $documentId]);
                $file = $fetch->fetch();
                if ($file) {
                    $rows[$documentId]['expected_version'] = Document::versionToken($file);
                }
            }
        }
        return $service->saveVerification($who, $app, $rows);
    };
    $untouched = fn(int $id) =>$row($docs($apps['A'])[$id]['verification_status'], (string) $docs($apps['A'])[$id]['staff_remarks'], $docs($apps['A'])[$id]['verification_status'], (string) $docs($apps['A'])[$id]['staff_remarks']);
    $reset = function () use ($test, $apps) {
        $test->exec("UPDATE application_documents SET verification_status = 'FOR_VERIFICATION', staff_remarks = NULL, verified_by = NULL, verified_at = NULL WHERE application_id = " . $apps['A']);
        $test->exec("UPDATE applications SET status = 'Under Verification' WHERE id = " . $apps['A']);
    };

    // 1. Single document: status, remarks, verifier, history, audit and in-app notice are written.
    $before = $counts();
    $r = $sv($staff, $appA(),[$aIds[0] => $row('VERIFIED'), $aIds[1] => $untouched($aIds[1]), $aIds[2] => $untouched($aIds[2])]);
    sv_assert($r['errors'] === [] && $r['changed'] === 1 && $r['notify_failures'] === 0, 'Single change was not saved.');
    $d = $docs($apps['A']);
    sv_assert($d[$aIds[0]]['verification_status'] === 'VERIFIED' && (int) $d[$aIds[0]]['verified_by'] === $staff['id'], 'Single change or verifier not stored.');
    $after = $counts();
    sv_assert($after['history'] === $before['history'] + 1 && $after['audit'] === $before['audit'] + 1 && $after['notifications'] === $before['notifications'] + 1, 'History, audit or notification missing for a single change.');

    // 2. Multiple documents together (one Verified, two Needs Correction).
    $reset();
    $before = $counts();
    $r = $sv($staff, $appA(),[
        $aIds[0] => $row('VERIFIED'),
        $aIds[1] => $row('NEEDS_CORRECTION', 'Photo is blurred.'),
        $aIds[2] => $row('NEEDS_CORRECTION', 'Wrong document.'),
    ]);
    sv_assert($r['errors'] === [] && $r['changed'] === 3, 'Multiple changes were not all saved.');
    $d = $docs($apps['A']);
    sv_assert($d[$aIds[0]]['verification_status'] === 'VERIFIED' && $d[$aIds[1]]['verification_status'] === 'NEEDS_CORRECTION'
        && $d[$aIds[1]]['staff_remarks'] === 'Photo is blurred.' && $d[$aIds[2]]['verification_status'] === 'NEEDS_CORRECTION' && $d[$aIds[2]]['staff_remarks'] === 'Wrong document.', 'Saved statuses are wrong after reload.');
    $after = $counts();
    sv_assert($after['history'] === $before['history'] + 3 && $after['audit'] === $before['audit'] + 3, 'History/audit rows missing for the batch.');
    // 3. One correction SMS per batch (two flagged documents), but one in-app notice per document.
    sv_assert($after['sms'] === $before['sms'] + 1, 'Expected exactly one correction SMS for the batch, got ' . ($after['sms'] - $before['sms']) . '.');
    sv_assert($after['notifications'] === $before['notifications'] + 3, 'Expected one in-app notice per changed document.');
    sv_assert($count("SELECT COUNT(*) FROM sms_logs WHERE template_key = 'document_correction'") === 1, 'The SMS was not the correction message.');

    // 4. Unchanged form: nothing written, nothing sent.
    $before = $counts();
    $r = $sv($staff, $appA(),array_combine($aIds, array_map($untouched, $aIds)));
    sv_assert($r['errors'] === [] && $r['changed'] === 0 && $counts() === $before, 'An unchanged form wrote something.');

    // 5. Failure while writing the second document rolls everything back (trigger forces the failure).
    $reset();
    $test->exec('CREATE TRIGGER sv_force_failure BEFORE UPDATE ON application_documents FOR EACH ROW BEGIN IF NEW.id = ' . $aIds[1] . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'; END IF; END");
    $beforeDocs = $docs($apps['A']);
    $before = $counts();
    $r = $sv($staff, $appA(),[
        $aIds[0] => $row('NEEDS_CORRECTION', 'Please re-scan.'),
        $aIds[1] => $row('VERIFIED'),
    ]);
    $test->exec('DROP TRIGGER sv_force_failure');
    sv_assert(isset($r['errors']['checklist']) && $r['changed'] === 0, 'A forced write failure was not reported.');
    sv_assert($docs($apps['A']) === $beforeDocs, 'A failed batch partly updated document statuses.');
    sv_assert($counts() === $before, 'A failed batch left history, audit, notification or SMS records.');
    sv_assert(!$test->inTransaction(), 'The transaction was left open.');

    // 6. A failed notification after commit does not undo the committed verification.
    $reset();
    $test->exec("CREATE TRIGGER sv_notify_failure BEFORE INSERT ON notifications FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced notification failure'; END");
    $before = $counts();
    $r = $sv($staff, $appA(),[$aIds[0] => $row('VERIFIED')]);
    $test->exec('DROP TRIGGER sv_notify_failure');
    sv_assert($r['errors'] === [] && $r['changed'] === 1 && $r['notify_failures'] === 1, 'A notification failure was not reported safely.');
    $after = $counts();
    sv_assert($docs($apps['A'])[$aIds[0]]['verification_status'] === 'VERIFIED' && $after['history'] === $before['history'] + 1 && $after['audit'] === $before['audit'] + 1, 'A notification failure rolled back the verification.');

    // 7. Untouched documents never block the batch: a legacy NEEDS_CORRECTION row with no remarks, and a row someone else changed.
    $reset();
    $test->exec("UPDATE application_documents SET verification_status = 'NEEDS_CORRECTION', staff_remarks = NULL WHERE id = " . $aIds[1]);
    $shown = $docs($apps['A']); // what this staff member's page displayed
    $test->exec("UPDATE application_documents SET verification_status = 'VERIFIED' WHERE id = " . $aIds[2]); // changed by someone else afterwards
    $r = $sv($staff, $appA(),[
        $aIds[0] => $row('VERIFIED'),
        $aIds[1] => $row('NEEDS_CORRECTION', '', 'NEEDS_CORRECTION', ''),
        $aIds[2] => $row('FOR_VERIFICATION', '', 'FOR_VERIFICATION', ''),
    ]);
    $d = $docs($apps['A']);
    sv_assert($r['errors'] === [] && $r['changed'] === 1, 'Untouched rows blocked the save.');
    sv_assert($d[$aIds[1]]['verification_status'] === 'NEEDS_CORRECTION' && $d[$aIds[2]]['verification_status'] === 'VERIFIED', 'An untouched document was overwritten.');

    // 8. Expected state is mandatory for every row.
    $reset();
    $before = $docs($apps['A']);
    $counted = $counts();
    foreach ([
        'missing expected' => ['status' => 'VERIFIED', 'remarks' => ''],
        'empty expected' => ['status' => 'VERIFIED', 'remarks' => '', 'expected' => '', 'expected_remarks' => ''],
        'invalid expected' => ['status' => 'VERIFIED', 'remarks' => '', 'expected' => 'BOGUS', 'expected_remarks' => ''],
        'missing expected remarks' => ['status' => 'VERIFIED', 'remarks' => '', 'expected' => 'FOR_VERIFICATION'],
    ] as $label => $bad) {
        $r = $sv($staff, $appA(),[$aIds[0] => $bad, $aIds[1] => $row('VERIFIED')]);
        sv_assert(isset($r['errors'][$aIds[0]]) && $docs($apps['A']) === $before && $counts() === $counted, 'Row with ' . $label . ' was not rejected cleanly (and the batch must stay unsaved).');
    }

    // 9. Stale edited rows are rejected (status or remarks changed by someone else).
    $test->exec("UPDATE application_documents SET verification_status = 'VERIFIED' WHERE id = " . $aIds[0]);
    $before = $docs($apps['A']);
    $r = $sv($staff, $appA(),[$aIds[0] => $row('NEEDS_CORRECTION', 'Blurred'), $aIds[1] => $row('VERIFIED')]);
    sv_assert(isset($r['errors'][$aIds[0]]) && $docs($apps['A']) === $before, 'A stale status was saved (or the batch partly saved).');
    $reset();
    $test->exec("UPDATE application_documents SET staff_remarks = 'Changed by colleague' WHERE id = " . $aIds[0]);
    $before = $docs($apps['A']);
    $r = $sv($staff, $appA(),[$aIds[0] => $row('VERIFIED')]);
    sv_assert(isset($r['errors'][$aIds[0]]) && $docs($apps['A']) === $before, 'Stale remarks were overwritten.');

    // 9b. The FILE changed after the page was loaded (an applicant replacement keeps status and remarks as they were): a verdict
    //     built for the earlier file is refused, nothing is written or sent, and a missing or empty version is refused too.
    $reset();
    $fetchFile = fn(int $id) => $test->query('SELECT * FROM application_documents WHERE id = ' . $id)->fetch();
    $shownVersion = Document::versionToken($fetchFile($aIds[0]));
    $test->exec("UPDATE application_documents SET stored_path = 'x/replaced.png', sha256 = '" . str_repeat('a', 64) . "', uploaded_at = DATE_ADD(uploaded_at, INTERVAL 1 SECOND) WHERE id = " . $aIds[0]);
    sv_assert(Document::versionToken($fetchFile($aIds[0])) !== $shownVersion, 'Replacing the file did not change its version token.');
    $before = $docs($apps['A']);
    $counted = $counts();
    $r = $sv($staff, $appA(), [$aIds[0] => $row('VERIFIED') + ['expected_version' => $shownVersion], $aIds[1] => $row('VERIFIED')]);
    sv_assert(isset($r['errors'][$aIds[0]]) && str_contains($r['errors'][$aIds[0]], 'newer file') && $r['changed'] === 0 && !isset($r['errors'][$aIds[1]]) && $docs($apps['A']) === $before && $counts() === $counted,
        'A verdict for the earlier file was accepted, or the batch partly saved, or notifications were sent.');
    $r = $sv($staff, $appA(), [$aIds[0] => $row('VERIFIED') + ['expected_version' => '']]);
    sv_assert(isset($r['errors'][$aIds[0]]) && $docs($apps['A']) === $before && $counts() === $counted, 'An empty file version was accepted.');
    $r = $service->saveVerification($staff, $appA(), [$aIds[0] => $row('VERIFIED')]); // no version at all, bypassing the helper
    sv_assert(isset($r['errors'][$aIds[0]]) && $docs($apps['A']) === $before && $counts() === $counted, 'A row without a file version was accepted.');
    $r = $sv($staff, $appA(), [$aIds[0] => $row('VERIFIED')]); // the version of the file as it is now
    sv_assert($r['errors'] === [] && $r['changed'] === 1 && $docs($apps['A'])[$aIds[0]]['verification_status'] === 'VERIFIED', 'A verdict for the current file was refused.');

    // 10. Legacy / unknown stored statuses are not silently overwritten.
    $reset();
    $test->exec("UPDATE application_documents SET verification_status = 'VALID' WHERE id = " . $aIds[2]);
    $r = $sv($staff, $appA(),[$aIds[0] => $row('VERIFIED'), $aIds[2] => $row('VALID', '', 'VALID', '')]);
    sv_assert($r['errors'] === [] && $docs($apps['A'])[$aIds[2]]['verification_status'] === 'VALID', 'A legacy status was changed by an untouched row.');
    $before = $docs($apps['A']);
    $r = $sv($staff, $appA(),[$aIds[2] => $row('VALID', 'just a remark', 'VALID', '')]);
    sv_assert(isset($r['errors'][$aIds[2]]) && $docs($apps['A']) === $before, 'A remarks-only edit overwrote a legacy status.');
    $r = $sv($staff, $appA(),[$aIds[2] => $row('VERIFIED', '', 'VALID', '')]);
    sv_assert($r['errors'] === [] && $docs($apps['A'])[$aIds[2]]['verification_status'] === 'VERIFIED', 'A deliberate change from a legacy status was refused.');

    // 10b. Legacy REJECTED records stay readable and are never overwritten implicitly, but cannot be newly assigned.
    $reset();
    $test->exec("UPDATE application_documents SET verification_status = 'REJECTED', staff_remarks = 'Old rejection.' WHERE id = " . $aIds[2]);
    $r = $sv($staff, $appA(),[$aIds[0] => $row('VERIFIED'), $aIds[2] => $row('REJECTED', 'Old rejection.', 'REJECTED', 'Old rejection.')]);
    sv_assert($r['errors'] === [] && $r['changed'] === 1 && $docs($apps['A'])[$aIds[2]]['verification_status'] === 'REJECTED', 'An untouched legacy REJECTED row was changed or blocked the save.');
    $before = $docs($apps['A']);
    $r = $sv($staff, $appA(),[$aIds[2] => $row('REJECTED', 'Edited remark.', 'REJECTED', 'Old rejection.')]);
    sv_assert(isset($r['errors'][$aIds[2]]) && $docs($apps['A']) === $before, 'A remarks-only edit kept REJECTED as an assignable status.');
    $r = $sv($staff, $appA(),[$aIds[1] => $row('REJECTED', 'Wrong document.')]);
    sv_assert(isset($r['errors'][$aIds[1]]) && $docs($apps['A']) === $before, 'REJECTED was newly assigned.');
    $r = $sv($staff, $appA(),[$aIds[2] => $row('NEEDS_CORRECTION', 'Please re-scan.', 'REJECTED', 'Old rejection.')]);
    sv_assert($r['errors'] === [] && $docs($apps['A'])[$aIds[2]]['verification_status'] === 'NEEDS_CORRECTION', 'A legacy REJECTED document could not be moved to a current status.');

    // 11. Remarks are required only for edited rows assigned Needs Correction; bad status values are refused.
    $reset();
    $before = $docs($apps['A']);
    $r = $sv($staff, $appA(),[$aIds[0] => $row('NEEDS_CORRECTION', '  ')]);
    sv_assert(isset($r['errors'][$aIds[0]]) && $docs($apps['A']) === $before, 'NEEDS_CORRECTION was accepted without remarks.');
    $r = $sv($staff, $appA(),[$aIds[0] => $row('REJECTED', '')]);
    sv_assert(isset($r['errors'][$aIds[0]]) && $docs($apps['A']) === $before, 'REJECTED was accepted as a new status.');
    $r = $sv($staff, $appA(),[$aIds[0] => $row('BOGUS')]);
    sv_assert(isset($r['errors'][$aIds[0]]) && $docs($apps['A']) === $before, 'An invalid status was accepted.');
    $r = $sv($staff, $appA(),[$aIds[0] => $row('NEEDS_CORRECTION', str_repeat('x', 501))]);
    sv_assert(isset($r['errors'][$aIds[0]]) && $docs($apps['A']) === $before, 'Over-long remarks were accepted.');

    // 12. Application / document ownership: another application's document cannot be changed, alone or in a batch.
    $r = $sv($staff, $appA(),[$bFirst => $row('VERIFIED')]);
    sv_assert(isset($r['errors'][$bFirst]) && $docs($apps['B']) === $bBefore, 'Another application document was modified.');
    $r = $sv($staff, $appA(),[$aIds[0] => $row('VERIFIED'), $bFirst => $row('VERIFIED')]);
    sv_assert($r['errors'] !== [] && $docs($apps['B']) === $bBefore && $docs($apps['A']) === $before, 'A mixed batch changed records.');
    $test->exec("UPDATE applications SET status = 'Approved' WHERE id = " . $apps['A']);
    $r = $sv($staff, $appA(),[$aIds[0] => $row('VERIFIED')]);
    sv_assert(isset($r['errors'][$aIds[0]]) && $docs($apps['A']) === $before, 'A finished application accepted document changes.');
    $test->exec("UPDATE applications SET status = 'Under Verification' WHERE id = " . $apps['A']);

    // 13. Roles without the processing permission cannot reach the action.
    sv_assert(!can(['id' => $ids['applicant'], 'role' => 'applicant'], 'applications.process'), 'Applicant may process applications.');
    sv_assert(!can(['id' => $ids['admin'], 'role' => 'admin'], 'applications.process'), 'Admin may process applications.');
    sv_assert(can($staff, 'applications.process'), 'Staff cannot process applications.');
    // The service itself also refuses callers without the permission and writes nothing.
    $reset();
    $before = $docs($apps['A']);
    $counted = $counts();
    foreach ([['id' => $ids['applicant'], 'role' => 'applicant', 'full_name' => 'applicant'], ['id' => $ids['admin'], 'role' => 'admin', 'full_name' => 'admin']] as $denied) {
        $r = $sv($denied, $appA(),[$aIds[0] => $row('VERIFIED')]);
        sv_assert(isset($r['errors']['checklist']) && $r['changed'] === 0 && $docs($apps['A']) === $before && $counts() === $counted, $denied['role'] . ' was able to save verification.');
    }

    // 14. Page: exactly one Save Verification button, expected fields, legacy status shown and not selectable by accident.
    $reset();
    $test->exec("UPDATE application_documents SET verification_status = 'VALID' WHERE id = " . $aIds[2]);
    $test->exec("UPDATE application_documents SET verification_status = 'REJECTED', staff_remarks = 'Old rejection.' WHERE id = " . $aIds[1]);
    $controller = (new ReflectionClass(StaffController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Controller::class, 'pdo'))->setValue($controller, $test);
    (new ReflectionProperty(StaffController::class, 'staff'))->setValue($controller, $staff);
    $_GET = ['id' => $apps['A']];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/staff/application.php';
    ob_start();
    $controller->application();
    $html = ob_get_clean();
    sv_assert(substr_count($html, 'Save Verification') === 1, 'Page must show exactly one Save Verification button.');
    sv_assert(substr_count($html, 'value="save_verification"') === 1 && !str_contains($html, 'update_document_status') && !str_contains($html, 'Save review'), 'Per-document save controls remain.');
    sv_assert(substr_count($html, '[expected]"') === count($keys) && substr_count($html, '[expected_remarks]"') === count($keys), 'Each document needs both expected fields.');
    sv_assert(substr_count($html, '[expected_version]"') === count($keys), 'Each document needs its file version field.');
    foreach ($aIds as $aId) {
        sv_assert(str_contains($html, 'name="docs[' . $aId . '][expected_version]" value="' . Document::versionToken($fetchFile($aId)) . '"'), 'A document row does not carry the version of the file it displays.');
    }
    sv_assert(preg_match('/<option value="VALID" selected>[^<]*current status/', $html) === 1, 'The legacy status is not shown as the selected current value.');
    sv_assert(preg_match('/<option value="REJECTED" selected>[^<]*Rejected \(legacy\)[^<]*current status/', $html) === 1 && substr_count($html, 'value="REJECTED"') === 1, 'Legacy REJECTED must show only as the read-only current value, not as a selectable status.');
    sv_assert(str_contains($html, 'download.php?doc_id=' . $aIds[0]), 'Document view/download links are missing.');
    sv_assert($count('SELECT COUNT(*) FROM application_documents') === count($keys) * 2, 'Document rows were removed.');
    echo "Single Save Verification checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($created && $server instanceof PDO) {
        drop_temporary_database($server, $testDatabase, 'solo_review_test_'); // re-validates the generated name
    }
}
exit($exitCode);
