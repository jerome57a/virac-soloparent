<?php
require_once __DIR__ . '/../../../includes/icons.php';
/**
 * One of the dashboard's lists (New forms, Re-uploaded documents, Set the interview date, Set the ID release date): every Solo Parent
 * in it, each row opening the application. Opened from the dashboard; the Back link of the application page returns here.
 *
 * @var string $listKey
 * @var string $listTitle
 * @var array  $list  ['rows' => [id, reference_no, full_name, type], 'total' => int]
 */
$staffHeadingOverride = [$listTitle, ''];
?>
<link rel="stylesheet" href="<?= public_asset('css/staff-queues.css') ?>">
<div class="portal-container" id="staff-work-list" style="max-width:900px">
  <?php require APP_ROOT . '/views/partials/staff-page-heading.php'; ?>
  <section class="sq-card" aria-label="<?= e($listTitle) ?>">
    <?php if (!$list['rows']): ?>
      <p class="sq-none m-0" data-i18n>Nothing waiting.</p>
    <?php else: ?>
      <ul class="sq-list m-0">
        <?php foreach ($list['rows'] as $row): ?>
          <li><a href="<?= e(base_url('staff/application.php?id=' . (int) $row['id'])) ?>">
            <span class="sq-who"><b translate="no"><?= e($row['reference_no']) ?></b><small><?= e($row['full_name']) ?></small></span>
            <span class="sq-tag"><?= ($row['type'] ?? '') === 'renewal' ? 'RENEWAL' : 'NEW' ?></span>
          </a></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>