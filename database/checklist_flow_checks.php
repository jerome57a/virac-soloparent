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
        // The row carries the status, remarks and file version currently stored, as the staff page would post them.
        return $review->saveVerification($staff, $app, [(int) $file['id'] => review_verdict_row($file, $status, $remarks)]);
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
    $denied = $review->saveVerification(['id' => $applicantId, 'role' => 'applicant', 'full_name' => 'Preparation Fixture'], $app, [$fileId => review_verdict_row(review_document_row($test, $fileId), 'VERIFIED')]);
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

    // ---- An interview booked once the application was ready (staff no longer schedule through a service call) ----
    $apptService = new AppointmentService($test);
    review_assert(!method_exists($apptService, 'book') && !method_exists($apptService, 'createSlot'), 'Applicant booking / slot management methods still exist.');
    $date = review_office_date($test);
    $app = $apps->findForReview($id);
    // What follows needs an existing interview whose documents are NOT all verified. That is a legal state (a document
    // can become unverified after booking, for example a replacement upload), so the booking is recorded directly.
    review_book_interview($test, $staff, $id, $date . ' 09:00:00');
    review_assert((new Appointment($test))->interviewFor($id)['slot_id'] === null, 'A booked interview must be saved with a NULL slot_id.');
    $app = $apps->findForReview($id);
    review_assert($app['status'] === 'Interview Scheduled' && $app['eligibility_status'] === 'Not Assessed', 'Booking an interview changed eligibility.');
    $longMessage = SMSService::preparationText(array_fill(0, 20, str_repeat('Long document label ', 5)), 200);
    review_assert(mb_strlen($longMessage) <= 200 && str_contains(strtolower($longMessage), 'required uploads online.') && str_contains($longMessage, 'After your interview'), 'Long requirement list loses document timing or the full-list instruction.');
    // Later steps here only need a later office date.
    $date = review_office_date($test, $date . ' +1 day');
    $app = $apps->findForReview($id);

    // ---- Staff page: uploaded files, per-document verification controls, no checklist-era controls ----
    $count = (int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn();
    $controller = (new ReflectionClass(StaffController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Controller::class, 'pdo'))->setValue($controller, $test);
    (new ReflectionProperty(StaffController::class, 'staff'))->setValue($controller, $staff);
    $_GET = ['id' => $id]; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/staff/application.php';
    ob_start(); $controller->application(); $staffHtml = ob_get_clean();
    review_assert(str_contains($staffHtml, 'The interview invitation is locked until all required documents are verified') && str_contains($staffHtml, $missingLabels[0]) && str_contains($staffHtml, 'name="docs[' . $fileId . '][status]"') && str_contains($staffHtml, 'name="docs[' . $fileId . '][expected_version]"') && str_contains($staffHtml, 'download.php?doc_id=' . $fileId) && str_contains($staffHtml, 'Save Verification'), 'Staff page omits the uploaded file, its verification control, its file version or the outstanding-documents summary.');
    review_assert(!str_contains($staffHtml, 'You can schedule the interview while documents are outstanding') && !str_contains($staffHtml, 'name="interview_date"') && !str_contains($staffHtml, 'name="interview_time"') && !str_contains($staffHtml, 'name="slot_id"'), 'Staff page offers interview scheduling while required documents are outstanding, or still exposes slot selection.');
    review_assert(!str_contains($staffHtml, 'Eligibility is set to Yes automatically'), 'Staff page still claims automatic eligibility.');
    review_assert(!str_contains($staffHtml, 'checklist-verification-form') && !str_contains($staffHtml, '>Verify All</button>') && !str_contains($staffHtml, 'Undo Verification') && !str_contains($staffHtml, 'name="checklist[]"'), 'Staff page still shows checklist-era verification controls.');
    review_assert(!str_contains($staffHtml, 'receive_checklist_document') && !str_contains($staffHtml, 'confirm_all_required_documents'), 'Physical-receipt actions are still offered to staff.');
    review_assert(!method_exists($review, 'receiveChecklistDocument') && !method_exists($review, 'confirmAllRequiredDocuments') && !method_exists($review, 'verifyAllChecklistItems') && !method_exists($review, 'reviewChecklistItems'), 'A retired physical-receipt checklist method came back.');
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
    $correctionLabel = $items($id)[$correctionKey]['label'];
    $correctionSms = $lastSms();
    review_assert(str_contains($correctionSms, '"' . $correctionLabel . '"') && str_contains($correctionSms, $app['reference_no']) && str_contains($correctionSms, 'Supporting Documents') && !str_contains($correctionSms, 'Physical requirements'),
        'The correction SMS does not name the affected document, the application reference and Supporting Documents.');
    review_assert(!str_contains($correctionSms, 'Scan is blurry'), 'The correction SMS exposes staff remarks.');
    $existing = $docs->findForApplication($id, $correctionKey);
    $docs->replace($existing, $id, $fileMeta('replacement'), $id . '/replacement.pdf', $applicantActor);
    $replaced = $uploaded($id)[$correctionKey];
    review_assert((int) $replaced['id'] === $correctionFile && $replaced['original_name'] === 'replacement.pdf' && $replaced['verification_status'] === 'FOR_VERIFICATION' && $replaced['staff_remarks'] === null && $replaced['verified_by'] === null, 'Replacing a flagged file did not reset it in place for staff review.');
    review_assert(array_column($docs->statusHistoryFor($id)[$correctionFile], 'to_status') === ['FOR_VERIFICATION', 'NEEDS_CORRECTION', 'FOR_VERIFICATION'], 'The correction and replacement trail is missing from the document history.');
    review_assert(in_array($correctionKey, array_column($docs->outstandingRequired($app), 'doc_key'), true), 'A replaced but unverified file counted as complete.');
    // One verification batch with two documents needing correction sends ONE SMS that names both, never their remarks.
    $secondKey = array_values(array_diff($requiredKeys, ['fixtureAlreadyAvailable', $correctionKey]))[0];
    $upload($id, $secondKey);
    $correctionSmsCount = static fn(): int => (int) $test->query("SELECT COUNT(*) FROM sms_logs WHERE template_key = 'document_correction'")->fetchColumn();
    $smsBeforeBatch = $correctionSmsCount();
    $notesBeforeBatch = (int) $test->query("SELECT COUNT(*) FROM notifications WHERE type = 'correction'")->fetchColumn();
    $currentFiles = $uploaded($id);
    $twoCorrections = $review->saveVerification($staff, $apps->findForReview($id), [
        (int) $currentFiles[$correctionKey]['id'] => review_verdict_row($currentFiles[$correctionKey], 'NEEDS_CORRECTION', 'First private remark'),
        (int) $currentFiles[$secondKey]['id'] => review_verdict_row($currentFiles[$secondKey], 'NEEDS_CORRECTION', 'Second private remark'),
    ]);
    review_assert($twoCorrections['errors'] === [] && $twoCorrections['changed'] === 2 && $twoCorrections['notify_failures'] === 0, 'A batch of two correction requests failed.');
    review_assert($correctionSmsCount() === $smsBeforeBatch + 1, 'A batch with two corrections did not send exactly one SMS.');
    $batchSms = $lastSms();
    $secondLabel = $items($id)[$secondKey]['label'];
    review_assert(str_contains($batchSms, $correctionLabel) && str_contains($batchSms, $secondLabel) && str_contains($batchSms, $app['reference_no']) && str_contains($batchSms, 'log in and open Supporting Documents'),
        'The batch correction SMS does not list every affected document, the reference and where to find them.');
    review_assert(!str_contains($batchSms, 'First private remark') && !str_contains($batchSms, 'Second private remark') && !str_contains($batchSms, 'Physical requirements'), 'The batch correction SMS exposes staff remarks.');
    review_assert((int) $test->query("SELECT COUNT(*) FROM notifications WHERE type = 'correction'")->fetchColumn() === $notesBeforeBatch + 2
        && (int) $test->query("SELECT COUNT(*) FROM notifications WHERE type = 'correction' AND message LIKE '%First private remark%' AND message LIKE '%Supporting Documents%'")->fetchColumn() === 1,
        'Each flagged document must get its own in-app notice, with its full remarks and the Supporting Documents instruction.');

    // ---- Approval and ID release are blocked until every required file is verified ----
    $test->prepare('UPDATE appointments SET scheduled_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE application_id = ?')->execute([$id]);
    // The interview result cannot be recorded while required documents are outstanding (the full matrix is in
    // check_interview_result_document_gate()), so approval stays refused here for both reasons.
    $resultAppointmentBefore = $test->query('SELECT * FROM appointments WHERE application_id = ' . (int) $id)->fetch();
    $resultRefusal = $review->recordInterviewOutcome($staff, $id, 'Successful', '');
    review_assert($resultRefusal !== null && str_contains($resultRefusal, 'until all required documents are verified')
        && $test->query('SELECT * FROM appointments WHERE application_id = ' . (int) $id)->fetch() === $resultAppointmentBefore, 'An interview result was recorded, or the appointment changed, while required documents were outstanding.');
    $app = $apps->findForReview($id);
    review_assert($review->updateStatus($staff, $app, 'Approved', '') !== null, 'Unverified documents allowed approval without a successful interview.');
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
            $rows[(int) $file['id']] = review_verdict_row($file, 'VERIFIED');
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
    review_assert($review->recordInterviewOutcome($staff, $id, 'Successful', '') === null, 'A successful interview could not be recorded after every required document was verified.');
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

/**
 * Business rule (D5): once an interview is booked, a later unverified or expired required document leaves the appointment
 * and its reminders alone, but the interview RESULT can be neither recorded nor changed until every required document is
 * Verified and unexpired again. Runs in the disposable database from test_staff_review_workflow.php on its own category,
 * requirements and applications. Every refusal must leave the appointment, the application status, its history, the SMS
 * log, the notifications and the audit log exactly as they were.
 */
function check_interview_result_document_gate(PDO $test, array $staff, int $applicantId): void
{
    require_once __DIR__ . '/../services/AppointmentService.php';
    review_assert(SMSService::testMode(), 'Run workflow tests with SMS_PROVIDER=test.');
    $GLOBALS['__settings_cache']['sms_enabled'] = '1';
    $apps = new Application($test);
    $docs = new Document($test);
    $appts = new Appointment($test);
    $review = new ApplicationReviewService($test);
    $appointmentService = new AppointmentService($test);
    $applicantActor = ['id' => $applicantId, 'full_name' => 'Result gate fixture', 'role' => 'applicant'];

    $test->exec("INSERT INTO categories (name,description,sort_order) VALUES ('Interview result gate fixture','Fixture',97)");
    $category = (int) $test->lastInsertId();
    $requirement = $test->prepare('INSERT INTO document_requirements (category_id,doc_key,label,is_required,sort_order) VALUES (?,?,?,?,?)');
    foreach ([['resultGateA', 'Result gate document A', 1], ['resultGateB', 'Result gate document B', 1]] as $order => [$key, $label, $required]) {
        $requirement->execute([$category, $key, $label, $required, $order + 1]);
    }
    $labelOf = array_column($docs->requirementsFor($category), 'label', 'doc_key');

    $insert = $test->prepare("INSERT INTO applications (reference_no,user_id,type,full_name,barangay,address,mobile,email,birthdate,sex,civil_status,category_id,consent_at,submitted_at) VALUES (?,?,'new','Result Gate Fixture','Test','Test','09123456789','resultgate@example.test','1990-01-01','Female','Single',?,NOW(),NOW())");
    $insert->execute(['RESULT-GATE-1', $applicantId, $category]);
    $id = (int) $test->lastInsertId();
    review_make_interview_ready($test, $review, $staff, $id, $category);
    $day = review_office_date($test, '+3 days');
    review_book_interview($test, $staff, $id, $day . ' 09:00:00', 'Result Gate Office');

    $docId = static fn(string $key): int => (int) $test->query('SELECT id FROM application_documents WHERE application_id = ' . $id . " AND doc_key = '" . $key . "'")->fetchColumn();
    $docA = $docId('resultGateA');
    $docB = $docId('resultGateB');
    $meta = static fn(string $name): array => ['original_name' => $name . '.png', 'mime' => 'image/png', 'size' => 1024, 'sha256' => hash('sha256', $name)];
    $count = static fn(string $sql): int => (int) $test->query($sql)->fetchColumn();
    // Everything a refused result must leave untouched, including the whole appointment row (result columns too).
    $state = static fn(): array => [
        'appointment' => $test->query("SELECT * FROM appointments WHERE application_id = $id")->fetch() ?: null,
        'status' => $test->query("SELECT status FROM applications WHERE id = $id")->fetchColumn(),
        'history' => $count("SELECT COUNT(*) FROM application_status_history WHERE application_id = $id"),
        'documentHistory' => $count("SELECT COUNT(*) FROM document_status_history WHERE application_id = $id"),
        'sms' => $count('SELECT COUNT(*) FROM sms_logs'),
        'notifications' => $count('SELECT COUNT(*) FROM notifications'),
        'audit' => $count('SELECT COUNT(*) FROM audit_logs'),
    ];
    // $verdicts: document id => [status, remarks]; each row carries the status, remarks and file version stored right now.
    $verdict = static function (array $verdicts) use ($test, $review, $apps, $staff, $id): array {
        $rows = [];
        foreach ($verdicts as $fileId => $v) {
            $rows[(int) $fileId] = review_verdict_row(review_document_row($test, (int) $fileId), $v[0], $v[1] ?? '');
        }
        return $review->saveVerification($staff, $apps->findForReview($id), $rows);
    };
    $refuse = static function (string $why, array $mustMention, string $outcome = 'Successful', string $notes = '') use ($review, $staff, $id, $state): string {
        $before = $state();
        $error = $review->recordInterviewOutcome($staff, $id, $outcome, $notes);
        review_assert($error !== null && str_contains($error, 'cannot be recorded or changed until all required documents are verified'), 'The interview result was not refused: ' . $why . ' (' . var_export($error, true) . ')');
        foreach ($mustMention as $text) {
            review_assert(str_contains($error, $text), 'The refusal does not name "' . $text . '": ' . $why . ' (' . $error . ')');
        }
        review_assert($state() === $before, 'A refused result changed the appointment, status, history, SMS log, notifications or audit log: ' . $why);
        return $error;
    };
    $sameBooking = static fn(array $a, array $b): bool => $a['scheduled_at'] === $b['scheduled_at'] && $a['location'] === $b['location'] && $a['status'] === $b['status'] && $a['slot_id'] === $b['slot_id'];

    // 1. A document becomes unverified AFTER booking: the appointment is kept untouched
    //    and its reminder is still sent normally (once).
    $test->prepare('UPDATE appointments SET scheduled_at = DATE_ADD(NOW(), INTERVAL 3 HOUR), reminder_sent_at = NULL WHERE application_id = ?')->execute([$id]);
    $booked = $appts->interviewFor($id);
    $docs->replace(review_document_row($test, $docB), $id, $meta('resultGateB2'), $id . '/resultGateB-second.png', $applicantActor);
    review_assert($appts->interviewFor($id) === $booked && $apps->findForReview($id)['status'] === 'Interview Scheduled', 'A document becoming unverified changed the existing appointment or the application status.');
    $reminderLogs = static fn(): int => $count("SELECT COUNT(*) FROM sms_logs WHERE template_key = 'appointment_reminder'");
    $logsBefore = $reminderLogs();
    $first = $appointmentService->sendDueReminders();
    review_assert(in_array('RESULT-GATE-1', $first['sent'], true) && $reminderLogs() > $logsBefore, 'The appointment reminder was not sent while a document was unverified.');
    $reminded = $appts->interviewFor($id);
    review_assert($reminded['reminder_sent_at'] !== null && $sameBooking($reminded, $booked), 'Sending the reminder changed the appointment date, time, location or status.');
    review_assert(!in_array('RESULT-GATE-1', $appointmentService->sendDueReminders()['sent'], true), 'The reminder was sent twice.');

    // 2. Once the appointment time has passed: a replacement awaiting verification blocks the result, and the page says so.
    $test->prepare('UPDATE appointments SET scheduled_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE application_id = ?')->execute([$id]);
    $refuse('the replacement awaits staff verification', [$labelOf['resultGateB'] . ' (Pending)']);
    $refuse('an unsuccessful result is gated the same way', [$labelOf['resultGateB'] . ' (Pending)'], 'Unsuccessful');
    $page = review_render_staff($test, $staff, $id);
    review_assert(str_contains($page, 'Interview result is locked until all required documents are verified') && str_contains($page, $labelOf['resultGateB']) && !str_contains($page, 'id="interview-result-form"') && !str_contains($page, 'name="interview_outcome"'),
        'The staff page still offers result recording, or does not name the blocking document, while a document is unverified.');

    // 3. Re-verification unlocks the result controls; Needs Correction and Pending block again.
    $saved = $verdict([$docB => ['VERIFIED']]);
    review_assert($saved['errors'] === [] && $docs->outstandingRequired($apps->findForReview($id)) === [], 'Staff could not verify the replacement.');
    $page = review_render_staff($test, $staff, $id);
    review_assert(str_contains($page, 'id="interview-result-form"') && str_contains($page, 'name="interview_outcome"') && !str_contains($page, 'Interview result is locked'), 'The result controls did not return after every document was verified.');
    $saved = $verdict([$docB => ['NEEDS_CORRECTION', 'Please retake the photo']]);
    review_assert($saved['errors'] === [], 'Staff could not request a correction.');
    $refuse('a document needs correction', [$labelOf['resultGateB'] . ' (Needs Correction)']);
    $saved = $verdict([$docB => ['FOR_VERIFICATION']]);
    review_assert($saved['errors'] === [], 'Staff could not return a document to Pending.');
    $refuse('a document is Pending', [$labelOf['resultGateB'] . ' (Pending)']);
    $saved = $verdict([$docB => ['VERIFIED']]);
    review_assert($saved['errors'] === [], 'Staff could not re-verify the document.');

    // 4. A newly required document that has no file blocks the result; so does expired required legacy evidence.
    $requirement->execute([$category, 'resultGateMissing', 'Result gate missing document', 1, 8]);
    $refuse('a required document has no file', ['Result gate missing document (not uploaded)']);
    $test->prepare('DELETE FROM document_requirements WHERE doc_key = ?')->execute(['resultGateMissing']);
    $requirement->execute([$category, 'resultGateLegacy', 'Result gate legacy evidence', 1, 9]);
    $test->prepare("INSERT INTO application_document_checklists (application_id,doc_key,applicant_has,verification_status,reviewed_by,reviewed_at,expires_at) VALUES (?,?,1,'Verified',?,NOW(),DATE_SUB(CURDATE(), INTERVAL 1 DAY))")->execute([$id, 'resultGateLegacy', $staff['id']]);
    $refuse('required legacy evidence has expired', ['Result gate legacy evidence (expired)']);
    $test->prepare('DELETE FROM application_document_checklists WHERE application_id = ? AND doc_key = ?')->execute([$id, 'resultGateLegacy']);
    $test->prepare('DELETE FROM document_requirements WHERE doc_key = ?')->execute(['resultGateLegacy']);
    review_assert($docs->outstandingRequired($apps->findForReview($id)) === [], 'The result-gate fixtures were not cleaned up.');

    // 5. Every required document verified: the result is recorded; the booking itself is unchanged.
    $before = $state();
    review_assert($review->recordInterviewOutcome($staff, $id, 'Successful', 'Fine') === null, 'The result could not be recorded after every document was verified.');
    $recorded = $appts->interviewFor($id);
    $after = $state();
    review_assert($recorded['status'] === 'COMPLETED' && $recorded['result'] === 'Successful: Fine' && $recorded['result_recorded_at'] !== null
        && $recorded['scheduled_at'] === $before['appointment']['scheduled_at'] && $recorded['location'] === $before['appointment']['location']
        && $after['status'] === $before['status'] && $after['history'] === $before['history'] + 1 && $after['audit'] === $before['audit'] + 1, 'Recording the result changed the wrong data.');

    // 6. Changing an existing result, and resubmitting the identical one, are both gated while a document is unverified.
    $saved = $verdict([$docB => ['FOR_VERIFICATION']]);
    review_assert($saved['errors'] === [], 'Staff could not return a document to Pending after the result.');
    $refuse('changing a recorded result', [$labelOf['resultGateB'] . ' (Pending)'], 'Unsuccessful', 'Changed');
    $refuse('resubmitting the identical result', [$labelOf['resultGateB'] . ' (Pending)'], 'Successful', 'Fine');
    review_assert($appts->interviewFor($id)['result'] === 'Successful: Fine', 'A gated change altered the recorded result.');
    $page = review_render_staff($test, $staff, $id);
    review_assert(str_contains($page, 'The interview result cannot be changed until all required documents are verified') && str_contains($page, 'Undo Interview Result'), 'The staff page does not explain the locked result, or the Undo control changed.');

    // 7. Replacement versus result recording on two connections to the disposable database (one process cannot run them
    //    truly in parallel, so both orderings are checked deterministically, exactly like the scheduling check).
    $saved = $verdict([$docB => ['VERIFIED']]);
    review_assert($saved['errors'] === [], 'Staff could not re-verify the document.');
    $testDatabase = (string) $test->query('SELECT DATABASE()')->fetchColumn();
    review_assert((bool) preg_match('/^solo_review_test_[a-f0-9]{12}$/', $testDatabase), 'The concurrency check requires the disposable database.');
    $second = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $testDatabase . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $secondDocs = new Document($second);
    $second->beginTransaction();
    $locked = $secondDocs->lockForApplication($id, 'resultGateA'); // exactly what an applicant replacement does first
    review_assert($locked !== null && $locked['verification_status'] === 'VERIFIED', 'The second connection could not lock the document.');
    $previousTimeout = (int) $test->query('SELECT @@SESSION.innodb_lock_wait_timeout')->fetchColumn();
    $beforeBlocked = $state();
    $test->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $waited = false;
    try {
        $review->recordInterviewOutcome($staff, $id, 'Unsuccessful', 'Changed');
    } catch (PDOException $e) {
        $waited = true; // lock wait timeout: the result could not be written while the replacement held its locks
    } finally {
        $test->exec('SET SESSION innodb_lock_wait_timeout = ' . $previousTimeout);
    }
    review_assert($waited && !$test->inTransaction() && $state() === $beforeBlocked, 'Result recording did not wait for a replacement holding the document locks, or changed data while waiting.');
    $secondDocs->replace($locked, $id, $meta('resultGateA2'), $id . '/resultGateA-second.png', $applicantActor);
    $second->commit();
    $refuse('the replacement committed before the result was written', [$labelOf['resultGateA'] . ' (Pending)'], 'Unsuccessful', 'Changed');
    $second = null;

    // 8. Re-verification allows changing the result again; an identical resubmission is then a harmless no-op.
    $saved = $verdict([$docA => ['VERIFIED']]);
    review_assert($saved['errors'] === [], 'Staff could not verify the concurrent replacement.');
    review_assert($review->recordInterviewOutcome($staff, $id, 'Unsuccessful', 'Changed') === null && $appts->interviewFor($id)['result'] === 'Unsuccessful: Changed', 'The result could not be changed after every document was verified again.');
    $before = $state();
    review_assert($review->recordInterviewOutcome($staff, $id, 'Unsuccessful', 'Changed') === null && $state() === $before, 'An identical resubmission with verified documents changed data.');

    // 9. Staff can still cancel an appointment, or mark a no-show, while documents are unverified.
    foreach (['RESULT-GATE-2' => 'CANCELLED', 'RESULT-GATE-3' => 'MISSED'] as $reference => $outcome) {
        $insert->execute([$reference, $applicantId, $category]);
        $otherId = (int) $test->lastInsertId();
        review_make_interview_ready($test, $review, $staff, $otherId, $category);
        review_book_interview($test, $staff, $otherId, $day . ' 10:00:00', 'Result Gate Office');
        $firstFile = $test->query('SELECT * FROM application_documents WHERE application_id = ' . $otherId . ' ORDER BY id LIMIT 1')->fetch();
        $docs->replace($firstFile, $otherId, $meta('unverified' . $otherId), $otherId . '/unverified-' . $otherId . '.png', $applicantActor);
        review_assert($docs->outstandingRequired($apps->findForReview($otherId)) !== [], 'The ' . $reference . ' fixture still has every document verified.');
        if ($outcome === 'MISSED') {
            $test->prepare('UPDATE appointments SET scheduled_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE application_id = ?')->execute([$otherId]);
        }
        $appointment = $appts->interviewFor($otherId);
        review_assert($appointmentService->staffSetOutcome($staff, (int) $appointment['id'], $outcome, $outcome === 'CANCELLED' ? 'Fixture cancellation' : '') === null && $appts->interviewFor($otherId)['status'] === $outcome,
            'Staff could not ' . ($outcome === 'CANCELLED' ? 'cancel' : 'mark a no-show for') . ' an appointment while a document was unverified.');
    }
    review_assert((int) $test->query('SELECT COUNT(*) FROM sms_logs WHERE test_mode = 0')->fetchColumn() === 0, 'A live SMS was attempted.');
    echo "PASS: after booking, unverified documents keep the appointment and reminders but block recording or changing the interview result; refusals change nothing; replacement and result recording are serialized; cancel and no-show stay available.\n";
}
