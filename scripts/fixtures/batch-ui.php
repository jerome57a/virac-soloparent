<?php
/**
 * CLI-only previews with SYNTHETIC data. No database, no login, no writes.
 *   php scripts/fixtures/batch-ui.php idcard          the dashboard ID card in every state (Active, Expires today, Expired, Renewal in progress,
 *                                                     renewal period over, Being prepared)
 *   php scripts/fixtures/batch-ui.php appointments    Appointment Management with rows in every section
 *   php scripts/fixtures/batch-ui.php notifications   staff Notifications: "Needs your action" and "For your information"
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$part = $argv[1] ?? 'idcard';
$_SESSION = ['csrf_token' => str_repeat('a', 64)];
$scripts = ['idcard' => 'applicant/dashboard.php', 'appointments' => 'staff/appointments.php', 'notifications' => 'staff/notifications.php'];
$_SERVER['SCRIPT_NAME'] = '/virac-soloparent/' . ($scripts[$part] ?? 'applicant/dashboard.php');
define('BASE_URL', 'http://localhost/virac-soloparent');
require dirname(__DIR__, 2) . '/config/constants.php';
require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/includes/csrf.php';
require APP_ROOT . '/config/settings.php';
require APP_ROOT . '/config/permissions.php';
require_once APP_ROOT . '/includes/icons.php';
$personnel = $part !== 'idcard';
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
