<?php
require_once __DIR__ . '/../../../includes/icons.php';
/**
 * @var array  $staff
 * @var string $ownDashboard
 * @var array  $filters   status, type, barangay, q
 * @var array  $statuses
 * @var array  $apps
 * @var array  $nextSteps  application id => ['label', 'tone'] (ApplicationNextStepService)
 * @var array  $pager
 */
$hasFilters = $filters['q'] !== '' || $filters['barangay'] !== '' || $filters['status'] !== 'ALL' || $filters['type'] !== 'ALL' || ($filters['auto'] ?? 'ALL') !== 'ALL';
?>
<div class="portal-container">
  <?php require APP_ROOT . '/views/partials/staff-page-heading.php'; ?>

  <form method="get" class="filter-bar" role="search" aria-label="Search applications" data-i18n-attrs="aria-label">
    <label><span data-i18n>Search</span><input type="search" name="q" <?= ($_GET['focus'] ?? '') === 'search' ? 'autofocus' : '' ?> aria-label="Search by reference number or name" value="<?= e($filters['q']) ?>" placeholder="Search reference no. or name" class="form-control filter-q"></label>
    <label><span data-i18n>Barangay</span><input type="text" name="barangay" aria-label="Barangay" value="<?= e($filters['barangay']) ?>" placeholder="Barangay" class="form-control filter-sm" data-i18n-attrs="aria-label placeholder"></label>
    <label><span data-i18n>Status</span><select name="status" class="form-select filter-sm" aria-label="Status" data-i18n-attrs="aria-label">
      <option value="ALL" <?= $filters['status'] === 'ALL' ? 'selected' : '' ?>>All statuses</option>
      <?php foreach ($statuses as $s): ?>
        <option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select></label>
    <label><span data-i18n>Application type</span><select name="type" class="form-select filter-sm" aria-label="Application type" data-i18n-attrs="aria-label">
      <option value="ALL" <?= $filters['type'] === 'ALL' ? 'selected' : '' ?>>New + Renewals</option>
      <option value="new" <?= $filters['type'] === 'new' ? 'selected' : '' ?>>New Applications</option>
      <option value="renewal" <?= $filters['type'] === 'renewal' ? 'selected' : '' ?>>Renewals Only</option>
    </select></label>
    <label><span data-i18n>Auto-check</span><select name="auto" class="form-select filter-sm" aria-label="Automated check result" data-i18n-attrs="aria-label">
      <option value="ALL" <?= ($filters['auto'] ?? 'ALL') === 'ALL' ? 'selected' : '' ?>>Any result</option>
      <option value="ready" <?= ($filters['auto'] ?? '') === 'ready' ? 'selected' : '' ?>>Ready for review</option>
      <option value="review" <?= ($filters['auto'] ?? '') === 'review' ? 'selected' : '' ?>>Needs a closer look</option>
      <option value="fix" <?= ($filters['auto'] ?? '') === 'fix' ? 'selected' : '' ?>>Needs the Solo Parent to fix</option>
    </select></label>
    <button type="submit" class="btn-navy" data-i18n>Search</button>
  </form>

  <div class="result-meta" role="status">
    <span><b style="color:var(--ink)"><?= (int) $pager['total'] ?></b> application<?= (int) $pager['total'] === 1 ? '' : 's' ?><?= $hasFilters ? ' match your search' : ' in total' ?></span>
    <?php if ($hasFilters): ?><a href="<?= base_url('staff/index.php') ?>">Clear search &amp; filters</a><?php endif; ?>
  </div>

  <?php if (!$apps): ?>
<div class="gi-empty"><span class="gi-chip gi-xl gi-blue"><?= ui_icon('search', 28) ?></span><strong><?= $hasFilters ? 'No applications match your search' : 'No applications yet' ?></strong><p><?= $hasFilters ? 'Check the reference number or name spelling, or clear the filters to see every application.' : 'New applications will appear here as soon as Solo Parents submit them.' ?></p><?php if ($hasFilters): ?><a class="gi-btn is-outline" href="<?= e(base_url('staff/index.php')) ?>"><?= ui_icon('rotate-cw', 17) ?><span data-i18n>Clear the filters</span></a><?php endif; ?></div>
  <?php endif; ?>
  <?php foreach ($apps as $app): $st = status_style($app['status']); ?>
    <a href="<?= base_url('staff/application.php?id=' . (int) $app['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
      <div>
        <div class="app-ref"><?= e($app['reference_no']) ?><?php if ($app['type'] === 'renewal'): ?> <span class="doc-tag" style="vertical-align:middle" data-i18n>RENEWAL</span><?php endif; ?></div>
        <div class="app-date"><?= e($app['full_name']) ?> · <?= e($app['category_name']) ?> · Brgy. <?= e($app['barangay']) ?></div>
        <div class="app-date" data-i18n>Submitted <?= e(date('M j, Y', strtotime($app['submitted_at']))) ?></div>
        <?php
        $auto = $autoChecks[(int) $app['id']] ?? null;
        if ($auto) {
            $alabel = ['ready' => 'Auto-check: ready', 'review' => 'Auto-check: ' . (int) $auto['flag_count'] . ' to review', 'fix' => 'Auto-check: needs fix'][$auto['verdict']] ?? 'Auto-check';
            ?><div class="app-date" style="margin-top:4px"><span class="ac-pill is-small is-<?= e($auto['verdict']) ?>"><?= e($alabel) ?></span></div><?php
        }
        ?>
        <?php $next = $nextSteps[(int) $app['id']] ?? null; if ($next): ?><div class="app-next app-next-<?= e($next['tone']) ?>"><span>Next:</span> <?= e($next['label']) ?></div><?php endif; ?>
      </div>
      <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></div>
      <span class="staff-open-record"><span data-i18n>Open application</span> <span aria-hidden="true">&rarr;</span></span>
    </a>
  <?php endforeach; ?>

<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
</div>
