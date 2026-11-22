<?php
/**
 * Interviews nobody confirmed (SYNTHETIC data, disposable database):
 *   - a BOOKED interview with no outcome is released after the grace weekdays: the booking reopens, it is NOT a counted miss,
 *     no SMS is sent, the Solo Parent and staff get in-app notices;
 *   - one still inside the grace period, or one without an open booking, is left alone;
 *   - a staff-marked missed interview still counts.
 *
 *   php database/test_unconfirmed_interview_release.php   (isolated test environment only)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
$testDb = 'solo_review_test_' . bin2hex(random_bytes(6));
validate_temporary_database_target($testDb, 'solo_review_test_');
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../controllers/StaffController.php';
require_once __DIR__ . "/../models/Document.php";
require_once __DIR__ . '/../models/SmsLog.php';
require_once __DIR__ . '/../includes/sms-attention.php';

function sa_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }

/** Inserts one row, filling every other NOT NULL column that has no default with a harmless value. Returns the new id. */
function sa_row(PDO $pdo, string $table, array $given): int
{
    $values = $given;
    foreach ($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll() as $c) {
        $name = $c['Field'];
        if (array_key_exists($name, $values) || str_contains(strtolower($c['Extra']), 'auto_increment') || str_contains(strtolower($c['Extra']), 'generated')) { continue; }
        if ($c['Null'] === 'YES' || $c['Default'] !== null) { continue; }
        $type = strtolower($c['Type']);
        $values[$name] = match (true) {
            str_starts_with($type, 'enum(') => (preg_match("/^enum\\('([^']*)'/", $type, $m) ? $m[1] : ''),
            str_contains($type, 'json') => '{}',
            str_starts_with($type, 'datetime'), str_starts_with($type, 'timestamp') => '2026-01-01 00:00:00',
            str_starts_with($type, 'date') => '2026-01-01',
            str_starts_with($type, 'time') => '00:00:00',
            (bool) preg_match('/int|decimal|float|double|year|bit/', $type) => 1,
            default => 'x',
        };
    }
    $cols = array_map(fn($c) => '`' . $c . '`', array_keys($values));
    $pdo->prepare('INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')')->execute(array_values($values));
    return (int) $pdo->lastInsertId();
}

