<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';

$staff = require_admin_login($pdo);

$q = trim((string) ($_GET['q'] ?? ''));
$actionFilter = trim((string) ($_GET['action'] ?? ''));

$sql = 'SELECT * FROM audit_logs WHERE 1=1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (actor_name LIKE ? OR details LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
}
if ($actionFilter !== '') {
    $sql .= ' AND action = ?';
    $params[] = $actionFilter;
}
$sql .= ' ORDER BY created_at DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Audit Logs';
require __DIR__ . '/../includes/header.php';
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
<?php
$footerVariant = 'portal';
require __DIR__ . '/../includes/footer.php';
