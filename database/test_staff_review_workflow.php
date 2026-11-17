<?php
/** Integration checks in a disposable database; existing applicant records are untouched. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
$testDatabase = 'solo_review_test_' . bin2hex(random_bytes(6));
validate_temporary_database_target($testDatabase, 'solo_review_test_');
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../services/ApplicationReviewService.php';
require __DIR__ . '/../services/ApplicationTrackerService.php';
require __DIR__ . '/../services/UserManagementService.php';
require __DIR__ . '/../services/RecordService.php';
require_once __DIR__ . '/../services/IdReleaseService.php';
require_once __DIR__ . '/../services/SettingsService.php';
require_once __DIR__ . '/../controllers/ApplicantController.php';
require_once __DIR__ . '/../controllers/StaffController.php';
require_once __DIR__ . '/../includes/csrf.php';

function review_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** The full application_documents row, exactly as the staff review page reads it (the version token is derived from it). */
function review_document_row(PDO $test, int $documentId): array
{
    $stmt = $test->prepare('SELECT * FROM application_documents WHERE id = ?');
    $stmt->execute([$documentId]);
    $row = $stmt->fetch();
    review_assert((bool) $row, 'Fixture document ' . $documentId . ' does not exist.');
    return $row;
}

/**
 * One posted verification row, as the staff form sends it: the chosen verdict, plus the status, remarks and FILE VERSION
 * the page showed. $doc is the current stored row; Document::versionToken() is the same function the page uses, so a row
 * built from a stale $doc is rejected by saveVerification exactly like a stale browser page.
 */
function review_verdict_row(array $doc, string $status, string $remarks = ''): array
{
    return ['status' => $status, 'remarks' => $remarks, 'expected' => (string) $doc['verification_status'],
        'expected_remarks' => trim((string) ($doc['staff_remarks'] ?? '')), 'expected_version' => Document::versionToken($doc)];
}

/** Verifies every not-yet-verified upload of an application through saveVerification (current tokens, no shortcut). */
function review_verify_all_uploads(PDO $test, ApplicationReviewService $service, array $staff, array $app): array
{
    $stmt = $test->prepare('SELECT * FROM application_documents WHERE application_id = ? ORDER BY id');
    $stmt->execute([(int) $app['id']]);
    $rows = [];
    foreach ($stmt->fetchAll() as $doc) {
        if ($doc['verification_status'] !== 'VERIFIED') {
            $rows[(int) $doc['id']] = review_verdict_row($doc, 'VERIFIED');
        }
    }
    $result = $rows ? $service->saveVerification($staff, $app, $rows) : ['errors' => [], 'changed' => 0, 'notify_failures' => 0];
    review_assert($result['errors'] === [] && $result['notify_failures'] === 0, 'Fixture verification of every upload failed: ' . json_encode($result['errors']));
    return $result;
}

/**
 * Brings an application to the state interview scheduling now requires: a file for every required document (only those
 * still missing are added) and every file verified by staff. Used by fixtures that need a legal scheduling state.
 */
function review_make_interview_ready(PDO $test, ApplicationReviewService $service, array $staff, int $applicationId, int $category): void
{
    $applications = new Application($test);
    $documents = new Document($test);
    $app = $applications->findForReview($applicationId);
    $have = array_column($documents->uploadedForApplication($applicationId), null, 'doc_key');
    $insert = $test->prepare("INSERT INTO application_documents (application_id,doc_key,original_name,stored_path,mime,size_bytes,sha256) VALUES (?,?,'fixture.png',?,'image/png',10,?)");
    foreach ($documents->checklistForReview($applicationId, $category, ($app['type'] ?? '') === 'renewal') as $row) {
        if (!empty($row['is_required']) && !isset($have[$row['doc_key']])) {
            $insert->execute([$applicationId, $row['doc_key'], 'x/' . $applicationId . '-' . $row['doc_key'] . '.png', hash('sha256', $applicationId . '|' . $row['doc_key'])]);
        }
    }
    review_verify_all_uploads($test, $service, $staff, $applications->findForReview($applicationId));
    review_assert($documents->outstandingRequired($applications->findForReview($applicationId)) === [], 'The fixture application still has outstanding required documents.');
}

/**
 * Fixture: books an interview straight into the tables, the way an appointment looks once the live flow has finished
 * (a BOOKED appointment, the application at Interview Scheduled, and its history line). Staff no longer schedule interviews
 * through a service call, so tests that need an appointment create it here. $scheduledAt is 'Y-m-d H:i:s'. It applies no
 * rules, sends no SMS and writes no notification: it is test data, not a path through the application.
 */
