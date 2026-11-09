<?php
/** Integration checks in a disposable database; existing applicant records are untouched. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../services/ApplicationReviewService.php';
require __DIR__ . '/../services/ApplicationTrackerService.php';
require __DIR__ . '/../services/UserManagementService.php';
require __DIR__ . '/../services/RecordService.php';
require_once __DIR__ . '/../services/IdReleaseService.php';
require_once __DIR__ . '/../services/SettingsService.php';
require_once __DIR__ . '/../controllers/ApplicantController.php';
require_once __DIR__ . '/../services/QrVerificationService.php';
require_once __DIR__ . '/../controllers/StaffController.php';
require_once __DIR__ . '/../includes/csrf.php';

function review_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
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

$source = $pdo;
$testDatabase = 'solo_review_test_' . bin2hex(random_bytes(6));
$created = false;
$exitCode = 0;
try {
    $source->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4');
    $created = true;
    $test = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $testDatabase . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]);
    $test->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($source->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM) as $table) {
        $name = str_replace('`', '``', $table[0]);
        $definition = $source->query('SHOW CREATE TABLE `' . $name . '`')->fetch(PDO::FETCH_NUM)[1];
        $test->exec($definition);
    }
    $test->exec('SET FOREIGN_KEY_CHECKS = 1');
    foreach ($source->query('SELECT * FROM roles')->fetchAll() as $row) {
        $columns = implode(',', array_map(fn($key) => '`' . $key . '`', array_keys($row)));
        $test->prepare('INSERT INTO roles (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    }
    $insertUser = $test->prepare('INSERT INTO users (full_name, email, username, password_hash, role) VALUES (?, ?, ?, ?, ?)');
    foreach (['applicant', 'mswd_social_worker', 'admin'] as $role) {
        $insertUser->execute([$role, $role . '@example.test', $role, password_hash('test fixture', PASSWORD_DEFAULT), $role]);
        $ids[$role] = (int) $test->lastInsertId();
    }
    $staff = ['id' => $ids['mswd_social_worker'], 'role' => 'mswd_social_worker', 'full_name' => 'Review fixture'];
    // Copy only requirement definitions; no real accounts or applications enter this database.
    foreach (['categories', 'document_requirements'] as $table) {
        foreach ($source->query('SELECT * FROM ' . $table)->fetchAll() as $row) {
            $columns = implode(',', array_map(fn($key) => '`' . $key . '`', array_keys($row)));
            $test->prepare('INSERT INTO ' . $table . ' (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        }
    }
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
        $requirements = $documents->checklistForReview($id, (int) $category, $type === 'renewal');
        foreach ($requirements as $row) {
            $test->prepare('INSERT INTO application_document_checklists (application_id,doc_key,applicant_has) VALUES (?,?,1)')->execute([$id, $row['doc_key']]);
        }
        $uncheckedKey = $requirements[0]['doc_key'];
        $test->prepare('UPDATE application_document_checklists SET applicant_has = 0 WHERE application_id = ? AND doc_key = ?')->execute([$id, $uncheckedKey]);
        $service->verifyAvailableChecklistOnOpen(['id' => $ids['admin'], 'role' => 'admin'], $app);
        $rows = $documents->checklistForReview($id, (int) $category, $type === 'renewal');
        review_assert(!array_filter($rows, fn($row) => $row['verification_status'] !== 'Pending'), 'Admin opening verified documents.');
        $service->verifyAvailableChecklistOnOpen($staff, $app);
        $rows = $documents->checklistForReview($id, (int) $category, $type === 'renewal');
        foreach ($rows as $row) {
            review_assert($row['verification_status'] === ($row['doc_key'] === $uncheckedKey ? 'Pending' : 'Verified'), 'Opening did not distinguish checked and unchecked documents.');
            if ($row['doc_key'] !== $uncheckedKey) {
                review_assert((int) $row['reviewed_by'] === $staff['id'] && $row['reviewed_at'] !== null, 'Automatic verification lacks staff attribution.');
            }
        }
        $autoHistoryCount = count($applications->history($id));
        $service->verifyAvailableChecklistOnOpen($staff, $app);
        review_assert(count($applications->history($id)) === $autoHistoryCount, 'Repeated opening duplicated automatic verification history.');
        $test->prepare("UPDATE application_document_checklists SET applicant_has = 1, verification_status = 'Needs Correction', staff_remarks = 'Bring an updated copy.', reviewed_by = ?, reviewed_at = NOW() WHERE application_id = ? AND doc_key = ?")->execute([$staff['id'], $id, $uncheckedKey]);
        $service->verifyAvailableChecklistOnOpen($staff, $app);
        $correctedRows = array_column($documents->checklistForReview($id, (int) $category, $type === 'renewal'), null, 'doc_key');
        review_assert($correctedRows[$uncheckedKey]['verification_status'] === 'Needs Correction' && $correctedRows[$uncheckedKey]['staff_remarks'] === 'Bring an updated copy.', 'Opening overwrote a staff correction.');
        $test->prepare('UPDATE application_document_checklists SET applicant_has = 1 WHERE application_id = ?')->execute([$id]);
        $submitted = [];
        foreach ($documents->checklistForReview($id, (int) $category, $type === 'renewal') as $row) {
            if ($row['is_required']) {
                $submitted[$row['id']] = ['status' => 'Verified', 'remarks' => ''];
            }
        }
        review_assert(count($submitted) > 0, 'Required checklist is empty.');
        $first = array_key_first($submitted);
        $submitted[$first]['status'] = 'Pending';
        review_assert($service->reviewChecklistItems($staff, $app, $submitted) === null, 'Partial verification failed.');
        review_assert($applications->findForReview($id)['eligibility_status'] === 'Not Assessed', 'Partial verification marked eligible.');
        $submitted[$first]['status'] = 'Verified';
        review_assert($service->reviewChecklistItems($staff, $app, $submitted) === null, 'Complete verification failed.');
        $app = $applications->findForReview($id);
        review_assert($app['eligibility_status'] === 'Not Assessed', 'Physical verification automatically decided eligibility.');
        review_assert($service->assessEligibility($staff, $app, 'Eligible', 'Interview assessment recorded by staff.') === null, 'Explicit assessment failed.');
        $app = $applications->findForReview($id);
        $assessment = new EligibilityAssessment($test);
        review_assert(count($assessment->mswdForApplication($id)) === 1, 'Official assessment was not recorded.');
        review_assert($service->reviewChecklistItems($staff, $app, $submitted) === null, 'Repeated verification failed.');
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
        $test->prepare("UPDATE application_document_checklists SET verification_status = 'Pending', reviewed_at = NULL, reviewed_by = NULL, staff_remarks = NULL WHERE id = ?")->execute([$first]);
        $service->verifyAvailableChecklistOnOpen($staff, $app);
        $checkedAfterScheduling = array_column($documents->checklistForReview($id, (int) $category, $type === 'renewal'), null, 'id');
        review_assert($checkedAfterScheduling[$first]['verification_status'] === 'Verified', 'A checked requirement stayed pending because an interview was scheduled.');
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
        $submitted[$first]['status'] = 'Pending';
        review_assert($service->reviewChecklistItems($staff, $app, $submitted) === null, 'Undoing verification failed.');
        $app = $applications->findForReview($id);
        review_assert($app['eligibility_status'] === 'Eligible', 'Document review overwrote an explicit staff assessment.');
        review_assert($service->assessEligibility($staff, $app, 'Not Eligible', '') !== null, 'No assessment accepted without a reason.');
        review_assert($service->assessEligibility($staff, $app, 'Not Eligible', 'Staff assessment reason') === null, 'Manual No assessment failed.');
        $app = $applications->findForReview($id);
        review_assert($service->verifyAllChecklistItems($staff, $app) === null, 'Verify All failed.');
        $rows = $documents->checklistForReview($id, (int) $category, $type === 'renewal');
        review_assert(!array_filter($rows, fn($row) => $row['verification_status'] !== 'Verified' || $row['reviewed_by'] != $staff['id'] || !$row['reviewed_at']), 'Verify All did not verify and stamp every document.');
        $app = $applications->findForReview($id);
        review_assert($app['eligibility_status'] === 'Not Eligible', 'Verify All overwrote a staff decision.');
        $historyCount = count($applications->history($id));
        review_assert($service->verifyAllChecklistItems($staff, $app) === null, 'Repeated Verify All failed.');
        review_assert(count($applications->history($id)) === $historyCount, 'Repeated Verify All duplicated history.');
        $undoToken = $service->reviewUndoToken($id);
        review_assert($service->undoReview(['id' => $ids['applicant'], 'role' => 'applicant'], $id, 'verification', $undoToken) !== null, 'Applicant could undo a staff review.');
        review_assert($service->undoReview($staff, $id, 'invalid', $undoToken) !== null, 'Unknown undo section was accepted.');
        review_assert($service->undoReview($staff, $id, 'verification', 'outdated-token') !== null, 'A stale page could undo verification.');
        review_assert($service->undoReview($staff, $id, 'verification', $undoToken) === null, 'Undo verification failed.');
        $service->verifyAvailableChecklistOnOpen($staff, $app);
        $rows = $documents->checklistForReview($id, (int) $category, $type === 'renewal');
        review_assert(!array_filter($rows, fn($row) => $row['verification_status'] !== 'Pending' || $row['reviewed_at'] !== null), 'Undo did not reopen document verification.');
        $app = $applications->findForReview($id);
        review_assert($app['eligibility_status'] === 'Not Eligible', 'Undo verification overwrote a staff decision.');
        review_assert($service->undoReview($staff, $id, 'verification', $undoToken) !== null, 'Repeated undo with an old token was accepted.');
        review_assert($service->verifyAllChecklistItems($staff, $app) === null, 'Verification after undo failed.');
        review_assert($service->undoReview($staff, $id, 'eligibility', $service->reviewUndoToken($id)) === null, 'Undo eligibility failed.');
        $app = $applications->findForReview($id);
        review_assert($app['eligibility_status'] === 'Not Assessed', 'Undo did not reopen eligibility assessment.');
        review_assert($service->assessEligibility($staff, $app, 'Eligible', '') === null, 'Assessment after undo failed.');
        review_assert($service->undoReview($staff, $id, 'interview', $service->reviewUndoToken($id)) === null, 'Undo interview failed.');
        $interviewAfterUndo = $appointments->interviewFor($id);
        review_assert($interviewAfterUndo['status'] === 'BOOKED' && $interviewAfterUndo['result'] === null && $interviewAfterUndo['result_recorded_at'] === null, 'Undo did not reopen interview result controls.');
        $app = $applications->findForReview($id);
        $stages = array_column((new ApplicationTrackerService($test))->track($app)['stages'], null, 'key');
        review_assert($stages['assessment']['state'] !== 'done', 'Undo left the interview marked complete on the applicant tracker.');
        review_assert($service->recordInterviewOutcome($staff, $id, 'Successful', '') === null, 'Interview after undo failed.');
        $test->prepare('UPDATE application_document_checklists SET expires_at = DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE id = ?')->execute([$first]);
        review_assert($service->verifyAllChecklistItems($staff, $applications->findForReview($id)) === null, 'Legacy expiry blocked verification after expiry controls were removed.');
        $expiryQuery = $test->prepare('SELECT expires_at FROM application_document_checklists WHERE id = ?');
        $expiryQuery->execute([$first]);
        review_assert($expiryQuery->fetchColumn() === null, 'Updated evidence retained the replaced document expiry.');
        $closedUndoToken = $service->reviewUndoToken($id);
        $test->prepare("UPDATE applications SET status = 'Rejected' WHERE id = ?")->execute([$id]);
        review_assert($service->undoReview($staff, $id, 'verification', $closedUndoToken) !== null, 'Undo changed a final application.');
        review_assert($service->verifyAllChecklistItems($staff, $applications->findForReview($id)) !== null, 'Verify All changed a final application.');
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
    $qrVerification = new QrVerificationService($test);
    $renderVerification = static function (array $outcome, array $verificationCard, array $staff): string {
        $ownDashboard = base_url('staff/index.php');
        $error = '';
        $input = '';
        ob_start();
        require APP_ROOT . '/views/staff/qr/verify.php';
        return ob_get_clean();
    };
    foreach ($test->query('SELECT id FROM applications')->fetchAll(PDO::FETCH_COLUMN) as $releaseId) {
        $releaseId = (int) $releaseId;
        $test->prepare("UPDATE applications SET status = 'Approved' WHERE id = ?")->execute([$releaseId]);
        $applicantController = new ApplicantController($test);
        $ownedApps = new ReflectionProperty(ApplicantController::class, 'applications');
        $ownedApps->setValue($applicantController, $applications->forUserDetailed($ids['applicant']));
        $_GET = ['ref' => $applications->findForReview($releaseId)['reference_no']];
        $myIdSection = new ReflectionMethod(ApplicantController::class, 'sectionMyid');
        review_assert($myIdSection->invoke($applicantController)['data']['idApplication'] === null, 'Approved ID was visible before release was scheduled.');
        $approvedApp = $applications->findForReview($releaseId);
        (new QrSlipService($test))->issue($approvedApp, $staff['id']);
        $beforeScheduleQr = $qrVerification->verify($staff, $approvedApp['reference_no']);
        review_assert($beforeScheduleQr['result'] === 'VALID' && !$beforeScheduleQr['app']['id_release_schedule'], 'Approved QR verification has an unexpected release schedule.');
        review_assert(!str_contains($renderVerification($beforeScheduleQr, $approvedApp, $staff), 'class="verification-id"'), 'QR verification shows an ID before release is scheduled.');
        $test->prepare("INSERT INTO id_release_schedules (application_id,scheduled_at,location,scheduled_by,sms_message) VALUES (?,NOW(),'Test office',?,'Fixture')")->execute([$releaseId, $staff['id']]);
        $releaseApp = $applications->findForReview($releaseId);
        $scheduledQr = $qrVerification->verify($staff, $releaseApp['reference_no']);
        review_assert($scheduledQr['result'] === 'VALID' && $scheduledQr['app']['status'] === 'Approved' && $scheduledQr['app']['id_release_schedule'], 'Scheduled release QR verification failed.');
        $verificationCard = $releaseApp;
        $verificationCard['children'] = $applications->children($releaseId);
        $scheduledQrHtml = $renderVerification($scheduledQr, $verificationCard, $staff);
        review_assert(str_contains($scheduledQrHtml, 'verification-id-front') && str_contains($scheduledQrHtml, 'verification-id-back') && str_contains($scheduledQrHtml, 'Release Scheduled'), 'Scheduled QR verification does not show the ID and release status.');
        review_assert(str_contains($scheduledQr['message'], 'Physical handover has not yet been confirmed.'), 'Scheduled QR verification implies confirmed physical release.');
        $idView = $myIdSection->invoke($applicantController);
        review_assert((int) $idView['data']['idApplication']['id'] === $releaseId && $idView['data']['idReleaseSchedule'] !== null, 'Scheduled ID is not available to its applicant.');
        $renderId = static function (array $data): string {
            extract($data);
            ob_start();
            require APP_ROOT . '/views/applicant/qr/my-id.php';
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
        (new QrSlipService($test))->issue($releaseApp, $staff['id']);
        review_assert($releaseService->confirmRelease($staff, $releaseApp) === null, 'Confirming ID release failed.');
        $ownedApps->setValue($applicantController, $applications->forUserDetailed($ids['applicant']));
        $_GET = ['ref' => $releaseApp['reference_no']];
        review_assert($myIdSection->invoke($applicantController)['data']['idApplication']['status'] === 'ID Released', 'Released ID is no longer viewable by its applicant.');
        $releasedQr = $qrVerification->verify($staff, $releaseApp['reference_no']);
        review_assert($releasedQr['result'] === 'VALID' && str_contains($renderVerification($releasedQr, $applications->findForReview($releaseId), $staff), 'verification-id-front'), 'Released QR verification lost its ID card.');
        $revokedQr = $qrVerification->verify($staff, $scheduledQr['slip']['token']);
        review_assert($revokedQr['result'] === 'INVALID', 'An old replaced QR token became valid after scheduling.');
        $expiry = $applications->findForReview($releaseId)['id_expires_at'];
        $expected = $test->query('SELECT DATE_ADD(DATE(released_at), INTERVAL 1 YEAR) FROM id_release_schedules WHERE application_id = ' . $releaseId)->fetchColumn();
        review_assert($expiry === $expected, 'Released ID did not expire one calendar year after handover.');
        $qrExpiry = $test->query('SELECT expires_at FROM qr_application_slips WHERE application_id = ' . $releaseId . ' AND revoked_at IS NULL')->fetchColumn();
        review_assert(substr((string) $qrExpiry, 0, 10) === $expiry, 'QR expiry did not follow ID expiry.');
        review_assert($releaseService->confirmRelease($staff, $releaseApp) !== null, 'Repeated release was accepted.');
        review_assert($applications->findForReview($releaseId)['id_expires_at'] === $expiry, 'Repeated release extended ID validity.');
    }
    $expiryBefore = $test->query('SELECT id, id_expires_at FROM applications ORDER BY id')->fetchAll();
    $test->exec(file_get_contents(__DIR__ . '/migrate_fixed_id_validity.sql'));
    review_assert($test->query('SELECT id, id_expires_at FROM applications ORDER BY id')->fetchAll() === $expiryBefore, 'Validity migration changed an existing expiry date.');
    require __DIR__ . '/checklist_flow_checks.php';
    check_preparation_flow($test, $staff, $ids['applicant'], (int) $category);
    require __DIR__ . '/automatic_interview_checks.php';
    check_staff_assigned_interviews($test, $staff, (int) $category);
    require __DIR__ . '/appointment_management_checks.php';
    check_appointment_management($test, $staff, (int) $category);
    echo "Staff workflow, shared records, one-year new/renewal ID expiry, QR expiry and existing expiry preservation passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($created) {
        $source->exec('DROP DATABASE `' . $testDatabase . '`');
    }
}
exit($exitCode);
