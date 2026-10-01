<?php
/**
 * @var array  $staff
 * @var string $ownDashboard
 * @var array  $filters   status, type, barangay, q
 * @var array  $statuses
 * @var array  $apps
 * @var array  $pager
 */
?>
<div class="portal-container">
  <a href="<?= e($ownDashboard) ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">Applications</h1>
    <div class="d-flex align-items-center gap-3">
      <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
      <form method="post" action="<?= base_url('admin/logout.php') ?>" class="m-0">
        <?= csrf_field() ?>
        <button type="submit" class="btn-outline" style="padding:6px 14px;font-size:13px">Log out</button>
      </form>
    </div>
  </div>

  <form method="get" class="d-flex flex-wrap gap-2 mb-4">
    <input type="text" name="q" aria-label="Search by reference number or name" value="<?= e($filters['q']) ?>" placeholder="Search reference no. or name" class="form-control" style="max-width:280px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
    <input type="text" name="barangay" aria-label="Barangay" value="<?= e($filters['barangay']) ?>" placeholder="Barangay" class="form-control" style="max-width:160px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
    <select name="status" class="form-select" style="max-width:220px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
      <option value="ALL" <?= $filters['status'] === 'ALL' ? 'selected' : '' ?>>All statuses</option>
      <?php foreach ($statuses as $s): ?>
        <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type" class="form-select" style="max-width:180px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
      <option value="ALL" <?= $filters['type'] === 'ALL' ? 'selected' : '' ?>>New + Renewals</option>
      <option value="new" <?= $filters['type'] === 'new' ? 'selected' : '' ?>>New Applications</option>
      <option value="renewal" <?= $filters['type'] === 'renewal' ? 'selected' : '' ?>>Renewals Only</option>
    </select>
    <button type="submit" class="btn-navy">Filter</button>
  </form>

  <?php if (!$apps): ?>
    <div style="font-size:14px;color:var(--muted)">No applications match this filter.</div>
  <?php endif; ?>

  <?php foreach ($apps as $app): $st = status_style($app['status']); ?>
    <a href="<?= base_url('staff/application.php?id=' . (int) $app['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
      <div>
        <div class="app-ref"><?= e($app['reference_no']) ?><?php if ($app['type'] === 'renewal'): ?> <span class="doc-tag" style="vertical-align:middle">RENEWAL</span><?php endif; ?></div>
        <div class="app-date"><?= e($app['full_name']) ?> · <?= e($app['category_name']) ?> · Brgy. <?= e($app['barangay']) ?></div>
        <div class="app-date">Submitted <?= e(date('M j, Y', strtotime($app['submitted_at']))) ?></div>
      </div>
      <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></div>
    </a>
  <?php endforeach; ?>

<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
</div>