function review_book_interview(PDO $test, array $staff, int $applicationId, string $scheduledAt, string $location = 'Fixture Office'): void
{
    $applications = new Application($test);
    (new Appointment($test))->upsertStaffScheduled($applicationId, $scheduledAt, $location, null, (int) $staff['id']);
    $applications->setReviewedStatus($applicationId, 'Interview Scheduled', (int) $staff['id']);
    $applications->addHistory($applicationId, 'Interview Scheduled', 'Interview at ' . $location, (string) $staff['full_name'], (int) $staff['id']);
}

function review_office_date(PDO $pdo, string $from = '+2 days'): string
{
    $calendar = new InterviewCalendarService($pdo);
    $date = new DateTimeImmutable($from);
    for ($day = 0; $day < 366; $day++, $date = $date->modify('+1 day')) {
        if ($calendar->dateError($date->format('Y-m-d')) === null) {
            return $date->format('Y-m-d');
        }
    }
    throw new RuntimeException('No configured office day is available for the fixture.');
}

function review_render_staff(PDO $pdo, array $staff, int $id): string
{
    $controller = (new ReflectionClass(StaffController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Controller::class, 'pdo'))->setValue($controller, $pdo);
    (new ReflectionProperty(StaffController::class, 'staff'))->setValue($controller, $staff);
    $_GET = ['id' => $id];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/staff/application.php';
    ob_start();
    $controller->application();
    return ob_get_clean();
}

/** Synthetic uploads, one per required document, queued for staff verification (the live workflow). Returns document id => doc_key. */
function review_seed_uploads(PDO $test, Document $documents, int $applicationId, int $category, bool $standardOnly): array
{
    $insert = $test->prepare("INSERT INTO application_documents (application_id,doc_key,original_name,stored_path,mime,size_bytes) VALUES (?,?,'file.pdf','x/file.pdf','application/pdf',10)");
    $uploads = [];
    foreach ($documents->checklistForReview($applicationId, $category, $standardOnly) as $row) {
        if (!empty($row['is_required'])) {
            $insert->execute([$applicationId, $row['doc_key']]);
            $uploads[(int) $test->lastInsertId()] = $row['doc_key'];
        }
    }
    return $uploads;
}

/** Saves staff verdicts (document id => status) through the live saveVerification workflow; each row carries the status and remarks currently stored. */
function review_save_verdicts(PDO $test, ApplicationReviewService $service, array $staff, array $app, array $verdicts): array
{
    $rows = [];
    foreach ($verdicts as $documentId => $status) {
        // The page's view of the document: status, remarks and file version as currently stored.
        $rows[$documentId] = review_verdict_row(review_document_row($test, (int) $documentId), $status);
    }
    $result = $service->saveVerification($staff, $app, $rows);
    // A notification that fails after the commit is only counted by the service, so a silent failure would otherwise pass.
    review_assert($result['notify_failures'] === 0, 'A document verification notification failed.');
    return $result;
}

