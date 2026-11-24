<?php
require_once __DIR__ . '/../../includes/icons.php';
/** Shared heading only; existing forms and their actions remain in each view. */
$headingPages = $adminPages ?? require APP_ROOT . '/includes/admin-pages.php';
$headingKey = $adminPageKey ?? basename($_SERVER['SCRIPT_NAME'] ?? '');
$heading = $headingPages[$headingKey] ?? null;
// What to do on each page, in two lines (the "What do I do here?" help under the title).
$adminHelp = [
  'applications.php' => 'Watch the applications the MSWDO staff are processing. This page is for monitoring: the staff review and decide.',
  'users.php' => 'Create staff accounts, change a role, and unlock an account that was locked after failed sign-ins.',
  'sms.php' => 'See every text message sent to Solo Parents. Check failed ones; if many fail, check the SMS provider on the server.',
  'settings.php' => 'Choose a section, change it, and save that section. Every change is recorded in the Audit Logs.',
  'requirements.php' => 'Choose the documents a Solo Parent must upload. Changes apply to new applications.',
  'audit_log.php' => 'Find who did what and when. Records here are permanent and cannot be edited.',
  'eligibility_logs.php' => 'See the results of the eligibility check that people ran before applying.',
  'expiry_sms.php' => 'Solo Parents whose ID expired without the expiry text. MSWDO staff can send it from Failed Texts.',
  'notifications.php' => 'Updates for the administrator appear here. Open one to go to the page it is about.',
  'reports.php' => 'Pick a report, set the filters, then print or download the results.',
];?>
<?php if ($heading): ?>
<header class="admin-page-heading">
  <a class="admin-breadcrumb" href="<?= base_url('admin/dashboard.php') ?>"><svg class="admin-breadcrumb-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m10 6-6 6 6 6M4 12h16"/></svg><span data-i18n>Back to Dashboard</span></a>
  <div class="admin-page-title-row personnel-page-heading gi-heading"><span class="gi-chip gi-lg"><?= ui_icon(ui_page_icon((string) $headingKey), 24) ?></span><div><p class="admin-page-eyebrow" data-i18n><?= e($heading[2]) ?></p><h1 class="page-title" data-i18n><?= e($heading[0]) ?></h1><p class="admin-page-description" data-i18n><?= e($heading[1]) ?></p>
    <?php if (isset($adminHelp[$headingKey])): ?><details class="gi-help"><summary><?= ui_icon('circle-help', 16) ?><span data-i18n>What do I do here?</span></summary><p data-i18n><?= e($adminHelp[$headingKey]) ?></p></details><?php endif; ?></div></div>
  <nav class="admin-page-sections" aria-label="Page sections" data-i18n-attrs="aria-label"><?php foreach ($heading[3] as $label => $id): ?><a href="#<?= e($id) ?>" data-i18n><?= e($label) ?></a><?php endforeach; ?></nav>
</header>
<?php endif; ?>
