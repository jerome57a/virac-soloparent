<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';

$staff = require_admin_login($pdo);

/** One COUNT query, kept inline since each is a distinct, simple filter -- no benefit to abstracting further. */
function count_where(PDO $pdo, string $where, array $params = []): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$stats = [
    'totalApplicants'   => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'applicant'")->fetchColumn(),
    'activeUsers'       => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn(),
    'totalApplications' => count_where($pdo, "type = 'new'"),
    'pendingNew'        => count_where($pdo, "type = 'new' AND status = 'Pending Review'"),
    'underVerification' => count_where($pdo, "type = 'new' AND status = 'Under Verification'"),
    'approvedNew'       => count_where($pdo, "type = 'new' AND status = 'Approved'"),
    'idReleased'        => count_where($pdo, "type = 'new' AND status = 'ID Released'"),
    'rejectedNew'       => count_where($pdo, "type = 'new' AND status = 'Rejected'"),
    'totalRenewals'     => count_where($pdo, "type = 'renewal'"),
    'pendingRenewals'   => count_where($pdo, "type = 'renewal' AND status = 'Pending Review'"),
];

$statusBreakdown = $pdo->query(
    "SELECT type, status, COUNT(*) AS n FROM applications GROUP BY type, status ORDER BY type, status"
)->fetchAll();

$recentActivity = $pdo->query('SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 15')->fetchAll();

audit_log($pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Report Generation', 'report', null, 'Admin dashboard report viewed');

$pageTitle = 'Reports';
require __DIR__ . '/../includes/header.php';
?>
<div class="portal-container">
  <a href="<?= base_url('admin/dashboard.php') ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">Reports</h1>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <div class="dash-quick-grid" style="margin-bottom:28px">
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Total Applicants</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $stats['totalApplicants'] ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Active Users</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $stats['activeUsers'] ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Total Applications</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $stats['totalApplications'] ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Total Renewals</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $stats['totalRenewals'] ?></div></div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-12 col-lg-6">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Applications</div></div>
        <div class="review-grid">
          <div class="review-row"><div class="k">Pending Review</div><div class="v"><?= $stats['pendingNew'] ?></div></div>
          <div class="review-row"><div class="k">Under Verification</div><div class="v"><?= $stats['underVerification'] ?></div></div>
          <div class="review-row"><div class="k">Approved</div><div class="v"><?= $stats['approvedNew'] ?></div></div>
          <div class="review-row"><div class="k">ID Released</div><div class="v"><?= $stats['idReleased'] ?></div></div>
          <div class="review-row"><div class="k">Rejected</div><div class="v"><?= $stats['rejectedNew'] ?></div></div>
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Renewals</div></div>
        <div class="review-grid">
          <div class="review-row"><div class="k">Pending Review</div><div class="v"><?= $stats['pendingRenewals'] ?></div></div>
          <div class="review-row"><div class="k">Total Renewals</div><div class="v"><?= $stats['totalRenewals'] ?></div></div>
        </div>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Export Data</div></div>
        <div class="d-flex flex-column gap-2">
          <a href="<?= base_url('admin/export_csv.php?export=applications') ?>" class="btn-navy text-decoration-none">Export Applications (CSV)</a>
          <a href="<?= base_url('admin/export_csv.php?export=users') ?>" class="btn-outline text-decoration-none">Export Users (CSV)</a>
        </div>
      </div>
    </div>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Status Breakdown</div></div>
    <div class="review-grid">
      <?php foreach ($statusBreakdown as $row): ?>
        <div class="review-row"><div class="k"><?= $row['type'] === 'renewal' ? 'Renewal' : 'Application' ?> — <?= e($row['status']) ?></div><div class="v"><?= (int) $row['n'] ?></div></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Recent Activity</div></div>
    <?php foreach ($recentActivity as $log): ?>
      <div class="mb-2" style="font-size:13px">
        <b><?= e($log['action']) ?></b> by <?= e($log['actor_name']) ?> — <?= e(date('M j, Y g:i A', strtotime($log['created_at']))) ?>
        <?php if ($log['details']): ?><br><span style="color:var(--muted)"><?= e($log['details']) ?></span><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <a href="<?= base_url('admin/audit_log.php') ?>" style="font-size:13px">View full audit log &rarr;</a>
  </div>
</div>
<?php
$footerVariant = 'portal';
require __DIR__ . '/../includes/footer.php';
