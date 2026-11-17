<?php
/**
 * Disposable-database checks for supporting-document uploads in the new-application wizard.
 * Synthetic data only: no row is copied from any existing database, and no real file is read.
 * Run only in the isolated test environment (see docs/database-tooling-safety.md). It also writes synthetic
 * files under STORAGE_PATH, which with APP_ENV=testing is the dedicated TEST_STORAGE_ROOT/uploads (config/storage.php
 * refuses to start otherwise): upload_fixtures.php refuses any folder that already exists or lies outside the test
 * id range, and deletes only the exact files this run created.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
$testDb = 'newapp_upload_test_' . bin2hex(random_bytes(6));
validate_temporary_database_target($testDb, 'newapp_upload_test_');
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/ApplicationService.php';
require_once __DIR__ . '/../services/ApplicationReviewService.php';
require_once __DIR__ . '/../controllers/ApplicationController.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/upload_fixtures.php';

function upload_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }

/**
 * Stands in for DocumentValidationService where the real one needs move_uploaded_file(), which only works for
 * genuine HTTP uploads. A fixture file ('fixture_bytes') is checked for a decodable image and stored like the real
 * one stores it (random name in the destination, same metadata); everything else goes through the REAL validator,
 * which refuses it exactly as it would a bad HTTP upload.
 */
class FixtureDocumentValidator extends DocumentValidationService
{
    public function storeDocument(array $file, string $destDir, bool $imageOnly = false): array
    {
        if (!isset($file['fixture_bytes'])) {
            return parent::storeDocument($file, $destDir, $imageOnly); // new-application uploads are image-only (see ApplicationService::attachDocument)
        }
        $bytes = (string) $file['fixture_bytes'];
        if (@getimagesizefromstring($bytes) === false) {
            return ['ok' => false, 'error' => 'The file appears to be damaged or is not a real PNG file.', 'status' => 'INVALID', 'meta' => null];
        }
        $stored = bin2hex(random_bytes(16)) . '.png';
        upload_fixture_write($destDir, $stored, $bytes);
        return ['ok' => true, 'error' => '', 'status' => 'VALID', 'meta' => [
            'original_name' => (string) $file['name'], 'stored_name' => $stored, 'path' => $destDir . '/' . $stored,
            'mime' => 'image/png', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'status' => 'VALID',
        ]];
    }
}

