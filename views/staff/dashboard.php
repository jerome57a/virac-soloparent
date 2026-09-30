<?php
/**
 * MSWD Social Worker dashboard -- processing-focused, distinct from the
 * admin's stats-focused dashboard. Only mswd_social_worker accounts land
 * here after login, but admins can open it too.
 *
 * @var array $staff
 * @var array $stats
 * @var array $upcomingInterviews
 * @var array $recentlyProcessed
 */
?>
<div class="portal-container">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
      <h1 class="font-heading m-0" style="font-size:26px">MSWD Dashboard</h1>
      <div style="font-size:13px;color:var(--muted)">Your processing queue at a glance</div>
    </div>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <div class="dash-quick-grid" style="margin-bottom:24px">
    <a href="<?= base_url('staff/index.php?type=new&status=Pending Review') ?>" class="dash-card" style="margin-bottom:0;text-decoration:none;display:block">
      <div class="dash-card-label">Pending Review</div>
      <div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $stats['pending'] ?></div>
    </a>
    <a href="<?= base_url('staff/index.php?status=Under Verification') ?>" class="dash-card" style="margin-bottom:0;text-decoration:none;display:block">
      <div class="dash-card-label">Under Verification</div>
      <div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $stats['underVerification'] ?></div>
    </a>
    <a href="<?= base_url('staff/index.php?status=For Revision') ?>" class="dash-card" style="margin-bottom:0;text-decoration:none;display:block">
      <div class="dash-card-label">For Revision</div>
      <div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $stats['forRevision'] ?></div>
    </a>
    <a href="<?= base_url('staff/index.php?type=renewal&status=Pending Review') ?>" class="dash-card" style="margin-bottom:0;text-decoration:none;display:block">
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
        <a href="<?= base_url('staff/application.php?id=' . (int) $iv['application_id']) ?>" class="app-card text-decoration-none" style="color:inherit">
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
        <a href="<?= base_url('staff/application.php?id=' . (int) $app['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
          <div>
            <div class="app-ref"><?= e($app['reference_no']) ?></div>
            <div class="app-date"><?= e($app['full_name']) ?></div>
          </div>
          <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="d-flex flex-wrap gap-2" style="margin-top:8px">
    <a href="<?= base_url('staff/index.php') ?>" class="btn-navy text-decoration-none">View All Applications</a>
    <a href="<?= base_url('staff/appointments.php') ?>" class="btn-outline text-decoration-none">Appointment Management</a>
    <a href="<?= base_url('staff/qr_verify.php') ?>" class="btn-outline text-decoration-none">QR Verification</a>
    <a href="<?= base_url('staff/applicants.php') ?>" class="btn-outline text-decoration-none">Applicant Records</a>
  </div>
</div>