$source = $pdo;
$originalGlobalPdo = $GLOBALS['pdo'] ?? null;
$originalSettingsCacheSet = array_key_exists('__settings_cache', $GLOBALS);
$originalSettingsCache = $GLOBALS['__settings_cache'] ?? null;
$created = false;
$exitCode = 0;
try {
    $source->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4');
    $created = true;
    $test = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $testDatabase . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]);
    if ($test->query('SELECT DATABASE()')->fetchColumn() !== $testDatabase) {
        throw new RuntimeException('Temporary test connection is not bound to the generated test database.');
    }
    $GLOBALS['pdo'] = $test;
    $GLOBALS['__settings_cache'] = null;
    $test->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($source->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM) as $table) {
        $name = str_replace('`', '``', $table[0]);
        $definition = $source->query('SHOW CREATE TABLE `' . $name . '`')->fetch(PDO::FETCH_NUM)[1];
        $test->exec($definition);
    }
    $test->exec('SET FOREIGN_KEY_CHECKS = 1');
    // Synthetic fixtures only: no rows are copied from any existing database.
    $test->exec("INSERT INTO roles (id, role_key, code, name, description) VALUES (1,'admin','ADMINISTRATOR','Fixture Administrator','Fixture'),(2,'mswd_social_worker','MSWD_STAFF','Fixture Staff','Fixture'),(3,'applicant','SOLO_PARENT','Fixture Applicant','Fixture')");
    $insertUser = $test->prepare('INSERT INTO users (full_name, email, username, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    foreach (['applicant', 'mswd_social_worker', 'admin'] as $role) {
        $insertUser->execute([$role, $role . '@example.test', $role, password_hash('test fixture', PASSWORD_DEFAULT), $role]);
        $ids[$role] = (int) $test->lastInsertId();
    }
    $staff = ['id' => $ids['mswd_social_worker'], 'role' => 'mswd_social_worker', 'full_name' => 'Review fixture'];
    $test->exec("INSERT INTO categories (name,description,sort_order) VALUES ('Fixture category','Fixture',1)");
    $fixtureCategory = (int) $test->lastInsertId();
    $fixtureRequirement = $test->prepare('INSERT INTO document_requirements (category_id,doc_key,label,is_required,sort_order) VALUES (?,?,?,1,?)');
    foreach (['fixtureDocA', 'fixtureDocB'] as $order => $key) {
        $fixtureRequirement->execute([$fixtureCategory, $key, 'Fixture ' . $key, $order + 1]);
    }
    $fixtureRequirement->execute([null, 'fixtureStandard', 'Fixture standard document', 0]); // standard (all-category) requirement
    $category = $test->query('SELECT category_id FROM document_requirements WHERE is_required = 1 AND category_id IS NOT NULL LIMIT 1')->fetchColumn();
    review_assert($category !== false, 'A category with required documents is needed for this test.');
    $insert = $test->prepare("INSERT INTO applications (reference_no,user_id,type,full_name,barangay,address,mobile,email,birthdate,sex,civil_status,category_id,consent_at,submitted_at) VALUES (?,?,?,'Review fixture','Test','Test','09123456789','fixture@example.test','1990-01-01','Female','Single',?,NOW(),NOW())");
    $applications = new Application($test);
    $documents = new Document($test);
    $service = new ApplicationReviewService($test);
    foreach (['new', 'renewal'] as $type) {
        $insert->execute(['REVIEW-TEST-' . $type, $ids['applicant'], $type, $category]);
        $id = (int) $test->lastInsertId();
        $app = $applications->findForReview($id);
        $service->startReviewOnOpen(['id' => $ids['admin'], 'role' => 'admin', 'full_name' => 'Admin'], $app);
        review_assert($applications->findForReview($id)['status'] === 'Pending Review', 'An admin view started a review.');
        $service->startReviewOnOpen($staff, $app);
        $service->startReviewOnOpen($staff, $app); // simulate a second view with the same stale snapshot
        $app = $applications->findForReview($id);
        review_assert($app['status'] === 'Under Verification' && (int) $app['reviewed_by'] === $staff['id'], 'Opening did not start review.');
        review_assert(count($applications->history($id)) === 1, 'Repeated opening duplicated review history.');
        // Documents are uploaded files that staff verify through saveVerification (the live workflow).
        $uploads = review_seed_uploads($test, $documents, $id, (int) $category, $type === 'renewal');
        review_assert(count($uploads) > 0, 'Required document list is empty.');
        $first = array_key_first($uploads);
        $verdicts = array_fill_keys(array_keys($uploads), 'VERIFIED');
        $partialVerdicts = $verdicts;
        $partialVerdicts[$first] = 'FOR_VERIFICATION';
        $saved = review_save_verdicts($test, $service, $staff, $applications->findForReview($id), $partialVerdicts);
        review_assert($saved['errors'] === [], 'Partial verification failed.');
        review_assert($applications->findForReview($id)['eligibility_status'] === 'Not Assessed', 'Partial verification marked eligible.');
        $saved = review_save_verdicts($test, $service, $staff, $applications->findForReview($id), $verdicts);
        review_assert($saved['errors'] === [] && $saved['changed'] === 1, 'Complete verification failed.');
        $app = $applications->findForReview($id);
        review_assert($app['eligibility_status'] === 'Not Assessed', 'Physical verification automatically decided eligibility.');
        review_assert($service->assessEligibility($staff, $app, 'Eligible', 'Interview assessment recorded by staff.') === null, 'Explicit assessment failed.');
        $app = $applications->findForReview($id);
        $assessment = new EligibilityAssessment($test);
        review_assert(count($assessment->mswdForApplication($id)) === 1, 'Official assessment was not recorded.');
        $saved = review_save_verdicts($test, $service, $staff, $app, $verdicts);
        review_assert($saved['errors'] === [] && $saved['changed'] === 0, 'Repeated verification failed or rewrote unchanged documents.');
        review_assert(count($assessment->mswdForApplication($id)) === 1, 'Repeated verification duplicated the assessment.');
        $tracker = (new ApplicationTrackerService($test))->track($app);
        $stages = array_column($tracker['stages'], null, 'key');
        review_assert($stages['review']['state'] === 'done' && $stages['documents']['state'] === 'done', 'Applicant tracker did not reflect automatic review and verification.');
        review_assert($stages['assessment']['state'] !== 'done' && $stages['assessment']['date'] === null, 'Eligibility alone completed the MSWD assessment.');
        $assertDecisionsBlocked = static function () use ($service, $staff, $app, $applications, $id): void {
            $before = count($applications->history($id));
            foreach (['For Revision', 'Approved', 'Rejected'] as $decision) {
                review_assert($service->updateStatus($staff, $app, $decision, 'Fixture reason') !== null, 'Decision allowed before a successful interview: ' . $decision);
            }
            review_assert(count($applications->history($id)) === $before, 'Blocked decisions changed history.');
        };
        $assertDecisionsBlocked();
        $beforeInterviewHtml = review_render_staff($test, $staff, $id);
        review_assert(!str_contains($beforeInterviewHtml, 'id="application-decision-form"'), 'Decision controls visible before an interview.');
        review_assert(!str_contains($beforeInterviewHtml, 'id="decisions"') && !str_contains($beforeInterviewHtml, 'No approve, reject or correction decision yet.'), 'Empty Decisions history visible before scheduling an interview.');
        review_assert(!str_contains($beforeInterviewHtml, 'id="id-release"') && str_contains($beforeInterviewHtml, 'name="interview_date"'), 'ID release section visible before scheduling an interview.');
        $appointments = new Appointment($test);
        $appointments->upsertStaffScheduled($id, '2020-01-01 09:00:00', 'Test office', null, $staff['id']);
        $test->prepare("UPDATE application_documents SET verification_status = 'FOR_VERIFICATION', verified_at = NULL, verified_by = NULL, staff_remarks = NULL WHERE id = ?")->execute([$first]);
        $saved = review_save_verdicts($test, $service, $staff, $applications->findForReview($id), [$first => 'VERIFIED']);
        review_assert($saved['errors'] === [] && $saved['changed'] === 1, 'Staff re-verification after scheduling failed.');
        $checkedAfterScheduling = array_column($documents->checklistForReview($id, (int) $category, $type === 'renewal'), null, 'doc_key');
        review_assert($checkedAfterScheduling[$uploads[$first]]['verification_status'] === 'Verified', 'Re-verification after scheduling an interview was not saved.');
        $stages = array_column((new ApplicationTrackerService($test))->track($app)['stages'], null, 'key');
        review_assert($stages['assessment']['state'] !== 'done', 'A booked interview completed the MSWD assessment.');
        $assertDecisionsBlocked();
        $scheduledInterviewHtml = review_render_staff($test, $staff, $id);
        review_assert(!str_contains($scheduledInterviewHtml, 'id="application-decision-form"'), 'Decision controls visible for a booked interview.');
        review_assert(str_contains($scheduledInterviewHtml, 'id="decisions"'), 'Decisions history missing after scheduling an interview.');
        review_assert(str_contains($scheduledInterviewHtml, 'id="id-release"') && !str_contains($scheduledInterviewHtml, 'value="schedule_id_release"'), 'ID release section should appear after scheduling, with booking still blocked until approval.');
        $appointments->markMissed((int) $appointments->interviewFor($id)['id']);
        $stages = array_column((new ApplicationTrackerService($test))->track($app)['stages'], null, 'key');
        review_assert($stages['assessment']['state'] === 'attention', 'A missed interview completed the MSWD assessment.');
        $appointments->upsertStaffScheduled($id, '2020-01-01 09:00:00', 'Test office', null, $staff['id']);
        // A result can only be recorded while every required document is verified: the fixture's uploads were all re-verified above.
        review_assert($documents->outstandingRequired($applications->findForReview($id)) === [], 'The interview-result fixtures need every required document verified.');
        review_assert($service->recordInterviewOutcome($staff, $id, 'Invalid', '') !== null, 'Invalid interview outcome was accepted.');
        review_assert($service->recordInterviewOutcome($staff, $id, 'Unsuccessful', '') === null, 'Recording an unsuccessful interview without notes failed.');
        $assertDecisionsBlocked();
        $unsuccessfulInterviewHtml = review_render_staff($test, $staff, $id);
        review_assert(!str_contains($unsuccessfulInterviewHtml, 'id="application-decision-form"'), 'Decision controls visible after an unsuccessful interview.');
        review_assert(str_contains($unsuccessfulInterviewHtml, 'id="id-release"'), 'ID release section disappeared because of an unsuccessful interview.');
        $stages = array_column((new ApplicationTrackerService($test))->track($app)['stages'], null, 'key');
        review_assert($stages['assessment']['state'] === 'attention' && $stages['decision']['state'] === 'pending', 'Unsuccessful interview completed assessment or allowed approval.');
        $checks = array_column($service->approvalChecklist($app), null, 'label');
        review_assert(!$checks['Successful interview recorded']['ok'], 'Unsuccessful interview passed approval checks.');
        review_assert($service->recordInterviewOutcome($staff, $id, 'Successful', 'Interview completed.') === null, 'Recording a successful interview failed.');
        $stages = array_column((new ApplicationTrackerService($test))->track($app)['stages'], null, 'key');
        review_assert($stages['assessment']['state'] === 'done' && $stages['assessment']['date'] !== null, 'Successful interview did not complete MSWD assessment.');
        $checks = array_column($service->approvalChecklist($app), null, 'label');
        review_assert($checks['Successful interview recorded']['ok'], 'Successful interview failed approval checks.');
        $decisionHtml = review_render_staff($test, $staff, $id);
        foreach (['For Revision', 'Approved', 'Rejected'] as $decision) {
            review_assert(str_contains($decisionHtml, 'name="status" value="' . $decision . '"'), 'Decision button missing after a successful interview: ' . $decision);
        }
        $withoutAssessment = $app;
        $withoutAssessment['eligibility_status'] = 'Not Assessed';
        $withoutAssessment['eligibility_remarks'] = null;
        review_assert(!array_filter($service->approvalChecklist($withoutAssessment), fn($check) => !$check['ok']), 'Not assessed blocks complete documents and a successful interview.');
        $readyStages = array_column((new ApplicationTrackerService($test))->track($withoutAssessment)['stages'], null, 'key');
        review_assert($readyStages['assessment']['state'] === 'done' && $readyStages['decision']['state'] === 'active', 'Tracker still waits for separate eligibility assessment.');
        $saved = review_save_verdicts($test, $service, $staff, $applications->findForReview($id), [$first => 'FOR_VERIFICATION']);
        review_assert($saved['errors'] === [] && $saved['changed'] === 1, 'Undoing verification failed.');
        $app = $applications->findForReview($id);
        review_assert($app['eligibility_status'] === 'Eligible', 'Document review overwrote an explicit staff assessment.');
        review_assert($service->assessEligibility($staff, $app, 'Not Eligible', '') !== null, 'No assessment accepted without a reason.');
        review_assert($service->assessEligibility($staff, $app, 'Not Eligible', 'Staff assessment reason') === null, 'Manual No assessment failed.');
        $app = $applications->findForReview($id);
        $saved = review_save_verdicts($test, $service, $staff, $app, $verdicts);
        review_assert($saved['errors'] === [] && $saved['changed'] === 1, 'Verifying every document failed.');
        $rows = $documents->checklistForReview($id, (int) $category, $type === 'renewal');
        review_assert(!array_filter($rows, fn($row) => $row['verification_status'] !== 'Verified' || $row['reviewed_by'] != $staff['id'] || !$row['reviewed_at']), 'Verification did not verify and stamp every document.');
        $app = $applications->findForReview($id);
        review_assert($app['eligibility_status'] === 'Not Eligible', 'Document verification overwrote a staff decision.');
        $historyCount = count($applications->history($id));
        $saved = review_save_verdicts($test, $service, $staff, $app, $verdicts);
        review_assert($saved['errors'] === [] && $saved['changed'] === 0, 'Repeated verification failed or rewrote unchanged documents.');
        review_assert(count($applications->history($id)) === $historyCount, 'Repeated verification duplicated history.');
        // Undo protections, exercised on the eligibility review (the section staff can undo; document verification has no undo).
        $undoToken = $service->reviewUndoToken($id);
        review_assert($service->undoReview(['id' => $ids['applicant'], 'role' => 'applicant'], $id, 'eligibility', $undoToken) !== null, 'Applicant could undo a staff review.');
        review_assert($service->undoReview($staff, $id, 'invalid', $undoToken) !== null, 'Unknown undo section was accepted.');
        review_assert($service->undoReview($staff, $id, 'eligibility', 'outdated-token') !== null, 'A stale page could undo the eligibility review.');
        review_assert($service->undoReview($staff, $id, 'eligibility', $undoToken) === null, 'Undo eligibility failed.');
        $app = $applications->findForReview($id);
        review_assert($app['eligibility_status'] === 'Not Assessed', 'Undo did not reopen eligibility assessment.');
        review_assert($service->undoReview($staff, $id, 'eligibility', $undoToken) !== null, 'Repeated undo with an old token was accepted.');
        review_assert(!array_filter($documents->checklistForReview($id, (int) $category, $type === 'renewal'), fn($row) => $row['verification_status'] !== 'Verified'), 'Undoing the eligibility review reopened document verification.');
        review_assert($service->assessEligibility($staff, $app, 'Eligible', '') === null, 'Assessment after undo failed.');
        review_assert($service->undoReview($staff, $id, 'interview', $service->reviewUndoToken($id)) === null, 'Undo interview failed.');
        $interviewAfterUndo = $appointments->interviewFor($id);
        review_assert($interviewAfterUndo['status'] === 'BOOKED' && $interviewAfterUndo['result'] === null && $interviewAfterUndo['result_recorded_at'] === null, 'Undo did not reopen interview result controls.');
        $app = $applications->findForReview($id);
        $stages = array_column((new ApplicationTrackerService($test))->track($app)['stages'], null, 'key');
        review_assert($stages['assessment']['state'] !== 'done', 'Undo left the interview marked complete on the applicant tracker.');
        review_assert($service->recordInterviewOutcome($staff, $id, 'Successful', '') === null, 'Interview after undo failed.');
        // Legacy checklist evidence is still read. No code writes its expiry any more, so only the read side is checked:
        // verified evidence without an expiry (or with a future one) satisfies a required document; expired evidence does not.
        $legacyKey = 'fixtureLegacyEvidence';
        $legacyLabel = 'Fixture legacy evidence';
        $test->prepare('INSERT INTO document_requirements (category_id,doc_key,label,is_required,sort_order) VALUES (?,?,?,1,99)')->execute([$type === 'renewal' ? null : (int) $category, $legacyKey, $legacyLabel]);
        $legacyState = function () use ($service, $documents, $applications, $test, $id, $legacyLabel): array {
            $current = $applications->findForReview($id);
            return [
                'ok' => array_column($service->approvalChecklist($current), null, 'label')['Required documents']['ok'],
                'outstanding' => in_array($legacyLabel, array_column($documents->outstandingRequired($current), 'label'), true),
                'documentsStage' => array_column((new ApplicationTrackerService($test))->track($current)['stages'], null, 'key')['documents']['state'],
            ];
        };
        $state = $legacyState();
        review_assert(!$state['ok'] && $state['outstanding'], 'A required document without any evidence did not block approval.');
        $test->prepare("INSERT INTO application_document_checklists (application_id,doc_key,applicant_has,verification_status,reviewed_by,reviewed_at) VALUES (?,?,1,'Verified',?,NOW())")->execute([$id, $legacyKey, $staff['id']]);
        $state = $legacyState();
        review_assert($state['ok'] && !$state['outstanding'] && $state['documentsStage'] === 'done', 'Verified legacy evidence without an expiry no longer satisfies a required document.');
        $test->prepare('UPDATE application_document_checklists SET expires_at = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE application_id = ? AND doc_key = ?')->execute([$id, $legacyKey]);
        $state = $legacyState();
        review_assert(!$state['ok'] && $state['outstanding'] && $state['documentsStage'] !== 'done', 'Expired legacy evidence still satisfied a required document.');
        $test->prepare('UPDATE application_document_checklists SET expires_at = DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE application_id = ? AND doc_key = ?')->execute([$id, $legacyKey]);
        $state = $legacyState();
        review_assert($state['ok'] && !$state['outstanding'] && $state['documentsStage'] === 'done', 'Unexpired legacy evidence was treated as expired.');
        $test->prepare('DELETE FROM application_document_checklists WHERE application_id = ? AND doc_key = ?')->execute([$id, $legacyKey]);
        $test->prepare('DELETE FROM document_requirements WHERE doc_key = ?')->execute([$legacyKey]);
        review_assert($legacyState()['ok'], 'The legacy-evidence fixture was not cleaned up.');
        $closedUndoToken = $service->reviewUndoToken($id);
        $test->prepare("UPDATE applications SET status = 'Rejected' WHERE id = ?")->execute([$id]);
        review_assert($service->undoReview($staff, $id, 'eligibility', $closedUndoToken) !== null, 'Undo changed a final application.');
        $saved = review_save_verdicts($test, $service, $staff, $applications->findForReview($id), [$first => 'FOR_VERIFICATION']);
        review_assert($saved['errors'] !== [] && $saved['changed'] === 0, 'Document verification changed a final application.');
        review_assert($test->query('SELECT verification_status FROM application_documents WHERE id = ' . (int) $first)->fetchColumn() === 'VERIFIED', 'A refused verification still changed the document.');
        $service->startReviewOnOpen($staff, $applications->findForReview($id));
        review_assert($applications->findForReview($id)['status'] === 'Rejected', 'Opening reopened a final application.');
    }
    // Add staff after existing work: shared records must remain visible and retain their authors.
    $before = $applications->findForReview($id);
    $historyBefore = $applications->history($id);
    $admin = (new User($test))->findById($ids['admin']);
    [$error, $success, $newStaffId] = (new UserManagementService($test))->create($admin, [
        'fullName' => 'New staff member', 'email' => 'new.staff@example.test',
        'username' => 'new.staff', 'password' => 'StaffTest123', 'password_confirm' => 'StaffTest123', 'role' => 'mswd_social_worker',
    ]);
    review_assert($error === '' && $newStaffId !== null, 'Admin could not create additional staff: ' . $error);
    $newStaff = (new User($test))->findById($newStaffId);
    review_assert((new AuthService($test))->verifyCredentials($newStaff, 'StaffTest123'), 'New staff cannot authenticate.');
    review_assert(can($newStaff, 'applications.process') && can($newStaff, 'applicants.view'), 'New staff lacks shared record permissions.');
    review_assert(count($applications->recentlyReviewed()) === 2, 'Shared dashboard omitted previous staff work.');
    foreach ($applications->recentlyReviewed() as $recent) {
        review_assert((int) $recent['reviewed_by'] === $staff['id'] && $recent['reviewer_name'] !== null, 'Shared activity lost the original reviewer.');
    }
    review_assert($applications->search([], 1, 20)['total'] === 2, 'New staff queue omitted existing applications.');
    $records = new RecordService($test);
    review_assert($records->search($records->filters([]), 1)['total'] === 1, 'Existing applicant record is unavailable to new staff.');
    review_assert($applications->findForReview($id) === $before && $applications->history($id) === $historyBefore, 'Creating staff or reading shared records changed existing work.');
    review_assert($service->addNote($newStaff, $before, 'Follow-up by new staff.') === null, 'New staff cannot update an existing case.');
    $notes = $applications->notes($id);
    review_assert(count($notes) === 1 && (int) $notes[0]['author_id'] === $newStaffId, 'New work was not attributed to new staff.');
    review_assert($applications->history($id) === $historyBefore, 'New staff follow-up overwrote previous status history.');
    // A stale saved validity cannot extend future IDs; exercise both new and renewal handovers.
    set_setting($test, 'id_validity_years', '5');
    $GLOBALS['__settings_cache'] = ['sms_enabled' => '0', 'id_validity_years' => '5'];
    [$settingsError] = (new SettingsService($test))->saveSection($admin, 'renewals', [
        'id_validity_years' => '5', 'renewal_reminder_days' => '30',
    ]);
    review_assert($settingsError !== '', 'Settings allowed a validity longer than one year.');
    $releaseService = new IdReleaseService($test);
    foreach ($test->query('SELECT id FROM applications')->fetchAll(PDO::FETCH_COLUMN) as $releaseId) {
        $releaseId = (int) $releaseId;
        $test->prepare("UPDATE applications SET status = 'Approved' WHERE id = ?")->execute([$releaseId]);
        $applicantController = new ApplicantController($test);
        $ownedApps = new ReflectionProperty(ApplicantController::class, 'applications');
        $ownedApps->setValue($applicantController, $applications->forUserDetailed($ids['applicant']));
        $_GET = ['ref' => $applications->findForReview($releaseId)['reference_no']];
        $myIdSection = new ReflectionMethod(ApplicantController::class, 'sectionMyid');
        review_assert($myIdSection->invoke($applicantController)['data']['idApplication'] === null, 'Approved ID was visible before release was scheduled.');
        $test->prepare("INSERT INTO id_release_schedules (application_id,scheduled_at,location,scheduled_by,sms_message) VALUES (?,NOW(),'Test office',?,'Fixture')")->execute([$releaseId, $staff['id']]);
        $releaseApp = $applications->findForReview($releaseId);
        $idView = $myIdSection->invoke($applicantController);
        review_assert((int) $idView['data']['idApplication']['id'] === $releaseId && $idView['data']['idReleaseSchedule'] !== null, 'Scheduled ID is not available to its applicant.');
        $renderId = static function (array $data): string {
            extract($data);
            ob_start();
            require APP_ROOT . '/views/applicant/my-id.php';
            return ob_get_clean();
        };
        $idHtml = $renderId($idView['data']);
        review_assert(str_contains($idHtml, 'verification-id-front') && str_contains($idHtml, 'verification-id-back') && str_contains($idHtml, 'Release Scheduled'), 'Scheduled applicant ID did not render both sides and its schedule status.');
        review_assert($applications->findForReview($releaseId)['status'] === 'Approved' && !$applications->findForReview($releaseId)['id_expires_at'], 'Viewing the ID confirmed release or started its validity.');
        $tracker = (new ApplicationTrackerService($test))->track($releaseApp);
        $app = $releaseApp;
        ob_start();
        require APP_ROOT . '/views/applicant/application/tracker.php';
        $trackerHtml = ob_get_clean();
        review_assert(str_contains($trackerHtml, 'View My ID') && str_contains($trackerHtml, 'ref=' . urlencode($releaseApp['reference_no'])), 'Release tracker is missing its application-specific ID link.');
        $_GET = ['ref' => 'ANOTHER-APPLICANT-REFERENCE'];
        review_assert($myIdSection->invoke($applicantController)['data']['idApplication'] === null, 'An unowned reference exposed an ID.');
        $_GET = [];
        $ownedApps->setValue($applicantController, []);
        review_assert($myIdSection->invoke($applicantController)['data']['idApplication'] === null, 'Applicant without applications could view an ID.');
        review_assert($releaseService->confirmRelease($staff, $releaseApp) === null, 'Confirming ID release failed.');
        $ownedApps->setValue($applicantController, $applications->forUserDetailed($ids['applicant']));
        $_GET = ['ref' => $releaseApp['reference_no']];
        review_assert($myIdSection->invoke($applicantController)['data']['idApplication']['status'] === 'ID Released', 'Released ID is no longer viewable by its applicant.');
        $expiry = $applications->findForReview($releaseId)['id_expires_at'];
        $expected = $test->query('SELECT DATE_ADD(DATE(released_at), INTERVAL 1 YEAR) FROM id_release_schedules WHERE application_id = ' . $releaseId)->fetchColumn();
        review_assert($expiry === $expected, 'Released ID did not expire one calendar year after handover.');
        review_assert($releaseService->confirmRelease($staff, $releaseApp) !== null, 'Repeated release was accepted.');
        review_assert($applications->findForReview($releaseId)['id_expires_at'] === $expiry, 'Repeated release extended ID validity.');
    }
    $expiryBefore = $test->query('SELECT id, id_expires_at FROM applications ORDER BY id')->fetchAll();
    $test->exec(file_get_contents(__DIR__ . '/migrate_fixed_id_validity.sql'));
    review_assert($test->query('SELECT id, id_expires_at FROM applications ORDER BY id')->fetchAll() === $expiryBefore, 'Validity migration changed an existing expiry date.');
    require __DIR__ . '/checklist_flow_checks.php';
    check_preparation_flow($test, $staff, $ids['applicant'], (int) $category);
    check_interview_result_document_gate($test, $staff, $ids['applicant']);
    require __DIR__ . '/automatic_interview_checks.php';
    check_staff_assigned_interviews($test, $staff, (int) $category);
    require __DIR__ . '/appointment_management_checks.php';
    check_appointment_management($test, $staff, (int) $category);
    echo "Staff workflow, shared records, one-year new/renewal ID expiry and existing expiry preservation passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    $GLOBALS['pdo'] = $originalGlobalPdo;
    if ($originalSettingsCacheSet) {
        $GLOBALS['__settings_cache'] = $originalSettingsCache;
    } else {
        unset($GLOBALS['__settings_cache']);
    }
    if ($created) {
        $source->exec('DROP DATABASE `' . $testDatabase . '`');
    }
}
exit($exitCode);
