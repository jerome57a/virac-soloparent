<?php
/**
 * The "what do I do next" guidance and icons (SYNTHETIC data, disposable database): the step tracker and next action of an application at
 * every stage, the admin setup checklist, and that every icon the screens ask for exists.
 *
 *   php database/test_staff_guide.php   (isolated test environment only)
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
    require_once __DIR__ . '/../services/StaffGuideService.php';
    require_once __DIR__ . '/../models/Appointment.php';
    require_once __DIR__ . '/../includes/icons.php';
    $test->exec('SET FOREIGN_KEY_CHECKS=0');

    // ---- The step tracker and the next action at every stage ----
    $guide = fn(array $app, ?array $interview = null, int $outstanding = 0) => StaffGuideService::applicationGuide($app + ['renewal_simplified' => 0], $interview, $outstanding);
    $states = fn(array $g) => implode(',', array_map(fn($s) => $s['key'] . ':' . $s['state'], $g['steps']));
    $done = ['status' => 'COMPLETED', 'result' => 'Successful', 'result_recorded_at' => date('Y-m-d H:i:s')];

    $g = $guide(['status' => 'Pending Review']);
    sa_assert($states($g) === 'review:now,verify:todo,interview:todo,decision:todo,release:todo', 'Pending Review: ' . $states($g));
    sa_assert($g['next']['href'] === '#checklist', 'Pending Review points to the documents.');

    $g = $guide(['status' => 'Under Verification'], null, 3);
    sa_assert($states($g) === 'review:done,verify:now,interview:todo,decision:todo,release:todo', 'Documents to verify: ' . $states($g));
    sa_assert(str_ends_with($g['next']['text'], ': 3'), 'The next card says how many documents are still to verify.');

    $g = $guide(['status' => 'For Revision'], null, 2);
    sa_assert($g['next']['title'] === 'Waiting for the Solo Parent' && $g['next']['tone'] === 'amber', 'For Revision: waiting for the Solo Parent.');

    $g = $guide(['status' => 'Under Verification'], null, 0);
    sa_assert($states($g) === 'review:done,verify:done,interview:now,decision:todo,release:todo' && $g['next']['label'] === 'Schedule the interview' && $g['next']['href'] === '#schedule-interview', 'Verified, no interview: schedule it.');

    $g = $guide(['status' => 'Under Verification'], ['status' => 'BOOKED', 'result' => null], 0);
    sa_assert($g['next']['label'] === 'Approve or reject', 'A booked interview: approve or reject.');
    $g = $guide(['status' => 'Under Verification'], ['status' => 'MISSED', 'result' => null], 0);
    sa_assert($g['next']['label'] === 'Schedule another interview', 'A missed interview: schedule another.');
    $g = $guide(['status' => 'Under Verification'], ['status' => 'COMPLETED', 'result' => 'Unsuccessful: documents unclear', 'result_recorded_at' => date('Y-m-d H:i:s')], 0);
    sa_assert($g['next']['title'] === 'The interview was unsuccessful', 'An unsuccessful interview offers corrections, another interview or reject.');

    $g = $guide(['status' => 'Under Verification'], $done, 0);
    sa_assert($states($g) === 'review:done,verify:done,interview:done,decision:now,release:todo' && $g['next']['href'] === '#decision', 'Successful interview: decide.');

    $g = $guide(['status' => 'Approved'], $done, 0);
    sa_assert($states($g) === 'review:done,verify:done,interview:done,decision:done,release:now' && $g['next']['label'] === 'Open ID release', 'Approved: release the ID.');
    $g = $guide(['status' => 'ID Released'], $done, 0);
    sa_assert(!str_contains($states($g), 'todo') && !str_contains($states($g), 'now') && $g['next']['label'] === '', 'ID Released: every step done, nothing more to do.');
    $g = $guide(['status' => 'Rejected'], null, 0);
    sa_assert($g['steps'] === [] && $g['next'] === null && $g['closed'] !== null, 'Rejected: no tracker, a closing note.');

    $g = StaffGuideService::applicationGuide(['status' => 'Under Verification', 'renewal_simplified' => 1], null, 0);
    sa_assert($states($g) === 'review:done,verify:done,decision:now,release:todo', 'A simplified renewal has no interview step: ' . $states($g));

    // ---- The admin setup checklist follows the real setup ----
    $test->exec('DELETE FROM users WHERE role NOT IN (\'applicant\')');
    $test->exec('DELETE FROM document_requirements');
    $test->exec('DELETE FROM sms_logs');
    $test->exec("DELETE FROM system_settings WHERE setting_key = 'renewal_grace_days'");
    $c = StaffGuideService::adminSetupChecklist($test);
    sa_assert($c['total'] === 5 && $c['done'] === 0, 'A new system: nothing is done yet: ' . json_encode(array_column($c['steps'], 'done')));
    sa_row($test, 'users', ['full_name' => 'Staff Fixture', 'role' => 'mswd_social_worker', 'username' => 'sg_' . bin2hex(random_bytes(3)), 'is_active' => 1]);
    sa_row($test, 'document_requirements', ['doc_key' => 'guide_fixture', 'label' => 'Fixture', 'is_required' => 1]);
    sa_row($test, 'sms_logs', ['mobile' => '09171234567', 'message' => 'x', 'status' => 'SENT']);
    $test->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('renewal_grace_days', '365')");
    $c = StaffGuideService::adminSetupChecklist($test);
    sa_assert(array_column($c['steps'], 'done') === [true, true, false, true, true], 'Each step follows the data: ' . json_encode(array_column($c['steps'], 'done')));
    sa_assert($c['done'] === 4, 'Four of five steps are done (the SMS provider is the test one here).');

    // ---- Icons: every icon a screen asks for exists ----
    sa_assert(str_contains(ui_icon('file-check', 20), '<svg') && ui_icon('no-such-icon') === '', 'ui_icon returns an svg, and nothing for an unknown name.');
    $missing = [];
    $files = array_merge(glob(__DIR__ . '/../views/*/*.php'), glob(__DIR__ . '/../views/*/*/*.php'), glob(__DIR__ . '/../includes/*.php'), glob(__DIR__ . '/../services/StaffGuideService.php'));
    $set = ui_icon_set();
    foreach ($files as $file) {
        $code = file_get_contents($file);
        preg_match_all("/ui_icon\(\s*\\\\?'([a-z0-9-]+)\\\\?'/", $code, $m1);
        preg_match_all("/(?:'icon' =>|\\\$next\()[^']*'([a-z0-9-]+)'/", $code, $m2);
        foreach ($m1[1] as $n) { if (!isset($set[$n])) { $missing[$n] = basename($file); } }
    }
    foreach (['house', 'file-text', 'file-check', 'calendar-check', 'phone', 'message-square-warning', 'user', 'chart-column', 'bell', 'key-round', 'clipboard-list', 'users', 'settings', 'list-checks', 'scroll-text', 'shield-check', 'circle-check', 'circle-dot', 'circle-x', 'calendar-plus', 'id-card', 'clipboard-check', 'clock', 'send', 'lock', 'triangle-alert', 'refresh-cw', 'message-square', 'arrow-right', 'chevron-right', 'circle-help', 'info', 'search', 'rotate-cw'] as $n) {
        if (!isset($set[$n])) { $missing[$n] = 'icon set'; }
    }
    foreach (StaffGuideService::STEPS as [$label, $icon]) { if (!isset($set[$icon])) { $missing[$icon] = 'tracker'; } }
    sa_assert(!$missing, 'Icons asked for but missing: ' . json_encode($missing));
    echo "PASS: guidance: the tracker and next action at every stage, the admin setup checklist follows the data, and every icon exists.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
