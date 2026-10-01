<?php
/**
 * Shared page header: <head>, gray top bar, and the seal + PST clock row.
 * Expects, optionally, before including:
 *   $pageTitle  string  browser tab title
 */
$pageTitle = $pageTitle ?? setting('system_name');
$unreadAnnouncementCount = $unreadAnnouncementCount ?? 0;

if (!empty($user)) {
    $navLoggedIn = true;
    $navAccountUrl = base_url('portal.php?tab=myapps');
} elseif (!empty($staff)) {
    $navLoggedIn = true;
    $navAccountUrl = base_url($staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
} else {
    $navLoggedIn = false;
    $navAccountUrl = base_url('portal.php?tab=login');
}

$navApplicationOpen = in_array($tab ?? '', ['apply', 'status', 'myapps'], true);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="<?= public_asset('css/style.css') ?>">
</head>
<body>

<header class="site-nav">
  <div class="site-nav-inner">
    <div class="site-nav-left">
      <button type="button" class="nav-hamburger-btn" id="nav-menu-btn" aria-label="Open menu" aria-expanded="false" aria-controls="nav-drawer">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <a href="<?= base_url('portal.php') ?>" class="site-nav-title text-decoration-none">Solo Parent ID Services</a>
      <a href="<?= base_url('portal.php') ?>" class="site-nav-logo">
        <img src="<?= asset('images/solo-parent.png') ?>" alt="Virac Solo Parent ID">
      </a>
    </div>
    <div class="site-nav-right">
      <?php $navBell = nav_notifications($user ?? null, $staff ?? null); if ($navBell): ?>
      <a href="<?= e($navBell['url']) ?>" class="nav-icon-btn nav-bell" aria-label="Notifications<?= $navBell['count'] ? ' (' . (int) $navBell['count'] . ' unread)' : '' ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <?php if ($navBell['count']): ?><span class="nav-bell-count"><?= $navBell['count'] > 99 ? '99+' : (int) $navBell['count'] ?></span><?php endif; ?>
      </a>
      <?php endif; ?>
      <a href="<?= base_url('portal.php?tab=status') ?>" class="nav-icon-btn" aria-label="Check application status">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </a>
      <a href="<?= e($navAccountUrl) ?>" class="nav-icon-btn" aria-label="<?= $navLoggedIn ? 'My account' : 'Log in' ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
      </a>
    </div>
  </div>
</header>

<div class="nav-drawer-overlay" id="nav-drawer-overlay" hidden></div>
<aside class="nav-drawer" id="nav-drawer" aria-hidden="true">
  <div class="nav-drawer-head">
    <a href="<?= base_url('portal.php') ?>" class="nav-drawer-brand text-decoration-none">
      <img src="<?= asset('images/solo-parent.png') ?>" alt="Virac Solo Parent ID">
      <span>Solo Parent ID Services</span>
    </a>
    <button type="button" class="nav-drawer-close" id="nav-drawer-close" aria-label="Close menu">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="5" y1="5" x2="19" y2="19"/><line x1="19" y1="5" x2="5" y2="19"/></svg>
    </button>
  </div>
  <nav class="nav-drawer-list">
    <?php if (!empty($staff)): ?>
      <div class="nav-drawer-section-label">Main</div>

      <a href="<?= base_url($staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg>
        <span>Dashboard</span>
      </a>
      <a href="<?= base_url($staff['role'] === 'admin' ? 'admin/notifications.php' : 'staff/notifications.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <span>Notifications</span>
      </a>
      <?php if (can($staff, 'applications.process')): ?>
      <a href="<?= base_url('staff/index.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
        <span>Applications</span>
      </a>
      <?php endif; ?>
      <?php if (can($staff, 'applicants.view')): ?>
      <a href="<?= base_url('staff/applicants.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        <span>Applicant Records</span>
      </a>
      <?php endif; ?>
      <?php if (can($staff, 'appointments.view')): ?>
      <a href="<?= base_url('staff/appointments.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span>Appointment Management</span>
      </a>
      <?php endif; ?>
      <?php if (can($staff, 'qr.verify')): ?>
      <a href="<?= base_url('staff/qr_verify.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><line x1="14" y1="14" x2="21" y2="14"/><line x1="14" y1="21" x2="21" y2="21"/><line x1="17.5" y1="14" x2="17.5" y2="21"/></svg>
        <span>QR Verification</span>
      </a>
      <?php endif; ?>
      <?php if (can($staff, 'chat.respond')): ?>
      <a href="<?= base_url('staff/chat.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        <span>Chat Support</span>
      </a>
      <?php endif; ?>
      <?php if (can($staff, 'reports.view')): ?>
      <a href="<?= base_url($staff['role'] === 'admin' ? 'admin/reports.php' : 'staff/reports.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        <span>Reports</span>
      </a>
      <?php endif; ?>

      <?php if (can($staff, 'admin.dashboard')): ?>
        <div class="nav-drawer-section-label">Administration</div>
        <a href="<?= base_url('admin/applications.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
          <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          <span>Application Monitoring</span>
        </a>
        <a href="<?= base_url('admin/users.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
          <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <span>User Management</span>
        </a>
        <a href="<?= base_url('admin/analytics.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
          <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
          <span>Analytics</span>
        </a>
        <a href="<?= base_url('admin/sms.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
          <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          <span>SMS Management</span>
        </a>
        <a href="<?= base_url('admin/settings.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
          <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
          <span>System Settings</span>
        </a>
        <a href="<?= base_url('admin/monitoring.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
          <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
          <span>DB &amp; System Monitoring</span>
        </a>
        <a href="<?= base_url('admin/audit_log.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
          <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6"/><path d="M9 16h6"/><path d="M13 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M13 3v5h5"/></svg>
          <span>Audit Logs</span>
        </a>
        <a href="<?= base_url('admin/faq.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
          <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 2-3 4"/><line x1="12" y1="17" x2="12" y2="17.01"/></svg>
          <span>Manage FAQ</span>
        </a>
      <?php endif; ?>

      <div class="nav-drawer-section-label">Account</div>
      <a href="<?= base_url('staff/account.php') ?>" class="nav-drawer-link nav-drawer-link-icon">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        <span>My Account</span>
      </a>
      <form method="post" action="<?= base_url('admin/logout.php') ?>" class="nav-drawer-logout-form">
        <?= csrf_field() ?>
        <button type="submit" class="nav-drawer-link nav-drawer-link-icon nav-drawer-logout">
          <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <span>Logout</span>
        </button>
      </form>
    <?php else: ?>
    <div class="nav-drawer-section-label">Main</div>

    <a href="<?= base_url('portal.php?tab=overview') ?>" class="nav-drawer-link nav-drawer-link-icon <?= ($tab ?? '') === 'overview' ? 'active' : '' ?>">
      <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg>
      <span>Dashboard</span>
    </a>

    <div class="nav-drawer-group">
      <button type="button" class="nav-drawer-toggle nav-drawer-link-icon <?= $navApplicationOpen ? 'open' : '' ?>" id="nav-application-toggle" aria-expanded="<?= $navApplicationOpen ? 'true' : 'false' ?>" aria-controls="nav-application-submenu">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
        <span>My Application</span>
        <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <div class="nav-drawer-submenu <?= $navApplicationOpen ? 'open' : '' ?>" id="nav-application-submenu">
        <a href="<?= base_url('portal.php?tab=apply') ?>" class="nav-drawer-link <?= ($tab ?? '') === 'apply' ? 'active' : '' ?>">Apply for Solo Parent ID</a>
        <a href="<?= base_url('portal.php?tab=status') ?>" class="nav-drawer-link <?= ($tab ?? '') === 'status' ? 'active' : '' ?>">Application Status</a>
        <a href="<?= base_url('portal.php?tab=myapps') ?>" class="nav-drawer-link <?= ($tab ?? '') === 'myapps' ? 'active' : '' ?>">Application History</a>
      </div>
    </div>

    <div class="nav-drawer-group">
      <button type="button" class="nav-drawer-toggle nav-drawer-link-icon" id="nav-renewal-toggle" aria-expanded="false" aria-controls="nav-renewal-submenu">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/></svg>
        <span>ID Renewal</span>
        <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <div class="nav-drawer-submenu" id="nav-renewal-submenu">
        <a href="<?= base_url('applicant/dashboard.php?section=renewal&type=new') ?>" class="nav-drawer-link">Renew ID</a>
        <a href="<?= base_url('applicant/dashboard.php?section=renewal&type=status') ?>" class="nav-drawer-link">Renewal Status</a>
        <a href="<?= base_url('applicant/dashboard.php?section=renewal&type=history') ?>" class="nav-drawer-link">Renewal History</a>
      </div>
    </div>

    <a href="<?= base_url('applicant/dashboard.php?section=myid') ?>" class="nav-drawer-link nav-drawer-link-icon">
      <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="12" r="2"/><line x1="14" y1="10" x2="18" y2="10"/><line x1="14" y1="14" x2="18" y2="14"/></svg>
      <span>My Solo Parent ID</span>
    </a>

    <div class="nav-drawer-section-label">Information</div>

    <a href="<?= base_url('portal.php?tab=news') ?>" class="nav-drawer-link nav-drawer-link-icon <?= ($tab ?? '') === 'news' ? 'active' : '' ?>">
      <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M4 4h12v14a2 2 0 0 0 2 2H6a2 2 0 0 1-2-2V4z"/>
        <path d="M18 8h2a2 2 0 0 1 2 2v9a1 1 0 0 1-1 1H8"/>
        <line x1="8" y1="8" x2="14" y2="8"/>
        <line x1="8" y1="12" x2="14" y2="12"/>
        <line x1="8" y1="15" x2="12" y2="15"/>
      </svg>
      <span>News &amp; Announcements</span>
      <?php if ($unreadAnnouncementCount > 0): ?><span class="nav-drawer-badge"><?= (int) $unreadAnnouncementCount ?></span><?php endif; ?>
    </a>

    <a href="<?= base_url('applicant/dashboard.php?section=documents') ?>" class="nav-drawer-link nav-drawer-link-icon">
      <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/></svg>
      <span>My Documents</span>
    </a>

    <a href="<?= base_url('applicant/dashboard.php?section=notifications') ?>" class="nav-drawer-link nav-drawer-link-icon">
      <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
      <span>Notifications</span>
    </a>

    <div class="nav-drawer-section-label">Account</div>

    <?php if (!empty($user)): ?>
      <a href="<?= base_url('applicant/dashboard.php?section=profile') ?>" class="nav-drawer-link nav-drawer-link-icon">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        <span>My Profile</span>
      </a>
      <a href="<?= base_url('applicant/dashboard.php?section=help') ?>" class="nav-drawer-link nav-drawer-link-icon">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 2-3 4"/><line x1="12" y1="17" x2="12" y2="17.01"/></svg>
        <span>Help &amp; Support</span>
      </a>
      <form method="post" action="<?= base_url('logout.php') ?>" class="nav-drawer-logout-form">
        <?= csrf_field() ?>
        <button type="submit" class="nav-drawer-link nav-drawer-link-icon nav-drawer-logout">
          <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <span>Logout</span>
        </button>
      </form>
    <?php else: ?>
      <a href="<?= base_url('portal.php?tab=login') ?>" class="nav-drawer-link nav-drawer-link-icon">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        <span>Log In / Create Account</span>
      </a>
    <?php endif; ?>
    <?php endif; ?>
  </nav>
</aside>
