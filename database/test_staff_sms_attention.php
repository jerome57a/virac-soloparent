<?php
/**
 * Failed texts and MSWD staff, in a disposable database with SYNTHETIC rows only:
 *   - the "failed texts" list: what appears, what never appears, and how an entry leaves it;
 *   - Retry from the list (and from the application) with its safety checks, including the POST path;
 *   - the menu badge count;
 *   - cancelling an appointment or marking it missed returns the real SMS result and the staff alert is raised.
 *
 *   php database/test_staff_sms_attention.php   (isolated test environment only)
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
require_once __DIR__ . '/../services/AppointmentService.php';
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

    // ---- The list ----
    $log = function (?int $app, string $kind, string $status, int $attempts, string $age = '0 DAY') use ($test): int {
        return sa_row($test, 'sms_logs', ['application_id' => $app, 'mobile' => '09123456789', 'message' => 'Fixture ' . $kind, 'status' => $status,
            'test_mode' => 1, 'template_key' => $kind, 'provider' => 'test', 'provider_status' => 'test-mode', 'error' => $status === 'FAILED' ? 'Simulated failure' : null,
            'attempts' => $attempts, 'sent_at' => $test->query('SELECT DATE_SUB(NOW(), INTERVAL ' . $age . ')')->fetchColumn()]);
    };
    $l901 = $log(901, 'interview_scheduled', 'FAILED', 1);              // listed, retryable
    $l902 = $log(902, 'id_release_scheduled', 'FAILED', 3);             // listed, all attempts used
    $log(903, 'status_change', 'FAILED', 1);                            // replaced by a newer text of the same kind
    $log(903, 'status_change', 'SENT', 1);
    $log(904, 'password_reset', 'FAILED', 1);                           // never listed: the applicant requests their own code
    $log(null, 'mobile_verification', 'FAILED', 1);                     // never listed: no application
    $log(905, 'appointment_cancelled', 'FAILED', 1, '10 DAY');          // too old
    $l906 = $log(906, 'document_correction', 'FAILED', 1);              // listed, cannot be retried from here
    $log(907, 'renewal_reminder', 'SENT', 1);                           // sent: not listed
    $l908 = $log(908, 'interview_scheduled', 'FAILED', 1);              // listed
    $log(908, 'appointment_reminder', 'SENT', 1);                       // a different kind does not replace it
    $l909 = $log(909, 'appointment_missed', 'FAILED', 1);               // listed, retryable
    $smsLog = new SmsLog($test);
    $ids = fn(array $rows): array => array_map('intval', array_column($rows, 'id'));
    $listed = $smsLog->needingAttention(SMS_MAX_ATTEMPTS);
    sa_assert($ids($listed) === [$l909, $l908, $l906, $l902, $l901], 'The failed-text list holds the wrong texts: ' . implode(',', $ids($listed)));
    $byId = array_column($listed, null, 'id');
    sa_assert($byId[$l901]['can_retry'] && $byId[$l908]['can_retry'] && $byId[$l909]['can_retry'], 'A failed text with attempts left must be retryable.');
    sa_assert(!$byId[$l902]['can_retry'] && !$byId[$l906]['can_retry'], 'A text with every attempt used, or a correction text, must not be retryable from the list.');
    sa_assert(staff_sms_attention_count() === 5, 'The menu badge does not match the list.');

    // ---- Retry: the safety checks ----
    $staff = ['id' => 1, 'role' => 'mswd_social_worker', 'full_name' => 'Fixture Staff', 'username' => 'fixture.staff'];
    if (!class_exists('SmsAttentionTestController')) {
        class SmsAttentionTestController extends StaffController {
            protected function redirectTo(string $path): void { throw new RuntimeException('REDIRECT:' . $path); }
        }
    }
    $controller = (new ReflectionClass(SmsAttentionTestController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Controller::class, 'pdo'))->setValue($controller, $test);
    (new ReflectionProperty(StaffController::class, 'staff'))->setValue($controller, $staff);
    $retry = new ReflectionMethod(StaffController::class, 'retrySms');
    $status = fn(int $id): string => (string) $test->query('SELECT status FROM sms_logs WHERE id = ' . $id)->fetchColumn();
    foreach ([[$l902, 'used every attempt'], [$l906, 'a correction text'], [999999, 'an unknown text']] as [$id, $what]) {
        $retry->invoke($controller, null, $id);
        $notice = take_sms_notice();
        sa_assert(($notice['state'] ?? '') === 'failed' && str_contains((string) $notice['text'], 'cannot be retried'), 'Retry of ' . $what . ' was not refused.');
        sa_assert($id === 999999 || $status($id) === 'FAILED', 'Refused retry of ' . $what . ' changed the text.');
    }
    $sentId = (int) $test->query("SELECT id FROM sms_logs WHERE template_key = 'renewal_reminder'")->fetchColumn();
    $retry->invoke($controller, null, $sentId);
    sa_assert((take_sms_notice()['state'] ?? '') === 'failed' && $status($sentId) === 'SENT', 'A text that was sent can be retried.');
    $retry->invoke($controller, 1, $l909);   // text belongs to application 909, not 1
    sa_assert((take_sms_notice()['state'] ?? '') === 'failed' && $status($l909) === 'FAILED', 'A text was retried through the wrong application.');

    // ---- Retry that works: through the real page (POST), the list shrinks ----
    $_SESSION['csrf_token'] = str_repeat('b', 64);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf_token' => str_repeat('b', 64), 'form_action' => 'retry_sms', 'sms_log_id' => (string) $l909];
    try { $controller->smsAttention(); sa_assert(false, 'The retry did not redirect.'); } catch (RuntimeException $e) { sa_assert($e->getMessage() === 'REDIRECT:staff/sms.php', 'Unexpected redirect: ' . $e->getMessage()); }
    $notice = take_sms_notice();
    sa_assert(($notice['state'] ?? '') === 'sent' && $status($l909) === 'SENT', 'A retryable failed text was not resent.');
    sa_assert(!in_array($l909, $ids($smsLog->needingAttention(SMS_MAX_ATTEMPTS)), true) && staff_sms_attention_count() === 4, 'A resent text stayed on the list.');
    $_POST = ['csrf_token' => str_repeat('b', 64), 'form_action' => 'retry_sms', 'sms_log_id' => (string) $l901];
    try { $controller->smsAttention(); } catch (RuntimeException $e) { /* redirect */ }
    sa_assert($status($l901) === 'SENT' && staff_sms_attention_count() === 3, 'The retry for an interview text did not work.');

    // ---- The page ----
    $_POST = []; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REQUEST_URI'] = '/staff/sms.php'; $_SERVER['SCRIPT_NAME'] = '/staff/sms.php';
    ob_start(); $controller->smsAttention(); $html = ob_get_clean();
    sa_assert(str_contains($html, 'Failed Texts') && substr_count($html, 'Retry SMS') === 1, 'The page should offer exactly one Retry (the other two failures cannot be retried).');
    sa_assert(str_contains($html, 'Application #902') && str_contains($html, 'Application #906') && !str_contains($html, 'Application #901'), 'The page lists the wrong applications.');
    sa_assert(str_contains($html, 'Contact the Solo Parent another way') && str_contains($html, 'confirm the correction text again'), 'The page does not explain the texts that cannot be retried.');
    sa_assert(str_contains($html, '3 failed texts'), 'The page does not show the count.');
    foreach ([$l902, $l906, $l908] as $id) { $test->exec('UPDATE sms_logs SET status = \'SENT\' WHERE id = ' . $id); }
    ob_start(); $controller->smsAttention(); $html = ob_get_clean();
    sa_assert(str_contains($html, 'No failed texts need attention') && staff_sms_attention_count() === 0, 'An empty list is not shown as empty.');

    // ---- Cancel / missed return the real SMS result and raise the alert ----
    $userId = sa_row($test, 'users', ['full_name' => 'Fixture Parent', 'username' => 'fixture.parent', 'email' => 'fixture.parent@example.test', 'role' => 'applicant', 'mobile' => '09123456789', 'password_hash' => 'x']);
    $mkAppointment = function (string $ref, string $when) use ($test, $userId): int {
        $appId = sa_row($test, 'applications', ['user_id' => $userId, 'reference_no' => $ref, 'status' => 'Under Verification', 'type' => 'new', 'mobile' => '09123456789', 'full_name' => 'Fixture Parent']);
        return sa_row($test, 'appointments', ['application_id' => $appId, 'scheduled_at' => $when, 'location' => 'Fixture Office', 'status' => 'BOOKED']);
    };
    $service = new AppointmentService($test);
    $apptMissed = $mkAppointment('VIRAC-SP-FIXTURE-1', '2020-01-01 09:00:00');
    $sms = null;
    sa_assert($service->staffSetOutcome($staff, $apptMissed, 'MISSED', '', $sms) === null, 'Marking a past appointment missed failed.');
    sa_assert(is_array($sms) && ($sms['status'] ?? '') === 'SENT', 'A missed interview did not return its SMS result.');
    $apptCancel = $mkAppointment('VIRAC-SP-FIXTURE-2', '2030-01-01 09:00:00');
    $sms = null;
    sa_assert($service->staffSetOutcome($staff, $apptCancel, 'CANCELLED', 'Office closed', $sms) === null && is_array($sms) && ($sms['status'] ?? '') === 'SENT', 'A cancellation did not return its SMS result.');
    // A failing gateway (template switched off here) is reported, not hidden.
    $apptOff = $mkAppointment('VIRAC-SP-FIXTURE-3', '2030-02-01 09:00:00');
    $test->exec("UPDATE sms_templates SET is_enabled = 0 WHERE template_key = 'appointment_cancelled'");
    $sms = null;
    sa_assert($service->staffSetOutcome($staff, $apptOff, 'CANCELLED', 'Office closed', $sms) === null && is_array($sms) && ($sms['status'] ?? '') === 'DISABLED', 'A switched-off SMS was not reported.');
    $notice = SMSService::outcomeNotice($sms, 'Appointment cancelled');
    sa_assert($notice['state'] === 'skipped', 'A switched-off SMS must show as "SMS not sent".');
    // The staff action itself (POST) raises the notice.
    $test->exec("UPDATE sms_templates SET is_enabled = 1 WHERE template_key = 'appointment_cancelled'");
    $apptPost = $mkAppointment('VIRAC-SP-FIXTURE-4', '2030-03-01 09:00:00');
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_SESSION['csrf_token'] = str_repeat('c', 64);
    $_POST = ['csrf_token' => str_repeat('c', 64), 'action' => 'cancel', 'appointment_id' => (string) $apptPost, 'reason' => 'Office closed'];
    try { $controller->appointments(); sa_assert(false, 'The cancellation did not redirect.'); } catch (RuntimeException $e) { sa_assert($e->getMessage() === 'REDIRECT:staff/appointments.php', 'Unexpected redirect: ' . $e->getMessage()); }
    $notice = take_sms_notice();
    sa_assert(($notice['state'] ?? '') === 'sent', 'Cancelling an appointment raised no SMS alert for staff.');

    echo "PASS: failed-text list, retry safety checks, resend through the page, menu count, and the cancel/missed SMS alert.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
