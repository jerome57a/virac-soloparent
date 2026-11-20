<?php
/** CLI-only UI fixture; renders the real dashboard without accounts or database changes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Asia/Manila');
$root = dirname(__DIR__, 2);
function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function base_url(string $path): string { return 'http://localhost/virac-soloparent/' . $path; }
function public_asset(string $path): string { return base_url('public/' . $path); }
$state = $argv[1] ?? 'populated';
$staff = ['full_name' => 'Test <Administrator>'];
$stats = ['totalApplications' => 348, 'totalRenewals' => 46, 'pendingNew' => 24, 'pendingRenewals' => 7, 'underVerification' => 31, 'approvedNew' => 72, 'rejectedApps' => 8, 'forRevision' => 12, 'expiringIds' => 9, 'expiringWindow' => 30, 'activeUsers' => 306, 'smsSent' => 1240, 'smsFailed' => 3, 'smsLoggedOnly' => 42, 'smsPending' => 2];
$userStats = ['applicants' => 312, 'newThisMonth' => 18, 'mswdStaff' => 5, 'admins' => 2, 'deactivated' => 13, 'locked' => 2];
$auditStats = ['eventsToday' => 35, 'loginsToday' => 9, 'accessDenied7d' => 1, 'failedLogins7d' => 4, 'passwordResets7d' => 3];
$summary = ['thisMonth' => 28, 'lastMonth' => 21, 'approvalRate' => 90, 'decided' => 80];
$recentActivity = [
    ['action' => 'SMS Template Updated', 'actor_name' => 'Example Administrator', 'created_at' => '2026-11-09 10:42:00'],
    ['action' => 'User Updated', 'actor_name' => 'Example Administrator', 'created_at' => '2026-11-09 10:15:00'],
    ['action' => 'Login', 'actor_name' => 'Example Staff', 'created_at' => '2026-11-09 09:52:00'],
];
if ($state === 'empty') {
    $stats = array_fill_keys(array_keys($stats), 0); $stats['expiringWindow'] = 30;
    $userStats = array_fill_keys(array_keys($userStats), 0);
    $auditStats = array_fill_keys(array_keys($auditStats), 0);
    $summary = ['thisMonth' => 0, 'lastMonth' => 0, 'approvalRate' => null, 'decided' => 0];
    $recentActivity = [];
} elseif ($state === 'clear') {
    $stats['smsFailed'] = 0; $userStats['locked'] = 0;
} elseif ($state === 'large') {
    $stats['totalApplications'] = 12345678; $stats['pendingNew'] = 999999;
    $userStats['applicants'] = 9876543;
    $staff['full_name'] = str_repeat('Long administrator name ', 6);
    $recentActivity[0]['action'] = str_repeat('Long action description ', 12);
}
?><!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Administrator Dashboard Fixture</title>
<?php foreach (['style', 'personnel-portal', 'admin-management', 'preferences-surfaces', 'preferences'] as $css): ?><style><?= file_get_contents($root . '/public/css/' . $css . '.css') ?></style><?php endforeach; ?>
</head><body class="portal-body personnel-portal admin-portal">
<header class="site-nav"><div class="site-nav-inner"><a class="site-nav-title" href="#admin-dashboard">Solo Parent ID Services</a><?php require $root . '/includes/preferences-controls.php'; ?></div></header>
<main class="portal-main"><?php require $root . '/views/admin/dashboard.php'; ?></main>
<script><?= file_get_contents($root . '/public/js/translations.js') ?></script><script><?= file_get_contents($root . '/public/js/preferences.js') ?></script>
</body></html>
