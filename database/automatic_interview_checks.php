<?php
/**
 * Submission, document verification, the interview calendar and its settings. Called only from the disposable workflow
 * database test (test_staff_review_workflow.php).
 *
 * Interviews are no longer scheduled by staff through a service call: staff send an invitation and the applicant chooses
 * an office day (InterviewInvitationService / InterviewBookingService). A booked appointment is created here with
 * review_book_interview(), a fixture. There is no appointment_slots capacity; appointments.slot_id stays NULL for every new
 * booking (the appointment_slots table remains only for history).
 */
function check_staff_assigned_interviews(PDO $test, array $staff, int $category): void
{
    require_once __DIR__ . '/upload_fixtures.php';
    upload_fixture_isolate_ids($test); // new users get ids in the test range; each submission picks and checks its own application folder
    set_setting($test, 'appointment_min_notice_hours', '2');
    $GLOBALS['__settings_cache']['appointment_min_notice_hours'] = '2';
    $holidayDate = review_office_date($test, '+9 days');
    $closureDate = review_office_date($test, $holidayDate . ' +1 day');
    $settingsInput = [];
    foreach (SETTINGS_REGISTRY['appointments']['keys'] as $key => $def) {
        $value = get_setting($test, $key, (string) $def['default']);
        $settingsInput[$key] = $def['type'] === 'list' ? explode(',', $value) : $value;
    }
    $settingsInput['appointment_holiday_dates'] = $holidayDate;
    $settingsInput['appointment_closure_dates'] = $closureDate . ', ' . $closureDate;
    $admin = $test->query("SELECT * FROM users WHERE role = 'admin' LIMIT 1")->fetch();
    $settingsService = new SettingsService($test);
    [$settingsError] = $settingsService->saveSection($admin, 'appointments', $settingsInput);
    review_assert($settingsError === '' && get_setting($test, 'appointment_closure_dates', '') === $closureDate, 'Valid office closure dates could not be saved or normalized.');
    $date = review_office_date($test, $closureDate . ' +1 day');
    $calendar = new InterviewCalendarService($test);
    review_assert($calendar->dateError('2026-12-25') !== null && $calendar->dateError('2027-03-25') !== null && $calendar->dateError('2026-10-26') !== null, 'Declared national or Catanduanes holidays were accepted.');
    $weekend = date('Y-m-d', strtotime('next Saturday'));

    $service = new ApplicationService($test);
    $applications = new Application($test);
    $appointments = new Appointment($test);
    $requirements = (new Document($test))->requirementsFor($category);
    $insertUser = $test->prepare("INSERT INTO users (full_name,email,username,password_hash,role,mobile,mobile_verified_at) VALUES (?,?,?,?,'applicant','09123456789',NOW())");
    $results = [];
    foreach ([1, 2, 3] as $index) {
        $name = 'Staff Scheduling Fixture ' . $index;
        $email = 'auto.interview.' . $index . '@example.test';
        $insertUser->execute([$name, $email, 'auto_interview_' . $index, password_hash('FixturePassword123', PASSWORD_DEFAULT)]);
        $user = ['id' => (int) $test->lastInsertId(), 'full_name' => $name, 'email' => $email, 'mobile' => '09123456789', 'role' => 'applicant'];
        $draft = array_replace($service->freshDraft($user), [
            'categoryId' => $category, 'barangay' => 'Test', 'address' => 'Test Address', 'birthdate' => '1990-01-01',
            'sex' => 'Female', 'civilStatus' => 'Single', 'children' => [['name' => 'Fixture Child', 'birthdate' => '2020-01-01']], 'consent' => true,
        ]);
        // Every required document is uploaded; submission is refused without them (see test_new_application_uploads.php).
        $draft = upload_fixture_stage_required($service, new Document($test), $user, $draft);
        review_assert($service->firstInvalidStep($draft) === null, 'Staff scheduling submission fixture is invalid.');
        $ref = upload_fixture_submit($test, $service, $user, $draft); // stored files are tracked and removed when the script ends
        $app = $applications->findByReference($ref);
        $results[] = $app;
        review_assert($app['status'] === 'Pending Review' && !$appointments->interviewFor((int) $app['id']), 'Submission assigned an interview before staff chose a schedule.');
        $logs = $test->prepare('SELECT template_key FROM sms_logs WHERE application_id = ?');
        $logs->execute([$app['id']]);
        review_assert($logs->fetchAll(PDO::FETCH_COLUMN) === ['submitted'], 'Submission must send only the submission SMS.');
        review_assert(count($applications->history((int) $app['id'])) === 1, 'Submission added unexpected scheduling history.');
    }

    // Submitted files are awaiting verification and no interview exists yet. (The rule that an invitation needs every
    // required document verified is enforced by InterviewInvitationService and is checked in the end-to-end run.)
    $review = new ApplicationReviewService($test);
    foreach ($results as $submitted) {
        review_assert($appointments->interviewFor((int) $submitted['id']) === null && $applications->findForReview((int) $submitted['id'])['status'] === 'Pending Review', 'A submitted application already had an interview.');
    }
    // Staff verify each application's uploaded files (current file versions), which is what makes an interview possible.
    foreach ($results as $submitted) {
        review_verify_all_uploads($test, $review, $staff, $applications->findForReview((int) $submitted['id']));
        review_assert($applications->findForReview((int) $submitted['id'])['status'] === 'Pending Review' && !(new Document($test))->outstandingRequired($applications->findForReview((int) $submitted['id'])), 'Verifying the uploads did not leave the application ready for an interview.');
    }

    // The applicant-facing booking/slot API must be gone.
    $bookingService = new AppointmentService($test);
    foreach (['book', 'openSlots', 'staffSlots', 'createSlot', 'cancelSlot', 'deleteSlot'] as $removed) {
        review_assert(!method_exists($bookingService, $removed), 'Removed slot/booking method still exists: ' . $removed);
    }

    // A booked interview is saved without a slot; saving a new date moves it and resets the reminder.
    $firstApp = $results[0];
    review_book_interview($test, $staff, (int) $firstApp['id'], $date . ' 09:00:00', 'Fixture Office');
    $firstApp = $applications->findForReview((int) $firstApp['id']);
    $appointment = $appointments->interviewFor((int) $firstApp['id']);
    review_assert($firstApp['status'] === 'Interview Scheduled' && (int) $appointment['scheduled_by'] === (int) $staff['id'] && $appointment['scheduled_at'] === $date . ' 09:00:00', 'The booked interview was not saved.');
    review_assert($appointment['slot_id'] === null, 'A booked interview must be saved with slot_id NULL.');
    $test->prepare('UPDATE appointments SET reminder_sent_at = NOW() WHERE application_id = ?')->execute([$firstApp['id']]);
    review_book_interview($test, $staff, (int) $firstApp['id'], $date . ' 10:00:00', 'Fixture Office');
    $moved = $appointments->interviewFor((int) $firstApp['id']);
    review_assert($moved['scheduled_at'] === $date . ' 10:00:00' && $moved['slot_id'] === null && $moved['reminder_sent_at'] === null && $moved['status'] === 'BOOKED', 'Saving a new date did not update the appointment and reset its reminder.');

    // The appointment table has no per-time capacity limit (a day's capacity is enforced when an applicant books).
    review_book_interview($test, $staff, (int) $results[1]['id'], $date . ' 10:00:00', 'Fixture Office');
    review_assert((int) $test->query("SELECT COUNT(*) FROM appointments WHERE scheduled_at = '" . $date . " 10:00:00'")->fetchColumn() >= 2, 'Two applications could not share the same appointment time.');

    // Closed days are refused by the shared interview calendar: weekends, declared holidays and the closures saved above.
    foreach ([$weekend, $holidayDate, $closureDate] as $blockedDate) {
        review_assert($calendar->dateError($blockedDate) !== null, 'The calendar accepted a closed day: ' . $blockedDate);
    }
    review_assert($calendar->dateError($date) === null, 'The calendar refused an ordinary office day.');

    // Settings validation for closures / office days.
    $invalidSettings = $settingsInput;
    $invalidSettings['appointment_closure_dates'] = '2026-02-30';
    review_assert($settingsService->saveSection($admin, 'appointments', $invalidSettings)[0] !== '', 'Settings accepted an invalid closure date.');
    $invalidSettings = $settingsInput;
    $invalidSettings['appointment_days'] = ['1', '6'];
    review_assert($settingsService->saveSection($admin, 'appointments', $invalidSettings)[0] !== '', 'Settings allowed Saturday interviews.');
    $invalidSettings = $settingsInput;
    $invalidSettings['appointment_closure_dates'] = $date;
    review_assert($settingsService->saveSection($admin, 'appointments', $invalidSettings)[0] !== '' && get_setting($test, 'appointment_closure_dates', '') === $closureDate, 'An office closure was saved over existing bookings without cancellation/rescheduling.');
    echo "Interview checks passed: submission SMS and history, upload verification, booked interviews (slot_id NULL, new date resets the reminder), closed-day calendar rules and closure settings.\n";
}
