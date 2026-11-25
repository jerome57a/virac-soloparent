<?php
/**
 * CLI-only previews with SYNTHETIC data. No database, no login, no writes.
 *   php scripts/fixtures/batch-ui.php idcard          the dashboard ID card in every state (Active, Expires today, Expired, Renewal in progress,
 *                                                     renewal period over, Being prepared)
 *   php scripts/fixtures/batch-ui.php appointments    Appointment Management with rows in every section
 *   php scripts/fixtures/batch-ui.php notifications   staff Notifications: "Needs your action" and "For your information"
 *   php scripts/fixtures/batch-ui.php office [today|already|other_day|no_date|docs|closed|many|none|list] [compact|nobody]
 *                                                     "Today at the office" with the confirm card for that case (list = no search)
 *   php scripts/fixtures/batch-ui.php counter [renewal|release]   the Solo Parent's "Show this at the MSWDO counter" card (release = ID release day)
 *   php scripts/fixtures/batch-ui.php myid [renewal]  My ID page (renewal = the label "Renewal in progress")
 *   office also takes release_today|release_overdue|release_later|release_unscheduled (an approved application searched at the counter)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$part = $argv[1] ?? 'idcard';
$_SESSION = ['csrf_token' => str_repeat('a', 64)];
$scripts = ['idcard' => 'applicant/dashboard.php', 'appointments' => 'staff/appointments.php', 'notifications' => 'staff/notifications.php', 'office' => 'staff/dashboard.php', 'counter' => 'applicant/dashboard.php', 'myid' => 'applicant/dashboard.php'];
$_SERVER['SCRIPT_NAME'] = '/virac-soloparent/' . ($scripts[$part] ?? 'applicant/dashboard.php');
define('BASE_URL', 'http://localhost/virac-soloparent');
require dirname(__DIR__, 2) . '/config/constants.php';
require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/includes/csrf.php';
require APP_ROOT . '/config/settings.php';
require APP_ROOT . '/config/permissions.php';
require_once APP_ROOT . '/includes/icons.php';
$personnel = !in_array($part, ['idcard', 'counter', 'myid'], true);
?><!doctype html>
<html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Preview</title>
<link rel="stylesheet" href="<?= public_asset('lib/bootstrap/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/style.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/dashboard.css') ?>">
<?php if ($personnel): ?><link rel="stylesheet" href="<?= public_asset('css/personnel-portal.css') ?>"><link rel="stylesheet" href="<?= public_asset('css/guidance.css') ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= public_asset('css/preferences-surfaces.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/preferences.css') ?>">
</head><body>
<?php
$day = fn(int $n) => date('Y-m-d', strtotime(($n >= 0 ? '+' : '') . $n . ' days'));
if ($part === 'idcard') {
    $id = ['reference_no' => 'VSP-2026-000123', 'status' => 'ID Released', 'category_name' => 'Death of the spouse', 'full_name' => 'Maria Santos', 'id_expires_at' => $day(100)];
    $cases = [
        'valid' => [$id, ['state' => 'valid', 'daysLeft' => 100, 'expiresAt' => $day(100)]],
        'due' => [['id_expires_at' => $day(0), 'reference_no' => 'VSP-2025-000087', 'category_name' => 'Unmarried father or mother who keeps and rears the child/children'] + $id, ['state' => 'due', 'daysLeft' => 0, 'expiresAt' => $day(0)]],
        'expired' => [['id_expires_at' => $day(-20)] + $id, ['state' => 'expired', 'daysLeft' => -20, 'expiresAt' => $day(-20)]],
        'in_progress' => [['id_expires_at' => $day(-3)] + $id, ['state' => 'in_progress', 'application' => ['reference_no' => 'VSP-2026-000150', 'status' => 'Under Verification']]],
        'closed' => [['id_expires_at' => $day(-400)] + $id, ['state' => 'closed', 'daysLeft' => -400, 'expiresAt' => $day(-400)]],
        'pending' => [null, ['state' => 'none']],
    ];
    echo '<div class="portal-container applicant-home"><div class="dash-two-col" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr))">';
    foreach ($cases as $name => [$idApplication, $renewal]) {
        $viewableIdApplication = $idApplication;
        $latestApplication = $idApplication ?? ['reference_no' => 'VSP-2026-000140', 'status' => 'Interview Scheduled'];
        echo '<div data-case="' . $name . '">';
        require APP_ROOT . '/views/partials/dashboard-id-card.php';
        echo '</div>';
    }
    echo '</div></div>';
} elseif ($part === 'office') {
    $case = $argv[2] ?? 'today';
    $staff = ['id' => 1, 'role' => 'mswd_social_worker', 'full_name' => 'Preview Staff'];
    $t = date('Y-m-d');
    $row = fn(int $id, string $ref, string $name, string $type, ?string $arrived, string $status = 'BOOKED', ?string $result = null) => ['appointment_id' => $id, 'application_id' => $id, 'reference_no' => $ref, 'full_name' => $name, 'type' => $type, 'category_name' => 'Detention or imprisonment of the spouse', 'status' => $status, 'scheduled_at' => $t . ' 00:00:00', 'arrived_at' => $arrived, 'result_recorded_at' => $result, 'outcome' => $result ? 'Successful' : null];
    $board = ['ready' => true,
        'waiting' => [$row(62, 'VIRAC-SP-2026-0062', 'Edwin R. Molina', 'new', $t . ' 09:12:00'), $row(68, 'VIRAC-SP-2026-0068', 'Elena R. Villanueva', 'renewal', $t . ' 09:40:00')],
        'expected' => [$row(71, 'VIRAC-SP-2026-0071', 'Ana Reyes', 'new', null), $row(74, 'VIRAC-SP-2026-0074', 'Jose Ramos', 'new', null)],
        'done' => [$row(59, 'VIRAC-SP-2026-0059', 'Joy C. Pantino', 'new', $t . ' 08:05:00', 'COMPLETED', $t . ' 08:40:00')]];
    $app = ['id' => 62, 'reference_no' => 'VIRAC-SP-2026-0062', 'full_name' => 'Edwin R. Molina', 'type' => 'new', 'status' => 'Interview Scheduled', 'birthdate' => '1982-09-09', 'barangay' => 'Francia', 'category_name' => 'Detention or imprisonment of the spouse'];
    $kids = [['full_name' => 'Bryan Molina', 'birthdate' => date('Y-m-d', strtotime('-9 years -20 days'))]];
    $office = ['board' => $board, 'query' => $case === 'list' ? null : '0062', 'matches' => [$app], 'situation' => null];
    if ($case === 'none') { $office['matches'] = []; $office['query'] = '0099'; }
    elseif ($case === 'many') { $office['query'] = 'Molina'; $office['matches'] = [$app, ['reference_no' => 'VIRAC-SP-2026-0031', 'full_name' => 'Edwina Molina', 'status' => 'Under Verification', 'type' => 'new'] + $app]; }
    elseif ($case !== 'list') {
        $sit = ['kind' => $case, 'app' => $app, 'appointment' => ['arrived_at' => $t . ' 09:12:00'], 'children' => $kids, 'outstanding' => 2, 'date' => date('Y-m-d', strtotime('+4 days')), 'position' => 1];
        if ($case === 'closed') { $sit['app']['status'] = 'Rejected'; }
        if (str_starts_with($case, 'release_')) {
            $sit['app']['status'] = 'Approved';
            $day = ['release_today' => $t, 'release_overdue' => date('Y-m-d', strtotime('-3 days')), 'release_later' => date('Y-m-d', strtotime('+7 days')), 'release_unscheduled' => null][$case];
            $sit['date'] = $day;
            $sit['release'] = $day ? ['scheduled_at' => $day . ' 09:00:00', 'location' => 'MSWDO Office, Virac', 'released_at' => null] : null;
        }
        $office['situation'] = $sit;
    }
    $officeReturn = 'dashboard';
    $officeCompact = ($argv[3] ?? '') === 'compact';   // the dashboard strip
    if (($argv[3] ?? '') === 'nobody') { $officeCompact = true; $board['waiting'] = []; $office['board'] = $board; }
    echo '<div class="portal-container">';
    require APP_ROOT . '/views/partials/office-today.php';
    echo '</div>';
} elseif ($part === 'counter') {
    $counterRef = 'VIRAC-SP-2026-0062'; $counterRenewal = ($argv[2] ?? '') === 'renewal';
    $counterRelease = ($argv[2] ?? '') === 'release' ? ['scheduled_at' => $t0 = date('Y-m-d') . ' 09:00:00', 'location' => 'MSWDO Office, Virac'] : null;
    echo '<div class="portal-container applicant-home" style="max-width:480px"><div class="dash-card">';
    require APP_ROOT . '/views/partials/counter-card.php';
    echo '</div></div>';
} elseif ($part === 'myid') {
    $renewalMode = ($argv[2] ?? '') === 'renewal';
    $idApplication = ['id' => 52, 'reference_no' => 'VIRAC-SP-2026-0052', 'full_name' => 'Ramon T. Bautista', 'birthdate' => '1984-02-03', 'address' => 'Purok 6', 'barangay' => 'Rawis', 'category_name' => 'Detention or imprisonment of the spouse', 'status' => 'ID Released', 'type' => 'new', 'id_valid_from' => '2025-11-14', 'id_expires_at' => '2026-11-14', 'emergency_contact_name' => '', 'emergency_contact_relation' => '', 'emergency_contact_phone' => '', 'emergency_contact_address' => ''];
    $idChildren = [['full_name' => 'Daniel Bautista', 'birthdate' => '2016-01-14']];
    $idExpiryInfo = ['date' => $idApplication['id_expires_at'], 'expired' => true];
    $idReleaseSchedule = ['scheduled_at' => '2025-11-14 09:00:00', 'released_at' => '2025-11-14 09:30:00', 'location' => 'MSWDO Office, Virac'];
    $latestApplication = $idApplication;
    $renewalInProgress = $renewalMode ? ['reference_no' => 'VIRAC-SP-2026-0068', 'type' => 'renewal', 'status' => 'Under Verification'] : null;
    require APP_ROOT . '/views/applicant/my-id.php';
} elseif ($part === 'appointments') {
    require_once APP_ROOT . '/models/Appointment.php';
    $staff = ['id' => 1, 'role' => 'mswd_social_worker', 'full_name' => 'Preview Staff'];
    $iv = fn(int $id, string $ref, string $name, string $at, string $status = 'BOOKED', int $dateOnly = 0, string $type = 'new') =>
        ['id' => $id, 'application_id' => $id, 'reference_no' => $ref, 'full_name' => $name, 'scheduled_at' => $at, 'status' => $status, 'date_only' => $dateOnly, 'location' => 'MSWDO Office, Virac', 'application_type' => $type];
    $canManage = true; $error = ''; $flashMessage = null;
    $awaitingOutcome = [$iv(1, 'VSP-2026-000099', 'Lito Cruz', $day(-3) . ' 00:00:00', 'BOOKED', 1, 'renewal'), $iv(2, 'VSP-2026-000117', 'Ana Dela Peña', $day(-4) . ' 10:00:00')];
    $todayAppointments = [$iv(3, 'VSP-2026-000123', 'Maria Santos', $day(0) . ' 23:30:00'), $iv(4, 'VSP-2026-000131', 'Jose Reyes', $day(0) . ' 00:00:00', 'BOOKED', 1), $iv(5, 'VSP-2026-000101', 'Carla Bautista', $day(0) . ' 00:00:00', 'COMPLETED', 1)];
    $upcomingByDate = [$day(1) => [$iv(6, 'VSP-2026-000135', 'Pedro Garcia', $day(1) . ' 00:00:00', 'BOOKED', 1)], $day(2) => [$iv(7, 'VSP-2026-000138', 'Liza Tan', $day(2) . ' 00:00:00', 'BOOKED', 1, 'renewal')]];
    $awaitingSchedule = [['id' => 8, 'reference_no' => 'VSP-2026-000140', 'full_name' => 'Rosa Valdez', 'appointment_status' => 'MISSED']];
    $waitingForDate = [];
    $overdueReleases = [['application_id' => 9, 'reference_no' => 'VSP-2026-000088', 'full_name' => 'Nena Torres', 'scheduled_at' => $day(-6) . ' 09:00:00', 'location' => 'MSWDO Office, Virac']];
    $dateState = 'ready'; $defaultCapacity = 20;
    $interviewDates = [
        ['interview_date' => $day(1), 'seats_used' => 2, 'capacity_configured' => true, 'effective_capacity' => 20, 'remaining' => 18, 'full' => false],
        ['interview_date' => $day(2), 'seats_used' => 20, 'capacity_configured' => true, 'effective_capacity' => 20, 'remaining' => 0, 'full' => true],
    ];
    $pageTitle = 'Appointment Management';
    require APP_ROOT . '/views/staff/appointments/index.php';
} else {
    require_once APP_ROOT . '/services/NotificationService.php';
    $staff = ['id' => 1, 'role' => 'mswd_social_worker', 'full_name' => 'Preview Staff'];
    $n = fn(int $id, string $type, string $msg, int $read, string $ago) => ['id' => $id, 'type' => $type, 'message' => $msg, 'is_read' => $read, 'link' => 'staff/application.php?id=5', 'created_at' => date('Y-m-d H:i:s', strtotime($ago))];
    $notifPage = ['rows' => [
        $n(4, 'document_resubmitted', 'RE-UPLOADED: Maria Santos sent "Birth certificate of the child" again for VSP-2026-000123 — needs your verification.', 0, '-2 minutes'),
        $n(3, 'new_application', 'New application VSP-2026-000141 from Ana Reyes is waiting for review.', 0, '-25 minutes'),
        $n(2, 'status_change', 'Settings were updated.', 0, '-1 hour'),
        $n(1, 'appointment_update', 'VSP-2026-000087 (Pedro Garcia) booked an interview date: Nov 28, 2026.', 1, '-1 day'),
    ], 'pager' => paginate(4, 1, 20), 'unread' => 3];
    $unreadOnly = false; $baseUrl = 'staff/notifications.php'; $notifRole = 'mswd_social_worker'; $flashMessage = null;
    echo '<div class="portal-container" style="max-width:760px">';
    require APP_ROOT . '/views/partials/notification-list.php';
    echo '</div>';
}
?>
<script src="<?= public_asset('js/translations.js') ?>"></script>
<script src="<?= public_asset('js/preferences.js') ?>"></script>
</body></html>
