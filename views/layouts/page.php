<?php
/**
 * Standard public-site page: shared header, one content view, portal footer.
 * includes/header.php reads $pageTitle and (optionally) $user/$staff from this scope.
 * Staff/admin pages get <main> (it grows so the footer sits at the bottom of short pages)
 * and their own portal footer instead of the public one.
 *
 * @var string $pageTitle
 * @var string $contentView  path relative to APP_ROOT
 */
$adminPages = require APP_ROOT . '/includes/admin-pages.php';
$adminPageKey = basename($_SERVER['SCRIPT_NAME'] ?? '');
$useAdminWorkspace = ($staff['role'] ?? '') === 'admin' && isset($adminPages[$adminPageKey]);
$useStaffWorkspace = ($staff['role'] ?? '') === 'mswd_social_worker' && in_array($adminPageKey, ['dashboard.php', 'notifications.php', 'index.php', 'applicants.php', 'appointments.php', 'reports.php', 'verification.php', 'follow_up.php'], true);
require APP_ROOT . '/includes/header.php';
if (!empty($staff)) {
    echo $useStaffWorkspace ? '<main class="portal-main staff-workspace" data-staff-page="' . e($adminPageKey) . '">' : '<main class="portal-main">';
    if ($useAdminWorkspace) {
        echo '<div class="admin-workspace" data-admin-page="' . e($adminPageKey) . '">';
        echo '<div class="admin-workspace-content" id="admin-page-content" tabindex="-1">';
    }
    require APP_ROOT . '/' . $contentView;
    if ($useAdminWorkspace) { echo '</div></div>'; }
    echo '</main>';
    $footerVariant = $staff['role'] === 'admin' ? 'admin' : 'staff';
} else {
    require APP_ROOT . '/' . $contentView;
    $footerVariant = 'portal';
}
require APP_ROOT . '/includes/footer.php';
