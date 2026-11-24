<?php
/**
 * The side menu (drawer) of the MSWDO staff and the MSWDO admin: groups of links, each with the pages that count as "active" and an
 * optional number badge for work that is waiting. Presentation only; each page still authorizes its own access.
 *
 * Staff:  Work (Dashboard, Applications, Documents to Verify, Appointment Management, Failed Texts) · Records (Solo Parent Records,
 *         Reports) · Account (Notifications, My Account).
 * Admin:  Overview (Dashboard, Notifications) · Monitor (Application Monitoring, Solo Parent Records, Appointment Overview, Eligibility
 *         Logs, Reports) · Manage (User Management, SMS Management, System Settings) · Security (Audit Logs) · Account.
 */
require_once __DIR__ . '/sms-attention.php';

/** The inner markup of each menu icon (the <svg> children). */
function staff_nav_icons(): array
{
    return [
    'dashboard' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>',
    'notifications' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    'applications' => '<path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/>',
    'applicants' => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>',
    'appointments' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    'verification' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><polyline points="9 15 11 17 15 13"/>',
    'sms' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="12" y1="8" x2="12" y2="11"/><line x1="12" y1="14" x2="12.01" y2="14"/>',
    'reports' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
    'eligibility' => '<path d="M6 3h12v18H6z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
    'monitoring' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'adminsms' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    'audit' => '<path d="M9 12h6"/><path d="M9 16h6"/><path d="M13 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M13 3v5h5"/>',
    'account' => '<circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>',
    'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    ];
}

/** A count that can never break a page: 0 when the database cannot be read. */
function staff_nav_count(string $sql): int
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return 0;
    }
    try {
        return (int) $pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * The menu for $staff: [ ['label' => group name, 'items' => [ ['key','label','href','pages'=>[active pages],'badge'=>int,'tone'=>color,
 * 'id'=>?string, 'translate'=>bool] ]] ]. Items the role may not open are left out; empty groups too.
 */
function staff_nav_groups(array $staff, int $unreadNotifications = 0): array
{
    $isAdmin = ($staff['role'] ?? '') === 'admin';
    $canWork = !$isAdmin && can($staff, 'applications.process');
    $item = fn(string $key, string $label, string $href, array $pages, int $badge = 0, string $tone = '#b3261e', ?string $id = null, bool $translate = true)
        => compact('key', 'label', 'href', 'pages', 'badge', 'tone', 'id', 'translate');
    $notifications = $item('notifications', 'Notifications', base_url($isAdmin ? 'admin/notifications.php' : 'staff/notifications.php'), ['admin/notifications.php', 'staff/notifications.php'], $unreadNotifications, '#1b3a6b');
    $account = $item('account', 'My Account', base_url(role_portal_path($staff, 'account.php')), ['staff/account.php', 'admin/account.php']);
    $groups = [];

    if ($isAdmin) {
        $groups[] = ['label' => 'Overview', 'items' => [
            $item('dashboard', 'Dashboard', base_url('admin/dashboard.php'), ['admin/dashboard.php']),
            $notifications,
        ]];
        $monitor = [];
        if (can($staff, 'admin.dashboard')) {
            $monitor[] = $item('monitoring', 'Application Monitoring', base_url('admin/applications.php'), ['admin/applications.php', 'admin/application_view.php'], staff_nav_count("SELECT COUNT(*) FROM applications WHERE status = 'Pending Review'"), '#b45309', null, false);
        }
        if (can($staff, 'applicants.view')) {
            $monitor[] = $item('applicants', 'Solo Parent Records', base_url(role_portal_path($staff, 'applicants.php')), ['staff/applicants.php', 'staff/applicant_view.php', 'admin/applicants.php', 'admin/applicant_view.php']);
        }
        if (can($staff, 'appointments.view')) {
            $monitor[] = $item('appointments', 'Appointment Overview', base_url(role_portal_path($staff, 'appointments.php')), ['staff/appointments.php', 'admin/appointments.php']);
        }
        if (can($staff, 'admin.dashboard')) {
            $monitor[] = $item('eligibility', 'Eligibility Logs', base_url('admin/eligibility_logs.php'), ['admin/eligibility_logs.php']);
        }
        if (can($staff, 'reports.view')) {
            $monitor[] = $item('reports', 'Reports', base_url('admin/reports.php'), ['admin/reports.php', 'staff/reports.php']);
        }
        $groups[] = ['label' => 'Monitor', 'items' => $monitor];
        if (can($staff, 'admin.dashboard')) {
            $groups[] = ['label' => 'Manage', 'items' => [
                $item('users', 'User Management', base_url('admin/users.php'), ['admin/users.php', 'admin/user_edit.php'], staff_nav_count('SELECT COUNT(*) FROM users WHERE locked_until > NOW()'), '#b45309'),
                $item('adminsms', 'SMS Management', base_url('admin/sms.php'), ['admin/sms.php', 'admin/expiry_sms.php'], staff_nav_count("SELECT COUNT(*) FROM sms_logs WHERE status = 'FAILED'"), '#b3261e', null, false),
                $item('settings', 'System Settings', base_url('admin/settings.php'), ['admin/settings.php', 'admin/requirements.php']),
            ]];
            $groups[] = ['label' => 'Security', 'items' => [
                $item('audit', 'Audit Logs', base_url('admin/audit_log.php'), ['admin/audit_log.php']),
            ]];
        }
        $groups[] = ['label' => 'Account', 'items' => [$account]];
        return array_values(array_filter($groups, fn($g) => $g['items']));
    }

    // MSWDO staff
    $work = [$item('dashboard', 'Dashboard', base_url('staff/dashboard.php'), ['staff/dashboard.php'])];
    if ($canWork) {
        $docWaiting = staff_doc_waiting_counts();
        $work[] = $item('applications', 'Applications', base_url('staff/index.php'), ['staff/index.php', 'staff/application.php'], staff_nav_count("SELECT COUNT(*) FROM applications WHERE status = 'Pending Review'"), '#b45309');
        $work[] = $item('verification', 'Documents to Verify', base_url('staff/verification.php'), ['staff/verification.php'], $docWaiting['applications'], $docWaiting['resent'] > 0 ? '#b45309' : '#1b3a6b', 'nav-docs-to-verify', false);
    }
    if (can($staff, 'appointments.view')) {
        // Needs a result + still booked today + ID release date passed: the work waiting on the Appointments page.
        $toConfirm = staff_outcome_waiting_count() + staff_today_booked_count() + staff_release_overdue_count();
        $work[] = $item('appointments', 'Appointment Management', base_url(role_portal_path($staff, 'appointments.php')), ['staff/appointments.php', 'staff/follow_up.php'], $toConfirm, '#b45309');
    }
    if ($canWork) {
        $work[] = $item('sms', 'Failed Texts', base_url('staff/sms.php'), ['staff/sms.php'], staff_sms_attention_count());
    }
    $groups[] = ['label' => 'Work', 'items' => $work];
    $records = [];
    if (can($staff, 'applicants.view')) {
        $records[] = $item('applicants', 'Solo Parent Records', base_url(role_portal_path($staff, 'applicants.php')), ['staff/applicants.php', 'staff/applicant_view.php']);
    }
    if (can($staff, 'reports.view')) {
        $records[] = $item('reports', 'Reports', base_url('staff/reports.php'), ['admin/reports.php', 'staff/reports.php']);
    }
    $groups[] = ['label' => 'Records', 'items' => $records];
    $groups[] = ['label' => 'Account', 'items' => [$notifications, $account]];
    return array_values(array_filter($groups, fn($g) => $g['items']));
}
