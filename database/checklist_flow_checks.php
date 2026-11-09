<?php
/** Additional checks invoked by test_staff_review_workflow.php in its disposable database. */
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
    foreach ($docs->requirementsFor($category) as $row) {
        $test->prepare('INSERT INTO application_document_checklists (application_id,doc_key,applicant_has) VALUES (?,?,0)')->execute([$id,$row['doc_key']]);
    }
    $test->prepare("UPDATE application_document_checklists SET applicant_has=1, verification_status='Verified', reviewed_at=NOW() WHERE application_id=? AND doc_key=?")->execute([$id,'fixtureAlreadyAvailable']);
    $app = $apps->findForReview($id);
    $draft = (new ApplicationService($test))->freshDraft(['id'=>$applicantId]);
    review_assert((new ApplicationService($test))->validateStep(3,$draft) === [], 'Unchecked requirements block document step.');
    $test->prepare("INSERT INTO users (full_name,email,username,password_hash,role,mobile,mobile_verified_at) VALUES ('Preparation Applicant','prepare@example.test','preparation_fixture',?,'applicant','09123456789',NOW())")->execute([password_hash('fixture',PASSWORD_DEFAULT)]);
    $submitterId = (int) $test->lastInsertId();
    $test->prepare('UPDATE categories SET is_active=1 WHERE id=?')->execute([$category]);
    $submitter = ['id'=>$submitterId,'full_name'=>'Preparation Applicant','role'=>'applicant','email'=>'prepare@example.test','mobile'=>'09123456789'];
    $applicationService = new ApplicationService($test);
    $submitDraft = $applicationService->freshDraft($submitter);
    $submitDraft = array_replace($submitDraft,['categoryId'=>$category,'barangay'=>'Test','address'=>'Test Address','birthdate'=>'1990-01-01','sex'=>'Female','civilStatus'=>'Single','children'=>[['name'=>'Fixture Child','birthdate'=>'2020-01-01']],'consent'=>true]);
    review_assert($applicationService->firstInvalidStep($submitDraft) === null,'Unchecked draft fails submission validation: '.json_encode($applicationService->firstInvalidStep($submitDraft)));
    $submitDraft['checkedDocuments'] = ['fixtureAlreadyAvailable' => true];
    $submittedRef = $applicationService->submit($submitter,$submitDraft);
    $submittedApp = $apps->findByReference($submittedRef);
    review_assert($submittedApp['status'] === 'Pending Review' && $submittedApp['eligibility_status'] === 'Not Assessed','Unchecked application submission failed.');
    $submittedChecklist = $docs->checklistForReview((int) $submittedApp['id'],$category);
    foreach ($submittedChecklist as $item) {
        review_assert($item['verification_status'] === ($item['doc_key'] === 'fixtureAlreadyAvailable' ? 'Verified' : 'Pending'), 'Submission did not automatically verify checked documents only.');
    }
    review_assert(!$test->query('SELECT COUNT(*) FROM application_documents WHERE application_id='.(int)$submittedApp['id'])->fetchColumn(),'Checkbox submission created uploaded documents.');
    $sms->notifySubmitted($id,$app['mobile'],$app['reference_no']);
    $message = $test->query('SELECT message FROM sms_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    review_assert(str_contains($message,'After your interview, submit') && str_contains($message,$app['reference_no']), 'Submission SMS omits preparation timing or reference.');
    $unprepared = $docs->checklistForReview($id,$category);
    $missingLabels = $sms->uncheckedRequirementLabels($id);
    review_assert(!in_array('Already Available Fixture Document',$missingLabels,true) && !in_array('Optional Fixture Document',$missingLabels,true),'Preparation includes checked or optional documents.');
    review_assert(str_contains($message,$missingLabels[0]),'Submission SMS does not name an unchecked document.');
    $render = static function (array $preparationItems): string {
        ob_start(); require APP_ROOT . '/views/partials/requirements-preparation.php'; return ob_get_clean();
    };
    review_assert(str_contains($render($unprepared),'Documents You Still Need to Prepare'), 'Preparation list missing.');
    $apptService = new AppointmentService($test);
    review_assert($apptService->book(['id'=>$applicantId,'full_name'=>'Fixture'],$app,1) !== null, 'Applicant can assign their own initial interview.');
    $date = review_office_date($test);
    $slotInsert = $test->prepare('INSERT INTO appointment_slots (slot_date,start_time,end_time,capacity,created_by) VALUES (?,\'09:00:00\',\'10:00:00\',1,?)');
    $slotInsert->execute([$date,$staff['id']]);
    $slot = (int) $test->lastInsertId();
    review_assert($review->scheduleInterview($staff,$app,$date.'T09:00','Fixture Office','','automatic','',$slot) === null, 'Staff cannot schedule with unchecked requirements.');
    $app = $apps->findForReview($id);
    review_assert($app['status'] === 'Interview Scheduled' && $app['eligibility_status'] === 'Not Assessed','Scheduling changed eligibility.');
    $count = (int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn();
    review_assert($review->scheduleInterview($staff,$app,$date.'T09:00','Fixture Office','','automatic','',$slot) === null,'Repeat scheduling failed.');
    review_assert((int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn() === $count,'Repeated scheduling sent duplicate SMS.');
    $interviewSms = (new Appointment($test))->interviewSms($id);
    review_assert(str_contains($interviewSms['message'],'Please arrive on time.') && str_contains($interviewSms['message'],'After your interview, submit missing documents before ID release:') && !str_contains($interviewSms['message'],'requirements to bring:') && str_contains($interviewSms['message'],$app['reference_no']),'Interview SMS lacks the correct schedule and document timing.');
    review_assert(str_contains($interviewSms['message'],$missingLabels[0]) && mb_strlen($interviewSms['message'])<=500,'Interview SMS omits document names or exceeds its limit.');
    review_assert(!str_contains($interviewSms['message'],'Already Available Fixture Document') && !str_contains($interviewSms['message'],'Optional Fixture Document'),'Interview SMS requests checked or optional documents.');
    $longMessage = SMSService::preparationText(array_fill(0,20,str_repeat('Long document label ',5)),200);
    review_assert(mb_strlen($longMessage)<=200 && str_contains(strtolower($longMessage),'full checklist online.') && str_contains($longMessage,'After your interview'),'Long requirement list loses document timing or full-checklist instruction.');
    $insert->execute(['PREPARATION-FULL-SLOT', $applicantId, $category]);
    $other = $apps->findForReview((int) $test->lastInsertId());
    review_assert($review->scheduleInterview($staff,$other,$date.'T09:00','Fixture Office','','automatic','',$slot) !== null,'Full slot accepted another interview.');
    $slotInsert->execute([review_office_date($test,$date . ' +1 day'),$staff['id']]);
    $rescheduleSlot = (int) $test->lastInsertId();
    review_assert($apptService->book(['id'=>$applicantId,'full_name'=>'Fixture','role'=>'applicant'],$app,$rescheduleSlot) === null,'Applicant cannot reschedule staff-assigned interview.');
    review_assert((int)(new Appointment($test))->interviewFor($id)['slot_id'] === $rescheduleSlot,'Rescheduled slot was not saved.');
    $count = (int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn();
    $controller = (new ReflectionClass(StaffController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Controller::class,'pdo'))->setValue($controller,$test);
    (new ReflectionProperty(StaffController::class,'staff'))->setValue($controller,$staff);
    $_GET = ['id'=>$id]; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/staff/application.php';
    ob_start(); $controller->application(); $staffHtml=ob_get_clean();
    review_assert(str_contains($staffHtml,'Documents to complete before ID release') && str_contains($staffHtml,'name="interview_date"') && str_contains($staffHtml,'name="interview_time"'),'Staff page omits preparation or direct interview date/time inputs.');
    review_assert(str_contains($staffHtml,'id="interview-slot"') && str_contains($staffHtml,'name="slot_id"'),'Staff scheduling is missing managed slot selection.');
    review_assert(!str_contains($staffHtml,'Eligibility is set to Yes automatically'),'Staff page still claims automatic eligibility.');
    review_assert(!str_contains($staffHtml,'checklist-verification-form') && !str_contains($staffHtml,'>Verify All</button>') && !str_contains($staffHtml,'Undo Verification'),'Staff checklist still requires manual verification.');
    review_assert(!str_contains($staffHtml,'receive_checklist_document'), 'Document receipt buttons are visible before the interview is completed.');
    review_assert(!str_contains($staffHtml,'confirm_all_required_documents'), 'Bulk confirmation is visible before the interview is completed.');
    review_assert($review->confirmAllRequiredDocuments($staff,$app) !== null, 'Staff could confirm all documents before completing the interview.');
    review_assert($review->receiveChecklistDocument($staff,$app,$unprepared[0]['doc_key']) !== null, 'Staff could record document receipt before completing the interview.');
    $appointmentPage = (new AppointmentController($test))->handle(['id'=>$applicantId],[$app]);
    $renderAppointment = static function(array $data): string {
        extract($data); ob_start(); require APP_ROOT.'/views/applicant/appointments/index.php'; return ob_get_clean();
    };
    $appointmentHtml = $renderAppointment($appointmentPage['data']);
    review_assert(str_contains($appointmentHtml,e($missingLabels[0])) && str_contains($appointmentHtml,'Documents You Still Need to Prepare'),'Applicant schedule omits unchecked document names.');
    $selected = array_column($unprepared,'doc_key');
    $docs->saveApplicantChecklist($id,$unprepared,$selected);
    review_assert(count($docs->outstandingRequired($app)) > 0, 'Applicant checkboxes cleared documents awaiting physical receipt after scheduling.');
    $review->verifyAvailableChecklistOnOpen($staff,$app);
    review_assert(count($docs->outstandingRequired($app)) > 0, 'Reopening automatically cleared documents awaiting physical receipt.');
    review_assert(in_array($missingLabels[0],$sms->uncheckedRequirementLabels($id),true), 'Applicant checkbox changes removed a missing physical document from reminders.');
    review_assert(str_contains($render($docs->checklistForReview($id,$category)),'Documents You Still Need to Prepare'),'Applicant changed the submitted checklist after scheduling.');
    review_assert($apps->findForReview($id)['eligibility_status'] === 'Not Assessed','Checking boxes decided eligibility.');
    review_assert((int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn() === $count,'Checkbox changes sent SMS.');
    $docs->saveApplicantChecklist($id,$unprepared,[]);
    $sms->notifyAppointmentReminder($id,$app['mobile'],$app['reference_no'],$date.' 09:00:00','Fixture Office');
    $message = $test->query('SELECT message FROM sms_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    review_assert(str_contains($message,'After your interview, submit') && str_contains($message,$missingLabels[0]),'Reminder lacks missing document names or submission timing.');
    // Complete the interview while documents remain outstanding.
    $test->prepare('UPDATE appointments SET scheduled_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE application_id = ?')->execute([$id]);
    review_assert($review->recordInterviewOutcome($staff,$id,'Successful','') === null, 'Successful interview with missing documents failed.');
    $app = $apps->findForReview($id);
    review_assert($review->updateStatus($staff,$app,'Approved','') !== null, 'Missing documents allowed approval after a successful interview.');
    $incompleteChecks = array_column($review->approvalChecklist($app),null,'label');
    $test->prepare("UPDATE application_document_checklists SET verification_status = 'Pending' WHERE application_id = ? AND doc_key = 'fixtureAlreadyAvailable'")->execute([$id]);
    $checkboxCounts = array_column($review->approvalChecklist($app),null,'label');
    review_assert($checkboxCounts['Required documents']['detail'] === $incompleteChecks['Required documents']['detail'], 'Document counts use review status instead of the applicant checkboxes.');
    $test->prepare("UPDATE application_document_checklists SET verification_status = 'Verified' WHERE application_id = ? AND doc_key = 'fixtureAlreadyAvailable'")->execute([$id]);
    review_assert(!$incompleteChecks['Required documents']['ok'] && str_contains($incompleteChecks['Required documents']['detail'],'1 document complete') && str_contains($incompleteChecks['Required documents']['detail'],count($docs->outstandingRequired($app)) . ' documents still missing.') && str_contains($incompleteChecks['Required documents']['detail'],'See the highlighted items in the Required Documents Checklist.') && str_contains($incompleteChecks['Required documents']['detail'],'before approval and ID release scheduling'), 'Before approving details omit document counts or the release restriction.');
    $_GET = ['id'=>$id];
    ob_start(); $controller->application(); $receiptHtml = ob_get_clean();
    review_assert(str_contains($receiptHtml,'>Document received</button>') && str_contains($receiptHtml,'disabled title="Complete all required documents before approval"'), 'Staff view omits receipt action or allows premature approval.');
    review_assert(str_contains($receiptHtml,'value="confirm_all_required_documents"'), 'Bulk confirmation is missing after the interview.');
    $availableDocumentSection = explode('id="checklist-fixtureAlreadyAvailable"', $receiptHtml, 2)[1] ?? '';
    $availableDocumentSection = explode('id="checklist-', $availableDocumentSection, 2)[0];
    review_assert($availableDocumentSection !== '' && str_contains($availableDocumentSection, 'disabled checked') && !str_contains($availableDocumentSection, 'Pending') && !str_contains($availableDocumentSection, 'receive_checklist_document') && str_contains($receiptHtml, 'class="review-row" id="checklist-fixtureAlreadyAvailable"'), 'A document checked before submission is not shown as a simple checked requirement.');
    $release = new IdReleaseService($test);
    $test->prepare("UPDATE applications SET status = 'Approved' WHERE id = ?")->execute([$id]);
    $approvedSnapshot = $apps->findForReview($id);
    $smsBeforeBlockedRelease = (int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn();
    review_assert($release->schedule($staff,$approvedSnapshot,$date,'10:00','Fixture Office','automatic','') !== null, 'Missing documents allowed ID release scheduling.');
    review_assert($release->scheduleFor($id) === null && (int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn() === $smsBeforeBlockedRelease, 'Blocked release created a schedule or sent SMS.');
    review_assert($release->confirmRelease($staff,$approvedSnapshot) !== null, 'Missing documents allowed ID handover.');
    $test->prepare("UPDATE applications SET status = 'Interview Scheduled' WHERE id = ?")->execute([$id]);
    review_assert($review->receiveChecklistDocument(['id'=>$applicantId,'role'=>'applicant'],$app,$unprepared[0]['doc_key']) !== null, 'Applicant recorded physical receipt.');
    review_assert($review->receiveChecklistDocument($staff,$app,'unknown-requirement') !== null, 'Receipt accepted an unknown requirement.');
    review_assert($review->receiveChecklistDocument($staff,$app,'fixtureOptional') !== null, 'Receipt accepted an optional requirement.');
    $outstanding = $docs->outstandingRequired($app);
    foreach ($outstanding as $index => $item) {
        $receiptError = $index === count($outstanding) - 1
            ? $review->confirmAllRequiredDocuments($staff,$app)
            : $review->receiveChecklistDocument($staff,$app,$item['doc_key']);
        review_assert($receiptError === null, 'Receiving a missing document failed.');
        $historyCount = count($apps->history($id));
        review_assert($review->receiveChecklistDocument($staff,$app,$item['doc_key']) === null && count($apps->history($id)) === $historyCount, 'Repeated receipt duplicated history.');
        $received = array_column($docs->checklistForReview($id,$category),null,'doc_key')[$item['doc_key']];
        review_assert($received['verification_status'] === 'Verified' && (int) $received['reviewed_by'] === (int) $staff['id'] && $received['reviewed_at'], 'Receipt lacks staff attribution.');
        if ($index < count($outstanding) - 1) {
            review_assert($review->updateStatus($staff,$app,'Approved','') !== null, 'Partially received requirements allowed approval.');
        }
    }
    $confirmedHistoryCount = count($apps->history($id));
    review_assert($review->confirmAllRequiredDocuments($staff,$app) === null && count($apps->history($id)) === $confirmedHistoryCount, 'Repeated bulk confirmation duplicated history.');
    $availableAfterConfirmation = array_column($docs->checklistForReview($id,$category),null,'doc_key')['fixtureAlreadyAvailable'];
    review_assert($availableAfterConfirmation['reviewed_by'] === null, 'Bulk confirmation changed an automatically verified document.');
    $app = $apps->findForReview($id);
    review_assert($app['eligibility_status'] === 'Not Assessed','Physical verification decided eligibility.');
    $checks = array_column($review->approvalChecklist($app),null,'label');
    review_assert($checks['Required documents']['ok'] && str_contains($checks['Required documents']['detail'], '0 documents still missing.') && !str_contains($checks['Required documents']['detail'], 'See the highlighted'), 'Before approving still lists missing documents after staff received them.');
    $sms->notifyAppointmentReminder($id,$app['mobile'],$app['reference_no'],$date.' 09:00:00','Fixture Office');
    $message = $test->query('SELECT message FROM sms_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    review_assert(str_contains($message,'Please arrive on time.') && !str_contains($message,'After your interview, submit'),'Reminder requests already verified documents.');
    review_assert($sms->uncheckedRequirementLabels($id) === [],'Verified physical documents remain in preparation list.');
    $sms->notifyDocumentCorrection($id,$app['mobile'],$app['reference_no'],'Sensitive document','Sensitive assessment details');
    $message = $test->query('SELECT message FROM sms_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    review_assert(!str_contains($message,'Sensitive') && str_contains($message,'Log in'),'Correction SMS exposes document details.');
    $test->prepare("UPDATE applications SET eligibility_status='Eligible',eligibility_remarks=? WHERE id=?")->execute(['Automatically assessed eligible after all required documents were verified.',$id]);
    review_assert(!isset(array_column($review->approvalChecklist($apps->findForReview($id)),null,'label')['MSWD eligibility assessment: Eligible']), 'Separate eligibility assessment still gates approval.');
    $review->startReviewOnOpen($staff,$apps->findForReview($id));
    review_assert($apps->findForReview($id)['eligibility_status'] === 'Not Assessed','Existing automatic eligibility not reopened for assessment.');
    $app = $apps->findForReview($id);
    review_assert($review->updateStatus($staff,$app,'Approved','') === null, 'Complete documents and successful interview could not be approved.');
    $app = $apps->findForReview($id);
    review_assert($release->schedule($staff,$app,$date,'10:00','Fixture Office','automatic','') === null && $release->scheduleFor($id), 'Complete approved application could not schedule ID release.');
    echo "Checkbox preparation, staff scheduling, slot capacity, duplicate SMS protection, privacy and explicit eligibility passed.\n";
}
