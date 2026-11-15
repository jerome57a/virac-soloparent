<?php
/**
 * Additional checks invoked by test_staff_review_workflow.php in its disposable database.
 *
 * They cover the upload-based document workflow: applicants upload files, staff verify each
 * file separately, and review, approval and ID release work from the uploaded files merged with
 * the configured requirements. None of it depends on application_document_checklists rows
 * (the fixture applications have none); the table is only read for retained legacy evidence.
 */
function check_preparation_flow(PDO $test, array $staff, int $applicantId, int $category): void
{
    require_once __DIR__ . '/../services/AppointmentService.php';
    require_once __DIR__ . '/../services/ApplicationService.php';
    require_once __DIR__ . '/../includes/data.php';
    require_once __DIR__ . '/../controllers/StaffController.php';
    require_once __DIR__ . '/../controllers/AppointmentController.php';
    require_once __DIR__ . '/../includes/csrf.php';
    review_assert(SMSService::testMode(), 'Run workflow tests with SMS_PROVIDER=test.');
    $GLOBALS['__settings_cache']['sms_enabled'] = '1';
    $_SESSION = $_SESSION ?? [];
    $apps = new Application($test);
    $docs = new Document($test);
    $review = new ApplicationReviewService($test);
    $sms = new SMSService($test);
    $insert = $test->prepare("INSERT INTO applications (reference_no,user_id,type,full_name,barangay,address,mobile,email,birthdate,sex,civil_status,category_id,consent_at,submitted_at) VALUES (?,?,'new','Preparation Fixture','Test','Test','09123456789','fixture@example.test','1990-01-01','Female','Single',?,NOW(),NOW())");
    $insert->execute(['PREPARATION-TEST', $applicantId, $category]);
    $id = (int) $test->lastInsertId();
    foreach ([['fixtureAlreadyAvailable','Already Available Fixture Document',1], ['fixtureOptional','Optional Fixture Document',0]] as [$key,$label,$required]) {
        $test->prepare('INSERT INTO document_requirements (category_id,doc_key,label,is_required,sort_order) VALUES (?,?,?,?,999)')->execute([$category,$key,$label,$required]);
    }
    $requiredKeys = array_column(array_filter($docs->requirementsFor($category), fn($r) => !empty($r['is_required'])), 'doc_key');
    review_assert(in_array('fixtureAlreadyAvailable', $requiredKeys, true) && !in_array('fixtureOptional', $requiredKeys, true) && count($requiredKeys) >= 2, 'Fixture requirements were not configured as expected.');

    // ---- Helpers: what the applicant upload and the staff verification screens do, without HTTP ----
    $applicantActor = ['id' => $applicantId, 'full_name' => 'Preparation Fixture', 'role' => 'applicant'];
    $fileMeta = static fn(string $name): array => ['original_name' => $name . '.pdf', 'mime' => 'application/pdf', 'size' => 1024, 'sha256' => hash('sha256', $name)];
    $upload = static fn(int $appId, string $key): int => $docs->create($appId, $key, $fileMeta($key), $appId . '/' . $key . '.pdf', $applicantActor);
    $uploaded = static fn(int $appId): array => array_column($docs->uploadedForApplication($appId), null, 'doc_key');
    $verdict = static function (array $app, string $key, string $status, string $remarks) use ($docs, $review, $staff, $uploaded): array {
        $file = $uploaded((int) $app['id'])[$key];
        return $review->saveVerification($staff, $app, [(int) $file['id'] => [
            'status' => $status, 'remarks' => $remarks,
            'expected' => $file['verification_status'], 'expected_remarks' => trim((string) $file['staff_remarks']),
        ]]);
    };
    $checklistRows = static fn(int $appId): int => (int) $test->query('SELECT COUNT(*) FROM application_document_checklists WHERE application_id=' . $appId)->fetchColumn();
    $documentHistory = static fn(int $appId): int => (int) $test->query('SELECT COUNT(*) FROM document_status_history WHERE application_id=' . $appId)->fetchColumn();
    $lastSms = static fn(): string => (string) $test->query('SELECT message FROM sms_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    $items = static fn(int $appId) => array_column($docs->checklistForReview($appId, $category), null, 'doc_key');

    // ---- An application with no checklist rows: every required requirement is "not uploaded" and outstanding ----
    review_assert($checklistRows($id) === 0, 'The fixture application unexpectedly has checklist rows.');
    $app = $apps->findForReview($id);
    $initial = $items($id);
    foreach ($requiredKeys as $key) {
        review_assert($initial[$key]['verification_status'] === 'Pending' && empty($initial[$key]['applicant_has']) && empty($initial[$key]['uploaded_document']), 'A requirement with no file and no checklist row is not shown as pending and not uploaded.');
    }
    review_assert(count($docs->outstandingRequired($app)) === count($requiredKeys), 'Required documents without uploads are not all outstanding.');
    review_assert(!array_column($review->approvalChecklist($app), null, 'label')['Required documents']['ok'], 'Approval would not be blocked with no uploaded documents.');

    // ---- Submitting through the application service stores the staged uploads, creates no checklist rows, verifies nothing ----
    require_once __DIR__ . '/upload_fixtures.php';
    upload_fixture_isolate_ids($test); // new users get ids in the test range; each submission picks and checks its own application folder
    $test->prepare("INSERT INTO users (full_name,email,username,password_hash,role,mobile,mobile_verified_at) VALUES ('Preparation Applicant','prepare@example.test','preparation_fixture',?,'applicant','09123456789',NOW())")->execute([password_hash('fixture', PASSWORD_DEFAULT)]);
    $submitterId = (int) $test->lastInsertId();
    $test->prepare('UPDATE categories SET is_active=1 WHERE id=?')->execute([$category]);
    $submitter = ['id' => $submitterId, 'full_name' => 'Preparation Applicant', 'role' => 'applicant', 'email' => 'prepare@example.test', 'mobile' => '09123456789'];
    $applicationService = new ApplicationService($test);
    $submitDraft = $applicationService->freshDraft($submitter);
    $submitDraft = array_replace($submitDraft, ['categoryId' => $category, 'barangay' => 'Test', 'address' => 'Test Address', 'birthdate' => '1990-01-01', 'sex' => 'Female', 'civilStatus' => 'Single', 'children' => [['name' => 'Fixture Child', 'birthdate' => '2020-01-01']], 'consent' => true]);
    review_assert(isset($applicationService->validateStep(3, $submitDraft)['documents']), 'A draft with no uploaded files passed the Documents step.');
    review_assert($applicationService->firstInvalidStep($submitDraft)['step'] === 3, 'A draft with no uploaded files was not stopped at the Documents step.');
    $refused = false;
    try { upload_fixture_submit($test, $applicationService, $submitter, $submitDraft); } catch (ApplicationDocumentsException $e) { $refused = true; }
    review_assert($refused && (int) $test->query("SELECT COUNT(*) FROM applications WHERE user_id=" . $submitterId)->fetchColumn() === 0, 'An application with no uploaded documents was submitted.');
    $submitDraft = upload_fixture_stage_required($applicationService, $docs, $submitter, $submitDraft);
    review_assert($applicationService->firstInvalidStep($submitDraft) === null, 'The fixture draft fails submission validation: ' . json_encode($applicationService->firstInvalidStep($submitDraft)));
    $submittedRef = upload_fixture_submit($test, $applicationService, $submitter, $submitDraft);
    $submittedApp = $apps->findByReference($submittedRef);
    review_assert($submittedApp['status'] === 'Pending Review' && $submittedApp['eligibility_status'] === 'Not Assessed', 'Application submission failed.');
    review_assert($checklistRows((int) $submittedApp['id']) === 0, 'Submission wrote applicant checklist rows.');
    review_assert((int) $test->query('SELECT COUNT(*) FROM application_documents WHERE application_id=' . (int) $submittedApp['id'] . " AND verification_status='FOR_VERIFICATION'")->fetchColumn() === count($requiredKeys), 'Submission did not store every uploaded file as awaiting verification.');
    foreach ($docs->checklistForReview((int) $submittedApp['id'], $category) as $item) {
        review_assert($item['verification_status'] === 'Pending', 'A submitted application shows a document as verified without staff review.');
    }
    review_assert(count($docs->outstandingRequired($submittedApp)) === count($requiredKeys), 'Uploaded but unverified files counted as complete for the submitted application.');

    // ---- Submission SMS and the applicant "still need to upload" list ----
    $sms->notifySubmitted($id, $app['mobile'], $app['reference_no']);
    $message = $lastSms();
    review_assert(str_contains($message, 'After your interview, submit') && str_contains($message, $app['reference_no']), 'Submission SMS omits preparation timing or reference.');
    $unprepared = $docs->checklistForReview($id, $category);
    $missingLabels = $sms->uncheckedRequirementLabels($id);
    review_assert(count($missingLabels) === count($requiredKeys) && !in_array('Optional Fixture Document', $missingLabels, true), 'The not-uploaded list is wrong before any upload.');
    review_assert(str_contains($message, $missingLabels[0]), 'Submission SMS does not name a document that is not uploaded.');
    $render = static function (array $preparationItems): string {
        ob_start(); require APP_ROOT . '/views/partials/requirements-preparation.php'; return ob_get_clean();
    };
    review_assert(str_contains($render($unprepared), 'Documents You Still Need to Upload'), 'Upload list missing.');

    // ---- Upload is not verification ----
    $fileId = $upload($id, 'fixtureAlreadyAvailable');
    $item = $items($id)['fixtureAlreadyAvailable'];
    review_assert(!empty($item['applicant_has']) && $item['verification_status'] === 'Pending' && (int) $item['uploaded_document']['id'] === $fileId && $item['uploaded_document']['verification_status'] === 'FOR_VERIFICATION', 'An uploaded file was not recorded as awaiting staff verification.');
    review_assert(in_array('fixtureAlreadyAvailable', array_column($docs->outstandingRequired($app), 'doc_key'), true), 'An uploaded but unverified file counted as complete.');
    review_assert($checklistRows($id) === 0, 'Uploading wrote checklist rows.');
    review_assert(array_column($docs->statusHistoryFor($id)[$fileId], 'to_status') === ['FOR_VERIFICATION'], 'The upload has no status-history entry.');

    // ---- Staff verification is separate, authorised, attributed, audited and recorded ----
    $denied = $review->saveVerification(['id' => $applicantId, 'role' => 'applicant', 'full_name' => 'Preparation Fixture'], $app, [$fileId => ['status' => 'VERIFIED', 'remarks' => '', 'expected' => 'FOR_VERIFICATION', 'expected_remarks' => '']]);
    review_assert($denied['errors'] !== [] && $denied['changed'] === 0 && $uploaded($id)['fixtureAlreadyAvailable']['verification_status'] === 'FOR_VERIFICATION', 'An applicant could verify a document.');
    $result = $verdict($app, 'fixtureAlreadyAvailable', 'VERIFIED', '');
    review_assert($result['errors'] === [] && $result['changed'] === 1, 'Staff could not verify an uploaded document.');
    $item = $items($id)['fixtureAlreadyAvailable'];
    review_assert($item['verification_status'] === 'Verified' && (int) $item['reviewed_by'] === (int) $staff['id'] && $item['reviewed_at'], 'Verification lacks staff attribution.');
    review_assert(array_column($docs->statusHistoryFor($id)[$fileId], 'to_status') === ['FOR_VERIFICATION', 'VERIFIED'], 'Verification is missing from the document history.');
    $audited = $test->prepare("SELECT COUNT(*) FROM audit_logs WHERE action='Document Verification' AND entity_type='application_document' AND entity_id=?");
    $audited->execute([$fileId]);
    review_assert((int) $audited->fetchColumn() === 1, 'Verification was not audit logged exactly once.');
    $unchanged = $documentHistory($id);
    $repeat = $verdict($app, 'fixtureAlreadyAvailable', 'VERIFIED', '');
    review_assert($repeat['errors'] === [] && $repeat['changed'] === 0 && $documentHistory($id) === $unchanged, 'Repeating an unchanged verification changed history.');
    review_assert(!in_array('fixtureAlreadyAvailable', $sms->uncheckedRequirementLabels($id), true), 'A verified upload is still listed as a missing document.');
    $missingLabels = $sms->uncheckedRequirementLabels($id);
    review_assert(count($missingLabels) === count($requiredKeys) - 1, 'The not-uploaded list did not shrink after a verified upload.');

    // ---- Staff schedule an interview (staff enter date and time; no slots) ----
    $apptService = new AppointmentService($test);
    review_assert(!method_exists($apptService, 'book') && !method_exists($apptService, 'createSlot'), 'Applicant booking / slot management methods still exist.');
    $date = review_office_date($test);
    $app = $apps->findForReview($id);
    review_assert($review->scheduleInterview($staff, $app, $date . 'T09:00', 'Fixture Office', '', 'automatic', '') === null, 'Staff cannot schedule while required documents are still outstanding.');
    review_assert((new Appointment($test))->interviewFor($id)['slot_id'] === null, 'Staff-entered schedule must be saved with a NULL slot_id.');
    $app = $apps->findForReview($id);
    review_assert($app['status'] === 'Interview Scheduled' && $app['eligibility_status'] === 'Not Assessed', 'Scheduling changed eligibility.');
    $count = (int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn();
    review_assert($review->scheduleInterview($staff, $app, $date . 'T09:00', 'Fixture Office', '', 'automatic', '') === null, 'Repeat scheduling failed.');
    review_assert((int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn() === $count, 'Repeated scheduling sent duplicate SMS.');
    $interviewSms = (new Appointment($test))->interviewSms($id);
    review_assert(str_contains($interviewSms['message'], 'Please arrive on time.') && str_contains($interviewSms['message'], 'After your interview, submit missing documents before ID release:') && !str_contains($interviewSms['message'], 'requirements to bring:') && str_contains($interviewSms['message'], $app['reference_no']), 'Interview SMS lacks the correct schedule and document timing.');
    review_assert(str_contains($interviewSms['message'], $missingLabels[0]) && mb_strlen($interviewSms['message']) <= 500, 'Interview SMS omits document names or exceeds its limit.');
    review_assert(!str_contains($interviewSms['message'], 'Already Available Fixture Document') && !str_contains($interviewSms['message'], 'Optional Fixture Document'), 'Interview SMS requests a verified or optional document.');
    $longMessage = SMSService::preparationText(array_fill(0, 20, str_repeat('Long document label ', 5)), 200);
    review_assert(mb_strlen($longMessage) <= 200 && str_contains(strtolower($longMessage), 'required uploads online.') && str_contains($longMessage, 'After your interview'), 'Long requirement list loses document timing or the full-list instruction.');
    $insert->execute(['PREPARATION-FULL-SLOT', $applicantId, $category]);
    $other = $apps->findForReview((int) $test->lastInsertId());
    review_assert($review->scheduleInterview($staff, $other, $date . 'T09:00', 'Fixture Office', '', 'automatic', '') === null, 'Slotless scheduling must not impose a per-time capacity limit.');
    $nextDate = review_office_date($test, $date . ' +1 day');
    review_assert($review->scheduleInterview($staff, $apps->findForReview($id), $nextDate . 'T09:00', 'Fixture Office', '', 'automatic', '') === null, 'Staff cannot reschedule an interview.');
    $rescheduled = (new Appointment($test))->interviewFor($id);
    review_assert($rescheduled['slot_id'] === null && $rescheduled['scheduled_at'] === $nextDate . ' 09:00:00', 'Rescheduled date/time was not saved without a slot.');
    $date = $nextDate;
    $app = $apps->findForReview($id);

    // ---- Staff page: uploaded files, per-document verification controls, no checklist-era controls ----
    $count = (int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn();
    $controller = (new ReflectionClass(StaffController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Controller::class, 'pdo'))->setValue($controller, $test);
    (new ReflectionProperty(StaffController::class, 'staff'))->setValue($controller, $staff);
    $_GET = ['id' => $id]; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/staff/application.php';
    ob_start(); $controller->application(); $staffHtml = ob_get_clean();
    review_assert(str_contains($staffHtml, 'Required documents still need review') && str_contains($staffHtml, 'name="docs[' . $fileId . '][status]"') && str_contains($staffHtml, 'download.php?doc_id=' . $fileId) && str_contains($staffHtml, 'Save Verification'), 'Staff page omits the uploaded file, its verification control or the outstanding-documents summary.');
    review_assert(str_contains($staffHtml, 'name="interview_date"') && str_contains($staffHtml, 'name="interview_time"') && !str_contains($staffHtml, 'name="slot_id"'), 'Staff page omits direct interview date/time inputs or still exposes slot selection.');
    review_assert(!str_contains($staffHtml, 'Eligibility is set to Yes automatically'), 'Staff page still claims automatic eligibility.');
    review_assert(!str_contains($staffHtml, 'checklist-verification-form') && !str_contains($staffHtml, '>Verify All</button>') && !str_contains($staffHtml, 'Undo Verification') && !str_contains($staffHtml, 'name="checklist[]"'), 'Staff page still shows checklist-era verification controls.');
    review_assert(!str_contains($staffHtml, 'receive_checklist_document') && !str_contains($staffHtml, 'confirm_all_required_documents'), 'Physical-receipt actions are still offered to staff.');
    review_assert(method_exists($review, 'receiveChecklistDocument') && method_exists($review, 'confirmAllRequiredDocuments') && method_exists($review, 'verifyAllChecklistItems') && method_exists($review, 'reviewChecklistItems'), 'The legacy checklist review methods were removed.');
    $appointmentPage = (new AppointmentController($test))->handle(['id' => $applicantId], [$app]);
    $renderAppointment = static function (array $data): string {
        extract($data); ob_start(); require APP_ROOT . '/views/applicant/appointments/index.php'; return ob_get_clean();
    };
    $appointmentHtml = $renderAppointment($appointmentPage['data']);
    review_assert(str_contains($appointmentHtml, e($missingLabels[0])) && str_contains($appointmentHtml, 'Documents You Still Need to Upload'), 'Applicant schedule omits the documents still to be uploaded.');
    review_assert($apps->findForReview($id)['eligibility_status'] === 'Not Assessed', 'Uploading or verifying documents decided eligibility.');
    review_assert((int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn() === $count, 'Rendering the review page sent SMS.');
    $sms->notifyAppointmentReminder($id, $app['mobile'], $app['reference_no'], $date . ' 09:00:00', 'Fixture Office');
    $message = $lastSms();
    review_assert(str_contains($message, 'After your interview, submit') && str_contains($message, $missingLabels[0]), 'Reminder lacks missing document names or submission timing.');

    // ---- Correction request, remarks, in-place replacement, then separate re-verification ----
    $correctionKey = array_values(array_diff($requiredKeys, ['fixtureAlreadyAvailable']))[0];
    $correctionFile = $upload($id, $correctionKey);
    $noRemarks = $verdict($app, $correctionKey, 'NEEDS_CORRECTION', '');
    review_assert($noRemarks['errors'] !== [] && $noRemarks['changed'] === 0 && $uploaded($id)[$correctionKey]['verification_status'] === 'FOR_VERIFICATION', 'A correction request without remarks was accepted.');
    $flagged = $verdict($app, $correctionKey, 'NEEDS_CORRECTION', 'Scan is blurry');
    review_assert($flagged['errors'] === [] && $flagged['changed'] === 1, 'Staff could not request a correction.');
    $item = $items($id)[$correctionKey];
    review_assert($item['verification_status'] === 'Needs Correction' && $item['staff_remarks'] === 'Scan is blurry', 'The correction request and remarks are not shown to review and the applicant.');
    review_assert(in_array($correctionKey, array_column($docs->outstandingRequired($app), 'doc_key'), true), 'A document needing correction counted as complete.');
    review_assert(!str_contains($lastSms(), 'Scan is blurry'), 'The correction SMS exposes staff remarks.');
    $existing = $docs->findForApplication($id, $correctionKey);
    $docs->replace($existing, $id, $fileMeta('replacement'), $id . '/replacement.pdf', $applicantActor);
    $replaced = $uploaded($id)[$correctionKey];
    review_assert((int) $replaced['id'] === $correctionFile && $replaced['original_name'] === 'replacement.pdf' && $replaced['verification_status'] === 'FOR_VERIFICATION' && $replaced['staff_remarks'] === null && $replaced['verified_by'] === null, 'Replacing a flagged file did not reset it in place for staff review.');
    review_assert(array_column($docs->statusHistoryFor($id)[$correctionFile], 'to_status') === ['FOR_VERIFICATION', 'NEEDS_CORRECTION', 'FOR_VERIFICATION'], 'The correction and replacement trail is missing from the document history.');
    review_assert(in_array($correctionKey, array_column($docs->outstandingRequired($app), 'doc_key'), true), 'A replaced but unverified file counted as complete.');
    $sms->notifyDocumentCorrection($id, $app['mobile'], $app['reference_no'], 'Sensitive document', 'Sensitive assessment details');
    $message = $lastSms();
    review_assert(!str_contains($message, 'Sensitive') && str_contains($message, 'Log in'), 'Correction SMS exposes document details.');

    // ---- Approval and ID release are blocked until every required file is verified ----
    $test->prepare('UPDATE appointments SET scheduled_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE application_id = ?')->execute([$id]);
    review_assert($review->recordInterviewOutcome($staff, $id, 'Successful', '') === null, 'Successful interview with documents outstanding failed.');
    $app = $apps->findForReview($id);
    review_assert($review->updateStatus($staff, $app, 'Approved', '') !== null, 'Unverified documents allowed approval after a successful interview.');
    $incomplete = array_column($review->approvalChecklist($app), null, 'label')['Required documents'];
    $notUploaded = count(array_filter($docs->checklistForReview($id, $category), [Document::class, 'needsPreparation']));
    $complete = count($requiredKeys) - $notUploaded;
    $summary = $complete . ($complete === 1 ? ' document complete' : ' documents complete') . ', ' . $notUploaded . ($notUploaded === 1 ? ' document still missing.' : ' documents still missing.');
    review_assert(!$incomplete['ok'] && str_contains($incomplete['detail'], $summary) && ($notUploaded === 0 || str_contains($incomplete['detail'], 'before approval and ID release scheduling')), 'The approval summary does not match the uploaded documents.');
    $release = new IdReleaseService($test);
    $test->prepare("UPDATE applications SET status = 'Approved' WHERE id = ?")->execute([$id]);
    $approvedSnapshot = $apps->findForReview($id);
    $smsBeforeBlockedRelease = (int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn();
    review_assert($release->schedule($staff, $approvedSnapshot, $date, '10:00', 'Fixture Office', 'automatic', '') !== null, 'Unverified documents allowed ID release scheduling.');
    review_assert($release->scheduleFor($id) === null && (int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn() === $smsBeforeBlockedRelease, 'Blocked release created a schedule or sent SMS.');
    review_assert($release->confirmRelease($staff, $approvedSnapshot) !== null, 'Unverified documents allowed ID handover.');
    $test->prepare("UPDATE applications SET status = 'Interview Scheduled' WHERE id = ?")->execute([$id]);

    // ---- Upload the rest, then verify them in one batch; a stale second save is refused ----
    foreach ($requiredKeys as $key) {
        if (!isset($uploaded($id)[$key])) {
            $upload($id, $key);
        }
    }
    $rows = [];
    foreach ($uploaded($id) as $file) {
        if ($file['verification_status'] !== 'VERIFIED') {
            $rows[(int) $file['id']] = ['status' => 'VERIFIED', 'remarks' => '', 'expected' => $file['verification_status'], 'expected_remarks' => trim((string) $file['staff_remarks'])];
        }
    }
    review_assert(count($rows) === count($requiredKeys) - 1, 'The batch does not cover every unverified required upload.');
    $app = $apps->findForReview($id);
    $batch = $review->saveVerification($staff, $app, $rows);
    review_assert($batch['errors'] === [] && $batch['changed'] === count($rows), 'The batch verification failed.');
    $historyAfterBatch = $documentHistory($id);
    $stale = $review->saveVerification($staff, $app, $rows);
    review_assert($stale['errors'] !== [] && $stale['changed'] === 0 && $documentHistory($id) === $historyAfterBatch, 'A stale verification save overwrote newer staff work.');
    review_assert($docs->outstandingRequired($app) === [], 'Verified uploads still count as outstanding.');
    review_assert($checklistRows($id) === 0, 'The upload workflow created checklist rows.');
    $app = $apps->findForReview($id);
    review_assert($app['eligibility_status'] === 'Not Assessed', 'Verifying documents decided eligibility.');
    $checks = array_column($review->approvalChecklist($app), null, 'label');
    review_assert($checks['Required documents']['ok'] && str_contains($checks['Required documents']['detail'], '0 documents still missing.') && !str_contains($checks['Required documents']['detail'], 'See the highlighted'), 'The approval summary still lists missing documents after every file was verified.');
    $sms->notifyAppointmentReminder($id, $app['mobile'], $app['reference_no'], $date . ' 09:00:00', 'Fixture Office');
    $message = $lastSms();
    review_assert(str_contains($message, 'Please arrive on time.') && !str_contains($message, 'After your interview, submit'), 'Reminder requests documents that are already verified.');
    review_assert($sms->uncheckedRequirementLabels($id) === [], 'Verified documents remain in the preparation list.');

    // ---- Approval, then ID release ----
    $test->prepare("UPDATE applications SET eligibility_status='Eligible',eligibility_remarks=? WHERE id=?")->execute(['Automatically assessed eligible after all required documents were verified.', $id]);
    review_assert(!isset(array_column($review->approvalChecklist($apps->findForReview($id)), null, 'label')['MSWD eligibility assessment: Eligible']), 'Separate eligibility assessment still gates approval.');
    $review->startReviewOnOpen($staff, $apps->findForReview($id));
    review_assert($apps->findForReview($id)['eligibility_status'] === 'Not Assessed', 'Existing automatic eligibility not reopened for assessment.');
    $app = $apps->findForReview($id);
    review_assert($review->updateStatus($staff, $app, 'Approved', '') === null, 'Verified documents and a successful interview could not be approved.');
    $app = $apps->findForReview($id);
    review_assert($release->schedule($staff, $app, $date, '10:00', 'Fixture Office', 'automatic', '') === null && $release->scheduleFor($id), 'The approved application could not schedule ID release.');

    // ---- Retained legacy evidence: checklist rows still count when no file was uploaded ----
    $insert->execute(['PREPARATION-LEGACY', $applicantId, $category]);
    $legacyId = (int) $test->lastInsertId();
    foreach ($requiredKeys as $key) {
        $test->prepare("INSERT INTO application_document_checklists (application_id,doc_key,applicant_has,verification_status,reviewed_at) VALUES (?,?,1,'Verified',NOW())")->execute([$legacyId, $key]);
    }
    review_assert($docs->outstandingRequired($apps->findForReview($legacyId)) === [], 'Retained legacy checklist evidence is no longer honoured.');
    echo "Upload-based document workflow, staff verification, staff date/time scheduling, duplicate SMS protection, privacy and explicit eligibility passed.\n";
}
