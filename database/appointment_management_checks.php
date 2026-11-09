<?php
/** Invoked only by the disposable staff workflow database test. */
function check_appointment_management(PDO $test, array $staff, int $category): void
{
    review_assert((bool) preg_match('/^solo_review_test_[a-f0-9]{12}$/', (string) $test->query('SELECT DATABASE()')->fetchColumn()), 'Appointment demo requires the disposable database.');
    review_assert(SMSService::testMode(), 'Appointment demo requires test SMS.');
    $GLOBALS['__settings_cache']['sms_enabled'] = '1';
    $users = new User($test);
    $userId = $users->createApplicant('Appointment Demo Parent', '', 'appointment_demo_parent', password_hash('DemoOnly123', PASSWORD_DEFAULT), '09123456789');
    $users->markMobileVerified($userId);
    $user = $users->findById($userId);
    $appointments = new Appointment($test);
    $apps = new Application($test);
    $service = new AppointmentService($test);
    $review = new ApplicationReviewService($test);
    $date = review_office_date($test, '+18 days');
    review_assert($service->createSlot($staff, $date, '09:00', '09:30', 1) === null, 'Could not create first slot.');
    review_assert($service->createSlot($staff, $date, '10:00', '10:30', 2) === null, 'Could not create second slot.');
    $slotQuery = $test->prepare('SELECT id FROM appointment_slots WHERE slot_date = ? AND start_time = ?');
    $slotQuery->execute([$date, '09:00:00']); $firstSlot = (int) $slotQuery->fetchColumn();
    $slotQuery->execute([$date, '10:00:00']); $secondSlot = (int) $slotQuery->fetchColumn();
    review_assert($service->createSlot($staff, $date, '09:15', '09:45', 1) !== null, 'Overlapping slot accepted.');
    review_assert($service->createSlot($staff, $date, '11:00', '11:30', 0) !== null, 'Zero capacity accepted.');
    echo "PASS: Manage Slots creates valid slots and rejects overlap/zero capacity.\n";
    $insert = $test->prepare("INSERT INTO applications (reference_no,user_id,type,full_name,barangay,address,mobile,email,birthdate,sex,civil_status,category_id,consent_at,submitted_at) VALUES (?,?,'new','Appointment Demo Parent','Test','Test','09123456789','','1990-01-01','Female','Single',?,NOW(),NOW())");
    $ids = [];
    for ($i = 1; $i <= 3; $i++) {
        $insert->execute(['APPOINTMENT-DEMO-' . $i, $userId, $category]);
        $newId = (int) $test->lastInsertId();
        $ids[] = $newId;
        $apps->addHistory($newId, 'Pending Review', 'Demo submission', 'Appointment Demo Parent', $userId);
    }
    $id = $ids[0];
    review_assert($review->scheduleInterview($staff, $apps->findForReview($id), $date . 'T09:00', 'Demo MSWDO Office', '', 'automatic', '', $firstSlot) === null, 'Initial staff scheduling failed.');
    $appt = $appointments->interviewFor($id);
    review_assert($appt['status'] === 'BOOKED' && $apps->findForReview($id)['status'] === 'Interview Scheduled', 'Scheduled appointment/status missing.');
    review_assert($review->recordInterviewOutcome($staff,$id,'Successful','') !== null, 'Future interview result was accepted.');
    $test->prepare('UPDATE appointments SET scheduled_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE) WHERE application_id = ?')->execute([$id]);
    review_assert($review->recordInterviewOutcome($staff,$id,'Successful','') !== null, 'Interview result was accepted before the scheduled time today.');
    $test->prepare('UPDATE appointments SET scheduled_at = ? WHERE application_id = ?')->execute([$date.' 09:00:00',$id]);
    review_assert($service->staffSetOutcome($staff, (int) $appt['id'], 'MISSED', '') !== null, 'Future appointment was marked missed.');
    review_assert($service->staffSetOutcome($staff, (int) $appt['id'], 'CANCELLED', '') !== null, 'Cancellation without reason accepted.');
    review_assert($review->scheduleInterview($staff, $apps->findForReview($id), $date . 'T10:00', 'Demo MSWDO Office', '', 'automatic', '', $secondSlot) === null, 'Staff rescheduling failed.');
    review_assert($appointments->bookedCountExcluding($firstSlot, 0) === 0 && $appointments->bookedCountExcluding($secondSlot, 0) === 1, 'Rescheduling did not release/reserve seats.');
    review_assert($service->staffSetOutcome($staff, (int) $appt['id'], 'CANCELLED', 'Demo office closure') === null, 'Individual cancellation failed.');
    review_assert($appointments->interviewFor($id)['status'] === 'CANCELLED' && $apps->findForReview($id)['status'] === 'Pending Review', 'Cancellation did not restore the prior status.');
    review_assert(AppointmentService::needsBooking(['interview_status'=>'CANCELLED']), 'Cancelled applicant lacks replacement status guidance.');
    review_assert($service->book($user, $apps->findForReview($id), $firstSlot) !== null, 'Unexpected applicant first/replacement booking behavior.');
    echo "PASS: staff scheduling/rescheduling, seat release, required cancellation reason, and restoring Pending Review. Applicant replacement booking requires staff scheduling.\n";
    review_assert($review->scheduleInterview($staff, $apps->findForReview($id), $date . 'T09:00', 'Demo MSWDO Office', '', 'automatic', '', $firstSlot) === null, 'Replacement schedule failed.');
    [$error, $cancelled] = $service->cancelSlot($staff, $firstSlot, 'Demo slot closed');
    review_assert($error === null && $cancelled === 1 && $appointments->interviewFor($id)['status'] === 'CANCELLED', 'Closing a booked slot did not cancel its booking.');
    review_assert($service->deleteSlot($staff, $firstSlot) !== null, 'A slot with appointment history was deleted.');
    echo "PASS: closing a booked slot cancels/notifies its booking; history prevents deletion.\n";
    foreach (array_slice($ids, 1) as $index => $applicationId) {
        review_assert($review->scheduleInterview($staff, $apps->findForReview($applicationId), $date . 'T10:00', 'Demo MSWDO Office', '', 'automatic', '', $secondSlot) === null, 'Outcome setup scheduling failed.');
        $test->prepare('UPDATE appointments SET scheduled_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE application_id = ?')->execute([$applicationId]);
        $appt = $appointments->interviewFor($applicationId);
        if ($index === 0) {
            review_assert($service->staffSetOutcome($staff, (int) $appt['id'], 'MISSED', '') === null, 'Past no-show could not be marked missed.');
            review_assert($apps->findForReview($applicationId)['status'] === 'Pending Review' && $appointments->interviewFor($applicationId)['status'] === 'MISSED', 'No-show state incorrect.');
            review_assert($service->staffSetOutcome($staff, (int) $appt['id'], 'MISSED', '') !== null, 'Repeated no-show action accepted.');
            review_assert($service->book($user, $apps->findForReview($applicationId), $secondSlot) !== null, 'Unexpected applicant no-show replacement booking behavior.');
        } else {
            review_assert($review->recordInterviewOutcome($staff, $applicationId, 'Successful', 'Demo attended interview') === null, 'Interview result failed.');
            review_assert($appointments->interviewFor($applicationId)['status'] === 'COMPLETED', 'Interview not completed.');
            review_assert($apps->findForReview($applicationId)['status'] !== 'Approved', 'Interview completion approved application automatically.');
        }
    }
    echo "PASS: past no-show, repeat-action rejection, completed interview and separate approval.\n";
    // Managed slots are now mandatory for the actual form/service path.
    $insert->execute(['APPOINTMENT-DEMO-MANUAL', $userId, $category]);
    $manualId = (int) $test->lastInsertId();
    $apps->addHistory($manualId, 'Pending Review', 'Demo submission', 'Appointment Demo Parent', $userId);
    $seatsBefore = $appointments->bookedCountExcluding($secondSlot, 0);
    review_assert($review->scheduleInterview($staff, $apps->findForReview($manualId), $date . 'T10:00', 'Demo MSWDO Office', '') !== null, 'Slotless scheduling bypassed capacity.');
    review_assert($appointments->interviewFor($manualId) === null, 'Invalid scheduling created an appointment.');
    review_assert($review->scheduleInterview($staff, $apps->findForReview($manualId), $date . 'T10:00', 'Demo MSWDO Office', '', 'automatic', '', $secondSlot) === null, 'Selected managed slot could not be reserved.');
    review_assert((int) $appointments->interviewFor($manualId)['slot_id'] === $secondSlot && $appointments->bookedCountExcluding($secondSlot, 0) === $seatsBefore + 1, 'Selected slot did not reserve capacity.');
    $insert->execute(['APPOINTMENT-DEMO-FULL', $userId, $category]); $fullId = (int) $test->lastInsertId();
    review_assert($review->scheduleInterview($staff, $apps->findForReview($fullId), $date . 'T10:00', 'Demo MSWDO Office', '', 'automatic', '', $secondSlot) !== null, 'Full slot accepted another staff booking.');
    review_assert(!in_array($secondSlot,array_map('intval',array_column($service->staffSlots($fullId),'id')),true), 'Full slot remained selectable.');
    review_assert(in_array($secondSlot,array_map('intval',array_column($service->staffSlots($manualId),'id')),true), 'An applicant could not retain their own seat.');
    review_assert($review->scheduleInterview($staff, $apps->findForReview($fullId), $date . 'T09:00', 'Demo MSWDO Office', '', 'automatic', '', $firstSlot) !== null, 'Cancelled slot accepted an interview.');
    review_assert($review->scheduleInterview($staff, $apps->findForReview($fullId), $date . 'T11:00', 'Demo MSWDO Office', '', 'automatic', '', $secondSlot) !== null, 'Mismatched date/time accepted.');
    $waitingIds = array_map('intval',array_column($appointments->awaitingStaffSchedule(),'id'));
    review_assert(in_array($id,$waitingIds,true) && in_array($ids[1],$waitingIds,true) && !in_array($ids[2],$waitingIds,true), 'Replacement scheduling queue is incorrect.');
    $noticeText = $test->prepare('SELECT message FROM notifications WHERE user_id = ?'); $noticeText->execute([$userId]);
    foreach ($noticeText->fetchAll(PDO::FETCH_COLUMN) as $text) { review_assert(!str_contains($text,'Please book a new appointment'), 'An in-app notice still tells the applicant to self-book a replacement.'); }
    // Older appointments remain intact while their time blocks still occupy capacity.
    review_assert($service->createSlot($staff,$date,'11:00','11:30',1) === null, 'Could not add legacy compatibility slot.');
    $legacySlot = $appointments->slotAt($date,'11:00:00');
    $insert->execute(['APPOINTMENT-DEMO-LEGACY', $userId, $category]); $legacyId = (int) $test->lastInsertId();
    $appointments->upsertStaffScheduled($legacyId,$date.' 11:10:00','Demo MSWDO Office',null,(int)$staff['id']);
    review_assert($appointments->bookedCountExcluding((int)$legacySlot['id'],0) === 1, 'An older unlinked appointment did not count toward capacity.');
    review_assert($review->scheduleInterview($staff,$apps->findForReview($fullId),$date.'T11:00','Demo MSWDO Office','','automatic','',(int)$legacySlot['id']) !== null, 'Staff overbooked an older unlinked appointment.');
    [$legacyError,$legacyCancelled] = $service->cancelSlot($staff,(int)$legacySlot['id'],'Demo closure');
    review_assert($legacyError === null && $legacyCancelled === 1 && $appointments->interviewFor($legacyId)['status'] === 'CANCELLED', 'Slot closure missed an older unlinked booking.');
    echo "PASS: mandatory managed slots, capacity, own-seat retention, stale/cancelled choices, replacement queue, corrected notices and legacy appointments.\n";
    // Exercise the controller path, including a forged browser date/time.
    review_assert($service->createSlot($staff,$date,'14:00','14:30',2) === null, 'Could not add controller test slot.');
    $controllerSlot = $appointments->slotAt($date,'14:00:00');
    if (!class_exists('AppointmentFlowTestController')) {
        class AppointmentFlowTestController extends StaffController {
            protected function redirectTo(string $path): void { throw new RuntimeException('APPOINTMENT_TEST_REDIRECT:' . $path); }
        }
    }
    $controller = (new ReflectionClass(AppointmentFlowTestController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Controller::class,'pdo'))->setValue($controller,$test);
    (new ReflectionProperty(StaffController::class,'staff'))->setValue($controller,$staff);
    $postBefore = $_POST;
    try {
        $_POST = ['form_action'=>'schedule_interview','slot_id'=>'0','location'=>'Demo Office'];
        $handler = new ReflectionMethod(StaffController::class,'handleReviewAction');
        review_assert(!empty($handler->invoke($controller,$apps->findForReview($fullId))['interview']), 'Controller accepted a missing slot.');
        $_POST = ['form_action'=>'schedule_interview','slot_id'=>(string)$controllerSlot['id'],'interview_date'=>'2000-01-01','interview_time'=>'00:00','location'=>'Demo Office','sms_message_type'=>'automatic'];
        try { $handler->invoke($controller,$apps->findForReview($fullId)); throw new RuntimeException('Scheduling did not redirect.'); }
        catch (RuntimeException $e) { review_assert(str_starts_with($e->getMessage(),'APPOINTMENT_TEST_REDIRECT:'),$e->getMessage()); }
        review_assert($appointments->interviewFor($fullId)['scheduled_at'] === $date.' 14:00:00', 'Browser date/time overrode the selected slot.');
    } finally { $_POST = $postBefore; }
    echo "PASS: controller requires a slot and derives date/time from it, ignoring forged date/time fields.\n";
    review_assert($service->createSlot($staff,$date,'15:00','15:30',1) === null, 'Could not create concurrent booking test slot.');
    $raceSlot = $appointments->slotAt($date,'15:00:00');
    $insert->execute(['APPOINTMENT-DEMO-CURRENT-READ',$userId,$category]); $raceId = (int)$test->lastInsertId();
    $testDatabase = (string)$test->query('SELECT DATABASE()')->fetchColumn();
    $secondConnection = new PDO('mysql:host='.DB_HOST.';dbname='.$testDatabase.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $test->beginTransaction();
    try {
        // Establish an older repeatable-read snapshot before the other request books.
        $test->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
        $otherReview = new ApplicationReviewService($secondConnection);
        $raceApp = (new Application($secondConnection))->findForReview($raceId);
        review_assert($otherReview->scheduleInterview($staff,$raceApp,$date.'T15:00','Demo Office','','automatic','',(int)$raceSlot['id']) === null, 'Second connection could not book its seat.');
        $appointments->lockSlot((int)$raceSlot['id']);
        review_assert($appointments->bookedCountExcluding((int)$raceSlot['id'],0) === 1, 'Capacity used a stale snapshot and missed a committed booking.');
        $test->commit();
    } catch (Throwable $e) { if ($test->inTransaction()) { $test->rollBack(); } throw $e; }
    echo "PASS: slot capacity sees a booking committed by another connection after an older snapshot.\n";
    $notice = $test->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
    $notice->execute([$userId]);
    review_assert((int) $notice->fetchColumn() >= 3, 'Applicant notifications missing.');
    review_assert((int) $test->query('SELECT COUNT(*) FROM sms_logs WHERE test_mode = 0')->fetchColumn() === 0, 'A live SMS was attempted.');
    echo "PASS: applicant notifications recorded in the disposable database; all SMS are test-only.\n";
}
