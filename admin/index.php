<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';

$staff = require_staff_login($pdo);

$statusFilter = (string) ($_GET['status'] ?? 'ALL');
$q = trim((string) ($_GET['q'] ?? ''));
$validStatuses = ['Pending Review', 'Under Verification', 'Approved', 'ID Released', 'Returned'];

$sql = 'SELECT a.*, c.name AS category_name FROM applications a JOIN categories c ON c.id = a.category_id WHERE 1=1';
$params = [];
if ($statusFilter !== 'ALL' && in_array($statusFilter, $validStatuses, true)) {
    $sql .= ' AND a.status = ?';
    $params[] = $statusFilter;
}
if ($q !== '') {
    $sql .= ' AND (a.reference_no LIKE ? OR a.full_name LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
}
$sql .= ' ORDER BY a.submitted_at DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$apps = $stmt->fetchAll();

$pageTitle = 'MSWDO Staff Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="portal-container">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
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
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search reference no. or name" class="form-control" style="max-width:280px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
    <select name="status" class="form-select" style="max-width:220px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
      <option value="ALL" <?= $statusFilter === 'ALL' ? 'selected' : '' ?>>All statuses</option>
      <?php foreach ($validStatuses as $s): ?>
        <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-navy">Filter</button>
  </form>

  <?php if (!$apps): ?>
    <div style="font-size:14px;color:var(--muted)">No applications match this filter.</div>
  <?php endif; ?>

  <?php foreach ($apps as $app): $st = status_style($app['status']); ?>
    <a href="<?= base_url('admin/application.php?id=' . (int) $app['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
      <div>
        <div class="app-ref"><?= e($app['reference_no']) ?></div>
        <div class="app-date"><?= e($app['full_name']) ?> · <?= e($app['category_name']) ?></div>
        <div class="app-date">Submitted <?= e(date('M j, Y', strtotime($app['submitted_at']))) ?></div>
      </div>
      <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></div>
    </a>
  <?php endforeach; ?>
</div>
<?php
$footerVariant = 'portal';
require __DIR__ . '/../includes/footer.php';
