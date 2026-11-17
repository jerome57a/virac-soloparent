<?php
/**
 * Appointment lifecycle checks: when a result can be recorded, staff and applicant cancellation, no-shows, reminders,
 * retrying a failed interview SMS, completed interviews and older rows that still carry a slot_id. Invoked only by the
 * disposable staff workflow database test (test_staff_review_workflow.php).
 *
 * Appointments are created with review_book_interview() (a test fixture): staff no longer schedule interviews through a
 * service call, and applicants choose their own day (InterviewBookingService).
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

    $insert = $test->prepare("INSERT INTO applications (reference_no,user_id,type,full_name,barangay,address,mobile,email,birthdate,sex,civil_status,category_id,consent_at,submitted_at) VALUES (?,?,'new','Appointment Demo Parent','Test','Test','09123456789','','1990-01-01','Female','Single',?,NOW(),NOW())");
    $ids = [];
    for ($i = 1; $i <= 3; $i++) {
        $insert->execute(['APPOINTMENT-DEMO-' . $i, $userId, $category]);
        $newId = (int) $test->lastInsertId();
        $ids[] = $newId;
        $apps->addHistory($newId, 'Pending Review', 'Demo submission', 'Appointment Demo Parent', $userId);
        // The fixtures start from an application whose required documents are all uploaded and verified.
        review_make_interview_ready($test, $review, $staff, $newId, $category);
    }
    $id = $ids[0];

    // --- Booking persistence, outcome timing, cancellation ---
    review_book_interview($test, $staff, $id, $date . ' 09:00:00', 'Demo MSWDO Office');
    $appt = $appointments->interviewFor($id);
    review_assert($appt['status'] === 'BOOKED' && $appt['slot_id'] === null && $apps->findForReview($id)['status'] === 'Interview Scheduled', 'Booked appointment/status missing or slot_id not NULL.');
    // Every required document is verified here, so only the scheduled-time rule can refuse these.
    review_assert((new Document($test))->outstandingRequired($apps->findForReview($id)) === [], 'The appointment fixture needs every required document verified.');
    $futureResult = $review->recordInterviewOutcome($staff, $id, 'Successful', '');
    review_assert($futureResult !== null && str_contains($futureResult, 'on or after its scheduled time'), 'Future interview result was accepted, or refused for the wrong reason.');
    $test->prepare('UPDATE appointments SET scheduled_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE) WHERE application_id = ?')->execute([$id]);
    review_assert($review->recordInterviewOutcome($staff, $id, 'Successful', '') !== null, 'Interview result was accepted before the scheduled time today.');
    $test->prepare('UPDATE appointments SET scheduled_at = ? WHERE application_id = ?')->execute([$date . ' 09:00:00', $id]);
    review_assert($service->staffSetOutcome($staff, (int) $appt['id'], 'MISSED', '') !== null, 'Future appointment was marked missed.');
    review_assert($service->staffSetOutcome($staff, (int) $appt['id'], 'CANCELLED', '') !== null, 'Cancellation without reason accepted.');
    review_assert($service->staffSetOutcome($staff, (int) $appt['id'], 'CANCELLED', 'Demo office closure') === null, 'Individual cancellation failed.');
    review_assert($appointments->interviewFor($id)['status'] === 'CANCELLED' && $apps->findForReview($id)['status'] === 'Pending Review', 'Cancellation did not restore the prior status.');
    review_assert(AppointmentService::needsBooking(['interview_status' => 'CANCELLED']), 'Cancelled applicant lacks replacement status guidance.');
    review_book_interview($test, $staff, $id, $date . ' 09:00:00', 'Demo MSWDO Office');
    review_assert($appointments->interviewFor($id)['status'] === 'BOOKED' && $appointments->interviewFor($id)['cancel_reason'] === null, 'A replacement booking did not clear the old cancellation.');
    echo "PASS: result timing, required cancellation reason, restoring Pending Review, replacement booking.\n";

    // --- Applicant cancellation and the minimum-notice window ---
    set_setting($test, 'appointment_min_notice_hours', '2');
    $GLOBALS['__settings_cache']['appointment_min_notice_hours'] = '2';
    $test->prepare('UPDATE appointments SET scheduled_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE application_id = ?')->execute([$id]);
    review_assert($service->cancelByApplicant($user, $apps->findForReview($id)) !== null && $appointments->interviewFor($id)['status'] === 'BOOKED', 'Applicant cancelled inside the minimum-notice window.');
    $test->prepare('UPDATE appointments SET scheduled_at = ? WHERE application_id = ?')->execute([$date . ' 09:00:00', $id]);
    review_assert($service->cancelByApplicant($user, $apps->findForReview($id)) === null && $appointments->interviewFor($id)['status'] === 'CANCELLED', 'Applicant could not cancel outside the notice window.');
    review_assert($service->cancelByApplicant($user, $apps->findForReview($id)) !== null, 'Applicant cancelled an already-cancelled appointment.');
    review_book_interview($test, $staff, $id, $date . ' 09:00:00', 'Demo MSWDO Office');
    review_assert($appointments->interviewFor($id)['status'] === 'BOOKED', 'Could not book again after applicant cancellation.');
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
    review_book_interview($test, $staff, $id, $date . ' 11:00:00', 'Demo MSWDO Office');
    review_assert($appointments->interviewFor($id)['reminder_sent_at'] === null, 'Booking a new date did not reset the reminder.');
    echo "PASS: reminders send once inside the window, skip distant appointments, and reset when the date changes.\n";

    // --- SMS retry for the current interview notification (older staff-scheduled interviews still carry one) ---
    $test->prepare("INSERT INTO sms_logs (application_id, mobile, message, status, test_mode, template_key, provider, provider_status, error) VALUES (?, '09123456789', 'Fixture interview SMS', 'FAILED', 1, 'interview_scheduled', 'test', 'test-mode', 'Simulated failure')")->execute([$id]);
    $appointments->linkSmsLog($id, (int) $test->lastInsertId());
    $interviewSms = $appointments->interviewSms($id);
    review_assert($interviewSms !== null && $interviewSms['status'] === 'FAILED', 'Interview SMS log is missing.');
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
    } finally { $_POST = $postBefore; }
    echo "PASS: SMS retry targets only the current failed interview SMS.\n";

    // --- Past no-show, completed interview and separate approval ---
    foreach (array_slice($ids, 1) as $index => $applicationId) {
        review_book_interview($test, $staff, $applicationId, $date . ' 10:00:00', 'Demo MSWDO Office');
        $test->prepare('UPDATE appointments SET scheduled_at = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE application_id = ?')->execute([$applicationId]);
        $appt = $appointments->interviewFor($applicationId);
        if ($index === 0) {
            review_assert($service->staffSetOutcome($staff, (int) $appt['id'], 'MISSED', '') === null, 'Past no-show could not be marked missed.');
            review_assert($apps->findForReview($applicationId)['status'] === 'Pending Review' && $appointments->interviewFor($applicationId)['status'] === 'MISSED', 'No-show state incorrect.');
            review_assert($service->staffSetOutcome($staff, (int) $appt['id'], 'MISSED', '') !== null, 'Repeated no-show action accepted.');
        } else {
            review_assert((new Document($test))->outstandingRequired($apps->findForReview($applicationId)) === [], 'The outcome fixture needs every required document verified before a result can be recorded.');
            review_assert($review->recordInterviewOutcome($staff, $applicationId, 'Successful', 'Demo attended interview') === null, 'Interview result failed.');
            review_assert($appointments->interviewFor($applicationId)['status'] === 'COMPLETED', 'Interview not completed.');
            review_assert($apps->findForReview($applicationId)['status'] !== 'Approved', 'Interview completion approved application automatically.');
        }
    }
    $waitingIds = array_map('intval', array_column($appointments->awaitingStaffSchedule(), 'id'));
    review_assert(in_array($ids[1], $waitingIds, true) && !in_array($ids[2], $waitingIds, true), 'The queue of applications needing a new interview is incorrect.');
    echo "PASS: past no-show, repeat-action rejection, completed interview, separate approval and the needs-a-new-interview queue.\n";

    // --- Older appointments that still carry a slot_id remain readable and become slotless when their date is saved again ---
    $test->prepare("INSERT INTO appointment_slots (slot_date,start_time,end_time,capacity,status,created_by) VALUES (?,?,?,?,'AVAILABLE',?)")->execute([$date, '13:00:00', '13:30:00', 1, $staff['id']]);
    $legacySlot = (int) $test->lastInsertId();
    $insert->execute(['APPOINTMENT-DEMO-LEGACY', $userId, $category]);
    $legacyId = (int) $test->lastInsertId();
    $apps->addHistory($legacyId, 'Pending Review', 'Demo submission', 'Appointment Demo Parent', $userId);
    review_make_interview_ready($test, $review, $staff, $legacyId, $category);
    $test->prepare("INSERT INTO appointments (application_id, slot_id, scheduled_at, location, status, scheduled_by) VALUES (?, ?, ?, 'Legacy Office', 'BOOKED', ?)")->execute([$legacyId, $legacySlot, $date . ' 13:00:00', $staff['id']]);
    $apps->setStatus($legacyId, 'Interview Scheduled');
    review_assert((int) $appointments->interviewFor($legacyId)['slot_id'] === $legacySlot, 'Legacy appointment with a slot_id could not be read.');
    review_book_interview($test, $staff, $legacyId, $date . ' 14:00:00', 'Demo MSWDO Office');
    review_assert($appointments->interviewFor($legacyId)['slot_id'] === null, 'Saving a new date for a legacy appointment did not clear its slot_id.');
    review_assert((int) $test->query('SELECT COUNT(*) FROM appointment_slots')->fetchColumn() >= 1, 'The appointment_slots history table lost its rows.');
    echo "PASS: legacy appointments with slot_id remain readable and become slotless when saved again.\n";

    $notice = $test->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ?');
    $notice->execute([$userId]);
    review_assert((int) $notice->fetchColumn() >= 2, 'Applicant notifications missing.');
    $noticeText = $test->prepare('SELECT message FROM notifications WHERE user_id = ?');
    $noticeText->execute([$userId]);
    foreach ($noticeText->fetchAll(PDO::FETCH_COLUMN) as $text) { review_assert(!str_contains($text, 'Please book a new appointment'), 'An in-app notice still tells the applicant to self-book.'); }
    review_assert((int) $test->query('SELECT COUNT(*) FROM sms_logs WHERE test_mode = 0')->fetchColumn() === 0, 'A live SMS was attempted.');
    echo "PASS: applicant notifications recorded in the disposable database; all SMS are test-only.\n";
}
