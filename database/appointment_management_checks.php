<?php
/**
 * Appointment lifecycle checks for staff-entered interviews. Invoked only by the
 * disposable staff workflow database test (test_staff_review_workflow.php).
 *
 * Removed with the slot workflow (intentionally no longer tested): slot creation,
 * overlap/zero-capacity rules, slot capacity/own-seat retention, closing or deleting a
 * slot, applicant self-booking, and the "forged slot" controller path. Those features no
 * longer exist; the appointment_slots table is only kept for historical rows.
 */
function check_appointment_management(PDO $test, array $staff, int $category): void
{
    review_assert((bool) preg_match('/^solo_review_test_[a-f0-9]{12}$/', (string) $test->query('SELECT DATABASE()')->fetchColumn()), 'Appointment demo requires the disposable database.');
    review_assert(SMSService::testMode(), 'Appointment demo requires test SMS.');
    $GLOBALS['__settings_cache']['sms_enabled'] = '1';
    $users = new User($test);
    $userId = $users->createApplicant('Appointment Demo Parent', '', 'appointment_demo_parent', password_hash('DemoOnly123', PASSWORD_DEFAULT), '09123456789');
    $user = $users->findById($userId);
    $appointments = new Appointment($test);
    $apps = new Application($test);
    $service = new AppointmentService($test);
    $review = new ApplicationReviewService($test);
    $date = review_office_date($test, '+18 days');
    $nextDate = review_office_date($test, $date . ' +1 day');

    $insert = $test->prepare("INSERT INTO applications (reference_no,user_id,type,full_name,barangay,address,mobile,email,birthdate,sex,civil_status,category_id,consent_at,submitted_at) VALUES (?,?,'new','Appointment Demo Parent','Test','Test','09123456789','','1990-01-01','Female','Single',?,NOW(),NOW())");
    $ids = [];
    for ($i = 1; $i <= 3; $i++) {
        $insert->execute(['APPOINTMENT-DEMO-' . $i, $userId, $category]);
        $newId = (int) $test->lastInsertId();
        $ids[] = $newId;
        $apps->addHistory($newId, 'Pending Review', 'Demo submission', 'Appointment Demo Parent', $userId);
    }
    $id = $ids[0];

    // --- Scheduling persistence, outcome timing, cancellation ---
    review_assert($review->scheduleInterview($staff, $apps->findForReview($id), $date . 'T09:00', 'Demo MSWDO Office', '') === null, 'Initial staff scheduling failed.');
    $appt = $appointments->interviewFor($id);
    review_assert($appt['status'] === 'BOOKED' && $appt['slot_id'] === null && $apps->findForReview($id)['status'] === 'Interview Scheduled', 'Scheduled appointment/status missing or slot_id not NULL.');
    review_assert($review->recordInterviewOutcome($staff, $id, 'Successful', '') !== null, 'Future interview result was accepted.');
    $test->prepare('UPDATE appointments SET scheduled_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE) WHERE application_id = ?')->execute([$id]);
    review_assert($review->recordInterviewOutcome($staff, $id, 'Successful', '') !== null, 'Interview result was accepted before the scheduled time today.');
    $test->prepare('UPDATE appointments SET scheduled_at = ? WHERE application_id = ?')->execute([$date . ' 09:00:00', $id]);
    review_assert($service->staffSetOutcome($staff, (int) $appt['id'], 'MISSED', '') !== null, 'Future appointment was marked missed.');
    review_assert($service->staffSetOutcome($staff, (int) $appt['id'], 'CANCELLED', '') !== null, 'Cancellation without reason accepted.');
    review_assert($review->scheduleInterview($staff, $apps->findForReview($id), $date . 'T10:00', 'Demo MSWDO Office', '') === null, 'Staff rescheduling failed.');
    review_assert($appointments->interviewFor($id)['scheduled_at'] === $date . ' 10:00:00', 'Rescheduling did not update the stored date/time.');
    review_assert($service->staffSetOutcome($staff, (int) $appt['id'], 'CANCELLED', 'Demo office closure') === null, 'Individual cancellation failed.');
    review_assert($appointments->interviewFor($id)['status'] === 'CANCELLED' && $apps->findForReview($id)['status'] === 'Pending Review', 'Cancellation did not restore the prior status.');
    review_assert(AppointmentService::needsBooking(['interview_status' => 'CANCELLED']), 'Cancelled applicant lacks replacement status guidance.');
    review_assert($review->scheduleInterview($staff, $apps->findForReview($id), $date . 'T09:00', 'Demo MSWDO Office', '') === null, 'Replacement schedule failed.');
    review_assert($appointments->interviewFor($id)['status'] === 'BOOKED' && $appointments->interviewFor($id)['cancel_reason'] === null, 'Replacement schedule did not clear the old cancellation.');
    echo "PASS: staff scheduling/rescheduling (slot_id NULL), required cancellation reason, restoring Pending Review, replacement scheduling.\n";

    // --- Applicant cancellation and the minimum-notice window ---
    set_setting($test, 'appointment_min_notice_hours', '2');
    $GLOBALS['__settings_cache']['appointment_min_notice_hours'] = '2';
    $test->prepare('UPDATE appointments SET scheduled_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE application_id = ?')->execute([$id]);
    review_assert($service->cancelByApplicant($user, $apps->findForReview($id)) !== null && $appointments->interviewFor($id)['status'] === 'BOOKED', 'Applicant cancelled inside the minimum-notice window.');
    $test->prepare('UPDATE appointments SET scheduled_at = ? WHERE application_id = ?')->execute([$date . ' 09:00:00', $id]);
    review_assert($service->cancelByApplicant($user, $apps->findForReview($id)) === null && $appointments->interviewFor($id)['status'] === 'CANCELLED', 'Applicant could not cancel outside the notice window.');
    review_assert($service->cancelByApplicant($user, $apps->findForReview($id)) !== null, 'Applicant cancelled an already-cancelled appointment.');
    review_assert($review->scheduleInterview($staff, $apps->findForReview($id), $date . 'T09:00', 'Demo MSWDO Office', '') === null, 'Could not re-schedule after applicant cancellation.');
    echo "PASS: applicant cancellation honours the minimum-notice window and cannot repeat.\n";

    // --- Reminders ---
    $logCount = static fn(string $key): int => (int) $test->query("SELECT COUNT(*) FROM sms_logs WHERE template_key = '" . $key . "'")->fetchColumn();
    $test->prepare('UPDATE appointments SET scheduled_at = DATE_ADD(NOW(), INTERVAL 3 HOUR), reminder_sent_at = NULL WHERE application_id = ?')->execute([$id]);
    $before = $logCount('appointment_reminder');
    $result = $service->sendDueReminders();
    review_assert(in_array('APPOINTMENT-DEMO-1', $result['sent'], true) && $logCount('appointment_reminder') === $before + 1, 'A due reminder was not sent (test mode).');
    review_assert($appointments->interviewFor($id)['reminder_sent_at'] !== null, 'Reminder was not marked as sent.');
    $service->sendDueReminders();
    review_assert($logCount('appointment_reminder') === $before + 1, 'A reminder was sent twice for the same appointment.');
    $test->prepare('UPDATE appointments SET scheduled_at = ? WHERE application_id = ?')->execute([$date . ' 09:00:00', $id]);
    $reminderBefore = $logCount('appointment_reminder');
    $service->sendDueReminders();
    review_assert($logCount('appointment_reminder') === $reminderBefore, 'A reminder was sent for an appointment outside the reminder window.');
    review_assert($review->scheduleInterview($staff, $apps->findForReview($id), $date . 'T11:00', 'Demo MSWDO Office', '') === null && $appointments->interviewFor($id)['reminder_sent_at'] === null, 'Rescheduling did not reset the reminder.');
    echo "PASS: reminders send once inside the window, skip distant appointments, and reset on reschedule.\n";

    // --- SMS retry for the current interview notification ---
    $interviewSms = $appointments->interviewSms($id);
    review_assert($interviewSms !== null, 'Interview SMS log is missing.');
    $test->prepare("UPDATE sms_logs SET status = 'FAILED', error = 'Simulated failure' WHERE id = ?")->execute([$interviewSms['id']]);
    if (!class_exists('AppointmentFlowTestController')) {
        class AppointmentFlowTestController extends StaffController {
            protected function redirectTo(string $path): void { throw new RuntimeException('APPOINTMENT_TEST_REDIRECT:' . $path); }
        }
    }
    $controller = (new ReflectionClass(AppointmentFlowTestController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Controller::class, 'pdo'))->setValue($controller, $test);
    (new ReflectionProperty(StaffController::class, 'staff'))->setValue($controller, $staff);
    $handler = new ReflectionMethod(StaffController::class, 'handleReviewAction');
    $postBefore = $_POST;
    try {
        $_POST = ['form_action' => 'retry_interview_sms', 'sms_log_id' => (string) ((int) $interviewSms['id'] + 9999)];
        review_assert(!empty($handler->invoke($controller, $apps->findForReview($id))['interview']), 'Retry accepted a log that is not the current interview SMS.');
        $_POST = ['form_action' => 'retry_interview_sms', 'sms_log_id' => (string) $interviewSms['id']];
        try {
            $outcome = $handler->invoke($controller, $apps->findForReview($id));
            review_assert(empty($outcome['interview']), 'Retry of the current failed interview SMS was refused: ' . ($outcome['interview'] ?? ''));
        } catch (RuntimeException $e) {
            review_assert(str_starts_with($e->getMessage(), 'APPOINTMENT_TEST_REDIRECT:'), $e->getMessage());
        }
        // Controller scheduling path: staff-entered date/time, validated server-side.
        $_POST = ['form_action' => 'schedule_interview', 'location' => 'Demo Office'];
        review_assert(!empty($handler->invoke($controller, $apps->findForReview($ids[1]))['interview']), 'Controller accepted a missing date/time.');
        $_POST = ['form_action' => 'schedule_interview', 'interview_date' => '2000-01-03', 'interview_time' => '09:00', 'location' => 'Demo Office', 'sms_message_type' => 'automatic'];
        review_assert(!empty($handler->invoke($controller, $apps->findForReview($ids[1]))['interview']) && $appointments->interviewFor($ids[1]) === null, 'Controller accepted a past date.');
        $_POST = ['form_action' => 'schedule_interview', 'interview_date' => $nextDate, 'interview_time' => '09:00', 'location' => 'Demo Office', 'sms_message_type' => 'automatic', 'slot_id' => '999'];
        try { $handler->invoke($controller, $apps->findForReview($ids[1])); throw new RuntimeException('Scheduling did not redirect.'); }
        catch (RuntimeException $e) { review_assert(str_starts_with($e->getMessage(), 'APPOINTMENT_TEST_REDIRECT:'), $e->getMessage()); }
        $stored = $appointments->interviewFor($ids[1]);
        review_assert($stored['scheduled_at'] === $nextDate . ' 09:00:00' && $stored['slot_id'] === null, 'Controller did not store the staff-entered time, or honoured a forged slot_id.');
    } finally { $_POST = $postBefore; }
    echo "PASS: SMS retry targets only the current failed interview SMS; controller validates staff-entered date/time and ignores any slot_id.\n";

    // --- Past no-show, completed interview and separate approval ---
    foreach (array_slice($ids, 1) as $index => $applicationId) {
        review_assert($review->scheduleInterview($staff, $apps->findForReview($applicationId), $date . 'T10:00', 'Demo MSWDO Office', '') === null, 'Outcome setup scheduling failed.');
        $test->prepare('UPDATE appointments SET scheduled_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE application_id = ?')->execute([$applicationId]);
        $appt = $appointments->interviewFor($applicationId);
        if ($index === 0) {
            review_assert($service->staffSetOutcome($staff, (int) $appt['id'], 'MISSED', '') === null, 'Past no-show could not be marked missed.');
            review_assert($apps->findForReview($applicationId)['status'] === 'Pending Review' && $appointments->interviewFor($applicationId)['status'] === 'MISSED', 'No-show state incorrect.');
            review_assert($service->staffSetOutcome($staff, (int) $appt['id'], 'MISSED', '') !== null, 'Repeated no-show action accepted.');
        } else {
            review_assert($review->recordInterviewOutcome($staff, $applicationId, 'Successful', 'Demo attended interview') === null, 'Interview result failed.');
            review_assert($appointments->interviewFor($applicationId)['status'] === 'COMPLETED', 'Interview not completed.');
            review_assert($apps->findForReview($applicationId)['status'] !== 'Approved', 'Interview completion approved application automatically.');
            review_assert($review->scheduleInterview($staff, $apps->findForReview($applicationId), $nextDate . 'T10:00', 'Demo MSWDO Office', '') !== null, 'A completed interview was rescheduled.');
        }
    }
    $waitingIds = array_map('intval', array_column($appointments->awaitingStaffSchedule(), 'id'));
    review_assert(in_array($ids[1], $waitingIds, true) && !in_array($ids[2], $waitingIds, true), 'Replacement scheduling queue is incorrect.');
    echo "PASS: past no-show, repeat-action rejection, completed interview, separate approval and replacement queue.\n";

    // --- Older appointments that still carry a slot_id remain readable and become slotless when rescheduled ---
    $test->prepare("INSERT INTO appointment_slots (slot_date,start_time,end_time,capacity,status,created_by) VALUES (?,?,?,?,'AVAILABLE',?)")->execute([$date, '13:00:00', '13:30:00', 1, $staff['id']]);
    $legacySlot = (int) $test->lastInsertId();
    $insert->execute(['APPOINTMENT-DEMO-LEGACY', $userId, $category]);
    $legacyId = (int) $test->lastInsertId();
    $apps->addHistory($legacyId, 'Pending Review', 'Demo submission', 'Appointment Demo Parent', $userId);
    $test->prepare("INSERT INTO appointments (application_id, slot_id, scheduled_at, location, status, scheduled_by) VALUES (?, ?, ?, 'Legacy Office', 'BOOKED', ?)")->execute([$legacyId, $legacySlot, $date . ' 13:00:00', $staff['id']]);
    $apps->setStatus($legacyId, 'Interview Scheduled');
    review_assert((int) $appointments->interviewFor($legacyId)['slot_id'] === $legacySlot, 'Legacy appointment with a slot_id could not be read.');
    review_assert($review->scheduleInterview($staff, $apps->findForReview($legacyId), $date . 'T14:00', 'Demo MSWDO Office', '') === null && $appointments->interviewFor($legacyId)['slot_id'] === null, 'Rescheduling a legacy appointment did not clear its slot_id.');
    review_assert((int) $test->query('SELECT COUNT(*) FROM appointment_slots')->fetchColumn() >= 1, 'The appointment_slots history table lost its rows.');
    echo "PASS: legacy appointments with slot_id remain readable and are rescheduled without a slot.\n";

    // --- Another connection scheduling the same application does not duplicate the interview or its SMS ---
    $insert->execute(['APPOINTMENT-DEMO-CONCURRENT', $userId, $category]);
    $raceId = (int) $test->lastInsertId();
    $apps->addHistory($raceId, 'Pending Review', 'Demo submission', 'Appointment Demo Parent', $userId);
    $testDatabase = (string) $test->query('SELECT DATABASE()')->fetchColumn();
    review_assert((bool) preg_match('/^solo_review_test_[a-f0-9]{12}$/', $testDatabase), 'Second connection requires the disposable database.');
    $secondConnection = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $testDatabase . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    review_assert($review->scheduleInterview($staff, $apps->findForReview($raceId), $nextDate . 'T15:00', 'Demo Office', '') === null, 'First connection could not schedule.');
    $smsBefore = $logCount('interview_scheduled');
    $otherReview = new ApplicationReviewService($secondConnection);
    review_assert($otherReview->scheduleInterview($staff, (new Application($secondConnection))->findForReview($raceId), $nextDate . 'T15:00', 'Demo Office', '') === null, 'Second connection repeat scheduling failed.');
    review_assert($logCount('interview_scheduled') === $smsBefore && (int) $test->query("SELECT COUNT(*) FROM appointments WHERE application_id = $raceId")->fetchColumn() === 1, 'Repeat scheduling duplicated the appointment or its SMS.');
    echo "PASS: repeat scheduling from a second connection stays idempotent.\n";

    $notice = $test->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
    $notice->execute([$userId]);
    review_assert((int) $notice->fetchColumn() >= 3, 'Applicant notifications missing.');
    $noticeText = $test->prepare('SELECT message FROM notifications WHERE user_id = ?');
    $noticeText->execute([$userId]);
    foreach ($noticeText->fetchAll(PDO::FETCH_COLUMN) as $text) { review_assert(!str_contains($text, 'Please book a new appointment'), 'An in-app notice still tells the applicant to self-book.'); }
    review_assert((int) $test->query('SELECT COUNT(*) FROM sms_logs WHERE test_mode = 0')->fetchColumn() === 0, 'A live SMS was attempted.');
    echo "PASS: applicant notifications recorded in the disposable database; all SMS are test-only.\n";
}