$live = $pdo;
$created = false;
$cleanupFiles = [];  // temporary files in TEST_STORAGE_ROOT/tmp (outside STORAGE_PATH) that this test creates; removed in `finally`
$exitCode = 0;
try {
    $live->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4');
    $created = true;
    $test = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $testDb . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    if ($test->query('SELECT DATABASE()')->fetchColumn() !== $testDb) {
        throw new RuntimeException('The test connection is not bound to the generated database.');
    }
    $test->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($live->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $test->exec($live->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1]);
    }
    $test->exec('SET FOREIGN_KEY_CHECKS=1');
    upload_fixture_isolate_ids($test);
    // Synthetic fixtures only.
    $test->exec("INSERT INTO roles (id, role_key, code, name, description) VALUES (1,'admin','ADMINISTRATOR','Fixture Administrator','Fixture'),(2,'mswd_social_worker','MSWD_STAFF','Fixture Staff','Fixture'),(3,'applicant','SOLO_PARENT','Fixture Applicant','Fixture')");
    $pdo = $test; // runtime settings, notifications and audit entries stay in the disposable database
    $GLOBALS['__settings_cache'] = ['sms_enabled' => '0'];
    $_SESSION = $_SESSION ?? [];

    $users = new User($test);
    $insertUser = $test->prepare('INSERT INTO users (id,full_name,email,username,password_hash,role,mobile,mobile_verified_at) VALUES (?,?,?,?,?,?,?,NOW())');
    $baseUserId = 1800000000 + random_int(2000000, 9000000);
    $makeUser = static function (int $n) use ($insertUser, $users, $baseUserId): array {
        $insertUser->execute([$baseUserId + $n, 'Fixture Applicant', 'upload' . $n . '@example.test', 'upload.fixture' . $n, password_hash('Fixture123', PASSWORD_DEFAULT), 'applicant', '0912345678' . $n]);
        return $users->findById($baseUserId + $n);
    };
    $insertUser->execute([$baseUserId + 90, 'Fixture Staff', 'upload-staff@example.test', 'upload.staff', password_hash('Fixture123', PASSWORD_DEFAULT), 'mswd_social_worker', null]);
    $staff = $users->findById($baseUserId + 90);

    $test->exec("INSERT INTO categories (name,description,sort_order) VALUES ('Fixture upload category','Fixture',1)");
    $category = (int) $test->query("SELECT id FROM categories WHERE name='Fixture upload category'")->fetchColumn();
    $requirement = $test->prepare('INSERT INTO document_requirements (category_id,doc_key,label,is_required,sort_order) VALUES (?,?,?,?,?)');
    foreach ([['reqA', 'Fixture requirement A', 1, 1], ['reqB', 'Fixture requirement B', 1, 2], ['optC', 'Fixture optional C', 0, 3]] as [$key, $label, $required, $order]) {
        $requirement->execute([$category, $key, $label, $required, $order]);
    }
    $documents = new Document($test);
    $applications = new Application($test);
    $service = new ApplicationService($test);
    $swap = new ReflectionProperty(ApplicationService::class, 'files');
    $swap->setAccessible(true);
    $swap->setValue($service, new FixtureDocumentValidator($test));

    $baseDraft = static function (array $user) use ($service, $category): array {
        return array_replace($service->freshDraft($user), [
            'categoryId' => $category, 'barangay' => 'Fixture barangay', 'address' => 'Fixture address', 'birthdate' => '1990-01-01',
            'sex' => 'Female', 'civilStatus' => 'Single', 'children' => [['name' => 'Fixture Child', 'birthdate' => '2020-01-01']], 'consent' => true,
        ]);
    };
    $png = upload_fixture_png();
    $fixtureFile = static fn(string $name, string $bytes): array => ['name' => $name, 'type' => 'image/png', 'tmp_name' => '', 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes), 'fixture_bytes' => $bytes];
    $rows = static fn(string $sql, array $params = []): array => (function () use ($test, $sql, $params) { $s = $test->prepare($sql); $s->execute($params); return $s->fetchAll(); })();
    $count = static fn(string $sql, array $params = []): int => (int) (function () use ($test, $sql, $params) { $s = $test->prepare($sql); $s->execute($params); return $s->fetchColumn(); })();

    // ---- 1. No files: the Documents step and the whole draft are refused; ticking boxes counts for nothing ----
    $user = $makeUser(1);
    $draft = $baseDraft($user);
    $errors = $service->validateStep(3, $draft);
    upload_assert(isset($errors['documents']) && str_contains($errors['documents'], 'Fixture requirement A') && str_contains($errors['documents'], 'Fixture requirement B') && !str_contains($errors['documents'], 'Fixture optional C'), 'Missing required uploads were not listed, or an optional one was demanded.');
    upload_assert($service->firstInvalidStep($draft)['step'] === 3, 'A draft with no uploads was not stopped at the Documents step.');
    $ticked = $service->collectStepInput(3, $draft, ['checklist' => ['reqA', 'reqB'], 'checkedDocuments' => ['reqA' => '1', 'reqB' => '1']]);
    upload_assert(isset($service->validateStep(3, $ticked)['documents']) && !isset($ticked['checkedDocuments']), 'Ticked boxes satisfied a document requirement.');
    upload_assert(!array_key_exists('checkedDocuments', $service->freshDraft($user)), 'New drafts still carry the retired checkbox field.');

    // ---- 2. Partial uploads: only the missing requirement is reported ----
    $draft = upload_fixture_stage($service, $user, $draft, 'reqA');
    $partial = $service->validateStep(3, $draft);
    upload_assert(isset($partial['documents']) && str_contains($partial['documents'], 'Fixture requirement B') && !str_contains($partial['documents'], 'Fixture requirement A'), 'A partly uploaded draft reported the wrong missing documents.');
    $stagedA = $draft['documents']['reqA'];

    // ---- 3. Invalid uploads are refused with a reason and never replace a good file ----
    $bad = [
        'no file' => ['name' => 'b.png', 'type' => 'image/png', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
        'too large' => ['name' => 'b.png', 'type' => 'image/png', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0],
        'tampered arrays' => ['name' => ['b.png'], 'type' => ['image/png'], 'tmp_name' => ['x'], 'error' => [UPLOAD_ERR_OK], 'size' => [1]],
        'corrupt image' => $fixtureFile('b.png', 'not an image at all'),
    ];
    upload_assert(TEST_TMP_PATH !== null && storage_root_still_safe(), 'The test temp folder is not the validated TEST_STORAGE_ROOT/tmp.');
    $notUploaded = tempnam(TEST_TMP_PATH, 'upl'); // inside the test root, never the system temp folder
    $cleanupFiles[] = $notUploaded;
    file_put_contents($notUploaded, $png);
    $bad['not a real HTTP upload'] = ['name' => 'b.png', 'type' => 'image/png', 'tmp_name' => $notUploaded, 'error' => UPLOAD_ERR_OK, 'size' => strlen($png)];
    foreach ($bad as $why => $file) {
        $try = $service->attachDocument($user, $draft, 'reqB', $file);
        upload_assert(!isset($try['documents']['reqB']) && isset($try['docErrors']['reqB']), 'An invalid upload (' . $why . ') was accepted.');
        upload_assert(isset($service->validateStep(3, $try)['upload_reqB']), 'The refusal (' . $why . ') is not reported by step validation.');
        $replace = $service->attachDocument($user, $draft, 'reqA', $file);
        upload_assert(($replace['documents']['reqA']['stored_name'] ?? '') === $stagedA['stored_name'] && is_file($stagedA['path']), 'A failed replacement (' . $why . ') removed or replaced the staged file.');
    }
    $forged = $service->attachDocument($user, $draft, 'notARequirement', $fixtureFile('b.png', $png));
    upload_assert(!isset($forged['documents']['notARequirement']) && isset($forged['docErrors']['notARequirement']), 'A file for a document the category does not require was accepted.');
    $outsider = $service->attachDocument($user, array_replace($draft, ['categoryId' => null]), 'reqA', $fixtureFile('b.png', $png));
    upload_assert(isset($outsider['docErrors']['reqA']), 'A file was accepted with no category chosen.');

    // ---- 4. Replacement: the new file wins, the old one is deleted, errors clear ----
    $second = $service->attachDocument($user, $draft, 'reqA', $fixtureFile('replacement.png', $png . 'extra'));
    upload_assert(!isset($second['docErrors']['reqA']) && $second['documents']['reqA']['stored_name'] !== $stagedA['stored_name'], 'Replacing a staged file did not stage the new one.');
    upload_assert(!is_file($stagedA['path']) && is_file($second['documents']['reqA']['path']), 'Replacing a staged file left the old file behind or lost the new one.');
    upload_assert($second['documents']['reqA']['sha256'] === hash('sha256', $png . 'extra') && $second['documents']['reqA']['original_name'] === 'replacement.png', 'The replacement was not recorded with its own name and hash.');
    upload_assert(dirname($second['documents']['reqA']['path']) === $service->draftDir((int) $user['id']), 'The staged file is not in the applicant\'s draft folder.');
    $draft = $second;

    // ---- 5. A staged file for a key the category does not require is dropped and deleted ----
    $withStray = upload_fixture_stage($service, $user, $draft, 'strayKey');
    $stray = $withStray['documents']['strayKey']['path'];
    $pruned = $service->collectStepInput(3, $withStray, ['consent' => '1']);
    upload_assert(!isset($pruned['documents']['strayKey']) && !is_file($stray) && isset($pruned['documents']['reqA']) && is_file($pruned['documents']['reqA']['path']), 'A stray staged key was kept, or a valid staged file was dropped.');

    // ---- 6. A staged file that vanished or changed no longer counts ----
    $gone = $draft; $gone['documents']['reqA']['path'] .= '.missing';
    $goneErrors = $service->validateStep(3, $gone);
    upload_assert(isset($goneErrors['documents']) && isset($goneErrors['upload_reqA']), 'A staged file that no longer exists passed validation.');
    $altered = upload_fixture_stage($service, $user, $draft, 'reqA');
    file_put_contents($altered['documents']['reqA']['path'], 'tampered after upload');
    upload_assert(isset($service->validateStep(3, $altered)['upload_reqA']), 'A staged file changed after upload passed validation.');
    $draft = $service->attachDocument($user, $draft, 'reqA', $fixtureFile('replacement.png', $png . 'extra')); // a good file again

    // ---- 7. Views: upload controls, the staged file, the reason, the missing list, no after-submit wording ----
    $renderStep = static function (array $data): string { extract($data); ob_start(); require APP_ROOT . '/views/applicant/application/step-documents.php'; return ob_get_clean(); };
    $renderReview = static function (array $data): string { extract($data); ob_start(); require APP_ROOT . '/views/applicant/application/review.php'; return ob_get_clean(); };
    $docDefs = $documents->requirementsFor($category);
    $catOnlyKeys = array_column($documents->categoryOnlyRequirements($category), 'doc_key');
    $viewDraft = $draft;
    $viewErrors = $service->validateStep(3, $viewDraft) + ['upload_optC' => 'Fixture optional C: File must be an image file (JPG, JPEG or PNG only). PDF and other document files are not accepted.'];
    $html = $renderStep(['draft' => $viewDraft, 'errors' => $viewErrors, 'docDefs' => $docDefs, 'catOnlyKeys' => $catOnlyKeys, 'isApplicationWizard' => true]);
    upload_assert(str_contains($html, 'enctype="multipart/form-data"') && str_contains($html, 'name="application_file[reqA]"') && str_contains($html, 'name="application_file[reqB]"') && str_contains($html, 'name="application_file[optC]"'), 'The Documents step has no upload control for a requirement.');
    upload_assert(str_contains($html, 'replacement.png') && str_contains($html, 'Choose another file only to replace it') && str_contains($html, 'File ready to submit'), 'The staged file and the replace hint are not shown.');
    upload_assert(str_contains($html, 'Fixture requirement B') && str_contains($html, 'Still needed: Fixture requirement B') && str_contains($html, 'File must be an image file (JPG, JPEG or PNG only)'), 'The missing requirement or the per-file reason is not shown.');
    upload_assert(str_contains($html, 'value="upload"') && str_contains($html, 'value="continue"') && !str_contains($html, 'name="checklist[]"') && !str_contains($html, 'upload the required files from Documents in your applicant portal'), 'The Documents step still shows checklist boxes or the after-submit instruction.');
    $reviewHtml = $renderReview(['draft' => $viewDraft, 'errors' => [], 'docDefs' => $docDefs, 'categoryMap' => [$category => 'Fixture upload category'], 'isApplicationWizard' => true]);
    upload_assert(str_contains($reviewHtml, 'replacement.png') && str_contains($reviewHtml, 'Required — not uploaded yet') && str_contains($reviewHtml, 'Still needed: Fixture requirement B') && !str_contains($reviewHtml, 'upload after submitting'), 'The review step does not show the staged file and the missing requirement.');

    // ---- 8. The wizard controller: stays on the Documents step while documents are missing or refused ----
    $controller = (new ReflectionClass(ApplicationController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Controller::class, 'pdo'))->setValue($controller, $test);
    $handle = new ReflectionMethod(ApplicationController::class, 'handleApplyAction');
    $handle->setAccessible(true);
    $atStep3 = array_replace($draft, ['step' => 3, 'highestStep' => 3]);
    unset($atStep3['documents']['reqA']);
    $_POST = ['action' => 'continue', 'wizard_step' => '3']; $_FILES = [];
    [$after, $stepErrors, $submitError] = $handle->invoke($controller, $service, $user, $atStep3);
    upload_assert((int) $after['step'] === 3 && isset($stepErrors['documents']) && $submitError === null, 'Continue advanced past the Documents step with a required file missing.');
    $_POST = ['action' => 'upload', 'wizard_step' => '3'];
    $_FILES = ['application_file' => ['name' => ['reqA' => 'a.png'], 'type' => ['reqA' => 'image/png'], 'tmp_name' => ['reqA' => ''], 'error' => ['reqA' => UPLOAD_ERR_INI_SIZE], 'size' => ['reqA' => 0]]];
    [$after, $stepErrors] = $handle->invoke($controller, $service, $user, $atStep3);
    upload_assert(isset($stepErrors['upload_reqA']) && (int) $after['step'] === 3 && !isset($stepErrors['documents']), 'Upload did not report a refused file while keeping the applicant on the step.');
    $_FILES = [];
    $_POST = ['action' => 'submit', 'wizard_step' => '4', 'consent' => '1'];
    [$after, $stepErrors, $submitError] = $handle->invoke($controller, $service, $user, array_replace($atStep3, ['step' => 4]));
    upload_assert((int) $after['step'] === 3 && isset($stepErrors['documents']) && $submitError === null && $count('SELECT COUNT(*) FROM applications WHERE user_id=?', [$user['id']]) === 0, 'Submitting from the review step with a missing file created an application or hid the reason.');

    // ---- 9. Submission with every required file: stored, linked, unverified, no checklist rows ----
    $draft = upload_fixture_stage($service, $user, $draft, 'reqB');
    $draft = upload_fixture_stage($service, $user, $draft, 'optC'); // optional files are submitted too
    upload_assert($service->firstInvalidStep($draft) === null, 'A complete draft was refused: ' . json_encode($service->firstInvalidStep($draft)));
    $stagedPaths = array_column($draft['documents'], 'path');
    $ref = upload_fixture_submit($test, $service, $user, $draft);
    $app = $applications->findByReference($ref);
    upload_assert($app['status'] === 'Pending Review' && $app['type'] === 'new' && (int) $app['user_id'] === (int) $user['id'], 'The application was not created as a new, pending application.');
    $stored = $rows('SELECT * FROM application_documents WHERE application_id=? ORDER BY doc_key', [$app['id']]);
    upload_assert(array_column($stored, 'doc_key') === ['optC', 'reqA', 'reqB'], 'The submitted documents are not linked to the application.');
    foreach ($stored as $row) {
        $file = STORAGE_PATH . '/' . $row['stored_path'];
        upload_assert($row['verification_status'] === 'FOR_VERIFICATION' && $row['verified_by'] === null && str_starts_with($row['stored_path'], $app['id'] . '/'), 'An uploaded file was not recorded as awaiting staff verification: ' . $row['doc_key']);
        upload_assert(is_file($file) && hash_file('sha256', $file) === $row['sha256'], 'A stored file is missing or does not match its recorded hash: ' . $row['doc_key']);
    }
    foreach ($stagedPaths as $path) { upload_assert(!is_file($path), 'A staged file was left in the draft folder after a successful submission.'); }
    upload_assert(!is_dir($service->draftDir((int) $user['id'])) && $count('SELECT COUNT(*) FROM application_drafts WHERE user_id=?', [$user['id']]) === 0, 'The draft or its folder survived a successful submission.');
    upload_assert($count('SELECT COUNT(*) FROM application_document_checklists WHERE application_id=?', [$app['id']]) === 0, 'Submission wrote applicant checklist rows.');
    upload_assert($count('SELECT COUNT(*) FROM document_status_history WHERE application_id=? AND to_status=? AND from_status IS NULL', [$app['id'], 'FOR_VERIFICATION']) === 3, 'Each upload does not start its own status history.');
    upload_assert($count("SELECT COUNT(*) FROM audit_logs WHERE action='Document Upload' AND entity_type='application' AND entity_id=?", [$app['id']]) === 3, 'Document uploads were not audit logged.');
    foreach ($documents->checklistForReview((int) $app['id'], $category) as $item) {
        upload_assert($item['verification_status'] === 'Pending', 'An uploaded file counted as verified before staff reviewed it.');
    }
    upload_assert(array_column($documents->outstandingRequired($app), 'doc_key') === ['reqA', 'reqB'], 'Review does not treat the uploaded, unverified required files as outstanding.');
    upload_assert(array_column((new ApplicationReviewService($test))->approvalChecklist($app), 'ok', 'label')['Required documents'] === false, 'Approval would not be blocked until staff verify the uploaded files.');
    $verdict = (new ApplicationReviewService($test))->saveVerification($staff, $app, array_column(array_map(
        fn($row) => [(int) $row['id'], ['status' => 'VERIFIED', 'remarks' => '', 'expected' => 'FOR_VERIFICATION', 'expected_remarks' => '', 'expected_version' => Document::versionToken($row)]], $stored), 1, 0));
    upload_assert($verdict['errors'] === [] && $documents->outstandingRequired($app) === [], 'Staff could not verify files of an application that has no checklist rows.');

    // ---- 10. Submission refused when a required file is missing, gone or changed ----
    $refuse = static function (array $user, array $draft, string $why) use ($test, $service, $count): ApplicationDocumentsException {
        try { upload_fixture_submit($test, $service, $user, $draft); } catch (ApplicationDocumentsException $e) {
            upload_assert($count('SELECT COUNT(*) FROM applications WHERE user_id=?', [$user['id']]) === 0, 'A refused submission (' . $why . ') still created an application.');
            return $e;
        }
        throw new RuntimeException('Submission was not refused: ' . $why);
    };
    $userMissing = $makeUser(2);
    $d = upload_fixture_stage($service, $userMissing, $baseDraft($userMissing), 'reqA');
    $e = $refuse($userMissing, $d, 'a required document was never uploaded');
    upload_assert(isset($e->errors['documents']) && str_contains($e->errors['documents'], 'Fixture requirement B') && is_file($d['documents']['reqA']['path']), 'The refusal omitted the missing document, or deleted the staged file.');
    $userGone = $makeUser(3);
    $d = upload_fixture_stage_required($service, $documents, $userGone, $baseDraft($userGone));
    unlink($d['documents']['reqA']['path']); // the file disappears after the page validated it
    $e = $refuse($userGone, $d, 'a staged file no longer exists');
    upload_assert(isset($e->errors['documents']) && isset($e->errors['upload_reqA']) && is_file($d['documents']['reqB']['path']), 'A vanished file was not caught, or the other staged files were disturbed.');
    $userAltered = $makeUser(4);
    $d = upload_fixture_stage_required($service, $documents, $userAltered, $baseDraft($userAltered));
    file_put_contents($d['documents']['reqB']['path'], 'changed after validation');
    $e = $refuse($userAltered, $d, 'a staged file changed');
    upload_assert(isset($e->errors['upload_reqB']), 'A changed file was not caught at submission.');
    $userForged = $makeUser(5);
    $d = upload_fixture_stage_required($service, $documents, $userForged, $baseDraft($userForged));
    $d['documents']['reqA']['stored_name'] = '../../escape.png'; // a draft tampered to point outside the applicant's folder
    $d['documents']['reqA']['path'] = $service->draftDir((int) $userForged['id']) . '/../../escape.png';
    $refuse($userForged, $d, 'a staged path pointing outside the draft folder');
    // The same forged name saved in a draft is dropped on load; paths are rebuilt inside the signed-in applicant's own folder, and no other applicant's draft is visible.
    $service->saveDraft($userForged, $d);
    $loaded = $service->getDraft($userForged);
    upload_assert(!isset($loaded['documents']['reqA']) && isset($loaded['documents']['reqB']) && dirname($loaded['documents']['reqB']['path']) === $service->draftDir((int) $userForged['id']), 'A forged stored name survived loading the draft, or a valid path was not rebuilt inside the signed-in applicant folder.');
    upload_assert($service->getDraft($userGone)['documents'] === [], 'One applicant could see the staged files of another applicant.');

    // ---- 11. A failed submission rolls back completely and keeps the draft and its files ----
    $userRollback = $makeUser(6);
    $d = upload_fixture_stage_required($service, $documents, $userRollback, $baseDraft($userRollback));
    $service->saveDraft($userRollback, $d);
    $nextId = upload_fixture_prepare_application_id($test); // chosen above every test id and checked free
    $blocker = upload_fixture_make_blocker($nextId); // a plain file where the application folder must go, so storing the files fails
    $failed = null;
    try { @$service->submit($userRollback, $d); } catch (Throwable $e) { $failed = $e; }
    upload_assert($failed !== null && !($failed instanceof ApplicationDocumentsException), 'The storage failure was not raised as a system failure.');
    upload_assert($count('SELECT COUNT(*) FROM applications WHERE user_id=?', [$userRollback['id']]) === 0 && $count('SELECT COUNT(*) FROM application_documents WHERE application_id>=?', [$nextId]) === 0 && $count('SELECT COUNT(*) FROM application_children WHERE application_id>=?', [$nextId]) === 0, 'A failed submission left an application, children or document rows behind.');
    foreach ($d['documents'] as $meta) { upload_assert(is_file($meta['path']), 'A failed submission lost a staged file.'); }
    upload_assert($count('SELECT COUNT(*) FROM application_drafts WHERE user_id=?', [$userRollback['id']]) === 1, 'A failed submission deleted the draft.');
    unlink($blocker);
    $ref = upload_fixture_submit($test, $service, $userRollback, $service->getDraft($userRollback)); // the same draft can be submitted once the cause is gone
    $retried = $applications->findByReference($ref);
    upload_assert($count('SELECT COUNT(*) FROM application_documents WHERE application_id=?', [$retried['id']]) === 2 && !is_dir($service->draftDir((int) $userRollback['id'])), 'Resubmitting the preserved draft did not store its files.');

    // ---- 11b. A failure AFTER the files were moved still puts them back; the retry duplicates nothing ----
    $userLate = $makeUser(7);
    $d = upload_fixture_stage_required($service, $documents, $userLate, $baseDraft($userLate));
    $service->saveDraft($userLate, $d);
    $lateId = upload_fixture_prepare_application_id($test);
    $test->exec("CREATE TRIGGER fixture_fail_history BEFORE INSERT ON application_status_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture failure'");
    $failed = null;
    try { $service->submit($userLate, $d); } catch (Throwable $e) { $failed = $e; }
    $test->exec('DROP TRIGGER IF EXISTS fixture_fail_history');
    upload_assert($failed !== null && !($failed instanceof ApplicationDocumentsException), 'The late failure was not raised.');
    foreach ($d['documents'] as $meta) { upload_assert(is_file($meta['path']) && hash_file('sha256', $meta['path']) === $meta['sha256'], 'A file moved before the late failure was not put back unchanged.'); }
    upload_assert(!file_exists(STORAGE_PATH . '/' . $lateId) && $count('SELECT COUNT(*) FROM applications WHERE user_id=?', [$userLate['id']]) === 0 && $count('SELECT COUNT(*) FROM application_documents WHERE application_id>=?', [$lateId]) === 0, 'The late failure left an application folder or database rows behind.');
    upload_assert($count('SELECT COUNT(*) FROM application_drafts WHERE user_id=?', [$userLate['id']]) === 1, 'The late failure deleted the draft.');
    $lateRef = upload_fixture_submit($test, $service, $userLate, $service->getDraft($userLate));
    $lateApp = $applications->findByReference($lateRef);
    upload_assert($count('SELECT COUNT(*) FROM application_documents WHERE application_id=?', [$lateApp['id']]) === 2 && $count("SELECT COUNT(*) FROM audit_logs WHERE action='Document Upload' AND entity_id=?", [$lateApp['id']]) === 2 && $count("SELECT COUNT(*) FROM audit_logs WHERE action='Application Submission' AND user_id=?", [$userLate['id']]) === 1 && $count('SELECT COUNT(*) FROM application_status_history WHERE application_id=?', [$lateApp['id']]) === 1, 'The retry after a failed submission duplicated documents, history or audit records.');

    // ---- 12. One active application at a time still holds ----
    $secondTry = upload_fixture_stage_required($service, $documents, $user, $baseDraft($user));
    $blocked = false;
    try { upload_fixture_submit($test, $service, $user, $secondTry); } catch (ApplicationDocumentsException $e) { $blocked = false; } catch (DomainException $e) { $blocked = true; }
    upload_assert($blocked, 'A second active application was accepted.');

    echo "New-application uploads passed: required files, invalid and replaced files, server rechecks, rollback, views, and staff review without checklist rows.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    $pdo = $live;
    foreach ($cleanupFiles as $file) { if (is_file($file)) { @unlink($file); } }
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
