<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';

$staff = require_staff_login($pdo);
$isAdmin = $staff['role'] === 'admin';

function count_apps(PDO $pdo, string $where, array $params = []): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

if ($isAdmin) {
    $stats = [
        'totalApplicants'   => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'applicant'")->fetchColumn(),
        'activeUsers'       => (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 1')->fetchColumn(),
        'totalApplications' => count_apps($pdo, "type = 'new'"),
        'pendingNew'        => count_apps($pdo, "type = 'new' AND status = 'Pending Review'"),
        'underVerification' => count_apps($pdo, "status = 'Under Verification'"),
        'approvedNew'       => count_apps($pdo, "type = 'new' AND status = 'Approved'"),
        'rejectedNew'       => count_apps($pdo, "type = 'new' AND status = 'Rejected'"),
        'totalRenewals'     => count_apps($pdo, "type = 'renewal'"),
        'pendingRenewals'   => count_apps($pdo, "type = 'renewal' AND status = 'Pending Review'"),
    ];
    $recentActivity = $pdo->query('SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT 8')->fetchAll();
} else {
    $stats = [
        'pending'          => count_apps($pdo, "type = 'new' AND status = 'Pending Review'"),
        'underVerification' => count_apps($pdo, "status = 'Under Verification'"),
        'forRevision'      => count_apps($pdo, "status = 'For Revision'"),
        'pendingRenewals'  => count_apps($pdo, "type = 'renewal' AND status = 'Pending Review'"),
    ];
    $upcomingInterviews = $pdo->query(
        "SELECT i.*, a.reference_no, a.full_name FROM interviews i
         JOIN applications a ON a.id = i.application_id
         WHERE i.scheduled_at >= NOW() ORDER BY i.scheduled_at ASC LIMIT 5"
    )->fetchAll();
    $recentlyProcessed = $pdo->prepare(
        "SELECT * FROM applications WHERE reviewed_by = ? ORDER BY updated_at DESC LIMIT 5"
    );
    $recentlyProcessed->execute([$staff['id']]);
    $recentlyProcessed = $recentlyProcessed->fetchAll();
}

$pageTitle = $isAdmin ? 'Administrator Dashboard' : 'MSWD Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="portal-container">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
      <h1 class="font-heading m-0" style="font-size:26px"><?= $isAdmin ? 'Administrator Dashboard' : 'MSWD Dashboard' ?></h1>
      <div style="font-size:13px;color:var(--muted)"><?= $isAdmin ? 'System-level overview' : 'Your processing queue at a glance' ?></div>
    </div>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <?php if ($isAdmin): ?>

    <div class="dash-quick-grid" style="margin-bottom:24px">
      <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Total Applicants</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $stats['totalApplicants'] ?></div></div>
      <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Active Users</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $stats['activeUsers'] ?></div></div>
      <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Total Applications</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $stats['totalApplications'] ?></div></div>
      <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Total Renewals</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $stats['totalRenewals'] ?></div></div>
    </div>

    <div class="review-card">
      <div class="review-card-head"><div class="title">Applications at a Glance</div></div>
      <div class="review-grid">
        <a href="<?= base_url('admin/index.php?type=new&status=Pending Review') ?>" class="review-row text-decoration-none"><div class="k">Pending Review</div><div class="v"><?= $stats['pendingNew'] ?></div></a>
        <a href="<?= base_url('admin/index.php?status=Under Verification') ?>" class="review-row text-decoration-none"><div class="k">Under Verification</div><div class="v"><?= $stats['underVerification'] ?></div></a>
        <a href="<?= base_url('admin/index.php?type=new&status=Approved') ?>" class="review-row text-decoration-none"><div class="k">Approved</div><div class="v"><?= $stats['approvedNew'] ?></div></a>
        <a href="<?= base_url('admin/index.php?type=new&status=Rejected') ?>" class="review-row text-decoration-none"><div class="k">Rejected</div><div class="v"><?= $stats['rejectedNew'] ?></div></a>
        <a href="<?= base_url('admin/index.php?type=renewal&status=Pending Review') ?>" class="review-row text-decoration-none"><div class="k">Pending Renewals</div><div class="v"><?= $stats['pendingRenewals'] ?></div></a>
      </div>
    </div>

    <div class="dash-quick-grid" style="margin-bottom:24px">
      <a href="<?= base_url('admin/index.php') ?>" class="dash-quick-card">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/></svg>
        All Applications
      </a>
      <a href="<?= base_url('admin/users.php') ?>" class="dash-quick-card">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Manage Users
      </a>
      <a href="<?= base_url('admin/reports.php') ?>" class="dash-quick-card">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        Reports &amp; Export
      </a>
      <a href="<?= base_url('admin/audit_log.php') ?>" class="dash-quick-card">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6"/><path d="M9 16h6"/><path d="M13 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M13 3v5h5"/></svg>
        Audit Logs
      </a>
    </div>

    <div class="review-card">
      <div class="review-card-head"><div class="title">Recent Activity</div></div>
      <?php foreach ($recentActivity as $log): ?>
        <div class="mb-2" style="font-size:13px">
          <b><?= e($log['action']) ?></b> by <?= e($log['actor_name']) ?> — <?= e(date('M j, Y g:i A', strtotime($log['created_at']))) ?>
        </div>
      <?php endforeach; ?>
      <a href="<?= base_url('admin/audit_log.php') ?>" style="font-size:13px">View full audit log &rarr;</a>
    </div>

  <?php else: ?>

    <div class="dash-quick-grid" style="margin-bottom:24px">
      <a href="<?= base_url('admin/index.php?type=new&status=Pending Review') ?>" class="dash-card" style="margin-bottom:0;text-decoration:none;display:block">
        <div class="dash-card-label">Pending Review</div>
        <div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $stats['pending'] ?></div>
      </a>
      <a href="<?= base_url('admin/index.php?status=Under Verification') ?>" class="dash-card" style="margin-bottom:0;text-decoration:none;display:block">
        <div class="dash-card-label">Under Verification</div>
        <div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $stats['underVerification'] ?></div>
      </a>
      <a href="<?= base_url('admin/index.php?status=For Revision') ?>" class="dash-card" style="margin-bottom:0;text-decoration:none;display:block">
        <div class="dash-card-label">For Revision</div>
        <div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $stats['forRevision'] ?></div>
      </a>
      <a href="<?= base_url('admin/index.php?type=renewal&status=Pending Review') ?>" class="dash-card" style="margin-bottom:0;text-decoration:none;display:block">
        <div class="dash-card-label">Pending Renewals</div>
        <div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $stats['pendingRenewals'] ?></div>
      </a>
    </div>

    <div class="review-card">
      <div class="review-card-head"><div class="title">Upcoming Interviews</div></div>
      <?php if (!$upcomingInterviews): ?>
        <div style="font-size:13px;color:var(--muted)">No interviews scheduled.</div>
      <?php else: ?>
        <?php foreach ($upcomingInterviews as $iv): ?>
          <a href="<?= base_url('admin/application.php?id=' . (int) $iv['application_id']) ?>" class="app-card text-decoration-none" style="color:inherit">
            <div>
              <div class="app-ref"><?= e($iv['reference_no']) ?></div>
              <div class="app-date"><?= e($iv['full_name']) ?> · <?= e($iv['location']) ?></div>
            </div>
            <div class="app-date"><?= e(date('M j, Y g:i A', strtotime($iv['scheduled_at']))) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="review-card">
      <div class="review-card-head"><div class="title">Recently Processed by You</div></div>
      <?php if (!$recentlyProcessed): ?>
        <div style="font-size:13px;color:var(--muted)">You haven't processed any applications yet.</div>
      <?php else: ?>
        <?php foreach ($recentlyProcessed as $app): $st = status_style($app['status']); ?>
          <a href="<?= base_url('admin/application.php?id=' . (int) $app['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
            <div>
              <div class="app-ref"><?= e($app['reference_no']) ?></div>
              <div class="app-date"><?= e($app['full_name']) ?></div>
            </div>
            <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <a href="<?= base_url('admin/index.php') ?>" class="btn-navy text-decoration-none" style="display:inline-block;margin-top:8px">View All Applications</a>

  <?php endif; ?>
</div>
<?php
$footerVariant = 'portal';
require __DIR__ . '/../includes/footer.php';