$live = $pdo;
$created = false;
$exitCode = 0;
try {
    $live->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4');
    $created = true;
    $test = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $testDb . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $test->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($live->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $test->exec($live->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1]);
    }
    $pdo = $test;
    $GLOBALS['pdo'] = $test;
    sa_assert(SMSService::testMode(), 'These checks require the test SMS provider.');
    set_setting($test, 'sms_enabled', '1');
    foreach (['interview_scheduled' => 'MSWDO {ref} {when}', 'appointment_cancelled' => 'MSWDO {ref} {when} {reason}', 'appointment_missed' => 'MSWDO {ref} {when}'] as $key => $body) {
        $test->prepare('INSERT INTO sms_templates (template_key, label, body) VALUES (?, ?, ?)')->execute([$key, $key, $body]);
    }
    require_once __DIR__ . '/../services/InterviewBookingService.php';
    require_once __DIR__ . '/../models/Appointment.php';
    // The cloned test data may lack the interview-booking tables: create them in this throwaway database from the migration file.
    $sql = file_get_contents(__DIR__ . '/migrate_interview_date_booking.sql');
    preg_match_all('/CREATE TABLE IF NOT EXISTS .*?\n\)[^;]*;/s', $sql, $m);
    foreach ($m[0] as $create) { $test->exec($create); }
    foreach (['interview_date' => 'DATE GENERATED ALWAYS AS (DATE(`scheduled_at`)) STORED', 'date_only' => 'TINYINT(1) NOT NULL DEFAULT 0', 'booking_source' => "ENUM('LEGACY','STAFF','APPLICANT') NOT NULL DEFAULT 'LEGACY'", 'confirmation_sms_log_id' => 'INT NULL'] as $col => $def) {
        $has = $test->query("SHOW COLUMNS FROM appointments LIKE '$col'")->fetch();
        if (!$has) { $test->exec("ALTER TABLE appointments ADD COLUMN $col $def"); }
    }
    // The real database refuses a date-only row that carries a time of day (the migration's CHECK): reproduce it here.
    $hasCheck = $test->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND CONSTRAINT_NAME = 'chk_appt_date_only'")->fetchColumn();
    if (!$hasCheck) { $test->exec("ALTER TABLE appointments ADD CONSTRAINT chk_appt_date_only CHECK (date_only = 0 OR TIME(`scheduled_at`) = '00:00:00')"); }
    set_setting($test, InterviewDateFeature::FLAG_KEY, '1');
    set_setting($test, 'interview_default_capacity', '10');
    (new ReflectionProperty(InterviewDateFeature::class, 'memo'))->setValue(null, null);
    // The throwaway database has the three tables but not the migration's triggers, so tell the readiness guard it is ready (this test only).
    $remember = new ReflectionMethod(InterviewDateFeature::class, 'remember');
    $remember->invoke(null, $test, 'problems', []);
    sa_assert(InterviewDateFeature::enabled($test), 'The feature must read as enabled for this test.');
    foreach (['interview_date_assigned' => 'MSWDO {ref} {when} {hours} {location}'] as $key => $body) {
        $test->prepare('INSERT INTO sms_templates (template_key, label, body) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE body = VALUES(body)')->execute([$key, $key, $body]);
    }
    require_once __DIR__ . '/../services/AppointmentService.php';
    require_once __DIR__ . '/../models/AppointmentEvent.php';
    $mk = function (string $ref, string $date, string $time, bool $withInvitation = true) use ($test): array {
        $uid = sa_row($test, 'users', ['full_name' => 'Parent ' . $ref, 'role' => 'applicant', 'username' => 'p_' . bin2hex(random_bytes(3))]);
        $cat = (int) ($test->query('SELECT id FROM categories LIMIT 1')->fetchColumn() ?: sa_row($test, 'categories', ['name' => 'Fixture ' . bin2hex(random_bytes(2))]));
        $app = sa_row($test, 'applications', ['reference_no' => $ref, 'user_id' => $uid, 'full_name' => 'Parent ' . $ref, 'status' => 'Interview Scheduled', 'type' => 'new', 'mobile' => '09123456789', 'category_id' => $cat, 'renewal_simplified' => 0]);
        $test->prepare("INSERT INTO application_status_history (application_id, status, changed_by) VALUES (?, 'Under Verification', 'x')")->execute([$app]);
        $test->prepare("INSERT INTO application_status_history (application_id, status, changed_by) VALUES (?, 'Interview Scheduled', 'x')")->execute([$app]);
        $appt = sa_row($test, 'appointments', ['application_id' => $app, 'scheduled_at' => $date . ' ' . $time, 'status' => 'BOOKED', 'date_only' => $time === '00:00:00' ? 1 : 0, 'booking_source' => 'STAFF', 'location' => 'Office']);
        if ($withInvitation) {
            sa_row($test, 'interview_invitations', ['application_id' => $app, 'status' => 'BOOKED', 'reschedules_used' => 0, 'reschedules_allowed' => 1]);
        }
        return [$app, $appt, $uid];
    };
    $monday = date('Y-m-d', strtotime('last monday -7 days')); // far enough back that any grace has passed
    $service = new AppointmentService($test);
    $smsBefore = (int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn();

    [$oldApp, $oldAppt, $oldUser] = $mk('R-OLD', $monday, '00:00:00');
    [$timedApp, $timedAppt] = $mk('R-TIMED', $monday, '14:30:00');
    [$freshApp, $freshAppt] = $mk('R-FRESH', date('Y-m-d', strtotime('-1 day')), '00:00:00');
    [$noInvApp, $noInvAppt] = $mk('R-NOINV', $monday, '00:00:00', false);
    sa_assert($service->releaseUnconfirmed() === 2, 'Exactly the two old interviews with an open booking should be released.');

    foreach ([[$oldApp, $oldAppt], [$timedApp, $timedAppt]] as [$app, $appt]) {
        $row = $test->query("SELECT status, notes FROM appointments WHERE id = $appt")->fetch();
        sa_assert($row['status'] === 'MISSED' && $row['notes'] === AppointmentService::RELEASE_NOTE, 'The interview was not released.');
        sa_assert($test->query("SELECT status FROM interview_invitations WHERE application_id = $app")->fetchColumn() === 'OPEN', 'The booking was not reopened.');
        sa_assert($test->query("SELECT status FROM applications WHERE id = $app")->fetchColumn() === 'Under Verification', 'The application did not go back to its earlier status.');
        $events = new AppointmentEvent($test);
        sa_assert($events->countOf($app, 'NO_OUTCOME') === 1 && $events->countOf($app, 'MISSED') === 0, 'It must be recorded as no outcome, never as a counted miss.');
    }
    sa_assert((int) $test->query("SELECT COUNT(*) FROM notifications WHERE user_id = $oldUser")->fetchColumn() >= 1, 'The Solo Parent got no in-app notice.');
    sa_assert((int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn() === $smsBefore, 'No SMS may be sent when the booking is reopened.');
    foreach ([[$freshApp, $freshAppt], [$noInvApp, $noInvAppt]] as [$app, $appt]) {
        sa_assert($test->query("SELECT status FROM appointments WHERE id = $appt")->fetchColumn() === 'BOOKED', 'An interview inside the grace period or without an open booking must be left alone.');
    }
    sa_assert($service->releaseUnconfirmed() === 0, 'Running it again must release nothing more.');
    // Grace arithmetic: Friday + 2 weekdays = Tuesday; Monday + 2 = Wednesday.
    sa_assert(AppointmentService::releaseAfter('2026-11-20') === '2026-11-24' && AppointmentService::releaseAfter('2026-11-23') === '2026-11-25', 'The grace period must count weekdays.');
    // The staff dashboard shows the waiting interview (the fresh one is not yet "awaiting": its date is yesterday, so it is).
    sa_assert(count((new Appointment($test))->awaitingOutcome()) >= 2, 'Waiting interviews must be listed for staff.');

    // Staff confirm "did not attend": the booking reopens, it counts as a miss, and NO SMS is sent.
    $staffId = sa_row($test, 'users', ['full_name' => 'Staff Fixture', 'role' => 'mswd_social_worker', 'username' => 'staff_fx_' . bin2hex(random_bytes(3))]);
    $staff = ['id' => $staffId, 'full_name' => 'Staff Fixture', 'role' => 'mswd_social_worker'];
    [$mApp, $mAppt, $mUser] = $mk('R-STAFFMISS', date('Y-m-d', strtotime('-2 days')), '00:00:00');
    $smsNow = (int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn();
    $err = $service->staffSetOutcome($staff, $mAppt, 'MISSED', '', $smsOut);
    sa_assert($err === null, 'Marking it missed failed: ' . var_export($err, true));
    sa_assert($smsOut === null && (int) $test->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn() === $smsNow, 'Did not attend must never send an SMS.');
    sa_assert($test->query("SELECT status FROM interview_invitations WHERE application_id = $mApp")->fetchColumn() === 'OPEN', 'The Solo Parent must be able to choose a new date.');
    sa_assert((new AppointmentEvent($test))->countOf($mApp, 'MISSED') === 1, 'A staff-confirmed miss must be counted.');
    // The Solo Parent then books a new day: staff must be able to see it is a reschedule (history, events, alert).
    require_once __DIR__ . '/../services/InterviewBookingService.php';
    $nextDay = date('Y-m-d', strtotime('+10 days'));
    while ((int) date('N', strtotime($nextDay)) >= 6) { $nextDay = date('Y-m-d', strtotime($nextDay . ' +1 day')); }
    $parent = ['id' => $mUser, 'full_name' => 'Parent R-STAFFMISS', 'role' => 'applicant'];
    $bookErr = (new InterviewBookingService($test))->book($parent, ['id' => $mApp], $nextDay);
    sa_assert($bookErr === null, 'The Solo Parent could not choose a new date: ' . var_export($bookErr, true));
    $hist = $test->query("SELECT remarks FROM application_status_history WHERE application_id = $mApp ORDER BY id DESC LIMIT 1")->fetchColumn();
    sa_assert(str_contains((string) $hist, 'Rescheduled by the Solo Parent after a missed interview') && str_contains((string) $hist, 'previous date'), 'The status history must say it is a reschedule: ' . var_export($hist, true));
    $evs = (new AppointmentEvent($test))->forApplication($mApp);
    $last = end($evs);
    sa_assert(str_contains((string) $last['details'], 'Rescheduled after a missed interview'), 'The interview history must say it is a reschedule.');
    $alert = $test->query("SELECT message FROM notifications WHERE related_application_id = $mApp AND message LIKE 'RESCHEDULED:%' LIMIT 1")->fetchColumn();
    sa_assert($alert === false || str_contains((string) $alert, 'after a missed interview'), 'The staff alert must say it is a reschedule.');
    // A record whose Solo Parent account no longer exists (it happens in real data): the action still works, no error page.
    [$ghostApp, $ghostAppt, $ghostUser] = $mk('R-GHOST', date('Y-m-d', strtotime('-2 days')), '00:00:00');
    $test->exec('SET FOREIGN_KEY_CHECKS=0');
    $test->exec("DELETE FROM users WHERE id = $ghostUser");
    $test->exec('SET FOREIGN_KEY_CHECKS=1'); // enforced again, as on the real database
    $ghostErr = null;
    try { $ghostErr = $service->staffSetOutcome($staff, $ghostAppt, 'MISSED', '', $ghostSms); } catch (Throwable $e) { $ghostErr = 'thrown: ' . $e->getMessage(); }
    sa_assert($ghostErr === null, 'Did not attend must work when the account is gone: ' . var_export($ghostErr, true));
    sa_assert($test->query("SELECT status FROM appointments WHERE id = $ghostAppt")->fetchColumn() === 'MISSED', 'The miss was not saved.');
    // ---- The recommended decisions ----
    $test->exec('SET FOREIGN_KEY_CHECKS=0'); // the cloned test data has no roles table rows; fixtures insert users directly
    require_once __DIR__ . '/../services/ApplicationReviewService.php';
    $review = new ApplicationReviewService($test);
    $appRow = fn(int $id) => $test->query("SELECT * FROM applications WHERE id = $id")->fetch();

    // (4) the missed-interview limit is 3 by default and is a setting.
    sa_assert((new InterviewInvitationService($test))->missedLimit() === 3, 'The default missed-interview limit must be 3.');
    set_setting($test, 'interview_missed_limit', '1');
    [$limApp, $limAppt] = $mk('R-LIMIT', date('Y-m-d', strtotime('-2 days')), '00:00:00');
    sa_assert($service->staffSetOutcome($staff, $limAppt, 'MISSED', '', $limSms) === null, 'Marking the limit case missed failed.');
    sa_assert($test->query("SELECT status FROM interview_invitations WHERE application_id = $limApp")->fetchColumn() === 'CLOSED', 'Reaching the limit must close booking.');
    set_setting($test, 'interview_missed_limit', '3');

    // (1) staff can still confirm "attended" after the system released the interview (R-OLD was released above).
    $late = $review->recordInterviewResult($staff, $oldApp, 'Successful');
    sa_assert($late === null, 'Recording attendance after a release failed: ' . var_export($late, true));
    sa_assert($test->query("SELECT status FROM appointments WHERE id = $oldAppt")->fetchColumn() === 'COMPLETED', 'The interview should be completed.');
    sa_assert($test->query("SELECT status FROM interview_invitations WHERE application_id = $oldApp")->fetchColumn() === 'BOOKED', 'The booking should close again.');
    sa_assert((new AppointmentEvent($test))->countOf($oldApp, 'ATTENDED_CONFIRMED') === 1, 'The late confirmation must be in the history.');

    // (2) after an UNSUCCESSFUL interview: approval stays locked, corrections and another interview are possible.
    [$unApp, $unAppt] = $mk('R-UNSUC', date('Y-m-d', strtotime('-2 days')), '00:00:00');
    sa_assert($review->recordInterviewOutcome($staff, $unApp, 'Unsuccessful', 'details do not match') === null, 'Recording an unsuccessful result failed.');
    $approve = $review->updateStatus($staff, $appRow($unApp), 'Approved', '');
    sa_assert(is_string($approve) && str_contains($approve, 'successful interview'), 'Approval must stay locked after an unsuccessful interview.');
    $retake = (new InterviewBookingService($test))->scheduleForApplicant($staff, $unApp, date('Y-m-d'), null, $retakeSms, '23:59');
    sa_assert($retake === null, 'Another interview could not be scheduled after an unsuccessful one: ' . var_export($retake, true));
    $row = $test->query("SELECT status, result FROM appointments WHERE id = $unAppt")->fetch();
    sa_assert($row['status'] === 'BOOKED' && $row['result'] === null, 'The new interview must be booked and the old result cleared.');
    sa_assert($test->query("SELECT status FROM interview_invitations WHERE application_id = $unApp")->fetchColumn() === 'BOOKED', 'The booking must show as set.');
    [$un2App, $un2Appt] = $mk('R-UNSUC2', date('Y-m-d', strtotime('-2 days')), '00:00:00');
    sa_assert($review->recordInterviewOutcome($staff, $un2App, 'Unsuccessful', 'incomplete') === null, 'Recording the second unsuccessful result failed.');
    $fix = $review->updateStatus($staff, $appRow($un2App), 'For Revision', 'Please upload a clearer ID');
    sa_assert($fix === null, 'Requesting corrections after an unsuccessful interview failed: ' . var_export($fix, true) . json_encode($test->query("SELECT status, result, result_recorded_at FROM appointments WHERE id = $un2Appt")->fetch()));
    sa_assert($test->query("SELECT status FROM applications WHERE id = $un2App")->fetchColumn() === 'For Revision', 'The application should be waiting for corrections.');

    // (3) the follow-up list: missed, booking open, not rebooked for N days.
    $apptModel = new Appointment($test);
    $refs0 = array_column($apptModel->missedNotRebooked(0), 'reference_no');
    sa_assert(in_array('R-TIMED', $refs0, true) && !in_array('R-OLD', $refs0, true), 'The follow-up list should hold released, not-rebooked interviews only.');
    sa_assert(!in_array('R-TIMED', array_column($apptModel->missedNotRebooked(7), 'reference_no'), true), 'A fresh miss must not be in the 7-day follow-up list.');
    echo "PASS: unconfirmed interviews are released after the grace weekdays, reopen the booking, are not counted as misses, send no SMS, and others are left alone.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
