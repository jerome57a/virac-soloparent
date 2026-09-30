<?php
/**
 * Read-only application monitoring list.
 *
 * @var array  $staff
 * @var string $statusFilter
 * @var string $typeFilter
 * @var string $q
 * @var array  $validStatuses
 * @var array  $stageCounts  status => count
 * @var array  $apps
 */
?>
<div class="portal-container">
  <a href="<?= base_url('admin/dashboard.php') ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">Application Monitoring</h1>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>
  <div style="font-size:12px;color:var(--faint);margin-bottom:16px">Read-only view. Application decisions (approve/reject/correction) are made by MSWD staff.</div>

  <div class="d-flex flex-wrap gap-2 mb-4">
    <?php foreach ($validStatuses as $vs): ?>
      <a href="?status=<?= urlencode($vs) ?>" class="status-pill text-decoration-none" style="background:<?= e(status_style($vs)['bg']) ?>;color:<?= e(status_style($vs)['color']) ?>"><?= e($vs) ?> (<?= (int) ($stageCounts[$vs] ?? 0) ?>)</a>
    <?php endforeach; ?>
  </div>

  <form method="get" class="d-flex flex-wrap gap-2 mb-4">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search reference no. or name" class="form-control" style="max-width:280px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
    <select name="status" class="form-select" style="max-width:220px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
      <option value="ALL" <?= $statusFilter === 'ALL' ? 'selected' : '' ?>>All statuses</option>
      <?php foreach ($validStatuses as $s): ?>
        <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type" class="form-select" style="max-width:180px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
      <option value="ALL" <?= $typeFilter === 'ALL' ? 'selected' : '' ?>>New + Renewals</option>
      <option value="new" <?= $typeFilter === 'new' ? 'selected' : '' ?>>New Applications</option>
      <option value="renewal" <?= $typeFilter === 'renewal' ? 'selected' : '' ?>>Renewals Only</option>
    </select>
    <button type="submit" class="btn-navy">Filter</button>
  </form>

  <?php if (!$apps): ?>
    <div style="font-size:14px;color:var(--muted)">No applications match this filter.</div>
  <?php endif; ?>

  <?php foreach ($apps as $app): $st = status_style($app['status']); ?>
    <a href="<?= base_url('admin/application_view.php?id=' . (int) $app['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
      <div>
        <div class="app-ref"><?= e($app['reference_no']) ?><?php if ($app['type'] === 'renewal'): ?> <span class="doc-tag" style="vertical-align:middle">RENEWAL</span><?php endif; ?></div>
        <div class="app-date"><?= e($app['full_name']) ?> · <?= e($app['category_name']) ?></div>
        <div class="app-date">Submitted <?= e(date('M j, Y', strtotime($app['submitted_at']))) ?></div>
      </div>
      <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></div>
    </a>
  <?php endforeach; ?>
</div>
