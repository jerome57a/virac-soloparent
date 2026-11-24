<?php
/**
 * Shared page header: <head>, gray top bar, and the seal + PST clock row.
 * Expects, optionally, before including:
 *   $pageTitle  string  browser tab title
 */
$pageTitle = $pageTitle ?? setting('system_name');
$isPersonnelPortal = in_array($staff['role'] ?? '', ['mswd_social_worker', 'admin'], true);

// Login establishes one identity; render the menu for that account's role.
if (!empty($staff)) {
    $navLoggedIn = true;
    // MSWD staff open their own account page (same as the drawer's "My Account").
    $navAccountUrl = base_url(role_portal_path($staff, 'account.php'));
    // Staff search their own application queue, never the public tracker.
    $navSearchUrl = base_url((can($staff, 'applications.process') ? 'staff/index.php' : 'admin/applications.php') . '?focus=search');
    $navSearchLabel = 'Search applications';
    // Staff stay inside their own portal: the brand opens their dashboard, not the public Home page.
    $navHomeUrl = base_url($staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
    $navPortal = $staff['role'] === 'admin' ? ['admin', 'MSWDO Admin'] : ['staff', 'MSWDO Staff'];
    // Current page (e.g. "staff/index.php") for the drawer's active state; detail pages count as their list.
    $navPage = basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) . '/' . basename($_SERVER['SCRIPT_NAME'] ?? '');
    $navActive = function (string ...$pages) use ($navPage): string {
        return in_array($navPage, $pages, true) ? ' active" aria-current="page' : '';
    };
} elseif (!empty($user)) {
    $navLoggedIn = true;
    // Signed-in applicants' account lives in their dashboard, not the public portal's My Applications tab.
    $navAccountUrl = base_url('applicant/dashboard.php?section=profile');
    $navHomeUrl = base_url('applicant/dashboard.php');
    $navSearchUrl = base_url('portal.php?tab=search');
    $navSearchLabel = 'Search services';
} else {
    $navLoggedIn = false;
    $navAccountUrl = base_url('login.php');
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<script src="<?= public_asset('js/preferences-init.js') ?>"></script>
<link rel="stylesheet" href="<?= public_asset('lib/bootstrap/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/style.css') ?>">
<?php if ($isPersonnelPortal): ?>
<link rel="stylesheet" href="<?= public_asset('css/personnel-portal.css') ?>">
<?php if (($staff['role'] ?? '') === 'admin'): ?>
<link rel="stylesheet" href="<?= public_asset('css/admin-management.css') ?>">
<?php endif; ?>
<?php endif; ?>
<link rel="stylesheet" href="<?= public_asset('css/preferences-surfaces.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/preferences.css') ?>">
<?php if (!empty($useAdminWorkspace)): ?><link rel="stylesheet" href="<?= public_asset('css/admin-workspace.css') ?>"><?php endif; ?>
<?php if (!empty($useStaffWorkspace)): ?><link rel="stylesheet" href="<?= public_asset('css/staff-workspace.css') ?>"><?php endif; ?>
<?php if ($isPersonnelPortal): require_once APP_ROOT . '/includes/icons.php'; ?><link rel="stylesheet" href="<?= public_asset('css/guidance.css') ?>"><?php endif; ?>
<?php if (($contentView ?? '') === 'views/staff/account.php'): ?><link rel="stylesheet" href="<?= public_asset('css/personnel-account.css') ?>"><?php endif; ?>
</head>
<body<?= !empty($staff) ? ' class="portal-body' . ($isPersonnelPortal ? ' personnel-portal' : '') . (($staff['role'] ?? '') === 'admin' ? ' admin-portal' : ' staff-portal') . '"' : '' ?>>

<header class="site-nav<?= !empty($staff) ? ' is-staff' : '' ?>">
  <div class="site-nav-inner">
    <div class="site-nav-left">
      <button type="button" class="nav-hamburger-btn" id="nav-menu-btn" aria-label="Open menu" aria-expanded="false" aria-controls="nav-drawer" data-i18n-attrs="aria-label">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <a href="<?= e($navHomeUrl ?? base_url('portal.php')) ?>" class="site-nav-title text-decoration-none" data-i18n>Solo Parent ID Services</a>
      <?php if (!empty($navPortal)): ?><span class="portal-badge <?= e($navPortal[0]) ?>" data-i18n title="You are using the <?= e($navPortal[1]) ?> portal"><?= e($navPortal[1]) ?></span><?php endif; ?>
      <a href="<?= e($navHomeUrl ?? base_url('portal.php')) ?>" class="site-nav-logo">
        <img src="<?= asset('images/solo-parent.png') ?>" alt="Virac Solo Parent ID">
      </a>
    </div>
    <div class="site-nav-right">
      <?php require APP_ROOT . '/includes/preferences-controls.php'; ?>
      <?php $navBell = nav_notifications($user ?? null, $staff ?? null); if ($navBell): ?>
      <a href="<?= e($navBell['url']) ?>" class="nav-icon-btn nav-bell" aria-label="Notifications<?= $navBell['count'] ? ' (' . (int) $navBell['count'] . ' unread)' : '' ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <?php if ($navBell['count']): ?><span class="nav-bell-count"><?= $navBell['count'] > 99 ? '99+' : (int) $navBell['count'] ?></span><?php endif; ?>
      </a>
      <?php endif; ?>
      <a href="<?= e($navSearchUrl ?? base_url('portal.php?tab=search')) ?>" class="nav-icon-btn" aria-label="<?= e($navSearchLabel ?? 'Search services') ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </a>
      <?php if ($navLoggedIn): ?>
      <a href="<?= e($navAccountUrl) ?>" class="nav-icon-btn" aria-label="My account" data-i18n-attrs="aria-label">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
      </a>
      <?php else: ?>
      <a href="<?= e($navAccountUrl) ?>" class="btn-navy btn-sm nav-auth-btn" aria-label="Log In / Create Account" data-i18n data-i18n-attrs="aria-label">Log In<span class="nav-auth-extra" data-i18n> / Create Account</span></a>
      <?php endif; ?>
    </div>
  </div>
</header>

<div class="nav-drawer-overlay" id="nav-drawer-overlay" hidden></div>
<aside class="nav-drawer" id="nav-drawer" aria-hidden="true">
  <div class="nav-drawer-head">
    <a href="<?= e($navHomeUrl ?? base_url('portal.php')) ?>" class="nav-drawer-brand text-decoration-none">
      <img src="<?= asset('images/solo-parent.png') ?>" alt="Virac Solo Parent ID">
      <span data-i18n>Solo Parent ID Services</span>
    </a>
    <button type="button" class="nav-drawer-close" id="nav-drawer-close" aria-label="Close menu" data-i18n-attrs="aria-label">
      <svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="5" y1="5" x2="19" y2="19"/><line x1="19" y1="5" x2="5" y2="19"/></svg>
    </button>
  </div>
  <nav class="nav-drawer-list" aria-label="Main navigation" data-i18n-attrs="aria-label">
    <?php if (!empty($staff)): require_once APP_ROOT . '/includes/staff-nav.php'; $staffNavIcons = staff_nav_icons(); ?>
      <?php /* Grouped by purpose, with a number badge where work is waiting (see includes/staff-nav.php). */ ?>
      <?php foreach (staff_nav_groups($staff, (int) ($navBell['count'] ?? 0)) as $navGroup): ?>
        <div class="nav-drawer-section-label" data-i18n><?= e($navGroup['label']) ?></div>
        <?php foreach ($navGroup['items'] as $navItem): ?>
        <a href="<?= e($navItem['href']) ?>" class="nav-drawer-link nav-drawer-link-icon<?= $navActive(...$navItem['pages']) ?>"<?= $navItem['id'] ? ' id="' . e($navItem['id']) . '"' : '' ?>>
          <svg aria-hidden="true" focusable="false" class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $staffNavIcons[$navItem['key']] ?? '' ?></svg>
          <span<?= $navItem['translate'] ? ' data-i18n' : '' ?>><?= e($navItem['label']) ?></span><?php if ($navItem['badge'] > 0): ?><span<?= $navItem['key'] === 'verification' ? ' data-doc-badge' : '' ?> class="nav-staff-badge" style="background:<?= e($navItem['tone']) ?>" aria-label="<?= (int) $navItem['badge'] ?> waiting"><?= (int) $navItem['badge'] ?></span><?php endif; ?>
        </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <form method="post" action="<?= base_url(role_portal_path($staff, 'logout.php')) ?>" class="nav-drawer-logout-form">
        <?= csrf_field() ?>
        <button type="submit" class="nav-drawer-link nav-drawer-link-icon nav-drawer-logout">
          <svg aria-hidden="true" focusable="false" class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <span data-i18n>Log Out</span>
        </button>
      </form>
<?php else: ?>
    <?php
    // Signed-in applicants return to their dashboard sections from standalone pages.
    // Visitors continue to use the public portal.
    $navScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $navOnPortal = $navScript === 'portal.php';
    ?>
    <div class="nav-drawer-section-label" data-i18n>Main</div>

    <a href="<?= base_url(!empty($user) ? 'applicant/dashboard.php' : 'portal.php?tab=overview') ?>" class="nav-drawer-link nav-drawer-link-icon <?= empty($user) && $navOnPortal && ($tab ?? '') === 'overview' ? 'active" aria-current="page' : '' ?>">
      <svg aria-hidden="true" focusable="false" class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg>
      <span data-i18n><?= !empty($user) ? 'Dashboard' : 'Home' ?></span>
    </a>

    <a href="<?= base_url('eligibility.php') ?>" class="nav-drawer-link nav-drawer-link-icon <?= $navScript === 'eligibility.php' ? 'active" aria-current="page' : '' ?>">
      <svg aria-hidden="true" focusable="false" class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      <span data-i18n>Eligibility Checker</span>
    </a>

    <?php // Signed-in applicants apply inside their dashboard layout; visitors get the public log-in prompt. ?>
    <a href="<?= base_url(!empty($user) ? 'applicant/dashboard.php?section=apply' : 'portal.php?tab=apply') ?>" class="nav-drawer-link nav-drawer-link-icon <?= $navOnPortal && ($tab ?? '') === 'apply' ? 'active" aria-current="page' : '' ?>">
      <svg aria-hidden="true" focusable="false" class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
      <span data-i18n>Apply for Solo Parent ID</span>
    </a>

    <a href="<?= base_url(!empty($user) ? 'applicant/dashboard.php?section=application' : 'portal.php?tab=status') ?>" class="nav-drawer-link nav-drawer-link-icon <?= $navOnPortal && ($tab ?? '') === 'status' ? 'active" aria-current="page' : '' ?>">
      <svg aria-hidden="true" focusable="false" class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <span data-i18n>Track Application</span>
    </a>

    <div class="nav-drawer-section-label" data-i18n>Information</div>

    <a href="<?= base_url(!empty($user) ? 'applicant/dashboard.php?section=help' : 'portal.php?tab=help') ?>" class="nav-drawer-link nav-drawer-link-icon<?= empty($user) && ($tab ?? '') === 'help' ? ' active" aria-current="page' : '' ?>">
      <svg aria-hidden="true" focusable="false" class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 5 0c0 2-2.5 2-2.5 4m0 3h.01"/></svg>
      <span data-i18n>Contact &amp; Help</span>
    </a>
    <a href="<?= base_url(!empty($user) ? 'applicant/dashboard.php?section=requirements' : 'portal.php#requirements') ?>" class="nav-drawer-link nav-drawer-link-icon">
      <svg aria-hidden="true" focusable="false" class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6zM14 3v5h5M9 12h7M9 16h7"/></svg>
      <span data-i18n>Requirements Guide</span>
    </a>
    <?php // Visitors sign in or register through the header button and the unified login page; this section is for signed-in applicants only. ?>
    <?php if (!empty($user)): ?>
    <div class="nav-drawer-section-label" data-i18n>Account</div>
      <a href="<?= base_url('applicant/dashboard.php?section=profile') ?>" class="nav-drawer-link nav-drawer-link-icon">
        <svg aria-hidden="true" focusable="false" class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        <span data-i18n>My Profile</span>
      </a>
      <form method="post" action="<?= base_url('logout.php') ?>" class="nav-drawer-logout-form">
        <?= csrf_field() ?>
        <button type="submit" class="nav-drawer-link nav-drawer-link-icon nav-drawer-logout">
          <svg aria-hidden="true" focusable="false" class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <span data-i18n>Log Out</span>
        </button>
      </form>
    <?php endif; ?>
    <?php endif; ?>
  </nav>
</aside>
