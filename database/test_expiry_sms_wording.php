<?php
/**
 * The ID-expiry SMS wording migration (SYNTHETIC data, disposable database): the old built-in text (full and compact) is replaced, an
 * administrator's own text is left alone, the labels are renamed, and a second run changes nothing.
 *
 *   php database/test_expiry_sms_wording.php   (isolated test environment only)
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
    require_once __DIR__ . '/../services/ExpirySmsWording.php';
    $test->exec('SET FOREIGN_KEY_CHECKS=0');
    $test->exec('DELETE FROM sms_templates');
    $oldFull = 'MSWDO Virac: Your Solo Parent ID ({ref}) expires on {when}. Please visit MSWDO or apply for renewal online soon to avoid interruption.';
    $oldCompact = 'MSWDO Virac: Your Solo Parent ID ({ref}) expires on {when}. Please renew online or visit MSWDO.';
    $custom = 'Hello! Your ID {ref} expires {when}. Come to MSWDO.';
    $put = function (string $key, string $label, string $body) use ($test) {
        $test->prepare('REPLACE INTO sms_templates (template_key, label, body) VALUES (?, ?, ?)')->execute([$key, $label, $body]);
    };
    $row = fn(string $key) => $test->query("SELECT label, body FROM sms_templates WHERE template_key = '$key'")->fetch();
    $newFull = SMSService::TEMPLATES['renewal_reminder'][1];
    $newCompact = SMSService::COMPACT_TEMPLATES['renewal_reminder'][1];
    sa_assert(str_contains($newFull, 'expires today') && str_contains($newCompact, 'expires today') && !str_contains($newFull, 'soon to avoid'), 'The built-in expiry-day text says "expires today".');
    sa_assert(mb_strlen($newCompact) <= 160 && mb_strlen($newFull) <= 160, 'Both wordings fit one text message.');

    // A row that is not stored: reported, nothing created.
    sa_assert(str_contains(ExpirySmsWording::migrate($test, true)['renewal_reminder'], 'no stored row'), 'A missing row is reported.');
    sa_assert(!$row('renewal_reminder'), 'Nothing is created for a missing row.');

    // The old full wording is replaced; a check run changes nothing.
    $put('renewal_reminder', 'Renewal Reminder (auto)', $oldFull); $put('renewal_expired', 'Renewal Reminder after Expiry (auto)', 'MSWDO Virac: Your Solo Parent ID ({ref}) expired on {when}. Please renew online or visit MSWDO to keep your benefits.');
    $check = ExpirySmsWording::migrate($test, false);
    sa_assert(str_contains($check['renewal_reminder'], 'text switched') && $row('renewal_reminder')['body'] === $oldFull, 'A check run reports the change but changes nothing.');
    ExpirySmsWording::migrate($test, true);
    $r = $row('renewal_reminder');
    sa_assert($r['body'] === $newFull && $r['label'] === 'ID Expiry Day (auto)', 'The old full text and its label are replaced: ' . json_encode($r));
    sa_assert($row('renewal_expired')['label'] === 'ID Expired, Sent Late (Send now)' && str_contains($row('renewal_expired')['body'], 'expired on'), 'The late text keeps its words and gets the new label.');
    sa_assert(str_contains(ExpirySmsWording::migrate($test, true)['renewal_reminder'], 'already updated'), 'A second run changes nothing.');

    // The old compact wording becomes the new compact wording.
    $put('renewal_reminder', 'Renewal Reminder (auto)', $oldCompact);
    ExpirySmsWording::migrate($test, true);
    sa_assert($row('renewal_reminder')['body'] === $newCompact, 'The old compact text becomes the new compact text.');

    // An administrator's own text is never overwritten (the label may still be renamed).
    $put('renewal_reminder', 'Renewal Reminder (auto)', $custom);
    $report = ExpirySmsWording::migrate($test, true);
    sa_assert($row('renewal_reminder')['body'] === $custom && str_contains($report['renewal_reminder'], 'left alone'), 'An administrator\'s own text is left alone: ' . json_encode($report));

    // Which text is sent: the expiry day says "expires today"; a missed day says "expired on".
    $test->exec('DELETE FROM sms_templates');
    $sms = new SMSService($test);
    $today = date('Y-m-d');
    $sent = $sms->render('renewal_reminder', ['{ref}' => 'X-1', '{when}' => date('F j, Y')]);
    sa_assert(str_contains($sent, 'expires today') && str_contains($sent, 'X-1'), 'The expiry-day text: ' . $sent);
    $late = $sms->render('renewal_expired', ['{ref}' => 'X-1', '{when}' => date('F j, Y', strtotime('-3 days'))]);
    sa_assert(str_contains($late, 'expired on'), 'The late text: ' . $late);
    echo "PASS: expiry SMS wording: the old built-in text is replaced (full and compact), an administrator's text is left alone, labels renamed, nothing changes on a second run; the expiry day says \"expires today\", a missed day says \"expired on\".\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
