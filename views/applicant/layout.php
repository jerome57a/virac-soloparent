<?php
/**
 * Applicant dashboard shell: top bar with the hamburger + slide-out menu around one section view.
 *
 * @var array  $user
 * @var string $section
 * @var string $sectionTitle
 * @var string $pageTitle
 * @var string $contentView  path relative to APP_ROOT
 * @var int    $unreadNotificationCount
 */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<script src="<?= public_asset('js/preferences-init.js') ?>"></script>
<link rel="stylesheet" href="<?= public_asset('lib/bootstrap/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/style.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/dashboard.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/preferences-surfaces.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/preferences.css') ?>">
</head>
<body class="dash-body">

<div class="dash-main">
  <header class="dash-topbar">
    <button type="button" class="nav-hamburger-btn" id="nav-menu-btn" aria-label="Open menu" aria-expanded="false" aria-controls="nav-drawer" data-i18n-attrs="aria-label">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="dash-topbar-text">
      <div class="dash-topbar-portal" data-i18n>Solo Parent Portal</div>
      <h1 class="dash-topbar-title" data-i18n><?= e($sectionTitle) ?></h1>
    </div>
    <a href="?section=notifications" class="nav-icon-btn nav-bell dash-topbar-notifications" title="Notifications" aria-label="Notifications<?= $unreadNotificationCount > 0 ? ' (' . (int) $unreadNotificationCount . ' unread)' : '' ?>"<?= $section === 'notifications' ? ' aria-current="page"' : '' ?> data-i18n-attrs="title">
      <svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
      <?php if ($unreadNotificationCount > 0): ?>
        <span class="nav-bell-count" aria-hidden="true"><?= $unreadNotificationCount > 99 ? '99+' : (int) $unreadNotificationCount ?></span>
      <?php endif; ?>
    </a>
    <a href="?section=profile" class="dash-topbar-user" title="Open My Profile" aria-label="My Profile: <?= e($user['full_name']) ?>"<?= $section === 'profile' ? ' aria-current="page"' : '' ?>>
      <?php if ($user['profile_photo']): ?>
        <img src="<?= base_url('avatar.php?user_id=' . (int) $user['id']) ?>" alt="" class="dash-user-avatar" style="object-fit:cover">
      <?php else: ?>
        <div class="dash-user-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim($user['full_name']), 0, 1))) ?></div>
      <?php endif; ?>
      <div class="dash-topbar-user-text">
        <div class="dash-user-name"><?= e($user['full_name']) ?></div>
        <div class="dash-user-role" data-i18n>Solo Parent</div>
      </div>
    </a>
    <?php require APP_ROOT . '/includes/preferences-controls.php'; ?>
  </header>

  <?php /* Same drawer as the Public/Staff/Admin header (ids and classes driven by app.js);
           placed right after the hamburger so Tab moves from the button into the open menu. */ ?>
  <div class="nav-drawer-overlay" id="nav-drawer-overlay" hidden></div>
  <aside class="nav-drawer dash-drawer" id="nav-drawer" aria-hidden="true">
    <div class="nav-drawer-head">
      <div class="nav-drawer-brand">
        <img src="<?= asset('images/solo-parent.png') ?>" alt="Virac Solo Parent ID">
        <div>
          <div class="dash-brand-name" data-i18n>Solo Parent ID</div>
          <div class="dash-brand-sub" data-i18n>e-SoloParentID Portal</div>
        </div>
      </div>
      <button type="button" class="nav-drawer-close" id="nav-drawer-close" aria-label="Close menu" data-i18n-attrs="aria-label">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="5" y1="5" x2="19" y2="19"/><line x1="19" y1="5" x2="5" y2="19"/></svg>
      </button>
    </div>

    <nav class="nav-drawer-list" aria-label="Solo Parent Portal" data-i18n-attrs="aria-label">
      <div class="nav-drawer-section-label" data-i18n>Main</div>

      <a href="?section=dashboard" class="nav-drawer-link nav-drawer-link-icon <?= $section === 'dashboard' ? 'active" aria-current="page' : '' ?>">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg>
        <span data-i18n>Dashboard</span>
      </a>

      <a href="?section=apply" class="nav-drawer-link nav-drawer-link-icon <?= $section === 'apply' ? 'active" aria-current="page' : '' ?>">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
        <span data-i18n>Apply for Solo Parent ID</span>
      </a>

      <a href="?section=application" class="nav-drawer-link nav-drawer-link-icon <?= $section === 'application' ? 'active" aria-current="page' : '' ?>">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
        <span data-i18n>My Application</span>
      </a>

      <a href="?section=documents" class="nav-drawer-link nav-drawer-link-icon <?= $section === 'documents' ? 'active" aria-current="page' : '' ?>">
        <svg class="nav-drawer-icon" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/></svg>
        <span data-i18n>Documents</span>
        <?php if (!empty($documentsToFixCount)): ?><span class="nav-drawer-badge" title="Documents that need a new file"><?= (int) $documentsToFixCount ?></span><?php endif; ?>
      </a>

      <a href="?section=appointment" class="nav-drawer-link nav-drawer-link-icon <?= $section === 'appointment' ? 'active" aria-current="page' : '' ?>">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span data-i18n>Appointment</span>
      </a>

      <a href="?section=renewal" class="nav-drawer-link nav-drawer-link-icon <?= $section === 'renewal' ? 'active" aria-current="page' : '' ?>">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/></svg>
        <span data-i18n>ID Renewal</span>
      </a>

      <a href="?section=myid" class="nav-drawer-link nav-drawer-link-icon <?= $section === 'myid' ? 'active" aria-current="page' : '' ?>">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="12" r="2"/><line x1="14" y1="10" x2="18" y2="10"/><line x1="14" y1="14" x2="18" y2="14"/></svg>
        <span data-i18n>My Solo Parent ID</span>
      </a>

      <div class="nav-drawer-section-label" data-i18n>Information</div>

      <a href="?section=requirements" class="nav-drawer-link nav-drawer-link-icon <?= $section === 'requirements' ? 'active" aria-current="page' : '' ?>">
        <svg class="nav-drawer-icon" aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 3H20v19H6.5A2.5 2.5 0 0 1 4 19.5v-14A2.5 2.5 0 0 1 6.5 3Z"/><path d="M8 7h8M8 11h6"/></svg>
        <span data-i18n>Requirements Guide</span>
      </a>

      <a href="?section=notifications" class="nav-drawer-link nav-drawer-link-icon <?= $section === 'notifications' ? 'active" aria-current="page' : '' ?>">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
        <span data-i18n>Notifications</span>
        <?php if ($unreadNotificationCount > 0): ?><span class="nav-drawer-badge"><?= (int) $unreadNotificationCount ?></span><?php endif; ?>
      </a>

      <div class="nav-drawer-section-label" data-i18n>Account</div>

      <a href="?section=profile" class="nav-drawer-link nav-drawer-link-icon <?= $section === 'profile' ? 'active" aria-current="page' : '' ?>">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        <span data-i18n>My Profile</span>
      </a>

      <a href="?section=help" class="nav-drawer-link nav-drawer-link-icon <?= $section === 'help' ? 'active" aria-current="page' : '' ?>">
        <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 2-3 4"/><line x1="12" y1="17" x2="12" y2="17.01"/></svg>
        <span data-i18n>Help &amp; Support</span>
      </a>

      <form method="post" action="<?= base_url('logout.php') ?>" class="nav-drawer-logout-form">
        <?= csrf_field() ?>
        <button type="submit" class="nav-drawer-link nav-drawer-link-icon nav-drawer-logout">
          <svg class="nav-drawer-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <span data-i18n>Logout</span>
        </button>
      </form>
    </nav>
  </aside>

  <main class="dash-content">
<?php if ($layoutFlash = take_flash()): ?>
      <div class="info-box" style="max-width:none" role="status"><?= e($layoutFlash) ?></div>
<?php endif; ?>
<?php require APP_ROOT . '/' . $contentView; ?>
  </main>

<?php
$footerAbout = 'e-SoloParentID System — Municipal Social Welfare and Development Office (MSWDO), Virac, Catanduanes.';
$footerListTitle = 'SOLO PARENT PORTAL';
$footerList = [
    ['Help & Support', base_url('applicant/dashboard.php?section=help')],
    ['Contact', base_url('applicant/dashboard.php?section=help#help-contact')],
];
require APP_ROOT . '/views/partials/site-footer.php';
?>
</div>

<script src="<?= public_asset('js/app.js') ?>"></script>
<script src="<?= public_asset('js/translations.js') ?>"></script>
<script src="<?= public_asset('js/preferences.js') ?>"></script>
</body>
</html>
