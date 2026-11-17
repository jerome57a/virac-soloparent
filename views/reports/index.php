<?php
/**
 * Reports page, shared by staff/reports.php and admin/reports.php (ReportController::handle()).
 *
 * @var array   $staff
 * @var string  $type
 * @var array   $typeLabels
 * @var string  $description
 * @var array   $filters         from, to, q + report filters
 * @var ?string $filterError
 * @var array   $filterOptions   name => [label, value => label]
 * @var string  $filterText
 * @var string  $dateLabel
 * @var array   $columns         key => [label, format]
 * @var array   $rows            this page
 * @var array   $totals          column totals (grouped reports)
 * @var array   $pager
 * @var string  $basePath
 * @var bool    $canExport
 */
$field = 'padding:8px;border:1px solid var(--input-border);border-radius:6px;font-size:14px;background:#fff';
$exportQuery = fn(string $output) => base_url($basePath) . '?' . http_build_query(array_filter($filters, fn($v) => $v !== '') + ['type' => $type, 'output' => $output]);
?>
<div class="portal-container" style="max-width:1200px">
<?php if (($staff['role'] ?? '') === 'admin'): ?>
  <?php require APP_ROOT . '/views/partials/admin-page-heading.php'; ?>

<div class="admin-report-description"><h2><?= e($typeLabels[$type]) ?></h2><p><?= e($description) ?></p></div>
<?php else: ?>
  <?php require APP_ROOT . '/views/partials/staff-page-heading.php'; ?>
  <div class="staff-report-heading review-card">
    <div>
      <h2 class="font-heading"><?= e($typeLabels[$type]) ?></h2>
      <div style="font-size:13px;color:var(--muted);max-width:640px"><?= e($description) ?></div>
    </div>
    <?php if ($canExport): ?>
      <div class="d-flex gap-2">
        <a href="<?= e($exportQuery('print')) ?>" target="_blank" rel="noopener" class="btn-outline text-decoration-none" style="padding:8px 16px;font-size:13px" data-i18n>Print</a>
        <a href="<?= e($exportQuery('csv')) ?>" class="btn-navy text-decoration-none" style="padding:8px 16px;font-size:13px">Download CSV</a>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>
  <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Report type" id="admin-report-types">
    <?php foreach ($typeLabels as $key => $label): ?>
      <a href="<?= e(base_url($basePath) . '?type=' . $key) ?>" class="status-pill text-decoration-none" <?= $type === $key ? 'aria-current="page"' : '' ?> style="background:<?= $type === $key ? 'var(--navy)' : '#eef1f5' ?>;color:<?= $type === $key ? '#fff' : 'var(--ink)' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <form method="get" action="<?= e(base_url($basePath)) ?>" class="analytics-filters" id="admin-report-filters" role="search" aria-label="Filter the report">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <label data-i18n>Search<input type="text" name="q" value="<?= e($filters['q']) ?>" maxlength="100" style="<?= $field ?>;min-width:200px"></label>
    <label><?= e($dateLabel) ?> from<input type="date" name="from" value="<?= e($filters['from']) ?>" style="<?= $field ?>"></label>
    <label>to<input type="date" name="to" value="<?= e($filters['to']) ?>" style="<?= $field ?>"></label>
    <?php foreach ($filterOptions as $name => [$label, $options]): ?>
      <label><?= e($label) ?>
        <select name="<?= e($name) ?>" style="<?= $field ?>">
          <option value="" data-i18n>All</option>
          <?php foreach ($options as $value => $optLabel): ?><option value="<?= e((string) $value) ?>" <?= $filters[$name] === (string) $value ? 'selected' : '' ?>><?= e($optLabel) ?></option><?php endforeach; ?>
        </select>
      </label>
    <?php endforeach; ?>
    <button type="submit" class="btn-navy">Apply</button>
    <a href="<?= e(base_url($basePath) . '?type=' . $type) ?>" class="btn-outline text-decoration-none" data-i18n>Reset</a>
  </form>
  <?php if ($filterError): ?><div class="auth-error mb-3" role="alert"><?= e($filterError) ?> The date range was not applied.</div><?php endif; ?>

  <?php if (($staff['role'] ?? '') === 'admin'): ?><div class="admin-results-toolbar"><?php endif; ?><div id="admin-report-results" style="font-size:13px;color:var(--muted);margin-bottom:10px" role="status"><b style="color:var(--ink)"><?= number_format($pager['total']) ?></b> record<?= $pager['total'] === 1 ? '' : 's' ?> · <?= e($filterText) ?></div><?php if (($staff['role'] ?? '') === 'admin'): ?><div class="admin-page-actions">    <?php if ($canExport): ?>
      <div class="d-flex gap-2">
        <a href="<?= e($exportQuery('print')) ?>" target="_blank" rel="noopener" class="btn-outline text-decoration-none" style="padding:8px 16px;font-size:13px" data-i18n>Print</a>
        <a href="<?= e($exportQuery('csv')) ?>" class="btn-navy text-decoration-none" style="padding:8px 16px;font-size:13px">Download CSV</a>
      </div>
    <?php endif; ?></div></div><?php endif; ?>

  <?php if (!$rows): ?>
    <div class="review-card" style="font-size:14px;color:var(--muted)">No records match these filters.</div>
  <?php else: ?>
    <div class="table-responsive report-table">
      <table class="table table-sm table-hover">
        <thead><tr><?php foreach ($columns as [$label, $format]): ?><th scope="col" class="<?= $format === 'int' ? 'num' : '' ?>"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr><?php foreach ($columns as $key => [, $format]): ?><td class="<?= $format === 'int' ? 'num' : '' ?>"><?= e(ReportService::formatCell($row[$key] ?? null, $format, $row)) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </tbody>
        <?php if ($totals): ?>
          <tfoot><tr><?php $first = true; foreach ($columns as $key => [, $format]): ?><th class="<?= $format === 'int' ? 'num' : '' ?>"><?= $first ? 'Total (all pages)' : (isset($totals[$key]) ? number_format((int) $totals[$key]) : '') ?></th><?php $first = false; endforeach; ?></tr></tfoot>
        <?php endif; ?>
      </table>
    </div>
    <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
  <?php endif; ?>
</div>
