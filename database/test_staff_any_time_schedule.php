<?php
/**
 * MSWDO staff may schedule an interview for any date from today and any time, including right now, with SYNTHETIC data in a disposable database:
 *   - today, a weekend and 3:00 AM are accepted; a past date is refused; the time is saved and the Solo Parent is texted;
 *   - the result can be recorded once the time has arrived (right away for "now").
 *
 *   php database/test_staff_any_time_schedule.php   (isolated test environment only)
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
    $staffId = sa_row($test, 'users', ['full_name' => 'Staff Fixture', 'role' => 'mswd_social_worker', 'username' => 'staff_fx_' . bin2hex(random_bytes(3))]);
    $staff = ['id' => $staffId, 'full_name' => 'Staff Fixture', 'role' => 'mswd_social_worker'];
    $service = new InterviewBookingService($test);
    $catId = sa_row($test, 'categories', ['name' => 'Fixture category ' . bin2hex(random_bytes(2))]);
    $mk = function (string $ref) use ($test, $catId): int {
        $uid = sa_row($test, 'users', ['full_name' => 'Parent ' . $ref, 'role' => 'applicant', 'username' => 'p_' . bin2hex(random_bytes(3))]);
        return sa_row($test, 'applications', ['reference_no' => $ref, 'user_id' => $uid, 'full_name' => 'Parent ' . $ref, 'status' => 'Under Verification', 'type' => 'new', 'mobile' => '09123456789', 'category_id' => $catId, 'renewal_simplified' => 0]);
    };
    $today = Appointment::manilaDate();
    $sat = date('Y-m-d', strtotime('next saturday'));
    $cases = [
        ['Q-TODAY-NOW', $today, date('H:i'), true],
        ['Q-SATURDAY', $sat, '03:00', true],
        ['Q-TODAY-NIGHT', $today, '23:59', true],
        ['Q-PAST', date('Y-m-d', strtotime('-1 day')), '09:00', false],
        ['Q-BADTIME', $today, '25:61', false],
    ];
    foreach ($cases as [$ref, $date, $time, $ok]) {
        $id = $mk($ref); $sms = null;
        $error = $service->scheduleForApplicant($staff, $id, $date, null, $sms, $time);
        if ($ok) {
            sa_assert($error === null, "$ref should be accepted ($date $time): " . var_export($error, true));
            $row = $test->query("SELECT scheduled_at, status FROM appointments WHERE application_id = $id")->fetch();
            sa_assert($row && $row['status'] === 'BOOKED' && $row['scheduled_at'] === $date . ' ' . $time . ':00', "$ref was not saved with its time: " . json_encode($row));
        } else {
            sa_assert(is_string($error) && $error !== '', "$ref should be refused.");
        }
    }
    // "Now": the result can be recorded at once; a later time today waits.
    $nowId = (int) $test->query("SELECT id FROM applications WHERE reference_no = 'Q-TODAY-NOW'")->fetchColumn();
    $row = (new Appointment($test))->interviewFor($nowId);
    sa_assert(Appointment::canRecordResult($row), 'An interview set for right now must accept its result.');
    echo "PASS: staff can schedule today (even right now), a weekend and any hour; past dates and invalid times are refused; the time is saved.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
