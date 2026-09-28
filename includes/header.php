<?php
/**
 * Shared page header: <head>, gray top bar, and the seal + PST clock row.
 * Expects, optionally, before including:
 *   $pageTitle  string  browser tab title
 */
$pageTitle = $pageTitle ?? APP_NAME;

if (!empty($user)) {
    $navLoggedIn = true;
    $navAccountUrl = base_url('portal.php?tab=myapps');
} elseif (!empty($staff)) {
    $navLoggedIn = true;
    $navAccountUrl = base_url('admin/index.php');
} else {
    $navLoggedIn = false;
    $navAccountUrl = base_url('portal.php?tab=login');
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= asset('css/style.css') ?>?v=<?= @filemtime(APP_ROOT . '/assets/css/style.css') ?: 1 ?>">
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
    <a href="<?= base_url('portal.php?tab=overview') ?>" class="nav-drawer-link <?= ($tab ?? '') === 'overview' ? 'active' : '' ?>">Overview</a>
    <a href="<?= base_url('portal.php?tab=apply') ?>" class="nav-drawer-link <?= ($tab ?? '') === 'apply' ? 'active' : '' ?>">Apply Online</a>
    <a href="<?= base_url('portal.php?tab=myapps') ?>" class="nav-drawer-link <?= ($tab ?? '') === 'myapps' ? 'active' : '' ?>">My Applications</a>
    <a href="<?= base_url('portal.php?tab=status') ?>" class="nav-drawer-link <?= ($tab ?? '') === 'status' ? 'active' : '' ?>">Check Status</a>

    <div class="nav-drawer-group">
      <button type="button" class="nav-drawer-toggle" id="nav-benefits-toggle" aria-expanded="false" aria-controls="nav-benefits-submenu">
        <span>Benefits</span>
        <svg class="chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
      </button>
      <div class="nav-drawer-submenu" id="nav-benefits-submenu" hidden>
        <a href="<?= base_url('portal.php?tab=overview#benefits') ?>" class="nav-drawer-link">Benefits of Having a Solo Parent ID</a>
        <a href="<?= base_url('portal.php?tab=overview#eligibility') ?>" class="nav-drawer-link">Eligibility Requirements</a>
        <a href="<?= base_url('portal.php?tab=overview#requirements') ?>" class="nav-drawer-link">Required Documents</a>
      </div>
    </div>

    <a href="<?= e($navAccountUrl) ?>" class="nav-drawer-link"><?= $navLoggedIn ? 'My Account' : 'Log In / Create Account' ?></a>
  </nav>
</aside>
