<?php
require_once __DIR__ . '/../../../includes/icons.php';
/**
 * Document Verification Queue: Solo Parents whose documents wait for MSWDO staff, oldest application first. "Re-uploaded" is a Solo
 * Parent who sent a document again after a correction request.
 *
 * @var array  $staff
 * @var string $filter      all | resent
 * @var array  $rows        Document::verificationQueue() rows (reference_no, full_name, pending_docs, resent_docs, oldest_upload)
 * @var int    $allCount
 * @var int    $resentCount
 * @var array  $backlog     applications, documents
 */
$tab = fn(string $key, string $label, int $count) => '<a href="' . e($key === 'all' ? base_url('staff/verification.php') : base_url('staff/verification.php?filter=' . $key))
    . '" class="queue-tab' . ($filter === $key ? ' is-active' : '') . '"' . ($filter === $key ? ' aria-current="page"' : '') . '>' . e($label) . ' <b>' . $count . '</b></a>';
?>
<link rel="stylesheet" href="<?= public_asset('css/dashboard-attention.css') ?>">
<div class="portal-container" style="max-width:900px">
  <?php require APP_ROOT . '/views/partials/staff-page-heading.php'; ?>

  <nav class="queue-tabs" aria-label="Show">
    <?= $tab('all', 'All waiting', $allCount) ?>
    <?= $tab('resent', 'Re-uploaded', $resentCount) ?>
  </nav>
  <p class="small" style="color:var(--muted)"><?= (int) $backlog['documents'] ?> document<?= (int) $backlog['documents'] === 1 ? '' : 's' ?> across <?= (int) $backlog['applications'] ?> application<?= (int) $backlog['applications'] === 1 ? '' : 's' ?> are waiting for verification.</p>

  <div class="review-card">
    <?php if (!$rows): ?>
      <div class="gi-empty"><span class="gi-chip gi-xl gi-green"><?= ui_icon('circle-check', 28) ?></span><strong data-i18n>All caught up</strong><p><span data-i18n><?= $filter === 'resent' ? 'No Solo Parent has sent a document again.' : 'No documents are waiting for verification.' ?></span> <span data-i18n>Next: check Appointment Management for interviews to schedule.</span></p><a class="gi-btn is-outline" href="<?= e(base_url('staff/appointments.php#staff-needs-schedule')) ?>"><?= ui_icon('calendar-check', 17) ?><span data-i18n>Open Appointment Management</span></a></div>
    <?php else: ?>
      <?php foreach ($rows as $q): ?>
        <a href="<?= base_url('staff/application.php?id=' . (int) $q['id']) ?>#checklist" class="app-card text-decoration-none" style="color:inherit">
          <div>
            <div class="app-ref"><?= e($q['reference_no']) ?> <span style="font-weight:400;color:var(--muted)">· <?= e($q['status']) ?></span></div>
            <div class="app-date"><?= e($q['full_name']) ?> · waiting since <?= e(date('M j, Y', strtotime($q['oldest_upload']))) ?></div>
          </div>
          <span>
            <?php if ((int) $q['resent_docs'] > 0): ?><span class="status-pill" style="background:#ffe3c2;color:#8a3b00;font-weight:700">Re-uploaded<?= (int) $q['resent_docs'] > 1 ? ' (' . (int) $q['resent_docs'] . ')' : '' ?></span><?php endif; ?>
            <span class="status-pill" style="background:#fdf1d6;color:#7a5f12"><?= (int) $q['pending_docs'] ?> to verify</span>
          </span>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
