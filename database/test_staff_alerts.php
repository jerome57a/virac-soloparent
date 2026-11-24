<?php
/**
 * Live staff alerts, document remarks and the Solo Parent dashboard ID card (SYNTHETIC data, disposable database):
 *   - a staff alert opens the exact place: the document's row, Supporting Documents or the Interview card (also older alerts);
 *   - the live check returns only unread alerts newer than the last one seen, oldest first; "Needs your action" types are known;
 *   - Needs Correction without a remark is refused with "Write what the Solo Parent must fix before saving.";
 *   - the Appointments menu badge counts interviews still booked today;
 *   - the dashboard ID card shows only the ID number, category and dates (never the name or address), with the right badge per state.
 *
 *   php database/test_staff_alerts.php   (isolated test environment only)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
$testDb = 'solo_review_test_' . bin2hex(random_bytes(6));
validate_temporary_database_target($testDb, 'solo_review_test_');
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/ApplicationReviewService.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../includes/sms-attention.php';
require_once __DIR__ . '/../includes/icons.php';

function sl_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }

function sl_row(PDO $pdo, string $table, array $given): int
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
            default => 'x' . bin2hex(random_bytes(3)),
        };
    }
    $cols = array_map(fn($c) => '`' . $c . '`', array_keys($values));
    $pdo->prepare('INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')')->execute(array_values($values));
    return (int) $pdo->lastInsertId();
}

/** Renders the dashboard ID card partial with the given data. */
function sl_card(array $vars): string
{
    extract($vars);
    ob_start();
    require APP_ROOT . '/views/partials/dashboard-id-card.php';
    return (string) ob_get_clean();
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
    unset($GLOBALS['__settings_cache']);
    $test->exec("INSERT INTO roles (id, role_key, code, name) VALUES (1,'applicant','APP','Solo Parent'),(2,'mswd_social_worker','MSWD','MSWDO Staff'),(3,'admin','ADM','Admin')");
    $staffId = sl_row($test, 'users', ['role' => 'mswd_social_worker', 'full_name' => 'Fixture Staff', 'is_active' => 1]);
    $parentId = sl_row($test, 'users', ['role' => 'applicant', 'full_name' => 'Fixture Parent', 'is_active' => 1]);
    $cat = sl_row($test, 'categories', ['name' => 'Fixture category', 'is_active' => 1]);
    $appId = sl_row($test, 'applications', ['reference_no' => 'FX-ALERT-1', 'user_id' => $parentId, 'type' => 'new', 'category_id' => $cat, 'status' => 'Under Verification', 'full_name' => 'Fixture Parent']);
    $service = new NotificationService($test);
    $model = new Notification($test);
    $links = fn() => $test->query("SELECT type, link FROM notifications WHERE user_id = $staffId ORDER BY id DESC LIMIT 1")->fetch();

    // 1. Each alert opens the exact place.
    $service->notifyStaff('document_resubmitted', 'Re-uploaded.', $appId, 'doc-42');
    sl_assert($links()['link'] === "staff/application.php?id=$appId#doc-42", 'A re-uploaded document opens its own row: ' . $links()['link']);
    $service->notifyStaff('new_application', 'New.', $appId);
    sl_assert($links()['link'] === "staff/application.php?id=$appId#checklist", 'A new application opens Supporting Documents.');
    $service->notifyStaff('appointment_update', 'Booked.', $appId);
    sl_assert($links()['link'] === "staff/application.php?id=$appId#interview", 'An interview change opens the Interview card.');
    $service->notifyStaff('new_renewal', 'Renewal.', $appId, 'bad anchor"><script>');
    sl_assert($links()['link'] === "staff/application.php?id=$appId#checklist", 'An unsafe anchor is never stored.');
    $old = ['type' => 'corrections_submitted', 'link' => "staff/application.php?id=$appId"];
    sl_assert(NotificationService::linkFor($old, 'mswd_social_worker') === "staff/application.php?id=$appId#checklist", 'An older alert without an anchor gets one.');
    sl_assert(NotificationService::linkFor(['type' => 'correction', 'link' => null], 'applicant') === 'applicant/dashboard.php?section=documents', 'Solo Parent links are unchanged.');
    sl_assert(NotificationService::isAction(['type' => 'document_resubmitted']) && !NotificationService::isAction(['type' => 'status_change']), 'Action types are known.');

    // 2. The live check: only unread and newer than the last seen, oldest first.
    $all = $test->query("SELECT id FROM notifications WHERE user_id = $staffId ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    sl_assert($model->latestId($staffId) === (int) end($all), 'latestId is the newest notification.');
    sl_assert($model->latestId($parentId) === 0, 'latestId is 0 without notifications.');
    $newer = $model->unreadNewerThan($staffId, (int) $all[1]);
    sl_assert(array_map('intval', array_column($newer, 'id')) === [(int) $all[2], (int) $all[3]], 'Only newer ones, oldest first.');
    $model->markRead($staffId, (int) $all[2]);
    sl_assert(array_map('intval', array_column($model->unreadNewerThan($staffId, (int) $all[1]), 'id')) === [(int) $all[3]], 'A read one is not shown again.');
    sl_assert(count($model->unreadNewerThan($staffId, 0, 2)) === 2, 'At most the limit is returned.');
    set_setting($test, 'notify_staff_new_work', '0');
    unset($GLOBALS['__settings_cache']);
    sl_assert($service->notifyStaff('new_application', 'Off.', $appId) === 0, 'No alert when staff alerts are off in Settings.');
    set_setting($test, 'notify_staff_new_work', '1');
    unset($GLOBALS['__settings_cache']);

    // 3. Needs Correction without a remark is refused with the message the page shows.
    $docId = sl_row($test, 'application_documents', ['application_id' => $appId, 'doc_key' => 'birthCert', 'original_name' => 'b.jpg', 'stored_path' => 'x/b.jpg', 'mime' => 'image/jpeg', 'verification_status' => 'FOR_VERIFICATION', 'size_bytes' => 1000]);
    $file = $test->query("SELECT * FROM application_documents WHERE id = $docId")->fetch();
    $app = $test->query("SELECT * FROM applications WHERE id = $appId")->fetch();
    $staff = ['id' => $staffId, 'role' => 'mswd_social_worker', 'full_name' => 'Fixture Staff'];
    $result = (new ApplicationReviewService($test))->saveVerification($staff, $app, [$docId => ['status' => 'NEEDS_CORRECTION', 'remarks' => '  ', 'expected' => 'FOR_VERIFICATION', 'expected_remarks' => '', 'expected_version' => Document::versionToken($file)]]);
    sl_assert(($result['errors'][$docId] ?? '') === 'Write what the Solo Parent must fix before saving.', 'An empty remark is refused: ' . json_encode($result['errors']));
    sl_assert($test->query("SELECT verification_status FROM application_documents WHERE id = $docId")->fetchColumn() === 'FOR_VERIFICATION', 'Nothing is saved.');
    $ok = (new ApplicationReviewService($test))->saveVerification($staff, $app, [$docId => ['status' => 'NEEDS_CORRECTION', 'remarks' => 'The photo is blurry.', 'expected' => 'FOR_VERIFICATION', 'expected_remarks' => '', 'expected_version' => Document::versionToken($file)]]);
    sl_assert(!$ok['errors'] && $test->query("SELECT verification_status FROM application_documents WHERE id = $docId")->fetchColumn() === 'NEEDS_CORRECTION', 'With a remark it is saved.');

    // 4. The Appointments menu badge counts interviews still booked today.
    $before = staff_today_booked_count();
    sl_row($test, 'appointments', ['application_id' => $appId, 'scheduled_at' => date('Y-m-d 23:59:00'), 'status' => 'BOOKED', 'location' => 'MSWDO']);
    sl_assert(staff_today_booked_count() === $before + 1, 'A booking later today is counted.');

    // 5. The dashboard ID card: only the ID number, category and dates.
    $released = ['reference_no' => 'VSP-2026-000123', 'status' => 'ID Released', 'id_expires_at' => date('Y-m-d', strtotime('+100 days')), 'category_name' => 'Death of the spouse',
        'full_name' => 'Maria Secret Santos', 'address' => '12 Hidden Street', 'barangay' => 'Secret Barangay'];
    $base = ['idApplication' => $released, 'viewableIdApplication' => $released, 'latestApplication' => $released];
    $html = sl_card($base + ['renewal' => ['state' => 'valid', 'daysLeft' => 100, 'expiresAt' => $released['id_expires_at']]]);
    sl_assert(str_contains($html, 'VSP-2026-000123') && str_contains($html, 'Death of the spouse') && str_contains($html, format_date_long($released['id_expires_at'])), 'The card shows the ID number, category and valid-until date.');
    sl_assert(!str_contains($html, 'Maria') && !str_contains($html, 'Secret') && !str_contains($html, 'Hidden'), 'The card never shows the name or address.');
    sl_assert(str_contains($html, '>SAMPLE<') && str_contains($html, 'Preview only. Your physical card is your official ID.') && str_contains($html, 'is-ok') && str_contains($html, 'Active'), 'Active, with the SAMPLE stamp and the preview line.');
    sl_assert(str_contains($html, 'section=myid&amp;ref=VSP-2026-000123'), 'Tapping opens My ID.');
    $due = sl_card(['idApplication' => ['id_expires_at' => date('Y-m-d')] + $released] + $base + ['renewal' => ['state' => 'due', 'daysLeft' => 0, 'expiresAt' => date('Y-m-d')]]);
    sl_assert(str_contains($due, 'Expires today') && str_contains($due, 'Renew My ID'), 'Expiry day: amber badge and Renew My ID.');
    $past = ['id_expires_at' => date('Y-m-d', strtotime('-5 days'))] + $released;
    $expired = sl_card(['idApplication' => $past] + $base + ['renewal' => ['state' => 'expired', 'daysLeft' => -5, 'expiresAt' => $past['id_expires_at']]]);
    sl_assert(str_contains($expired, 'is-err') && str_contains($expired, 'Expired on') && str_contains($expired, 'Renew My ID'), 'Expired: red badge, "Expired on", Renew My ID.');
    $inProgress = sl_card(['idApplication' => $past] + $base + ['renewal' => ['state' => 'in_progress', 'application' => ['reference_no' => 'VSP-REN-9', 'status' => 'Under Verification']]]);
    sl_assert(str_contains($inProgress, 'Renewal in progress') && str_contains($inProgress, 'VSP-REN-9') && str_contains($inProgress, 'Renewal Status') && str_contains($inProgress, 'Expired on'), 'Renewal in progress: blue badge, the renewal number, the old ID kept.');
    $closed = sl_card(['idApplication' => $past] + $base + ['renewal' => ['state' => 'closed', 'daysLeft' => -400, 'expiresAt' => $past['id_expires_at']]]);
    sl_assert(str_contains($closed, 'Apply Again') && !str_contains($closed, 'Renew My ID'), 'Renewal period over: Apply Again.');
    $pending = sl_card(['idApplication' => null, 'viewableIdApplication' => null, 'latestApplication' => ['reference_no' => 'VSP-NEW-7', 'status' => 'Interview Scheduled'], 'renewal' => ['state' => 'none']]);
    sl_assert(str_contains($pending, 'Being prepared') && str_contains($pending, 'IN PROCESS') && str_contains($pending, 'VSP-NEW-7') && !str_contains($pending, 'SAMPLE'), 'In process: grey card with the application number.');
    $rejected = sl_card(['idApplication' => null, 'viewableIdApplication' => null, 'latestApplication' => ['reference_no' => 'VSP-NEW-8', 'status' => 'Rejected'], 'renewal' => ['state' => 'none']]);
    sl_assert(!str_contains($rejected, 'Being prepared') && str_contains($rejected, 'Your ID details will appear here'), 'A rejected application does not show "Being prepared".');

    echo "PASS: staff alerts open the exact place (also older ones), the live check returns only new unread alerts, alerts follow the Settings switch; Needs Correction needs a remark; today's bookings are in the Appointments badge; the dashboard ID card shows only the ID number, category and dates, with the right badge for each state.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
