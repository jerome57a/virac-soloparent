<?php
/**
 * The MSWDO staff and MSWDO admin side menus (SYNTHETIC data, disposable database): the groups and items of each role, nothing of the
 * other role, and the number badges for waiting work.
 *
 *   php database/test_side_menu.php   (isolated test environment only)
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
    require_once __DIR__ . '/../includes/staff-nav.php';
    $test->exec('SET FOREIGN_KEY_CHECKS=0');
    $keys = fn(array $groups) => array_map(fn($g) => array_column($g['items'], 'key'), array_column($groups, null, 'label'));
    $staff = ['id' => 1, 'full_name' => 'Staff', 'role' => 'mswd_social_worker'];
    $admin = ['id' => 2, 'full_name' => 'Admin', 'role' => 'admin'];

    $s = staff_nav_groups($staff, 3);
    sa_assert(array_column($s, 'label') === ['Work', 'Records', 'Account'], 'Staff groups: ' . json_encode(array_column($s, 'label')));
    $sk = $keys($s);
    sa_assert($sk['Work'] === ['dashboard', 'applications', 'verification', 'appointments'], 'Staff work items: ' . json_encode($sk['Work']));
    sa_assert($sk['Records'] === ['applicants', 'reports'] && $sk['Account'] === ['notifications', 'account'], 'Staff records/account items.');
    $everyStaffKey = array_merge(...array_values($sk));
    sa_assert(!array_intersect($everyStaffKey, ['monitoring', 'users', 'adminsms', 'settings', 'audit', 'eligibility']), 'Staff must not see admin-only items.');
    $notif = array_values(array_filter($s[2]['items'], fn($i) => $i['key'] === 'notifications'))[0];
    sa_assert($notif['badge'] === 0, 'The staff menu has no coloured count on Notifications (the bell in the header counts).');
    sa_assert(!in_array('sms', $sk['Work'], true), 'The staff menu has no Failed Texts entry.');

    $a = staff_nav_groups($admin, 0);
    sa_assert(array_column($a, 'label') === ['Overview', 'Monitor', 'Manage', 'Security', 'Account'], 'Admin groups: ' . json_encode(array_column($a, 'label')));
    $ak = $keys($a);
    sa_assert($ak['Overview'] === ['dashboard', 'notifications'], 'Admin overview items.');
    sa_assert($ak['Monitor'] === ['monitoring', 'applicants', 'appointments', 'eligibility', 'reports'], 'Admin monitor items: ' . json_encode($ak['Monitor']));
    sa_assert($ak['Manage'] === ['users', 'adminsms', 'settings'] && $ak['Security'] === ['audit'], 'Admin manage/security items.');
    sa_assert(!array_intersect(array_merge(...array_values($ak)), ['verification', 'sms', 'applications']), 'Admin must not see the staff work items.');

    // Badges follow the waiting work.
    $badge = function (array $groups, string $key) { foreach ($groups as $g) { foreach ($g['items'] as $i) { if ($i['key'] === $key) { return $i['badge']; } } } return null; };
    sa_assert($badge(staff_nav_groups($staff), 'applications') === 0 && $badge(staff_nav_groups($admin), 'users') === 0, 'No badge without waiting work.');
    $catId = sa_row($test, 'categories', ['name' => 'Menu fixture ' . bin2hex(random_bytes(2))]);
    $uid = sa_row($test, 'users', ['full_name' => 'Locked Person', 'role' => 'applicant', 'username' => 'lk_' . bin2hex(random_bytes(3)), 'is_active' => 1]);
    $test->prepare("UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?")->execute([$uid]);
    sa_row($test, 'applications', ['reference_no' => 'MENU-1', 'user_id' => $uid, 'full_name' => 'Pending Person', 'status' => 'Pending Review', 'type' => 'new', 'category_id' => $catId]);
    sa_row($test, 'sms_logs', ['mobile' => '09171234567', 'message' => 'x', 'status' => 'FAILED']);
    foreach (staff_nav_groups($staff) as $g) { foreach ($g['items'] as $i) { sa_assert($i['badge'] === 0, 'The staff menu has no coloured badge: ' . $i['key']); } }
    foreach (staff_nav_groups($admin) as $g) { foreach ($g['items'] as $i) { sa_assert($i['badge'] === 0, 'The admin menu has no coloured badge: ' . $i['key']); } }

    // Every item has what the drawer needs to render it.
    $icons = staff_nav_icons();
    foreach (array_merge($s, $a) as $g) {
        foreach ($g['items'] as $i) {
            sa_assert(isset($icons[$i['key']]) && $i['href'] !== '' && $i['pages'] && $i['label'] !== '', 'Menu item incomplete: ' . $i['key']);
        }
    }
    // ---- The Solo Parent menu follows the stage ----
    require_once __DIR__ . '/../controllers/ApplicantController.php';
    $menuFor = function (int $userId) use ($test): array {
        $c = (new ReflectionClass(ApplicantController::class))->newInstanceWithoutConstructor();
        $user = $test->query("SELECT * FROM users WHERE id = $userId")->fetch();
        (new ReflectionProperty(Controller::class, 'pdo'))->setValue($c, $test);
        (new ReflectionProperty(ApplicantController::class, 'user'))->setValue($c, $user);
        (new ReflectionProperty(ApplicantController::class, 'applications'))->setValue($c, (new Application($test))->forUserDetailed($userId));
        $m = new ReflectionMethod(ApplicantController::class, 'menuState');
        return $m->invoke($c);
    };
    $newSp = fn(string $tag) => sa_row($test, 'users', ['full_name' => "Menu $tag", 'role' => 'applicant', 'username' => 'm_' . $tag . bin2hex(random_bytes(2)), 'is_active' => 1]);
    $appFor = fn(int $uid, string $status, array $more = []) => sa_row($test, 'applications', ['reference_no' => 'MN-' . bin2hex(random_bytes(3)), 'user_id' => $uid, 'full_name' => 'Menu Parent', 'status' => $status, 'type' => 'new', 'category_id' => $catId, 'submitted_at' => date('Y-m-d H:i:s')] + $more);

    $m = $menuFor($newSp('none'));
    sa_assert($m['apply'] === true && !$m['application'] && !$m['documents'] && !$m['appointment'] && !$m['myid'] && !$m['renewal'], 'A new Solo Parent sees Apply only: ' . json_encode($m));

    $u = $newSp('pending'); $appFor($u, 'Under Verification');
    $m = $menuFor($u);
    sa_assert($m['apply'] === false && $m['application'] && $m['documents'] && $m['appointment'] && !$m['myid'] && !$m['renewal'], 'After applying: My Application, Documents and Appointment replace Apply: ' . json_encode($m));

    $u = $newSp('rejected'); $appFor($u, 'Rejected');
    $m = $menuFor($u);
    sa_assert($m['apply'] === true && !$m['appointment'], 'After a rejection the Solo Parent can apply again and has no appointment: ' . json_encode($m));

    $u = $newSp('approved'); $appId = $appFor($u, 'Approved');
    sa_row($test, 'id_release_schedules', ['application_id' => $appId, 'scheduled_at' => date('Y-m-d H:i:s', strtotime('+2 days')), 'location' => 'MSWDO', 'sms_message' => 'x']);
    $m = $menuFor($u);
    sa_assert($m['myid'] === true && $m['renewal'] === false, 'Approved with a release scheduled shows My Solo Parent ID, not Renewal: ' . json_encode($m));

    $expiry = fn(string $rel) => date('Y-m-d', strtotime($rel));
    $u = $newSp('valid'); $appFor($u, 'ID Released', ['id_expires_at' => $expiry('+200 days')]);
    $m = $menuFor($u);
    sa_assert($m['myid'] === true && $m['renewal'] === false && $m['apply'] === false, 'A valid ID far from expiry: My ID, no Renewal: ' . json_encode($m));
    $u = $newSp('soon'); $appFor($u, 'ID Released', ['id_expires_at' => $expiry('+10 days')]);
    sa_assert($menuFor($u)['renewal'] === false, 'Before the expiration date there is no Renewal item (no early window).');
    $u = $newSp('due'); $appFor($u, 'ID Released', ['id_expires_at' => $expiry('today')]);
    sa_assert($menuFor($u)['renewal'] === true, 'On the expiration date the Renewal item appears.');
    $u = $newSp('expired'); $appFor($u, 'ID Released', ['id_expires_at' => $expiry('-5 days')]);
    sa_assert($menuFor($u)['renewal'] === true, 'An expired ID shows Renewal.');
    $u = $newSp('closed'); $appFor($u, 'ID Released', ['id_expires_at' => $expiry('-400 days')]);
    $m = $menuFor($u);
    sa_assert($m['apply'] === true && $m['renewal'] === false, 'After the renewal period the Solo Parent applies again: ' . json_encode($m));
    echo "PASS: side menus: staff Work/Records/Account, admin Overview/Monitor/Manage/Security/Account, no item of the other role, badges follow waiting work; the Solo Parent menu follows the stage.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
