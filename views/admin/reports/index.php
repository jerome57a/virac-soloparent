<?php
/**
 * @var string $type
 * @var array  $typeLabels
 * @var string $dateFrom
 * @var string $dateTo
 * @var string $q
 * @var string $dateFieldLabel  what the date range filters on for this report
 * @var array  $columns         row key => column heading
 * @var array  $rows
 * @var int    $rowLimit
 */
?>
<div class="portal-container" style="max-width:1100px">
  <a href="<?= base_url('admin/dashboard.php') ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px"><?= e($typeLabels[$type]) ?></h1>
    <div class="d-flex gap-2">
      <button type="button" onclick="window.print()" class="btn-outline" style="padding:8px 16px;font-size:13px">🖨️ Print</button>
      <a href="<?= e(query_with(['export' => 'csv'])) ?>" class="btn-navy text-decoration-none" style="padding:8px 16px;font-size:13px">Download CSV</a>
    </div>
  </div>

  <div class="d-flex flex-wrap gap-2 mb-4">
    <?php foreach ($typeLabels as $key => $label): ?>
      <a href="<?= e(query_with(['type' => $key, 'from' => null, 'to' => null, 'q' => null])) ?>" class="status-pill text-decoration-none" style="background:<?= $type === $key ? 'var(--navy)' : '#eef1f5' ?>;color:<?= $type === $key ? '#fff' : 'var(--ink)' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <form method="get" class="d-flex flex-wrap gap-2 mb-4">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search" class="form-control" style="max-width:220px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
    <label style="font-size:12px;color:var(--muted);display:flex;align-items:center;gap:6px">
      <?= e($dateFieldLabel) ?> from
      <input type="date" name="from" value="<?= e($dateFrom) ?>" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px">
    </label>
    <label style="font-size:12px;color:var(--muted);display:flex;align-items:center;gap:6px">
      to
      <input type="date" name="to" value="<?= e($dateTo) ?>" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px">
    </label>
    <button type="submit" class="btn-navy">Filter</button>
  </form>

  <div style="font-size:13px;color:var(--muted);margin-bottom:10px"><?= count($rows) ?> record(s)<?= count($rows) === $rowLimit ? ' (showing first ' . $rowLimit . ')' : '' ?></div>

  <?php if (!$rows): ?>
    <div style="font-size:14px;color:var(--muted)">No records match this filter.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover" style="font-size:13px">
        <thead>
          <tr>
            <?php foreach ($columns as $label): ?><th style="color:var(--muted);font-weight:700"><?= e($label) ?></th><?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <?php foreach ($columns as $key => $label): ?>
                <td>
                  <?php $val = $row[$key] ?? ''; ?>
                  <?php if (in_array($key, ['created_at', 'decided_at', 'submitted_at', 'scheduled_at'], true) && $val): ?>
                    <?= e(date('M j, Y g:i A', strtotime($val))) ?>
                  <?php elseif ($key === 'id_expires_at' && $val): ?>
                    <?= e(date('M j, Y', strtotime($val))) ?>
                  <?php else: ?>
                    <?= e((string) $val) ?>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
