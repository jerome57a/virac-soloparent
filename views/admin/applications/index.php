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
  <?php require APP_ROOT . '/views/partials/admin-page-heading.php'; ?>

  <nav class="d-flex flex-wrap gap-2 mb-4" aria-label="Filter by status" id="admin-application-status">
    <a href="?<?= e(http_build_query(['status' => 'ALL', 'type' => $typeFilter, 'q' => $q])) ?>" class="status-pill text-decoration-none"<?= $statusFilter === 'ALL' ? ' aria-current="page"' : '' ?>><span data-i18n>All applications</span> (<?= array_sum($stageCounts) ?>)</a>
    <?php foreach ($validStatuses as $vs): ?>
      <a href="?<?= e(http_build_query(['status' => $vs, 'type' => $typeFilter, 'q' => $q])) ?>" class="status-pill text-decoration-none"<?= $statusFilter === $vs ? ' aria-current="page"' : '' ?> style="background:<?= e(status_style($vs)['bg']) ?>;color:<?= e(status_style($vs)['color']) ?>"><?= e($vs) ?> (<?= (int) ($stageCounts[$vs] ?? 0) ?>)</a>
    <?php endforeach; ?>
  </nav>

  <form method="get" class="filter-bar mb-4" id="admin-application-filters" role="search" aria-label="Search applications" data-i18n-attrs="aria-label">
    <label class="admin-filter-field"><span data-i18n>Search</span><input type="text" name="q" <?= ($_GET['focus'] ?? '') === 'search' ? 'autofocus' : '' ?> aria-label="Search by reference number or name" value="<?= e($q) ?>" placeholder="Search reference no. or name" class="form-control filter-q"></label>
    <label class="admin-filter-field"><span data-i18n>Status</span><select name="status" class="form-select filter-sm" aria-label="Status" data-i18n-attrs="aria-label">
      <option value="ALL" <?= $statusFilter === 'ALL' ? 'selected' : '' ?>>All statuses</option>
      <?php foreach ($validStatuses as $s): ?>
        <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select></label>
    <label class="admin-filter-field"><span data-i18n>Application type</span><select name="type" class="form-select filter-sm" aria-label="Application type" data-i18n-attrs="aria-label">
      <option value="ALL" <?= $typeFilter === 'ALL' ? 'selected' : '' ?>>New + Renewals</option>
      <option value="new" <?= $typeFilter === 'new' ? 'selected' : '' ?>>New Applications</option>
      <option value="renewal" <?= $typeFilter === 'renewal' ? 'selected' : '' ?>>Renewals Only</option>
    </select></label>
    <button type="submit" class="btn-navy" data-i18n>Search</button><a href="<?= base_url('admin/applications.php') ?>" class="btn-outline" data-i18n>Clear</a>
  </form>

  <?php if (!$apps): ?>
    <div class="empty-state">
      <div class="empty-state-title">No applications match this search</div>
      Try a different reference number, name or status. <a href="<?= base_url('admin/applications.php') ?>">Clear search &amp; filters</a>
    </div>
  <?php endif; ?>

  <div id="admin-application-results"><?php require APP_ROOT . '/views/partials/admin-list-meta.php'; ?></div>
  <?php if ($apps): ?><div class="admin-list-scroll" role="region" aria-label="Application monitoring records" tabindex="0"><?php endif; ?>
  <?php foreach ($apps as $app): $st = status_style($app['status']); ?>
    <a href="<?= base_url('admin/application_view.php?id=' . (int) $app['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
      <div>
        <div class="app-ref"><?= e($app['reference_no']) ?><?php if ($app['type'] === 'renewal'): ?> <span class="doc-tag" style="vertical-align:middle" data-i18n>RENEWAL</span><?php endif; ?></div>
        <div class="app-date"><?= e($app['full_name']) ?> · <?= e($app['category_name']) ?></div>
        <div class="app-date" data-i18n>Submitted <?= e(date('M j, Y', strtotime($app['submitted_at']))) ?></div>
        <?php $next = ($nextSteps ?? [])[(int) $app['id']] ?? null; if ($next): ?><div class="app-next app-next-<?= e($next['tone']) ?>"><span>Next:</span> <?= e($next['label']) ?></div><?php endif; ?>
      </div>
      <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></div>
      <span class="admin-record-open"><span data-i18n>Open application</span><span aria-hidden="true">&rarr;</span></span>
    </a>
  <?php endforeach; ?>
  <?php if ($apps): ?></div><?php endif; ?>
  <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
</div>
