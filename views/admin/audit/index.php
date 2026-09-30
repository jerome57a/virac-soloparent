<?php
/**
 * @var array  $staff
 * @var string $q
 * @var string $actionFilter
 * @var string $dateFrom
 * @var string $dateTo
 * @var array  $actions  distinct action names for the filter dropdown
 * @var array  $logs
 */
?>
<div class="portal-container">
  <a href="<?= base_url('admin/dashboard.php') ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">Audit Logs</h1>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <div style="font-size:13px;color:var(--muted);margin-bottom:16px">Showing the most recent 300 events. This log is read-only — no one can delete or edit audit records through this system.</div>

  <form method="get" class="d-flex flex-wrap gap-2 mb-4">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search actor or details" class="form-control" style="max-width:260px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
    <select name="action" class="form-select" style="max-width:220px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
      <option value="">All actions</option>
      <?php foreach ($actions as $a): ?>
        <option value="<?= e($a) ?>" <?= $actionFilter === $a ? 'selected' : '' ?>><?= e($a) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="date" name="from" value="<?= e($dateFrom) ?>" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px">
    <input type="date" name="to" value="<?= e($dateTo) ?>" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px">
    <button type="submit" class="btn-navy">Filter</button>
  </form>

  <?php if (!$logs): ?>
    <div style="font-size:14px;color:var(--muted)">No audit events match this filter.</div>
  <?php endif; ?>

  <?php foreach ($logs as $log): ?>
    <div class="app-card" style="align-items:flex-start">
      <div>
        <div class="app-ref"><?= e($log['action']) ?></div>
        <div class="app-date"><?= e($log['actor_name']) ?> (<?= e($log['actor_role']) ?>)<?php if ($log['entity_type']): ?> · <?= e($log['entity_type']) ?><?php if ($log['entity_id']): ?> #<?= (int) $log['entity_id'] ?><?php endif; ?><?php endif; ?></div>
        <?php if ($log['details']): ?><div class="app-date"><?= e($log['details']) ?></div><?php endif; ?>
      </div>
      <div style="text-align:right">
        <div class="app-date"><?= e(date('M j, Y g:i A', strtotime($log['created_at']))) ?></div>
        <?php if ($log['ip_address']): ?><div style="font-size:11px;color:var(--faint)"><?= e($log['ip_address']) ?></div><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
