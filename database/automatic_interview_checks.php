<?php
/**
 * Submission + staff-entered interview scheduling checks. Called only from the
 * disposable workflow database test (test_staff_review_workflow.php).
 *
 * Interviews are scheduled by staff typing a date and time. There is no applicant
 * self-booking and no appointment_slots capacity; appointments.slot_id stays NULL
 * for every new schedule (the appointment_slots table remains only for history).
 */
function check_staff_assigned_interviews(PDO $test, array $staff, int $category): void
{
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
            'checkedDocuments' => $index === 1 ? array_fill_keys(array_column($requirements, 'doc_key'), true) : [],
        ]);
        review_assert($service->firstInvalidStep($draft) === null, 'Staff scheduling submission fixture is invalid.');
        $ref = $service->submit($user, $draft);
        $app = $applications->findByReference($ref);
        $results[] = $app;
        review_assert($app['status'] === 'Pending Review' && !$appointments->interviewFor((int) $app['id']), 'Submission assigned an interview before staff chose a schedule.');
        $logs = $test->prepare('SELECT template_key FROM sms_logs WHERE application_id = ?');
        $logs->execute([$app['id']]);
        review_assert($logs->fetchAll(PDO::FETCH_COLUMN) === ['submitted'], 'Submission must send only the submission SMS.');
        review_assert(count($applications->history((int) $app['id'])) === 1, 'Submission added unexpected scheduling history.');
    }

    // The applicant-facing booking/slot API must be gone.
    $bookingService = new AppointmentService($test);
    foreach (['book', 'openSlots', 'staffSlots', 'createSlot', 'cancelSlot', 'deleteSlot'] as $removed) {
        review_assert(!method_exists($bookingService, $removed), 'Removed slot/booking method still exists: ' . $removed);
    }

    // Staff enter the date and time directly.
    $review = new ApplicationReviewService($test);
    $firstApp = $results[0];
    review_assert($review->scheduleInterview($staff, $firstApp, $date . 'T09:00', 'Fixture Office', '') === null, 'Staff could not assign the first interview.');
    $firstApp = $applications->findForReview((int) $firstApp['id']);
    $appointment = $appointments->interviewFor((int) $firstApp['id']);
    review_assert($firstApp['status'] === 'Interview Scheduled' && (int) $appointment['scheduled_by'] === (int) $staff['id'] && $appointment['scheduled_at'] === $date . ' 09:00:00', 'Staff-entered schedule was not saved.');
    review_assert($appointment['slot_id'] === null, 'Staff-entered schedule must be saved with slot_id NULL.');
    $sms = $appointments->interviewSms((int) $firstApp['id']);
    review_assert($sms && str_contains($sms['message'], $firstApp['reference_no']) && str_contains($sms['message'], 'Fixture Office'), 'Staff scheduling SMS was not linked to the interview.');

    // Staff can reschedule an existing appointment; it stays slotless and resets the reminder.
    $test->prepare('UPDATE appointments SET reminder_sent_at = NOW() WHERE application_id = ?')->execute([$firstApp['id']]);
    review_assert($review->scheduleInterview($staff, $firstApp, $date . 'T10:00', 'Fixture Office', '') === null, 'Staff could not reschedule an existing appointment.');
    $moved = $appointments->interviewFor((int) $firstApp['id']);
    review_assert($moved['scheduled_at'] === $date . ' 10:00:00' && $moved['slot_id'] === null && $moved['reminder_sent_at'] === null && $moved['status'] === 'BOOKED', 'Rescheduling did not update the appointment and reset its reminder.');

    // No capacity limit: another applicant may be scheduled at exactly the same time.
    $secondApp = $results[1];
    review_assert($review->scheduleInterview($staff, $secondApp, $date . 'T10:00', 'Fixture Office', '') === null, 'Staff could not schedule despite unchecked requirements / an existing booking at that time.');

    // Invalid input and closed days are rejected and create no appointment.
    $pendingApp = $results[2];
    $holidaySet = [$weekend, $holidayDate, $closureDate];
    foreach ($holidaySet as $blockedDate) {
        review_assert($review->scheduleInterview($staff, $pendingApp, $blockedDate . 'T09:00', 'Fixture Office', '') !== null, 'Staff scheduled an interview on a closed day: ' . $blockedDate);
    }
    $invalidInputs = [
        'malformed date' => ['2026-02-30T09:00', 'Fixture Office'],
        'missing time' => [$date . 'T', 'Fixture Office'],
        'past date' => ['2000-01-03T09:00', 'Fixture Office'],
        'before opening' => [$date . 'T03:00', 'Fixture Office'],
        'after closing' => [$date . 'T23:00', 'Fixture Office'],
        'empty location' => [$date . 'T09:00', ''],
    ];
    foreach ($invalidInputs as $label => [$when, $location]) {
        review_assert($review->scheduleInterview($staff, $pendingApp, $when, $location, '') !== null, 'Invalid interview input accepted: ' . $label);
    }
    review_assert($review->scheduleInterview($staff, $pendingApp, $date . 'T09:00', str_repeat('x', 256), '') !== null, 'Over-long location accepted.');
    review_assert($appointments->interviewFor((int) $pendingApp['id']) === null, 'A rejected schedule still created an appointment.');

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
    echo "Staff scheduling passed: pending submissions, submission SMS, staff-entered date/time (slot_id NULL), rescheduling, no capacity limit, invalid input and closed-day validation.\n";
}
