<?php
/** Shared heading only; existing forms and their actions remain in each view. */
$headingPages = $adminPages ?? require APP_ROOT . '/includes/admin-pages.php';
$headingKey = $adminPageKey ?? basename($_SERVER['SCRIPT_NAME'] ?? '');
$heading = $headingPages[$headingKey] ?? null;
?>
<?php if ($heading): ?>
<header class="admin-page-heading">
  <a class="admin-breadcrumb" href="<?= base_url('admin/dashboard.php') ?>"><svg class="admin-breadcrumb-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m10 6-6 6 6 6M4 12h16"/></svg><span data-i18n>Back to Dashboard</span></a>
  <div class="admin-page-title-row personnel-page-heading"><div><p class="admin-page-eyebrow" data-i18n><?= e($heading[2]) ?></p><h1 class="page-title" data-i18n><?= e($heading[0]) ?></h1><p class="admin-page-description" data-i18n><?= e($heading[1]) ?></p></div></div>
  <nav class="admin-page-sections" aria-label="Page sections" data-i18n-attrs="aria-label"><?php foreach ($heading[3] as $label => $id): ?><a href="#<?= e($id) ?>" data-i18n><?= e($label) ?></a><?php endforeach; ?></nav>
</header>
<?php endif; ?>
