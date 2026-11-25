<?php
/**
 * Office check-in by reference number (services/OfficeCheckInService.php) on SYNTHETIC data in a disposable database:
 *   - finding the record by the full reference number, its last digits, a mobile number (any written form) or part of a name;
 *   - what the card offers: booked today, already checked in, booked for another day, no date, documents not verified, closed;
 *   - Arrived records the time, the staff member, an interview event, a history line and an audit entry, and alerts the OTHER staff;
 *   - checking in twice changes nothing; another day needs "move to today"; the waiting list keeps arrival order;
 *   - what was brought is written into the interview result; without the arrival columns nothing breaks and nobody can check in.
 *
 *   php database/test_office_checkin.php   (isolated test environment only)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
$testDb = 'solo_review_test_' . bin2hex(random_bytes(6));
validate_temporary_database_target($testDb, 'solo_review_test_');
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../services/OfficeCheckInService.php';
require_once __DIR__ . '/../services/ApplicationReviewService.php';
$_SESSION = $_SESSION ?? [];

function oc_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }

function oc_row(PDO $pdo, string $table, array $given): int
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
    $hasCol = fn(string $t, string $c) => (bool) $test->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND COLUMN_NAME = '$c'")->fetchColumn();
    // The isolated test instance may lack the interview-booking tables (the live database has them): create them from schema.sql.
    $schema = file_get_contents(__DIR__ . '/schema.sql');
    foreach (['interview_dates', 'interview_invitations', 'appointment_events', 'id_release_schedules'] as $t) {
        if (!$test->query("SHOW TABLES LIKE '$t'")->fetchColumn() && preg_match('/CREATE TABLE ' . $t . ' \(.*?\) ENGINE=InnoDB[^;]*;/s', $schema, $m)) {
            $test->exec($m[0]);
        }
    }
    if (!$hasCol('appointments', 'date_only')) { $test->exec('ALTER TABLE appointments ADD COLUMN date_only TINYINT(1) NOT NULL DEFAULT 0'); }
    foreach (['arrived_at', 'arrived_by'] as $c) { if ($hasCol('appointments', $c)) { $test->exec("ALTER TABLE appointments DROP COLUMN $c"); } } // start like an older database
    $test->exec("INSERT INTO roles (id, role_key, code, name) VALUES (1,'applicant','APP','Solo Parent'),(2,'mswd_social_worker','MSWD','MSWDO Staff'),(3,'admin','ADM','Admin')");
    $staffA = oc_row($test, 'users', ['role' => 'mswd_social_worker', 'full_name' => 'Counter Staff', 'is_active' => 1]);
    $staffB = oc_row($test, 'users', ['role' => 'mswd_social_worker', 'full_name' => 'Interview Staff', 'is_active' => 1]);
    $staff = ['id' => $staffA, 'full_name' => 'Counter Staff', 'role' => 'mswd_social_worker'];
    $cat = oc_row($test, 'categories', ['name' => 'Fixture category', 'is_active' => 1]);
    $today = date('Y-m-d');
    $mkApp = function (string $ref, string $name, string $mobile, string $status = 'Interview Scheduled', string $type = 'new') use ($test, $cat) {
        $user = oc_row($test, 'users', ['role' => 'applicant', 'full_name' => $name, 'mobile' => $mobile, 'is_active' => 1]);
        return oc_row($test, 'applications', ['reference_no' => $ref, 'user_id' => $user, 'type' => $type, 'category_id' => $cat, 'status' => $status, 'full_name' => $name, 'mobile' => $mobile, 'birthdate' => '1985-03-04', 'barangay' => 'Francia']);
    };
    $book = fn(int $app, string $date, string $status = 'BOOKED') => oc_row($test, 'appointments', ['application_id' => $app, 'scheduled_at' => $date . ' 00:00:00', 'date_only' => 1, 'status' => $status, 'location' => 'MSWDO', 'scheduled_by' => $staffA]);
    $a1 = $mkApp('VIRAC-SP-2026-0062', 'Edwin R. Molina', '09990002001'); $book($a1, $today);
    $a2 = $mkApp('VIRAC-SP-2026-0068', 'Elena R. Villanueva', '+639990002002', 'Interview Scheduled', 'renewal'); $book($a2, $today);
    $a3 = $mkApp('VIRAC-SP-2026-0071', 'Ana Reyes', '09990002003'); $book($a3, date('Y-m-d', strtotime('+4 days')));
    $a4 = $mkApp('VIRAC-SP-2026-0080', 'Rosa Valdez', '09990002004', 'Under Verification');                 // no date, nothing to verify
    $a5 = $mkApp('VIRAC-SP-2026-0081', 'Pedro Lim', '09990002005', 'Under Verification');
    $a6 = $mkApp('VIRAC-SP-2026-0090', 'Released Person', '09990002006', 'ID Released');
    // One required document for the (active) category: verified for a4, still waiting for a5.
    oc_row($test, 'document_requirements', ['category_id' => $cat, 'doc_key' => 'validId', 'label' => 'Valid ID', 'is_required' => 1, 'sort_order' => 1]);
    oc_row($test, 'application_documents', ['application_id' => $a1, 'doc_key' => 'validId', 'original_name' => 'v.jpg', 'stored_path' => 'x/a1.jpg', 'mime' => 'image/jpeg', 'verification_status' => 'VERIFIED', 'size_bytes' => 10]);
    oc_row($test, 'application_documents', ['application_id' => $a4, 'doc_key' => 'validId', 'original_name' => 'v.jpg', 'stored_path' => 'x/v.jpg', 'mime' => 'image/jpeg', 'verification_status' => 'VERIFIED', 'size_bytes' => 10]);
    oc_row($test, 'application_documents', ['application_id' => $a5, 'doc_key' => 'validId', 'original_name' => 'v.jpg', 'stored_path' => 'x/w.jpg', 'mime' => 'image/jpeg', 'verification_status' => 'FOR_VERIFICATION', 'size_bytes' => 10]);
    $orphanApp = oc_row($test, 'applications', ['reference_no' => 'VIRAC-SP-2026-0099', 'user_id' => 99999, 'category_id' => $cat, 'status' => 'Interview Scheduled', 'full_name' => 'Gone Account', 'mobile' => '09990002009']);

    $svc = new OfficeCheckInService($test);
    OfficeCheckInService::forgetReady();

    // Without the arrival columns: the board still works, nobody can check in.
    oc_assert($svc->ready() === false, 'An older database is not ready.');
    oc_assert(count($svc->board()['expected']) === 2, 'Without the columns, today\'s bookings are still listed.');
    [$err] = $svc->arrive($staff, $a1);
    oc_assert(is_string($err) && str_contains($err, 'migrate_office_checkin'), 'Check-in explains it is not set up.');
    $test->exec('ALTER TABLE appointments ADD COLUMN arrived_at DATETIME DEFAULT NULL, ADD COLUMN arrived_by INT NULL');
    OfficeCheckInService::forgetReady();
    oc_assert($svc->ready(), 'Ready once the columns exist.');

    // Finding the record.
    $refs = fn(string $q) => array_column($svc->find($q), 'reference_no');
    oc_assert($refs('VIRAC-SP-2026-0062') === ['VIRAC-SP-2026-0062'] && $refs('virac-sp-2026-0062') === ['VIRAC-SP-2026-0062'], 'Full reference number, any letter case.');
    oc_assert($refs('62') === ['VIRAC-SP-2026-0062'] && $refs('0062') === ['VIRAC-SP-2026-0062'] && $refs('2026-62') === ['VIRAC-SP-2026-0062'], 'Last digits find it.');
    oc_assert($refs('+639990002001') === ['VIRAC-SP-2026-0062'] && $refs('0999 000 2002') === ['VIRAC-SP-2026-0068'], 'A mobile number in any written form.');
    oc_assert($refs('Villanueva') === ['VIRAC-SP-2026-0068'] && $refs('elena') === ['VIRAC-SP-2026-0068'], 'Part of a name.');
    oc_assert($refs('ab') === [] && $refs('') === [] && $refs('%') === [], 'Too short or empty finds nothing.');
    oc_assert($refs('0099') === [], 'An application without an account is never offered.');

    // What the card offers.
    $kind = fn(int $id) => $svc->situation((new Application($test))->findForReview($id))['kind'];
    oc_assert($kind($a1) === 'today' && $kind($a3) === 'other_day' && $kind($a4) === 'no_date' && $kind($a5) === 'docs' && $kind($a6) === 'closed', 'Each situation: ' . json_encode([$kind($a1), $kind($a3), $kind($a4), $kind($a5), $kind($a6)]));
    $otherDay = $svc->situation((new Application($test))->findForReview($a3));
    oc_assert($otherDay['date'] === date('Y-m-d', strtotime('+4 days')), 'The other day is named.');

    // ID release: an approved application searched at the counter leads to the ID release card (new application or renewal).
    $rel = function (string $ref, string $name, string $mobile, ?string $date, ?string $releasedAt = null, string $type = 'new') use ($test, $mkApp) {
        $id = $mkApp($ref, $name, $mobile, 'Approved', $type);
        if ($date !== null) { oc_row($test, 'id_release_schedules', ['application_id' => $id, 'scheduled_at' => $date . ' 09:00:00', 'location' => 'MSWDO Office, Virac', 'sms_message' => 'x', 'released_at' => $releasedAt]); }
        return $id;
    };
    $r1 = $rel('VIRAC-SP-2026-0101', 'Release Today', '09990003001', $today);
    $r2 = $rel('VIRAC-SP-2026-0102', 'Release Late', '09990003002', date('Y-m-d', strtotime('-3 days')), null, 'renewal');
    $r3 = $rel('VIRAC-SP-2026-0103', 'Release Later', '09990003003', date('Y-m-d', strtotime('+7 days')));
    $r4 = $rel('VIRAC-SP-2026-0104', 'Release None', '09990003004', null);
    oc_assert([$kind($r1), $kind($r2), $kind($r3), $kind($r4)] === ['release_today', 'release_overdue', 'release_later', 'release_unscheduled'], 'The release cases: ' . json_encode([$kind($r1), $kind($r2), $kind($r3), $kind($r4)]));
    $s1 = $svc->situation((new Application($test))->findForReview($r1));
    oc_assert($s1['date'] === $today && $s1['release']['location'] === 'MSWDO Office, Virac', 'The release date and place are on the card.');
    oc_assert($refs('0101') === ['VIRAC-SP-2026-0101'] && $refs('09990003002') === ['VIRAC-SP-2026-0102'], 'An approved application is found like any other.');
    [$err] = $svc->arrive($staff, $r1);
    oc_assert(is_string($err), 'An approved application is not checked in as an arrival (release is confirmed in the ID release card).');
    // Once the ID is handed over, it is a closed application again.
    $test->exec("UPDATE id_release_schedules SET released_at = NOW() WHERE application_id = $r1");
    $test->exec("UPDATE applications SET status = 'ID Released', id_expires_at = DATE_ADD(CURDATE(), INTERVAL 1 YEAR) WHERE id = $r1");
    oc_assert($kind($r1) === 'closed', 'A released ID is closed.');

    // Arrived.
    [$err, $pos] = $svc->arrive($staff, $a2);
    oc_assert($err === null && $pos === 1, 'First to arrive is number 1: ' . json_encode([$err, $pos]));
    usleep(1100000);
    [$err, $pos] = $svc->arrive($staff, $a1);
    oc_assert($err === null && $pos === 2, 'Second to arrive is number 2.');
    $appt = $test->query("SELECT * FROM appointments WHERE application_id = $a1")->fetch();
    oc_assert(!empty($appt['arrived_at']) && (int) $appt['arrived_by'] === $staffA && $appt['status'] === 'BOOKED', 'Arrival time and staff are saved; the interview stays booked.');
    oc_assert((int) $test->query("SELECT COUNT(*) FROM appointment_events WHERE application_id = $a1 AND event_type = 'ARRIVED'")->fetchColumn() === 1, 'An ARRIVED interview event.');
    oc_assert((int) $test->query("SELECT COUNT(*) FROM application_status_history WHERE application_id = $a1 AND remarks LIKE 'Arrived at the MSWDO office%'")->fetchColumn() === 1, 'A history line.');
    oc_assert((int) $test->query("SELECT COUNT(*) FROM audit_logs WHERE action = 'Office Check-in' AND entity_id = $a1")->fetchColumn() === 1, 'An audit entry.');
    $toB = (int) $test->query("SELECT COUNT(*) FROM notifications WHERE user_id = $staffB AND type = 'office_arrival' AND related_application_id = $a1")->fetchColumn();
    $toA = (int) $test->query("SELECT COUNT(*) FROM notifications WHERE user_id = $staffA AND type = 'office_arrival'")->fetchColumn();
    oc_assert($toB === 1 && $toA === 0, 'The other staff are alerted, not the one at the counter.');
    $link = (string) $test->query("SELECT link FROM notifications WHERE user_id = $staffB AND related_application_id = $a1")->fetchColumn();
    oc_assert(str_ends_with($link, "id=$a1#decision"), 'The alert opens the interview result: ' . $link);
    oc_assert($kind($a1) === 'already', 'The card now says already checked in.');
    [$err, $pos] = $svc->arrive($staff, $a1);
    oc_assert($err === null && $pos === 2 && (int) $test->query("SELECT COUNT(*) FROM appointment_events WHERE application_id = $a1 AND event_type = 'ARRIVED'")->fetchColumn() === 1, 'Checking in twice changes nothing.');
    $board = $svc->board();
    oc_assert(array_column($board['waiting'], 'reference_no') === ['VIRAC-SP-2026-0068', 'VIRAC-SP-2026-0062'] && $board['expected'] === [], 'The waiting list keeps arrival order.');

    // Another day: refused unless moved to today.
    [$err] = $svc->arrive($staff, $a3);
    oc_assert(is_string($err) && str_contains($err, 'not today'), 'Another day is refused without moving.');
    [$err, $pos] = $svc->arrive($staff, $a3, true);
    oc_assert($err === null && $pos === 3 && substr((string) $test->query("SELECT scheduled_at FROM appointments WHERE application_id = $a3")->fetchColumn(), 0, 10) === $today, 'Moved to today and checked in as number 3.');
    oc_assert((int) $test->query("SELECT COUNT(*) FROM application_status_history WHERE application_id = $a3 AND remarks LIKE 'Interview moved from %'")->fetchColumn() === 1, 'The move is in the history.');

    // A closed application cannot be checked in.
    [$err] = $svc->arrive($staff, $a6);
    oc_assert(is_string($err), 'A released ID cannot be checked in.');
    // An admin (no applications.process) cannot check in.
    [$err] = $svc->arrive(['id' => 1, 'full_name' => 'Admin', 'role' => 'admin'], $a1);
    oc_assert(is_string($err) && str_contains($err, 'Only MSWDO staff'), 'Admins view only.');

    // What was brought is written into the result; the board moves the person to Done today.
    $review = new ApplicationReviewService($test);
    $test->exec("UPDATE appointments SET scheduled_at = CONCAT(CURDATE(), ' 00:00:00') WHERE application_id = $a1");
    $err = $review->recordInterviewOutcome($staff, $a1, 'Successful', '', [], '', ['valid_id', 'pictures']);
    $result = (string) $test->query("SELECT result FROM appointments WHERE application_id = $a1")->fetchColumn();
    oc_assert($err === null, 'The result is recorded: ' . json_encode($err));
    if ($err === null) {
        oc_assert(str_contains($result, 'Brought: valid ID (name and birthdate match), two 1x1 ID pictures.') && str_contains($result, 'Not shown: original documents.'), 'Brought and not shown are in the result: ' . $result);
        $done = $svc->board()['done'];
        oc_assert(array_column($done, 'reference_no') === ['VIRAC-SP-2026-0062'] && $done[0]['outcome'] === 'Successful', 'Done today lists the result.');
    } else {
        // Some test databases lack tables the full result path needs; the note itself is still checked through the same code.
        fwrite(STDERR, "(note: the result could not be recorded on this test database: $err)\n");
    }

    // No date yet: "schedule today" goes through the normal staff scheduling; when that is unavailable nothing is checked in.
    [$err] = $svc->scheduleTodayAndArrive($staff, $a4);
    $arrived4 = $test->query("SELECT arrived_at FROM appointments WHERE application_id = $a4")->fetchColumn();
    oc_assert(($err === null && !empty($arrived4)) || (is_string($err) && empty($arrived4)), 'Schedule today either books and checks in, or changes nothing: ' . json_encode($err));

    echo "PASS: office check-in (including ID release of an approved application): found by full number, last digits, mobile or name (not an account that is gone); the card's situations; Arrived saves time, staff, event, history and audit and alerts only the other staff; twice changes nothing; another day needs moving; arrival order kept; admins view only; what was brought goes into the result; without the columns nothing breaks.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
