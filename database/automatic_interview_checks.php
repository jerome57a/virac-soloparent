<?php
/** Submission/scheduling integration checks; called only in the disposable workflow database. */
function check_staff_assigned_interviews(PDO $test, array $staff, int $category): void
{
    // Isolate this scenario from the completed slot scenarios in the same test database.
    $test->exec("UPDATE appointment_slots SET status = 'CANCELLED'");
    set_setting($test, 'appointment_min_notice_hours', '2');
    $GLOBALS['__settings_cache']['appointment_min_notice_hours'] = '2';
    $insertSlot = $test->prepare('INSERT INTO appointment_slots (slot_date,start_time,end_time,capacity,status,created_by) VALUES (?,?,?,?,?,?)');
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
    review_assert($settingsError === '' && get_setting($test,'appointment_closure_dates','') === $closureDate, 'Valid office closure dates could not be saved or normalized.');
    $date = review_office_date($test, $closureDate . ' +1 day');
    $calendar = new InterviewCalendarService($test);
    review_assert($calendar->dateError('2026-12-25') !== null && $calendar->dateError('2027-03-25') !== null && $calendar->dateError('2026-10-26') !== null, 'Declared national or Catanduanes holidays were accepted.');
    $blockedSlots = [];
    foreach ([date('Y-m-d',strtotime('next Saturday')), date('Y-m-d',strtotime('next Sunday')), $holidayDate, $closureDate] as $blockedDate) {
        $insertSlot->execute([$blockedDate,'09:00:00','09:30:00',10,'AVAILABLE',$staff['id']]);
        $blockedSlots[] = (int) $test->lastInsertId();
    }
    $slotIds = [];
    foreach (['08:00:00' => 'CANCELLED', '09:00:00' => 'AVAILABLE', '10:00:00' => 'AVAILABLE'] as $time => $status) {
        $insertSlot->execute([$date,$time,date('H:i:s',strtotime($time.' +30 minutes')),1,$status,$staff['id']]);
        $slotIds[] = (int) $test->lastInsertId();
    }
    $service = new ApplicationService($test);
    $applications = new Application($test);
    $appointments = new Appointment($test);
    $requirements = (new Document($test))->requirementsFor($category);
    $insertUser = $test->prepare("INSERT INTO users (full_name,email,username,password_hash,role,mobile,mobile_verified_at) VALUES (?,?,?,?,'applicant','09123456789',NOW())");
    $results = [];
    foreach ([1,2,3] as $index) {
        $name = 'Staff Scheduling Fixture ' . $index;
        $email = 'auto.interview.' . $index . '@example.test';
        $insertUser->execute([$name,$email,'auto_interview_'.$index,password_hash('FixturePassword123',PASSWORD_DEFAULT)]);
        $user = ['id'=>(int)$test->lastInsertId(),'full_name'=>$name,'email'=>$email,'mobile'=>'09123456789','role'=>'applicant'];
        $draft = array_replace($service->freshDraft($user),[
            'categoryId'=>$category,'barangay'=>'Test','address'=>'Test Address','birthdate'=>'1990-01-01',
            'sex'=>'Female','civilStatus'=>'Single','children'=>[['name'=>'Fixture Child','birthdate'=>'2020-01-01']],'consent'=>true,
            'checkedDocuments'=>$index === 1 ? array_fill_keys(array_column($requirements,'doc_key'),true) : [],
        ]);
        review_assert($service->firstInvalidStep($draft) === null, 'Staff scheduling submission fixture is invalid.');
        $ref = $service->submit($user,$draft);
        $app = $applications->findByReference($ref);
        $appointment = $appointments->interviewFor((int)$app['id']);
        $results[] = $app;
        review_assert($app['status'] === 'Pending Review' && !$appointment, 'Submission assigned an interview before staff chose a schedule.');
        $logs = $test->prepare('SELECT template_key FROM sms_logs WHERE application_id = ?');
        $logs->execute([$app['id']]);
        review_assert($logs->fetchAll(PDO::FETCH_COLUMN) === ['submitted'], 'Submission must send only the submission SMS.');
        review_assert(count($applications->history((int)$app['id'])) === 1, 'Submission added unexpected scheduling history.');
    }
    foreach (array_slice($slotIds,1) as $slotId) {
        review_assert($appointments->bookedCountExcluding($slotId,0) === 0, 'Submission reserved a seat before staff scheduling.');
    }
    $bookingService = new AppointmentService($test);
    $visibleSlots = array_column($bookingService->openSlots(),'id');
    review_assert(!array_intersect($visibleSlots,$blockedSlots), 'Closed-day slots are visible for applicant rescheduling.');
    $firstApp = $results[0];
    $firstUser = ['id'=>$firstApp['user_id'],'full_name'=>'Fixture','role'=>'applicant'];
    review_assert($bookingService->book($firstUser,$firstApp,$slotIds[1]) !== null, 'Applicant assigned their own first interview.');
    $review = new ApplicationReviewService($test);
    review_assert($review->scheduleInterview($staff,$firstApp,$date.'T09:00','Fixture Office','','automatic','',$slotIds[1]) === null, 'Staff could not assign the first interview.');
    $firstApp = $applications->findForReview((int)$firstApp['id']);
    $appointment = $appointments->interviewFor((int)$firstApp['id']);
    review_assert($firstApp['status'] === 'Interview Scheduled' && (int)$appointment['scheduled_by'] === (int)$staff['id'] && $appointment['scheduled_at'] === $date.' 09:00:00', 'Staff-selected schedule was not saved.');
    $sms = $appointments->interviewSms((int)$firstApp['id']);
    review_assert($sms && str_contains($sms['message'],$firstApp['reference_no']) && str_contains($sms['message'],'Fixture Office'), 'Staff scheduling SMS was not linked to the interview.');
    foreach ($blockedSlots as $blockedSlot) {
        review_assert($bookingService->book($firstUser,$firstApp,$blockedSlot) !== null, 'Applicant could reschedule onto a weekend, holiday or office closure.');
    }
    review_assert($bookingService->book($firstUser,$firstApp,$slotIds[2]) === null, 'Applicant could not reschedule an existing staff booking.');
    review_assert($appointments->bookedCountExcluding($slotIds[2],0) === 1, 'Rescheduling did not reserve the selected seat.');
    review_assert($bookingService->createSlot($staff,$date,'11:00','11:30',1) === null, 'Could not add second managed interview slot.');
    $secondManagedSlot = $appointments->slotAt($date,'11:00:00');
    $secondApp = $results[1];
    review_assert($review->scheduleInterview($staff,$secondApp,$date.'T11:00','Fixture Office','','automatic','',(int)$secondManagedSlot['id']) === null, 'Staff could not schedule despite unchecked requirements.');
    review_assert($bookingService->book(['id'=>$secondApp['user_id'],'full_name'=>'Fixture'],$secondApp,$slotIds[2]) !== null, 'Applicant overbooked a full slot.');
    $pendingApp = $results[2];
    $review = new ApplicationReviewService($test);
    foreach ([date('Y-m-d',strtotime('next Saturday')), $holidayDate, $closureDate] as $blockedDate) {
        review_assert($bookingService->createSlot($staff,$blockedDate,'14:00','14:30',10) !== null, 'Staff opened a slot on a closed day.');
        review_assert($review->scheduleInterview($staff,$pendingApp,$blockedDate.'T09:00','Fixture Office','') !== null, 'Staff manually scheduled an interview on a closed day.');
    }
    $invalidSettings = $settingsInput;
    $invalidSettings['appointment_closure_dates'] = '2026-02-30';
    review_assert($settingsService->saveSection($admin,'appointments',$invalidSettings)[0] !== '', 'Settings accepted an invalid closure date.');
    $invalidSettings = $settingsInput;
    $invalidSettings['appointment_days'] = ['1','6'];
    review_assert($settingsService->saveSection($admin,'appointments',$invalidSettings)[0] !== '', 'Settings allowed Saturday interviews.');
    $invalidSettings = $settingsInput;
    $invalidSettings['appointment_closure_dates'] = $date;
    review_assert($settingsService->saveSection($admin,'appointments',$invalidSettings)[0] !== '' && get_setting($test,'appointment_closure_dates','') === $closureDate, 'An office closure was saved over existing bookings without cancellation/rescheduling.');
    echo "Staff scheduling passed: pending submissions, submission SMS, staff-selected interviews, schedule SMS, applicant rescheduling, capacity and closed-day validation.\n";
}
