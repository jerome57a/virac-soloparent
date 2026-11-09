<?php
/** Disposable-database checks; synthetic category policies are not installed in the live system. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/RenewalService.php';
require_once __DIR__ . '/../services/ApplicationReviewService.php';
require_once __DIR__ . '/../services/ApplicationTrackerService.php';
require_once __DIR__ . '/../services/IdReleaseService.php';
require_once __DIR__ . '/../services/AppointmentService.php';
require_once __DIR__ . '/../controllers/StaffController.php';
require_once __DIR__ . '/../includes/csrf.php';

function renewal_assert(bool $ok,string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$live = $pdo;
$testDb = 'renewal_test_' . bin2hex(random_bytes(6));
$created = false;
$exitCode = 0;
try {
    $live->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4');
    $created = true;
    $test = new PDO('mysql:host='.DB_HOST.';dbname='.$testDb.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $test->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($live->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $test->exec($live->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1]);
    }
    $test->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach ($live->query('SELECT * FROM roles')->fetchAll() as $row) {
        $columns = '`'.implode('`,`',array_keys($row)).'`';
        $test->prepare('INSERT INTO roles ('.$columns.') VALUES ('.implode(',',array_fill(0,count($row),'?')).')')->execute(array_values($row));
    }
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
    $draft = $service->collectStepInput(1,$draft,['barangay'=>'Updated barangay','address'=>'Updated address','email'=>'','fullName'=>'Forged name','child_name'=>['Forged child'],'checklist'=>['barangayCert'],'consent'=>'1'],$source);
    renewal_assert(isset($service->validateStep(1,$draft)['documents']),'Missing expired replacement passed validation.');
    $draft = $service->collectStepInput(1,$draft,['checklist'=>['barangayCert','expiredMedical','unrelatedDocument'],'consent'=>'1'],$source);
    renewal_assert(!$service->firstInvalidStep($draft),'Existing identity/children were revalidated as a new application.');
    $renderRenewal = static function (array $data): string {
        extract($data);
        ob_start(); require APP_ROOT . '/views/applicant/renewal/confirmation.php'; return ob_get_clean();
    };
    $html = $renderRenewal(['renewalSource'=>$source,'renewalWindow'=>$service->window($source),'renewalInProgress'=>null,'renewalPlan'=>$plan,'draft'=>$draft,'errors'=>[],'submitError'=>null,'flashMessage'=>null,'docDefs'=>$plan['fresh']]);
    renewal_assert(str_contains($html,'name="checklist[]"') && !str_contains($html,'name="child_name') && !str_contains($html,'step-bar'),'Renewal form repeated new application steps.');
    $pendingPlan = $plan; $pendingPlan['configured']=false;
    $html = $renderRenewal(['renewalSource'=>$source,'renewalWindow'=>$service->window($source),'renewalInProgress'=>null,'renewalPlan'=>$pendingPlan]);
    renewal_assert(str_contains($html,'not configured yet') && !str_contains($html,'Submit Renewal'),'Unconfigured category offered an invented renewal checklist.');
    $ref = $service->submit($user,$source,$draft);
    $renewal = $applications->findByReference($ref);
    renewal_assert($renewal['renewal_simplified'] && (int) $renewal['renewal_of_id']===$id,'Renewal not linked to its existing ID.');
    renewal_assert($renewal['full_name']==='Fixture Applicant' && $applications->children((int) $renewal['id'])[0]['full_name']==='Existing Child','Identity or children changed from tampered input.');
    $documents = new Document($test);
    $rows = $documents->checklistForReview((int) $renewal['id'],$categoryA,true);
    renewal_assert(count(array_filter($rows,fn($row)=>$row['is_required']))===2,'Renewal repeated the new-application requirements.');
    $retained = array_values(array_filter($rows,fn($row)=>!$row['is_required']));
    renewal_assert(count($retained)===2 && $retained[0]['reviewed_at']==='2025-01-01 09:00:00','Original verification evidence was not retained.');
    $documents->saveApplicantChecklist((int) $renewal['id'],$rows,['barangayCert','expiredMedical']);
    renewal_assert($documents->checklistForReview((int) $renewal['id'],$categoryA,true)[2]['applicant_has']==1,'Applicant toggled an inherited record.');
    renewal_assert((new AppointmentService($test))->findBookableApplication([$renewal])===null,'Renewal offered a new interview.');
    $review = new ApplicationReviewService($test);
    $review->startReviewOnOpen($staff,$renewal);
    $renewal = $applications->findForReview((int) $renewal['id']);
    renewal_assert($review->scheduleInterview($staff,$renewal,'2030-01-01T09:00','Fixture','')!==null,'Staff could schedule an unnecessary renewal interview.');
    $submitted = [];
    foreach ($rows as $row) { if ($row['is_required']) { $submitted[$row['id']]=['status'=>'Verified','remarks'=>'','expires_at'=>date('Y-m-d',strtotime('+1 year'))]; } }
    renewal_assert($review->reviewChecklistItems($staff,$renewal,$submitted)===null,'Renewal document verification failed.');
    $renewal = $applications->findForReview((int) $renewal['id']);
    renewal_assert($renewal['eligibility_status'] === 'Not Assessed', 'Renewal verification automatically decided eligibility.');
    renewal_assert(!array_filter($review->approvalChecklist($renewal),fn($row)=>!$row['ok']), 'Not assessed still blocks a complete renewal.');
    renewal_assert($review->assessEligibility($staff,$renewal,'Eligible','Renewal reviewed by MSWDO.')===null,'Explicit renewal assessment failed.');
    $renewal = $applications->findForReview((int) $renewal['id']);
    renewal_assert(!array_filter($review->approvalChecklist($renewal),fn($row)=>!$row['ok']),'Approval requires a new interview.');
    $stages = array_column((new ApplicationTrackerService($test))->track($renewal)['stages'],null,'key');
    renewal_assert($stages['assessment']['label']==='Renewal Review' && $stages['assessment']['state']==='done','Tracker still requires the new-applicant assessment/interview.');
    // Render the real staff processing page against the fixture without changing any live session.
    $controller = (new ReflectionClass(StaffController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Controller::class,'pdo'))->setValue($controller,$test);
    (new ReflectionProperty(StaffController::class,'staff'))->setValue($controller,$staff);
    $_GET=['id'=>(int) $renewal['id']]; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/staff/application.php';
    ob_start(); $controller->application(); $staffHtml=ob_get_clean();
    renewal_assert(!str_contains($staffHtml,'id="interview"') && !str_contains($staffHtml,'id="interview-result-form"'),'Staff renewal screen still shows interview controls.');
    renewal_assert(str_contains($staffHtml,'name="remarks"'),'Renewal decision remarks are missing.');
    renewal_assert(!str_contains($staffHtml,'[expires_at]') && !str_contains($staffHtml,'Document expiry:') && !str_contains($staffHtml,'Document expires on'),'Staff review still shows document expiry.');
    renewal_assert(!str_contains($staffHtml,'Undo Verification') && !str_contains($staffHtml,'>Verify All</button>') && !str_contains($staffHtml,'>Save Verification</button>'),'Automatic checklist still shows manual verification buttons.');
    renewal_assert(!str_contains($staffHtml,'Undo Eligibility') && !str_contains($staffHtml,'name="eligibility_status"') && !str_contains($staffHtml,'Is the applicant eligible?'),'Staff page still shows the separate eligibility step.');
    renewal_assert($review->updateStatus($staff,$renewal,'Approved','',$renewal['status'])===null,'Document-only renewal approval failed.');
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
    // When every configured document can be reused, renewal still reaches approval without an interview.
    $test->prepare('UPDATE renewal_requirements SET always_refresh=0 WHERE category_id=?')->execute([$categoryA]);
    $test->prepare('UPDATE applications SET id_expires_at=CURDATE() WHERE id=?')->execute([$renewal['id']]);
    $nextSource = $service->sourceApplication($user);
    renewal_assert(!$policies->plan($nextSource)['fresh'],'Accepted, unexpired configured document was requested again.');
    $nextDraft = $service->freshDraft($nextSource); $nextDraft['contact']=$user['mobile']; $nextDraft['consent']=true;
    $nextRef = $service->submit($user,$nextSource,$nextDraft);
    $next = $applications->findByReference($nextRef);
    $review->startReviewOnOpen($staff,$next); $next=$applications->findForReview((int) $next['id']);
    renewal_assert($review->assessEligibility($staff,$next,'Eligible','Existing documents and renewal reviewed.')===null,'All-reused renewal staff assessment failed.');
    $next=$applications->findForReview((int) $next['id']);
    renewal_assert(!array_filter($review->approvalChecklist($next),fn($row)=>!$row['ok']),'All-reused renewal cannot reach approval.');
    echo "Simplified renewal passed: category-specific documents, expired replacements, retained history, no repeated form/interview, approval and one-year release.\n";
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage().PHP_EOL); $exitCode=1;
} finally {
    $pdo=$live;
    if ($created) { $live->exec('DROP DATABASE `'.$testDb.'`'); }
}
exit($exitCode);
