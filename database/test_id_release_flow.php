<?php
/**
 * ID release (SYNTHETIC data, disposable database):
 *   - Confirm handover needs the start and expiration dates printed on the card and the in-person tick; bad dates are refused;
 *   - a required document that expired while the ID waited can be overridden only with a reason;
 *   - the dates can be corrected with a reason;
 *   - one SMS on the exact expiry date: not before, not after, never twice; a failed SMS is retried the same day;
 *   - a day that was missed is listed for staff (Send now), never sent by itself.
 *
 *   php database/test_id_release_flow.php   (isolated test environment only)
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
    require_once __DIR__ . '/../services/IdReleaseService.php';
    require_once __DIR__ . '/../services/RenewalService.php';
    $test->exec('SET FOREIGN_KEY_CHECKS=0');
    // The new column (database/migrate_id_valid_from.sql), added to this throwaway copy if the test instance does not have it yet.
    foreach (explode(';', (string) preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/migrate_id_valid_from.sql'))) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') { $test->exec($statement); }
    }
    sa_assert(Application::validFromReady($test), 'The id_valid_from column should exist after the migration.');
    set_setting($test, 'sms_enabled', '1');

    $staffId = sa_row($test, 'users', ['full_name' => 'Staff Fixture', 'role' => 'mswd_social_worker', 'username' => 'staff_fx_' . bin2hex(random_bytes(3))]);
    $staff = ['id' => $staffId, 'full_name' => 'Staff Fixture', 'role' => 'mswd_social_worker'];
    $catId = sa_row($test, 'categories', ['name' => 'Release fixture ' . bin2hex(random_bytes(2))]);
    $today = RenewalService::manilaToday();
    $mk = function (string $ref, string $status = 'Approved', ?string $releaseAt = null, string $mobile = '09123456789') use ($test, $catId): array {
        $uid = sa_row($test, 'users', ['full_name' => 'Parent ' . $ref, 'role' => 'applicant', 'username' => 'p_' . bin2hex(random_bytes(3)), 'is_active' => 1]);
        $app = sa_row($test, 'applications', ['reference_no' => $ref, 'user_id' => $uid, 'full_name' => 'Parent ' . $ref, 'status' => $status, 'type' => 'new', 'mobile' => $mobile, 'category_id' => $catId, 'renewal_simplified' => 0]);
        if ($releaseAt !== null) {
            sa_row($test, 'id_release_schedules', ['application_id' => $app, 'scheduled_at' => $releaseAt, 'location' => 'MSWDO Office', 'sms_message' => 'x']);
        }
        return [$app, $uid];
    };
    $row = fn(int $id) => $test->query("SELECT * FROM applications WHERE id = $id")->fetch();
    $service = new IdReleaseService($test);
    $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
    $inAYear = date('Y-m-d', strtotime($today . ' +1 year'));

    // ---- Confirm handover ----
    [$a1] = $mk('REL-1', 'Approved', $yesterday . ' 09:00:00');
    $err = $service->confirmRelease($staff, $row($a1), ['valid_from' => $today, 'expires_at' => $inAYear, 'identity_checked' => false]);
    sa_assert(is_string($err) && str_contains($err, 'in person'), 'The in-person tick must be required.');
    $err = $service->confirmRelease($staff, $row($a1), ['valid_from' => $today, 'expires_at' => $yesterday, 'identity_checked' => true]);
    sa_assert(is_string($err) && str_contains($err, 'after the start date'), 'An expiry before the start must be refused.');
    $err = $service->confirmRelease($staff, $row($a1), ['valid_from' => date('Y-m-d', strtotime($today . ' +3 days')), 'expires_at' => $inAYear, 'identity_checked' => true]);
    sa_assert(is_string($err) && str_contains($err, 'later than today'), 'A start date in the future must be refused.');
    $err = $service->confirmRelease($staff, $row($a1), ['valid_from' => $today, 'expires_at' => date('Y-m-d', strtotime($today . ' +9 years')), 'identity_checked' => true]);
    sa_assert(is_string($err) && str_contains($err, 'five years'), 'A typo far in the future must be refused.');
    $cardFrom = date('Y-m-d', strtotime($today . ' -2 days'));
    $cardTo = date('Y-m-d', strtotime($cardFrom . ' +1 year -1 day'));
    $err = $service->confirmRelease($staff, $row($a1), ['valid_from' => $cardFrom, 'expires_at' => $cardTo, 'identity_checked' => true]);
    sa_assert($err === null, 'A valid handover failed: ' . var_export($err, true));
    $r1 = $row($a1);
    sa_assert($r1['status'] === 'ID Released' && $r1['id_valid_from'] === $cardFrom && $r1['id_expires_at'] === $cardTo, 'The dates from the card must be saved exactly.');
    $hist = (string) $test->query("SELECT remarks FROM application_status_history WHERE application_id = $a1 ORDER BY id DESC LIMIT 1")->fetchColumn();
    sa_assert(str_contains($hist, 'in person') && str_contains($hist, 'Valid from'), 'The history must record the handover and the dates.');
    sa_assert(is_string($service->confirmRelease($staff, $r1, ['valid_from' => $today, 'expires_at' => $inAYear, 'identity_checked' => true])), 'A second handover must be refused.');

    // Older callers (no details): today and one year later.
    [$a2] = $mk('REL-2', 'Approved', $yesterday . ' 09:00:00');
    sa_assert($service->confirmRelease($staff, $row($a2)) === null, 'The handover without details failed.');
    sa_assert($row($a2)['id_expires_at'] === date('Y-m-d', strtotime(date('Y-m-d') . ' +1 year')), 'Without details the ID is valid for one year.');

    // ---- A required document that is no longer verified: release only with a reason ----
    [$a3] = $mk('REL-3', 'Approved', $yesterday . ' 09:00:00');
    sa_row($test, 'document_requirements', ['category_id' => $catId, 'doc_key' => 'fixtureDoc', 'label' => 'Fixture certificate', 'is_required' => 1]);
    $err = $service->confirmRelease($staff, $row($a3), ['valid_from' => $today, 'expires_at' => $inAYear, 'identity_checked' => true]);
    sa_assert(is_string($err) && str_contains($err, 'reason'), 'Without a reason an expired document must block: ' . var_export($err, true));
    $err = $service->confirmRelease($staff, $row($a3), ['valid_from' => $today, 'expires_at' => $inAYear, 'identity_checked' => true, 'override_reason' => 'Shown at the counter']);
    sa_assert($err === null, 'The handover with a reason failed: ' . var_export($err, true));
    $hist = (string) $test->query("SELECT remarks FROM application_status_history WHERE application_id = $a3 ORDER BY id DESC LIMIT 1")->fetchColumn();
    sa_assert(str_contains($hist, 'Shown at the counter'), 'The override reason must be recorded.');
    $test->exec("DELETE FROM document_requirements WHERE doc_key = 'fixtureDoc'");

    // ---- Correcting the dates ----
    sa_assert(is_string($service->correctDates($staff, $row($a1), $cardFrom, $inAYear, '')), 'A correction needs a reason.');
    sa_assert(is_string($service->correctDates($staff, $row($a1), $cardFrom, $cardTo, 'same')), 'Unchanged dates must be refused.');
    sa_assert($service->correctDates($staff, $row($a1), $cardFrom, $inAYear, 'Typed the year wrong') === null, 'A correction failed.');
    sa_assert($row($a1)['id_expires_at'] === $inAYear, 'The corrected expiry must be saved.');

    // ---- The expiry-day SMS ----
    $renewals = new RenewalService($test);
    $smsCount = fn() => (int) $test->query("SELECT COUNT(*) FROM sms_logs WHERE template_key IN ('renewal_reminder','renewal_expired')")->fetchColumn();
    [$eToday, $uToday] = $mk('EXP-TODAY', 'ID Released');
    [$eTomorrow] = $mk('EXP-TOMORROW', 'ID Released');
    [$eYesterday] = $mk('EXP-YESTERDAY', 'ID Released');
    [$eBad, $uBad] = $mk('EXP-BADNUMBER', 'ID Released', null, 'not-a-number');
    [$eAdvance] = $mk('EXP-ADVANCE', 'ID Released');
    $test->exec("UPDATE applications SET id_expires_at = '$today' WHERE id IN ($eToday, $eBad, $eAdvance)");
    $test->exec("UPDATE applications SET id_expires_at = '" . date('Y-m-d', strtotime($today . ' +1 day')) . "' WHERE id = $eTomorrow");
    $test->exec("UPDATE applications SET id_expires_at = '$yesterday' WHERE id = $eYesterday");
    // An advance reminder from the old 30-day rule for the same expiry must not stop the expiry-day SMS.
    $test->prepare("INSERT INTO renewal_reminders (application_id, id_expires_at, user_id, days_before, status) VALUES (?, ?, NULL, 30, 'SENT')")->execute([$eAdvance, $today]);

    $before = $smsCount();
    $run = $renewals->sendDueReminders('cron', $today);
    sa_assert(in_array('EXP-TODAY', $run['sent'], true) && in_array('EXP-ADVANCE', $run['sent'], true), 'IDs expiring today must get their SMS: ' . json_encode($run));
    sa_assert(!in_array('EXP-TOMORROW', $run['sent'], true) && !in_array('EXP-YESTERDAY', $run['sent'], true), 'Nothing is sent before or after the expiry date.');
    sa_assert(in_array('EXP-BADNUMBER', $run['failed'], true), 'A failed SMS must be reported as failed.');
    $msg = (string) $test->query("SELECT message FROM notifications WHERE user_id = $uToday ORDER BY id DESC LIMIT 1")->fetchColumn();
    sa_assert(str_contains($msg, 'expires today') && str_contains($msg, 'renew online starting today'), 'The in-app notice must say the ID expires today and renewal is open: ' . $msg);
    $smsToday = (string) $test->query("SELECT message FROM sms_logs WHERE application_id = $eToday ORDER BY id DESC LIMIT 1")->fetchColumn();
    sa_assert(str_contains($smsToday, 'expires today') && !str_contains($smsToday, 'expired on'), 'The expiry-day SMS says it expires today: ' . $smsToday);
    $afterFirst = $smsCount();
    sa_assert((int) $test->query("SELECT COUNT(*) FROM sms_logs WHERE application_id IN ($eToday, $eAdvance) AND status = 'SENT'")->fetchColumn() === 2, 'Exactly one SMS per ID that expires today.');
    sa_assert($test->query("SELECT status FROM renewal_reminders WHERE application_id = $eBad")->fetchColumn() === 'FAILED', 'The failed SMS must be marked for a same-day retry.');

    // The same day again: nothing more for the sent ones; the failed one is retried, and its in-app notice is not repeated.
    $run2 = $renewals->sendDueReminders('cron', $today);
    sa_assert($run2['sent'] === [] && $run2['failed'] === ['EXP-BADNUMBER'], 'Only the failed SMS is retried the same day: ' . json_encode($run2));
    sa_assert((int) $test->query("SELECT COUNT(*) FROM notifications WHERE user_id = $uBad")->fetchColumn() === 1, 'The in-app notice must not repeat on a retry.');
    $renewals->sendDueReminders('cron', $today);
    sa_assert($renewals->sendDueReminders('cron', $today)['checked'] === 0, 'After the last attempt nothing more is tried.');
    // The next day: nothing is sent by itself.
    $nextDay = $renewals->sendDueReminders('cron', date('Y-m-d', strtotime($today . ' +1 day')));
    sa_assert($nextDay['sent'] === ['EXP-TOMORROW'] && $nextDay['failed'] === [], 'The next day only the ID expiring that day gets its SMS; no catch-up: ' . json_encode($nextDay));

    // ---- Missed days are listed for staff; Send now sends once ----
    $notSent = array_column($renewals->expirySmsNotSent(30, $today), 'reference_no');
    sa_assert(in_array('EXP-YESTERDAY', $notSent, true) && !in_array('EXP-TODAY', $notSent, true), 'A missed expiry day must be listed for staff: ' . json_encode($notSent));
    [$nowErr, $nowSms] = $renewals->sendExpirySmsNow($staff, $eYesterday);
    sa_assert($nowErr === null && ($nowSms['status'] ?? '') === 'SENT', 'Send now failed: ' . var_export($nowErr, true));
    $smsLate = (string) $test->query("SELECT message FROM sms_logs WHERE application_id = $eYesterday ORDER BY id DESC LIMIT 1")->fetchColumn();
    sa_assert(str_contains($smsLate, 'expired on') && !str_contains($smsLate, 'expires today'), 'A missed day sent late says it expired on the date: ' . $smsLate);
    sa_assert(!in_array('EXP-YESTERDAY', array_column($renewals->expirySmsNotSent(30, $today), 'reference_no'), true), 'A sent SMS must leave the list.');
    [$againErr] = $renewals->sendExpirySmsNow($staff, $eYesterday);
    sa_assert(is_string($againErr), 'Send now must not send twice.');

    // ---- Overdue release dates ----
    [$late] = $mk('REL-LATE', 'Approved', date('Y-m-d H:i:s', strtotime('-3 days')));
    sa_assert(in_array('REL-LATE', array_column($service->overdue(), 'reference_no'), true) && $service->overdueCount() >= 1, 'A passed release date must be counted.');

    echo "PASS: ID release: card dates and in-person check, expired-document override with reason, date correction, one SMS on the exact expiry date (retried the same day, never repeated), missed days listed for Send now, overdue releases counted.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
