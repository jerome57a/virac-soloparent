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
 * @var array $readyToSchedule    documents verified, no interview date yet (first 5; the total is stats[readyToSchedule])
 * @var array $idReleaseQueue     approved applications waiting for the ID handover (first 5)
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

  <?php if (!empty($flashMessage)): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>
  <nav class="staff-section-links" aria-label="Dashboard sections" data-i18n-attrs="aria-label"><a href="#verification-queue" data-i18n>Documents to Verify</a><a href="#ready-to-schedule">Ready to Schedule</a><a href="#staff-today" data-i18n>Today's Appointments</a><a href="#staff-upcoming" data-i18n>Upcoming Interviews</a><a href="#id-release-queue">ID Release</a><a href="#staff-recent" data-i18n>Recently Processed by MSWDO Staff</a></nav>
  <div class="dash-section-title">Needs attention</div>
  <div class="stat-grid stat-grid-quad">
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
    <a href="#verification-queue" class="stat-tile">
      <div class="stat-label">Re-uploaded Documents</div>
      <div class="stat-value <?= (int) $docBacklog['resent_applications'] > 0 ? 'stat-alert' : '' ?>"><?= (int) $docBacklog['resent_applications'] ?></div>
      <div class="stat-sub">Solo Parents who sent a document again</div>
    </a>
    <a href="#needs-outcome" class="stat-tile">
      <div class="stat-label">Interviews to Confirm</div>
      <div class="stat-value <?= (int) $stats['needsOutcome'] > 0 ? 'stat-alert' : '' ?>"><?= (int) $stats['needsOutcome'] ?></div>
      <div class="stat-sub">date passed, no result yet</div>
    </a>
    <a href="#ready-to-schedule" class="stat-tile">
      <div class="stat-label">Ready to Schedule</div>
      <div class="stat-value <?= (int) $stats['readyToSchedule'] > 0 ? 'stat-alert' : '' ?>"><?= (int) $stats['readyToSchedule'] ?></div>
      <div class="stat-sub">documents verified, no date yet</div>
    </a>
    <a href="<?= base_url('staff/index.php?status=For Revision') ?>" class="stat-tile">
      <div class="stat-label">Pending Corrections</div>
      <div class="stat-value"><?= (int) $stats['forRevision'] ?></div>
      <div class="stat-sub">waiting on Solo Parent</div>
    </a>
    <a href="<?= base_url('staff/index.php?type=renewal&status=Pending Review') ?>" class="stat-tile">
      <div class="stat-label">Pending Renewals</div>
      <div class="stat-value"><?= (int) $stats['pendingRenewals'] ?></div>
      <div class="stat-sub">renewal requests</div>
    </a>
    <a href="<?= base_url('staff/appointments.php') ?>" class="stat-tile">
      <div class="stat-label">Expected Today</div>
      <div class="stat-value"><?= (int) $stats['todaysAppointments'] ?></div>
      <div class="stat-sub">Solo Parents booked for today</div>
    </a>
    <a href="#id-release-queue" class="stat-tile">
      <div class="stat-label">ID Release</div>
      <div class="stat-value"><?= (int) $stats['awaitingRelease'] ?></div>
      <div class="stat-sub">approved, ID not handed over</div>
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

  <?php if (!empty($awaitingOutcome)): ?>
  <div class="review-card" id="needs-outcome" style="border-color:var(--red)">
    <div class="review-card-head"><div class="title">Confirm who attended</div><a class="small" href="<?= base_url('staff/appointments.php') ?>">See all (<?= (int) $stats['needsOutcome'] ?>)</a></div>
    <div class="small text-muted mb-2">These interview dates have passed. Record the result if the Solo Parent came, or mark it missed if not (they can then choose a new day; no SMS is sent). If nobody confirms within <?= (int) AppointmentService::RELEASE_GRACE_WEEKDAYS ?> weekdays the system reopens the booking by itself.</div>
    <?php foreach ($awaitingOutcome as $iv): ?>
      <div class="appt-row">
        <a href="<?= base_url('staff/application.php?id=' . (int) $iv['application_id']) ?>#interview" class="text-decoration-none" style="color:inherit;min-width:0;flex:1 1 220px">
          <div class="app-ref"><?= e($iv['reference_no']) ?> <span style="font-weight:400;color:var(--muted)">· <?= e($iv['full_name']) ?></span></div>
          <div class="app-date"><?= e(interview_schedule_text($iv['scheduled_at'], interview_is_date_only($iv), 'D, M j, Y g:i A')) ?></div>
        </a>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <a href="<?= base_url('staff/application.php?id=' . (int) $iv['application_id']) ?>#interview" class="btn-navy text-decoration-none" style="padding:6px 12px;font-size:12px">Attended</a>
          <form method="post" action="<?= base_url('staff/appointments.php') ?>" class="m-0" onsubmit="return confirm('Mark this Solo Parent as not attended? They can then choose a new day.');">
            <?= csrf_field() ?>
            <input type="hidden" name="appointment_id" value="<?= (int) $iv['id'] ?>">
            <input type="hidden" name="return_to" value="dashboard">
            <button type="submit" name="action" value="missed" class="btn-outline" style="padding:6px 12px;font-size:12px">Did not attend</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

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
            <span>
              <?php if ((int) $q['resent_docs'] > 0): ?><span class="status-pill" style="background:#ffe3c2;color:#8a3b00;font-weight:700">Re-uploaded<?= (int) $q['resent_docs'] > 1 ? ' (' . (int) $q['resent_docs'] . ')' : '' ?></span><?php endif; ?>
              <span class="status-pill" style="background:#fdf1d6;color:#7a5f12"><?= (int) $q['pending_docs'] ?> to verify</span>
            </span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="review-card" id="ready-to-schedule">
      <div class="review-card-head"><div class="title">Ready to Schedule</div><a class="small" href="<?= base_url('staff/appointments.php#staff-needs-schedule') ?>">See all (<?= (int) $stats['readyToSchedule'] ?>)</a></div>
      <div class="small text-muted mb-2">Documents are verified. Open the application and press <b>Schedule Date and Send SMS</b>.</div>
      <?php if (!$readyToSchedule): ?>
        <div class="empty-state compact"><div class="empty-state-title">Nothing to schedule</div>Applications appear here once every required document is verified.</div>
      <?php else: ?>
        <?php foreach ($readyToSchedule as $r): ?>
          <a href="<?= base_url('staff/application.php?id=' . (int) $r['id']) ?>#schedule-interview" class="app-card text-decoration-none" style="color:inherit">
            <div>
              <div class="app-ref"><?= e($r['reference_no']) ?></div>
              <div class="app-date"><?= e($r['full_name']) ?> · submitted <?= e(date('M j, Y', strtotime($r['submitted_at']))) ?></div>
            </div>
            <span class="status-pill" style="background:#dce8f5;color:#1b3a6b">Schedule</span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="dash-two-col">
    <div class="review-card" id="staff-today">
      <div class="review-card-head"><div class="title">Today's Appointments</div></div>
      <div class="small text-muted mb-2">Total expected today: <b><?= (int) $stats['todaysAppointments'] ?></b></div>
      <?php if (!$todaysAppointments): ?>
        <div class="empty-state compact">No Solo Parents are booked for today.</div>
      <?php else: ?>
        <?php foreach ($todaysAppointments as $iv): ?>
          <a href="<?= base_url('staff/application.php?id=' . (int) $iv['application_id']) ?>" class="app-card text-decoration-none" style="color:inherit">
            <div>
              <div class="app-ref"><?= e($iv['reference_no']) ?></div>
              <div class="app-date"><?= e($iv['full_name']) ?> · <?= ($iv['booking_source'] ?? '') === 'STAFF' ? 'scheduled by MSWDO' : 'chosen by the Solo Parent' ?></div>
            </div>
            <div class="app-date"><?= e(interview_time_text($iv['scheduled_at'], interview_is_date_only($iv), 'g:i A', 'Any time today')) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="review-card" id="staff-upcoming">
      <div class="review-card-head"><div class="title">Upcoming Interviews</div></div>
      <?php if (!$upcomingInterviews): ?>
        <div class="empty-state compact"><?= !empty($bookingEnabled) ? 'No upcoming interviews. Interviews appear here once Solo Parents book a date or MSWDO staff assign a schedule.' : 'No upcoming interviews. Interviews appear here once MSWDO staff assign a schedule.' ?></div>
      <?php else: ?>
        <?php foreach ($upcomingInterviews as $iv): ?>
          <a href="<?= base_url('staff/application.php?id=' . (int) $iv['application_id']) ?>" class="app-card text-decoration-none" style="color:inherit">
            <div>
              <div class="app-ref"><?= e($iv['reference_no']) ?></div>
              <div class="app-date"><?= e($iv['full_name']) ?> · <?= ($iv['booking_source'] ?? '') === 'STAFF' ? 'scheduled by MSWDO' : 'chosen by the Solo Parent' ?></div>
            </div>
            <div class="app-date"><?= e(interview_schedule_text($iv['scheduled_at'], interview_is_date_only($iv), 'D, M j, Y g:i A')) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="dash-two-col">
    <div class="review-card" id="id-release-queue">
      <div class="review-card-head"><div class="title">ID Release</div><a class="small" href="<?= base_url('staff/index.php?status=Approved') ?>">See all (<?= (int) $stats['awaitingRelease'] ?>)</a></div>
      <div class="small text-muted mb-2">Approved applications waiting for the ID handover. Schedule the release, then confirm the handover.</div>
      <?php if (!$idReleaseQueue): ?>
        <div class="empty-state compact"><div class="empty-state-title">No IDs waiting</div>Approved applications appear here until their ID is handed over.</div>
      <?php else: ?>
        <?php foreach ($idReleaseQueue as $r): ?>
          <a href="<?= base_url('staff/application.php?id=' . (int) $r['id']) ?>#id-release" class="app-card text-decoration-none" style="color:inherit">
            <div>
              <div class="app-ref"><?= e($r['reference_no']) ?></div>
              <div class="app-date"><?= e($r['full_name']) ?></div>
            </div>
            <?php if ($r['release_at']): ?>
              <span class="status-pill" style="background:#dcf3e2;color:#1c7a3f">Release <?= e(date('M j, g:i A', strtotime($r['release_at']))) ?></span>
            <?php else: ?>
              <span class="status-pill" style="background:#fdf1d6;color:#7a5f12">Schedule release</span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="review-card" id="staff-recent">
      <div class="review-card-head"><div class="title">Recently Processed by MSWDO Staff</div></div>
      <?php if (!$recentlyProcessed): ?>
        <div class="empty-state compact">No applications have been processed yet. Recent MSWDO staff activity will be listed here.</div>
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
    <a href="<?= base_url('staff/applicants.php') ?>" class="btn-outline text-decoration-none" data-i18n>Solo Parent Records</a>
    <a href="<?= base_url('staff/reports.php') ?>" class="btn-outline text-decoration-none" data-i18n>Reports</a>
  </div>
</div>
