<?php
/** Disposable-database checks; synthetic category policies are not installed in the live system. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
$testDb = 'renewal_test_' . bin2hex(random_bytes(6));
validate_temporary_database_target($testDb, 'renewal_test_');
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/RenewalService.php';
require_once __DIR__ . '/../services/ApplicationReviewService.php';
require_once __DIR__ . '/../services/ApplicationTrackerService.php';
require_once __DIR__ . '/../services/IdReleaseService.php';
require_once __DIR__ . '/../services/AppointmentService.php';
require_once __DIR__ . '/../controllers/StaffController.php';
require_once __DIR__ . '/../includes/csrf.php';
// Storage safety: config/storage.php already refused to start without a validated TEST_STORAGE_ROOT. These helpers
// additionally refuse pre-existing folders, track every file this test creates, delete only those exact files and
// remove folders with rmdir only (no glob, no recursion).
require_once __DIR__ . '/upload_fixtures.php';
upload_fixture_require_test_root();

function renewal_assert(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }

/** Test fixture: records an MSWD eligibility assessment the way the retired manual form did (assessEligibility was removed from the app). */
function renewal_set_eligibility(PDO $pdo, array $staff, array $app, string $status, string $remarks): void
{
    $notes = trim($remarks) !== '' ? trim($remarks) : null;
    (new EligibilityAssessment($pdo))->saveMswd((int) $app['id'], (int) $app['user_id'], $status === 'Eligible' ? 'ELIGIBLE' : 'NOT_ELIGIBLE', $notes, (int) $staff['id']);
    $applications = new Application($pdo);
    $applications->setEligibility((int) $app['id'], $status, $notes);
    $applications->addHistory((int) $app['id'], $app['status'], mb_substr('MSWD eligibility assessment: ' . $status . ($notes ? '. ' . $notes : '.'), 0, 500), $staff['full_name'], (int) $staff['id']);
}
$live = $pdo;
$created = false;
$exitCode = 0;
try {
    $live->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4');
    $created = true;
    $test = new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.$testDb.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $test->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($live->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $test->exec($live->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1]);
    }
    $test->exec('SET FOREIGN_KEY_CHECKS=1');
    // Uploaded files are stored under STORAGE_PATH/<application id>. Start ids far above any real application folder.
    $test->exec('ALTER TABLE applications AUTO_INCREMENT = ' . (1900000000 + random_int(1,1000000)));
    // Synthetic fixtures only: no rows are copied from any existing database.
    $test->exec("INSERT INTO roles (id, role_key, code, name, description) VALUES (1,'admin','ADMINISTRATOR','Fixture Administrator','Fixture'),(2,'mswd_social_worker','MSWD_STAFF','Fixture Staff','Fixture'),(3,'applicant','SOLO_PARENT','Fixture Applicant','Fixture')");
    $pdo = $test; // Runtime settings and notifications stay in the disposable database.
    $GLOBALS['__settings_cache'] = ['sms_enabled'=>'0'];
    $applicantId = 1800000000 + random_int(1,10000);
    $insertUser = $test->prepare('INSERT INTO users (id,full_name,email,username,password_hash,role,mobile,mobile_verified_at) VALUES (?,?,?,?,?,?,?,NOW())');
    $insertUser->execute([$applicantId,'Fixture Applicant','fixture@example.test','renewal.fixture',password_hash('Fixture123',PASSWORD_DEFAULT),'applicant','09123456789']);
    $insertUser->execute([$applicantId+1,'Fixture Staff','staff@example.test','staff.fixture',password_hash('Fixture123',PASSWORD_DEFAULT),'mswd_social_worker',null]);
    $user = (new User($test))->findById($applicantId);
    $staff = (new User($test))->findById($applicantId+1);
    $test->exec("INSERT INTO categories (name,description,sort_order) VALUES ('Fixture category A','Fixture',1),('Fixture category B','Fixture',2)");
    $categoryA = (int) $test->query("SELECT id FROM categories WHERE name='Fixture category A'")->fetchColumn();
    $categoryB = (int) $test->query("SELECT id FROM categories WHERE name='Fixture category B'")->fetchColumn();
    $requirements = $test->prepare('INSERT INTO document_requirements (category_id,doc_key,label) VALUES (?,?,?)');
    foreach (['birthCert','barangayCert','expiredMedical','futureCertificate'] as $key) { $requirements->execute([$categoryA,$key,'Fixture '.$key]); }
    $applications = new Application($test);
    $id = $applications->create('RENEWAL-FIXTURE-ID',$applicantId,'new',[
        'full_name'=>'Fixture Applicant','birthdate'=>'1980-01-01','sex'=>'Female','category_id'=>$categoryA,
        'barangay'=>'Fixture barangay','address'=>'Fixture address','mobile'=>'09123456789','email'=>'fixture@example.test','civil_status'=>'Single',
    ],date('Y-m-d H:i:s'));
    $applications->addChild($id,'Existing Child','2000-01-01'); // Existing record is retained without a new-application age check.
    $test->prepare("UPDATE applications SET status='ID Released',id_expires_at=CURDATE(),eligibility_status='Eligible' WHERE id=?")->execute([$id]);
    $insertDoc = $test->prepare("INSERT INTO application_document_checklists (application_id,doc_key,applicant_has,verification_status,reviewed_by,reviewed_at,expires_at) VALUES (?,?,1,'Verified',?,'2025-01-01 09:00:00',?)");
    foreach (['birthCert'=>null,'barangayCert'=>date('Y-m-d',strtotime('+30 days')),'expiredMedical'=>date('Y-m-d',strtotime('-1 day')),'futureCertificate'=>date('Y-m-d',strtotime('+1 year'))] as $key=>$expiry) {
        $insertDoc->execute([$id,$key,$staff['id'],$expiry]);
    }
    $service = new RenewalService($test);
    $source = $service->sourceApplication($user);
    $policies = new RenewalRequirement($test);
    renewal_assert(!$policies->plan($source)['configured'],'Unknown policy was guessed.');
    $test->prepare("INSERT INTO renewal_requirements (category_id,doc_key,label,always_refresh) VALUES (?,'barangayCert','Fixture fresh certificate',1),(?,'otherCategoryProof','Fixture category B document',1)")->execute([$categoryA,$categoryB]);
    $plan = $policies->plan($source);
    renewal_assert(array_column($plan['fresh'],'doc_key') === ['expiredMedical','barangayCert'],'Expired replacement or category fresh certificate missing.');
    renewal_assert(array_column($plan['retained'],'doc_key') === ['birthCert','futureCertificate'],'Unexpired/non-expiring documents were requested again.');
    $original = $test->query('SELECT * FROM application_document_checklists WHERE application_id='.$id.' ORDER BY id')->fetchAll();
    $draft = $service->freshDraft($source);
    $draft['contact'] = $user['mobile'];
    $draft = $service->collectStepInput(1,$draft,['barangay'=>'Updated barangay','address'=>'Updated address','email'=>'','fullName'=>'Forged name','child_name'=>['Forged child'],'checklist'=>['barangayCert','expiredMedical'],'consent'=>'1'],$source);
    renewal_assert(isset($service->validateStep(1,$draft)['documents']),'Missing renewal document uploads passed validation (checkbox ticks must not count).');
    // Stages a valid file the way DocumentValidationService::attachToDraft() does (move_uploaded_file cannot run on the CLI).
    $stage = static function (array $draft, string $key) use ($service,$applicantId): array {
        $bytes = upload_fixture_png();
        $stored = bin2hex(random_bytes(16)) . '.png';
        // Claims the draft folder (refused if it already exists), tracks the exact file, then writes it.
        $path = upload_fixture_write($service->draftDir($applicantId),$stored,$bytes);
        $draft['documents'][$key] = ['original_name'=>$key . '.png','stored_name'=>$stored,'path'=>$path,'mime'=>'image/png','size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'status'=>'VALID'];
        return $draft;
    };
    $draft = $stage($draft,'barangayCert');
    $partial = $service->validateStep(1,$draft);
    renewal_assert(isset($partial['documents']) && str_contains($partial['documents'],'Fixture expiredMedical') && !str_contains($partial['documents'],'Fixture fresh certificate'),'A partly uploaded renewal passed validation or named the wrong missing document.');
    $noFile = ['name'=>'x.pdf','type'=>'application/pdf','tmp_name'=>'','error'=>UPLOAD_ERR_NO_FILE,'size'=>0];
    $rejected = $service->attachDocument($user,$source,$draft,'expiredMedical',$noFile);
    renewal_assert(!isset($rejected['documents']['expiredMedical']) && isset($rejected['docErrors']['expiredMedical']) && isset($service->validateStep(1,$rejected)['upload_expiredMedical']),'A rejected upload was staged or its error was hidden.');
    $unrelated = $service->attachDocument($user,$source,$draft,'unrelatedDocument',$noFile);
    renewal_assert(!isset($unrelated['documents']['unrelatedDocument']) && isset($unrelated['docErrors']['unrelatedDocument']),'An upload for a document this renewal does not require was accepted.');
    $forged = $service->collectStepInput(1,$stage($draft,'unrelatedDocument'),['consent'=>'1'],$source);
    renewal_assert(!isset($forged['documents']['unrelatedDocument']) && isset($forged['documents']['barangayCert']),'A forged document key was kept, or a valid staged file was dropped.');
    $vanished = $draft; $vanished['documents']['barangayCert']['path'] .= '.missing';
    renewal_assert(isset($service->validateStep(1,$vanished)['documents']),'A staged file that no longer exists passed validation.');
    $draft = $stage($draft,'expiredMedical'); // replacing a staged file is the same call
    $draft = $service->collectStepInput(1,$draft,['consent'=>'1'],$source);
    renewal_assert(!$service->firstInvalidStep($draft),'Existing identity/children were revalidated as a new application, or complete uploads were refused.');
    $incomplete = $draft; unset($incomplete['documents']['expiredMedical']);
    $refused = false;
    try { $service->submit($user,$source,$incomplete); } catch (DomainException $e) { $refused = true; }
    renewal_assert($refused && (int) $test->query("SELECT COUNT(*) FROM applications WHERE type='renewal'")->fetchColumn()===0,'A renewal with a missing document upload was submitted.');
    $renderRenewal = static function (array $data): string {
        extract($data);
        ob_start(); require APP_ROOT . '/views/applicant/renewal/confirmation.php'; return ob_get_clean();
    };
    $html = $renderRenewal(['renewalSource'=>$source,'renewalWindow'=>$service->window($source),'renewalInProgress'=>null,'renewalPlan'=>$plan,'draft'=>$draft,'errors'=>[],'submitError'=>null,'flashMessage'=>null,'docDefs'=>$plan['fresh']]);
    renewal_assert(str_contains($html,'name="renewal_file[barangayCert]"') && str_contains($html,'enctype="multipart/form-data"') && !str_contains($html,'name="checklist[]"') && !str_contains($html,'name="child_name') && !str_contains($html,'step-bar'),'Renewal form still uses checkboxes, lacks file inputs, or repeated new application steps.');
    $pendingPlan = $plan; $pendingPlan['configured']=false;
    $html = $renderRenewal(['renewalSource'=>$source,'renewalWindow'=>$service->window($source),'renewalInProgress'=>null,'renewalPlan'=>$pendingPlan]);
    renewal_assert(str_contains($html,'not configured yet') && !str_contains($html,'Submit Renewal'),'Unconfigured category offered an invented renewal checklist.');
    $firstId = upload_fixture_prepare_application_id($test); // refuses an application folder that already exists
    try { $ref = $service->submit($user,$source,$draft); } finally { upload_fixture_track_applications($test,$firstId); }
    $renewal =$applications->findByReference($ref);
    renewal_assert(!$renewal['renewal_simplified'] && (int) $renewal['renewal_of_id']===$id,'Renewal not linked to its existing ID, or still documents-only (every renewal now has an interview).');
    renewal_assert($renewal['full_name']==='Fixture Applicant' && $applications->children((int) $renewal['id'])[0]['full_name']==='Existing Child','Identity or children changed from tampered input.');
    $documents = new Document($test);
    $uploadedRows = $documents->uploadedForApplication((int) $renewal['id']);
    renewal_assert(count($uploadedRows)===2 && !array_filter($uploadedRows,fn($row)=>$row['verification_status']!=='FOR_VERIFICATION' || !is_file(STORAGE_PATH.'/'.$row['stored_path'])),'Uploaded renewal files were not stored and queued for staff verification.');
    renewal_assert(!is_dir($service->draftDir($applicantId)),'Draft upload files remained after submission.');
    $rows = $documents->checklistForReview((int) $renewal['id'],$categoryA,true);
    renewal_assert(count(array_filter($rows,fn($row)=>$row['is_required']))===2,'Renewal repeated the new-application requirements.');
    $retained = array_values(array_filter($rows,fn($row)=>!$row['is_required']));
    renewal_assert(count($retained)===2 && $retained[0]['reviewed_at']==='2025-01-01 09:00:00','Original verification evidence was not retained.');
    $freshRows = array_values(array_filter($rows,fn($row)=>$row['is_required']));
    renewal_assert(!array_filter($freshRows,fn($row)=>$row['applicant_has']!=1 || $row['verification_status']!=='Pending' || $row['reviewed_at']!==null),'An uploaded renewal document was treated as verified on submission.');
    $review = new ApplicationReviewService($test);
    $review->startReviewOnOpen($staff,$renewal);
    $renewal = $applications->findForReview((int) $renewal['id']);
    // Every renewal now has an interview (RA 11861: MSWDO confirms the Solo Parent still qualifies).
    renewal_assert(!array_column($review->approvalChecklist($renewal),null,'label')['Required documents']['ok'],'Uploaded but unverified renewal documents already satisfied approval.');
    $verdicts = [];
    foreach ($uploadedRows as $upload) { $verdicts[(int) $upload['id']] = ['status'=>'VERIFIED','remarks'=>'','expected'=>'FOR_VERIFICATION','expected_remarks'=>'','expected_version'=>Document::versionToken($upload)]; }
    $saved = $review->saveVerification($staff,$renewal,$verdicts);
    renewal_assert(!$saved['errors'] && $saved['changed']===2,'Staff verification of the uploaded renewal documents failed.');
    $renewal = $applications->findForReview((int) $renewal['id']);
    renewal_assert($renewal['eligibility_status'] === 'Not Assessed', 'Renewal verification automatically decided eligibility.');
    renewal_assert(array_values(array_map(fn($row)=>$row['label'],array_filter($review->approvalChecklist($renewal),fn($row)=>!$row['ok'])))===['Successful interview recorded'], 'Only the interview should still block a renewal with verified documents.');
    renewal_set_eligibility($test,$staff,$renewal,'Eligible','Renewal reviewed by MSWDO.');
    $renewal = $applications->findForReview((int) $renewal['id']);
    renewal_assert(is_string($review->updateStatus($staff,$renewal,'Approved','',$renewal['status'])),'A renewal was approved without an interview.');
    // The interview took place yesterday: staff record it with the renewal re-check.
    $test->prepare("INSERT INTO appointments (application_id,scheduled_at,location,status,scheduled_by) VALUES (?,DATE_SUB(NOW(),INTERVAL 1 DAY),'Fixture','BOOKED',?)")->execute([$renewal['id'],$staff['id']]);
    // Render the real staff processing page against the fixture without changing any live session.
    $controller = (new ReflectionClass(StaffController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Controller::class,'pdo'))->setValue($controller,$test);
    (new ReflectionProperty(StaffController::class,'staff'))->setValue($controller,$staff);
    $_GET=['id'=>(int) $renewal['id']]; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/staff/application.php';
    ob_start(); $controller->application(); $staffHtml=ob_get_clean();
    renewal_assert(str_contains($staffHtml,'id="interview-result-form"') && str_contains($staffHtml,'Renewal re-check (RA 11861)') && str_contains($staffHtml,'name="recheck[]"'),'Staff renewal screen does not show the interview with the renewal re-check.');
    renewal_assert(str_contains($staffHtml,'value="Rejected"'),'Staff cannot reject a renewal.');
    renewal_assert(is_string($review->recordInterviewOutcome($staff,(int) $renewal['id'],'Successful','',['not_married','caregiver'])),'A successful renewal interview was recorded without the full re-check.');
    renewal_assert($review->recordInterviewOutcome($staff,(int) $renewal['id'],'Successful','',array_keys(ApplicationReviewService::RENEWAL_RECHECK))===null,'Recording the renewal interview failed.');
    renewal_assert(str_contains((string) (new Appointment($test))->interviewFor((int) $renewal['id'])['result'],'all confirmed'),'The re-check was not recorded with the result.');
    $renewal = $applications->findForReview((int) $renewal['id']);
    renewal_assert(!array_filter($review->approvalChecklist($renewal),fn($row)=>!$row['ok']),'A renewal with verified documents and a successful interview cannot be approved.');
    renewal_assert(str_contains($staffHtml,'name="remarks"'),'Renewal decision remarks are missing.');
    renewal_assert(!str_contains($staffHtml,'[expires_at]') && !str_contains($staffHtml,'Document expiry:') && !str_contains($staffHtml,'Document expires on'),'Staff review still shows document expiry.');
    renewal_assert(!str_contains($staffHtml,'Undo Verification') && !str_contains($staffHtml,'>Verify All</button>'),'Legacy bulk verification buttons are still shown.');
    renewal_assert(!str_contains($staffHtml,'Undo Eligibility') && !str_contains($staffHtml,'name="eligibility_status"') && !str_contains($staffHtml,'Is the applicant eligible?'),'Staff page still shows the separate eligibility step.');
    renewal_assert($review->updateStatus($staff,$renewal,'Approved','',$renewal['status'])===null,'Renewal approval after the interview failed.');
    $test->prepare("INSERT INTO id_release_schedules (application_id,scheduled_at,location,scheduled_by,sms_message) VALUES (?,NOW(),'Fixture',?,'Fixture')")->execute([$renewal['id'],$staff['id']]);
    renewal_assert((new IdReleaseService($test))->confirmRelease($staff,$applications->findForReview((int) $renewal['id']))===null,'Renewed ID handover failed.');
    renewal_assert($applications->findForReview((int) $renewal['id'])['id_expires_at']===$test->query('SELECT DATE_ADD(CURDATE(),INTERVAL 1 YEAR)')->fetchColumn(),'Renewed ID validity changed.');
    renewal_assert($test->query('SELECT * FROM application_document_checklists WHERE application_id='.$id.' ORDER BY id')->fetchAll()===$original,'Original accepted document history was modified.');
    // A later renewal still detects expired inherited documents using original accepted evidence.
    $test->prepare('UPDATE application_document_checklists SET expires_at=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE application_id=? AND doc_key=?')->execute([$renewal['id'],'futureCertificate']);
    $nextSource = $service->sourceApplication($user);
    renewal_assert(in_array('futureCertificate',array_column($policies->plan($nextSource)['fresh'],'doc_key'),true),'An inherited document expiring later was silently dropped.');
    $test->prepare('UPDATE application_document_checklists SET expires_at=CURDATE() WHERE application_id=? AND doc_key=?')->execute([$renewal['id'],'futureCertificate']);
    renewal_assert(in_array('futureCertificate',array_column($policies->plan($service->sourceApplication($user))['retained'],'doc_key'),true),'A document expired before its expiry date ended.');
    // When every configured document can be reused, the renewal still needs its interview before approval.
    $test->prepare('UPDATE renewal_requirements SET always_refresh=0 WHERE category_id=?')->execute([$categoryA]);
    $test->prepare('UPDATE applications SET id_expires_at=CURDATE() WHERE id=?')->execute([$renewal['id']]);
    $nextSource = $service->sourceApplication($user);
    // An uploaded document is not remembered for the next renewal: the applicant presents the configured documents again.
    renewal_assert(array_column($policies->plan($nextSource)['fresh'],'doc_key')===['barangayCert'],'An uploaded document was carried to the next renewal; it must be presented again.');
    // A verified record carried over from the older paper checklist is still reused while it is unexpired.
    $test->prepare("INSERT INTO application_document_checklists (application_id,doc_key,applicant_has,verification_status,reviewed_by,reviewed_at) VALUES (?,'barangayCert',1,'Verified',?,NOW()) ON DUPLICATE KEY UPDATE applicant_has=1,verification_status='Verified',expires_at=NULL")->execute([$renewal['id'],$staff['id']]);
    $nextSource = $service->sourceApplication($user);
    renewal_assert(!$policies->plan($nextSource)['fresh'],'Accepted, unexpired configured document was requested again.');
    $nextDraft = $service->freshDraft($nextSource); $nextDraft['contact']=$user['mobile']; $nextDraft['consent']=true;
    $nextFirstId = upload_fixture_prepare_application_id($test);
    try { $nextRef = $service->submit($user,$nextSource,$nextDraft); } finally { upload_fixture_track_applications($test,$nextFirstId); }
    $next = $applications->findByReference($nextRef);
    $review->startReviewOnOpen($staff,$next); $next=$applications->findForReview((int) $next['id']);
    renewal_set_eligibility($test,$staff,$next,'Eligible','Existing documents and renewal reviewed.');
    $next=$applications->findForReview((int) $next['id']);
    renewal_assert(array_values(array_map(fn($row)=>$row['label'],array_filter($review->approvalChecklist($next),fn($row)=>!$row['ok'])))===['Successful interview recorded'],'An all-reused renewal should wait only for its interview.');
    echo "Renewal passed: required file uploads, staff verification of uploads, retained history, an interview with the RA 11861 re-check before approval, and one-year release.\n";
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage().PHP_EOL); $exitCode=1;
} finally {
    $pdo=$live;
    // Deletes only the exact files tracked above, then rmdir (never recursive, never glob) on folders this run claimed.
    upload_fixture_cleanup();
    if ($created) { $live->exec('DROP DATABASE `'.$testDb.'`'); }
}
exit($exitCode);
