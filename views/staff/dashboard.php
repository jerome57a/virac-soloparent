<?php
/**
 * MSWD Social Worker dashboard -- the processing queue at a glance: what's
 * waiting, which documents need verifying, today's appointments, and
 * decision totals. All counts are live queries.
 *
 * @var array $staff
 * @var array $stats              pending, underVerification, forRevision, pendingRenewals, todaysAppointments, approved, rejected
 * @var array $docBacklog         applications, documents (awaiting verification)
 * @var array $verificationQueue  oldest-first applications with unverified documents
 * @var array $todaysAppointments  today's BOOKED appointments only (Manila date); their count is "expected today"
 * @var bool  $bookingEnabled      interview date booking is on (applicants choose their own date)
 * @var array $upcomingInterviews
 * @var array $recentlyProcessed
 */
?>
<div class="portal-container">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
      <h1 class="page-title">MSWD Dashboard</h1>
      <div style="font-size:13px;color:var(--muted)">Your processing queue at a glance · <?= e(date('l, F j, Y')) ?></div>
    </div>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <nav class="staff-section-links" aria-label="Dashboard sections" data-i18n-attrs="aria-label"><a href="#verification-queue" data-i18n>Documents to Verify</a><a href="#staff-today" data-i18n>Today's Appointments</a><a href="#staff-upcoming" data-i18n>Upcoming Interviews</a><a href="#staff-recent" data-i18n>Recently Processed by Staff</a></nav>
  <div class="dash-section-title">Needs attention</div>
  <div class="stat-grid">
    <a href="<?= base_url('staff/index.php?type=new&status=Pending Review') ?>" class="stat-tile">
      <div class="stat-label" data-i18n>Pending Review</div>
      <div class="stat-value"><?= (int) $stats['pending'] ?></div>
      <div class="stat-sub">new applications</div>
    </a>
    <a href="<?= base_url('staff/index.php?status=Under Verification') ?>" class="stat-tile">
      <div class="stat-label" data-i18n>Under Verification</div>
      <div class="stat-value"><?= (int) $stats['underVerification'] ?></div>
      <div class="stat-sub">being checked</div>
    </a>
    <a href="#verification-queue" class="stat-tile">
      <div class="stat-label">Documents to Verify</div>
      <div class="stat-value <?= (int) $docBacklog['documents'] > 0 ? 'stat-alert' : '' ?>"><?= (int) $docBacklog['documents'] ?></div>
      <div class="stat-sub">across <?= (int) $docBacklog['applications'] ?> application<?= (int) $docBacklog['applications'] === 1 ? '' : 's' ?></div>
    </a>
    <a href="<?= base_url('staff/index.php?status=For Revision') ?>" class="stat-tile">
      <div class="stat-label">Pending Corrections</div>
      <div class="stat-value"><?= (int) $stats['forRevision'] ?></div>
      <div class="stat-sub">waiting on applicant</div>
    </a>
    <a href="<?= base_url('staff/index.php?type=renewal&status=Pending Review') ?>" class="stat-tile">
      <div class="stat-label">Pending Renewals</div>
      <div class="stat-value"><?= (int) $stats['pendingRenewals'] ?></div>
      <div class="stat-sub">renewal requests</div>
    </a>
    <a href="<?= base_url('staff/appointments.php') ?>" class="stat-tile">
      <div class="stat-label">Expected Today</div>
      <div class="stat-value"><?= (int) $stats['todaysAppointments'] ?></div>
      <div class="stat-sub">applicants booked for today</div>
    </a>
  </div>

  <div class="dash-section-title">Decisions to date</div>
  <div class="stat-grid">
    <a href="<?= base_url('staff/index.php?status=Approved') ?>" class="stat-tile">
      <div class="stat-label" data-i18n>Approved</div>
      <div class="stat-value"><?= (int) $stats['approved'] ?></div>
      <div class="stat-sub">incl. IDs released</div>
    </a>
    <a href="<?= base_url('staff/index.php?status=Rejected') ?>" class="stat-tile">
      <div class="stat-label" data-i18n>Rejected</div>
      <div class="stat-value"><?= (int) $stats['rejected'] ?></div>
      <div class="stat-sub">not approved</div>
    </a>
  </div>

  <div class="dash-two-col">
    <div class="review-card" id="verification-queue">
      <div class="review-card-head"><div class="title">Document Verification Queue</div></div>
      <?php if (!$verificationQueue): ?>
        <div class="empty-state compact"><div class="empty-state-title">All caught up</div>No documents are waiting for verification.</div>
      <?php else: ?>
        <?php foreach ($verificationQueue as $q): ?>
          <a href="<?= base_url('staff/application.php?id=' . (int) $q['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
            <div>
              <div class="app-ref"><?= e($q['reference_no']) ?></div>
              <div class="app-date"><?= e($q['full_name']) ?> · waiting since <?= e(date('M j, Y', strtotime($q['oldest_upload']))) ?></div>
            </div>
            <span class="status-pill" style="background:#fdf1d6;color:#8a6d1a"><?= (int) $q['pending_docs'] ?> to verify</span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="review-card" id="staff-today">
      <div class="review-card-head"><div class="title">Today's Appointments</div></div>
      <div class="small text-muted mb-2">Total expected today: <b><?= (int) $stats['todaysAppointments'] ?></b></div>
      <?php if (!$todaysAppointments): ?>
        <div class="empty-state compact">No applicants are booked for today.</div>
      <?php else: ?>
        <?php foreach ($todaysAppointments as $iv): ?>
          <a href="<?= base_url('staff/application.php?id=' . (int) $iv['application_id']) ?>" class="app-card text-decoration-none" style="color:inherit">
            <div>
              <div class="app-ref"><?= e($iv['reference_no']) ?></div>
              <div class="app-date"><?= e($iv['full_name']) ?> · <?= e($iv['location']) ?></div>
            </div>
            <div class="app-date"><?= e(interview_time_text($iv['scheduled_at'], interview_is_date_only($iv))) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="dash-two-col">
    <div class="review-card" id="staff-upcoming">
      <div class="review-card-head"><div class="title">Upcoming Interviews</div></div>
      <?php if (!$upcomingInterviews): ?>
        <div class="empty-state compact"><?= !empty($bookingEnabled) ? 'No upcoming interviews. Interviews appear here once applicants book a date or staff assign a schedule.' : 'No upcoming interviews. Interviews appear here once MSWDO staff assign a schedule.' ?></div>
      <?php else: ?>
        <?php foreach ($upcomingInterviews as $iv): ?>
          <a href="<?= base_url('staff/application.php?id=' . (int) $iv['application_id']) ?>" class="app-card text-decoration-none" style="color:inherit">
            <div>
              <div class="app-ref"><?= e($iv['reference_no']) ?></div>
              <div class="app-date"><?= e($iv['full_name']) ?> · <?= e($iv['location']) ?></div>
            </div>
            <div class="app-date"><?= e(interview_schedule_text($iv['scheduled_at'], interview_is_date_only($iv), 'M j, Y g:i A')) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="review-card" id="staff-recent">
      <div class="review-card-head"><div class="title">Recently Processed by Staff</div></div>
      <?php if (!$recentlyProcessed): ?>
        <div class="empty-state compact">No applications have been processed yet. Recent staff activity will be listed here.</div>
      <?php else: ?>
        <?php foreach ($recentlyProcessed as $app): $st = status_style($app['status']); ?>
          <a href="<?= base_url('staff/application.php?id=' . (int) $app['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
            <div>
              <div class="app-ref"><?= e($app['reference_no']) ?></div>
              <div class="app-date"><?= e($app['full_name']) ?></div>
              <?php if ($app['reviewer_name']): ?><div class="app-date">Last reviewed by <?= e($app['reviewer_name']) ?></div><?php endif; ?>
            </div>
            <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="d-flex flex-wrap gap-2" style="margin-top:8px">
    <a href="<?= base_url('staff/index.php') ?>" class="btn-navy text-decoration-none">View All Applications</a>
    <a href="<?= base_url('staff/appointments.php') ?>" class="btn-outline text-decoration-none" data-i18n>Appointment Management</a>
    <a href="<?= base_url('staff/applicants.php') ?>" class="btn-outline text-decoration-none" data-i18n>Applicant Records</a>
    <a href="<?= base_url('staff/reports.php') ?>" class="btn-outline text-decoration-none" data-i18n>Reports</a>
  </div>
</div>
