<?php
/**
 * MSWD Social Worker dashboard -- the processing queue at a glance. The "Needs attention" tiles stay on top; the detailed lists
 * behind them are hidden and open below the tiles when a tile (or a section link) is clicked, one list at a time. All counts are
 * live queries.
 *
 * @var array $staff
 * @var array $stats              pending, underVerification, forRevision, pendingRenewals, todaysAppointments, needsOutcome, approved,
 *                                readyToSchedule, awaitingRelease, releaseOverdue, releaseUnscheduled, rejected
 * @var array $docBacklog         applications, documents, resent_applications (awaiting verification)
 * @var array $verificationQueue  applications with unverified documents
 * @var array $awaitingOutcome    interviews whose date passed with no result (first 5)
 * @var array $readyToSchedule    documents verified, no interview date yet (first 5)
 * @var array $idReleaseQueue     approved applications waiting for the ID handover (first 5)
 * @var array $pendingNewList     new applications waiting for review (first 5)
 * @var array $pendingRenewalList renewals waiting for review (first 5)
 * @var array $followUp           missed and not rebooked for 7 days
 * @var int   $failedTextCount    failed texts and ID expiry texts not sent
 * @var array $failedTexts        the latest failed texts (first 5)
 * @var array $todaysAppointments  today's BOOKED appointments only (Manila date); their count is "expected today"
 * @var bool  $bookingEnabled
 * @var array $upcomingInterviews
 * @var array $recentlyProcessed
 */
foreach (['pendingNewList', 'pendingRenewalList', 'followUp', 'failedTexts', 'awaitingOutcome', 'verificationQueue', 'readyToSchedule', 'idReleaseQueue'] as $listVar) { $$listVar = $$listVar ?? []; }
$failedTextCount = $failedTextCount ?? 0;
$appLink = fn(int $id, string $anchor = '') => base_url('staff/application.php?id=' . $id) . $anchor;
/** A tile that opens a hidden list below the tiles ($panel) or, without a panel, goes to $href. */
$tile = function (string $label, int $value, string $sub, ?string $panel, string $href = '', bool $alert = false, bool $i18n = false): void { ?>
    <a href="<?= $panel ? '#' . e($panel) : e($href) ?>" class="stat-tile"<?= $panel ? ' data-panel="' . e($panel) . '" aria-controls="' . e($panel) . '" aria-expanded="false"' : '' ?>>
      <div class="stat-label"<?= $i18n ? ' data-i18n' : '' ?>><?= e($label) ?></div>
      <div class="stat-value <?= $alert && $value > 0 ? 'stat-alert' : '' ?>"><?= $value ?></div>
      <div class="stat-sub"><?= e($sub) ?></div>
    </a>
<?php };
$close = '<button type="button" class="dash-panel-close js-panel-close" aria-label="Hide this list">Hide</button>';
?>
<link rel="stylesheet" href="<?= public_asset('css/dashboard-attention.css') ?>">
<div class="portal-container" id="staff-dashboard">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
      <h1 class="page-title">MSWD Dashboard</h1>
      <div style="font-size:13px;color:var(--muted)">Your processing queue at a glance · <?= e(date('l, F j, Y')) ?></div>
    </div>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <?php if (!empty($flashMessage)): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>
  <?php if (!empty($smsNotice)) { require APP_ROOT . '/views/staff/applications/sms-notice.php'; } ?>
  <nav class="staff-section-links" aria-label="Dashboard sections" data-i18n-attrs="aria-label"><a href="#verification-queue" data-i18n>Documents to Verify</a><a href="#ready-to-schedule">Ready to Schedule</a><a href="#staff-today" data-i18n>Today's Appointments</a><a href="#staff-upcoming" data-i18n>Upcoming Interviews</a><a href="#id-release-queue">ID Release</a><a href="#staff-recent" data-i18n>Recently Processed by MSWDO Staff</a></nav>
  <div class="dash-section-title">Needs attention</div>
  <div class="stat-grid stat-grid-quad">
    <?php
    $tile('Pending Review', (int) $stats['pending'], 'new applications', 'pending-review', '', false, true);
    $tile('Under Verification', (int) $stats['underVerification'], 'being checked', null, base_url('staff/index.php?status=Under Verification'), false, true);
    $tile('Documents to Verify', (int) $docBacklog['documents'], 'across ' . (int) $docBacklog['applications'] . ' application' . ((int) $docBacklog['applications'] === 1 ? '' : 's'), 'verification-queue', '', true);
    $tile('Re-uploaded Documents', (int) $docBacklog['resent_applications'], 'Solo Parents who sent a document again', 'verification-queue', '', true);
    $tile('Interviews to Confirm', (int) $stats['needsOutcome'], 'date passed, no result yet', 'needs-outcome', '', true);
    $tile('Ready to Schedule', (int) $stats['readyToSchedule'], 'documents verified, no date yet', 'ready-to-schedule', '', true);
    $tile('Pending Corrections', (int) $stats['forRevision'], 'waiting on Solo Parent', null, base_url('staff/index.php?status=For Revision'));
    $tile('Pending Renewals', (int) $stats['pendingRenewals'], 'renewal requests', 'pending-renewals');
    $tile('Expected Today', (int) $stats['todaysAppointments'], 'Solo Parents booked for today', 'staff-today');
    $tile('ID Release', (int) $stats['awaitingRelease'], 'approved, ID not handed over', 'id-release-queue');
    $tile('ID Release Date Passed', (int) ($stats['releaseOverdue'] ?? 0), 'ID still at the office', 'id-release-queue', '', true);
    $tile('Missed and Not Rebooked', count($followUp), 'no new date for 7 days: call them', 'missed-followup', '', true);
    $tile('Failed Texts', (int) $failedTextCount, 'SMS not sent', 'failed-texts', '', true);
    ?>
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

  <p class="dash-panel-hint" id="dash-panel-hint">Click a tile or a section link to see its list here.</p>

  <!-- The detailed lists: hidden until their tile or section link is clicked (one at a time). -->
  <div class="review-card dash-panel" id="needs-outcome" hidden style="border-color:var(--red)">
    <div class="review-card-head"><div class="title">Confirm who attended</div><span class="d-flex gap-2 align-items-center"><a class="small" href="<?= base_url('staff/appointments.php') ?>">See all (<?= (int) $stats['needsOutcome'] ?>)</a><?= $close ?></span></div>
    <div class="small text-muted mb-2">These interview dates have passed. Record the result if the Solo Parent came, or mark it missed if not (they can then choose a new day; no SMS is sent). If nobody confirms within <?= (int) AppointmentService::RELEASE_GRACE_WEEKDAYS ?> weekdays the system reopens the booking by itself.</div>
    <?php if (!$awaitingOutcome): ?><div class="empty-state compact">No interviews are waiting for a result.</div><?php endif; ?>
    <?php foreach ($awaitingOutcome as $iv): ?>
      <div class="appt-row">
        <a href="<?= $appLink((int) $iv['application_id'], '#interview') ?>" class="text-decoration-none" style="color:inherit;min-width:0;flex:1 1 220px">
          <div class="app-ref"><?= e($iv['reference_no']) ?> <span style="font-weight:400;color:var(--muted)">· <?= e($iv['full_name']) ?></span></div>
          <div class="app-date"><?= e(interview_schedule_text($iv['scheduled_at'], interview_is_date_only($iv), 'D, M j, Y g:i A')) ?></div>
        </a>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <a href="<?= $appLink((int) $iv['application_id'], '#interview') ?>" class="btn-navy text-decoration-none" style="padding:6px 12px;font-size:12px">Attended</a>
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

  <div class="review-card dash-panel" id="missed-followup" hidden>
    <div class="review-card-head"><div class="title">Missed and not rebooked</div><?= $close ?></div>
    <div class="small text-muted mb-2">These Solo Parents missed their interview and have not chosen a new date for 7 days or more. Call them (no SMS is sent for this). If the system released the interview and they really came, open it and record the result.</div>
    <?php if (!$followUp): ?><div class="empty-state compact">Nobody to call right now.</div><?php endif; ?>
    <?php foreach ($followUp as $f): $released = ($f['notes'] ?? '') === AppointmentService::RELEASE_NOTE; ?>
      <div class="appt-row">
        <a href="<?= $appLink((int) $f['application_id'], '#interview') ?>" class="text-decoration-none" style="color:inherit;min-width:0;flex:1 1 220px">
          <div class="app-ref"><?= e($f['reference_no']) ?> <span style="font-weight:400;color:var(--muted)">· <?= e($f['full_name']) ?></span></div>
          <div class="app-date"><?= e($f['mobile']) ?> · missed <?= e(date('M j, Y', strtotime((string) $f['scheduled_at']))) ?><?= $released ? ' (no outcome recorded)' : '' ?></div>
        </a>
        <a href="<?= $appLink((int) $f['application_id'], '#interview') ?>" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px"><?= $released ? 'Actually attended / open' : 'Open' ?></a>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="review-card dash-panel" id="verification-queue" hidden>
    <div class="review-card-head"><div class="title">Document Verification Queue</div><?= $close ?></div>
    <?php if (!$verificationQueue): ?>
      <div class="empty-state compact"><div class="empty-state-title">All caught up</div>No documents are waiting for verification.</div>
    <?php else: ?>
      <?php foreach ($verificationQueue as $q): ?>
        <a href="<?= $appLink((int) $q['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
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

  <div class="review-card dash-panel" id="ready-to-schedule" hidden>
    <div class="review-card-head"><div class="title">Ready to Schedule</div><span class="d-flex gap-2 align-items-center"><a class="small" href="<?= base_url('staff/appointments.php#staff-needs-schedule') ?>">See all (<?= (int) $stats['readyToSchedule'] ?>)</a><?= $close ?></span></div>
    <div class="small text-muted mb-2">Documents are verified. Open the application and press <b>Schedule the interview</b>.</div>
    <?php if (!$readyToSchedule): ?>
      <div class="empty-state compact"><div class="empty-state-title">Nothing to schedule</div>Applications appear here once every required document is verified.</div>
    <?php else: ?>
      <?php foreach ($readyToSchedule as $r): ?>
        <a href="<?= $appLink((int) $r['id'], '#schedule-interview') ?>" class="app-card text-decoration-none" style="color:inherit">
          <div>
            <div class="app-ref"><?= e($r['reference_no']) ?></div>
            <div class="app-date"><?= e($r['full_name']) ?> · submitted <?= e(date('M j, Y', strtotime($r['submitted_at']))) ?></div>
          </div>
          <span class="status-pill" style="background:#dce8f5;color:#1b3a6b">Schedule</span>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="review-card dash-panel" id="pending-review" hidden>
    <div class="review-card-head"><div class="title">New Applications to Review</div><span class="d-flex gap-2 align-items-center"><a class="small" href="<?= base_url('staff/index.php?type=new&status=Pending Review') ?>">See all (<?= (int) $stats['pending'] ?>)</a><?= $close ?></span></div>
    <?php if (!$pendingNewList): ?><div class="empty-state compact">No new applications are waiting.</div><?php endif; ?>
    <?php foreach ($pendingNewList as $p): ?>
      <a href="<?= $appLink((int) $p['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
        <div><div class="app-ref"><?= e($p['reference_no']) ?></div><div class="app-date"><?= e($p['full_name']) ?> · submitted <?= e(date('M j, Y', strtotime((string) $p['submitted_at']))) ?></div></div>
        <span class="status-pill" style="background:#fdf1d6;color:#7a5f12">Review</span>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="review-card dash-panel" id="pending-renewals" hidden>
    <div class="review-card-head"><div class="title">Renewals to Review</div><span class="d-flex gap-2 align-items-center"><a class="small" href="<?= base_url('staff/index.php?type=renewal&status=Pending Review') ?>">See all (<?= (int) $stats['pendingRenewals'] ?>)</a><?= $close ?></span></div>
    <?php if (!$pendingRenewalList): ?><div class="empty-state compact">No renewals are waiting.</div><?php endif; ?>
    <?php foreach ($pendingRenewalList as $p): ?>
      <a href="<?= $appLink((int) $p['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
        <div><div class="app-ref"><?= e($p['reference_no']) ?></div><div class="app-date"><?= e($p['full_name']) ?> · submitted <?= e(date('M j, Y', strtotime((string) $p['submitted_at']))) ?></div></div>
        <span class="status-pill" style="background:#fdf1d6;color:#7a5f12">Review</span>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="review-card dash-panel" id="staff-today" hidden>
    <div class="review-card-head"><div class="title">Today's Appointments</div><?= $close ?></div>
    <div class="small text-muted mb-2">Total expected today: <b><?= (int) $stats['todaysAppointments'] ?></b></div>
    <?php if (!$todaysAppointments): ?>
      <div class="empty-state compact">No Solo Parents are booked for today.</div>
    <?php else: ?>
      <?php foreach ($todaysAppointments as $iv): ?>
        <a href="<?= $appLink((int) $iv['application_id']) ?>" class="app-card text-decoration-none" style="color:inherit">
          <div>
            <div class="app-ref"><?= e($iv['reference_no']) ?></div>
            <div class="app-date"><?= e($iv['full_name']) ?> · <?= ($iv['booking_source'] ?? '') === 'STAFF' ? 'scheduled by MSWDO' : 'chosen by the Solo Parent' ?></div>
          </div>
          <div class="app-date"><?= e(interview_time_text($iv['scheduled_at'], interview_is_date_only($iv), 'g:i A', 'Any time today')) ?></div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="review-card dash-panel" id="staff-upcoming" hidden>
    <div class="review-card-head"><div class="title">Upcoming Interviews</div><?= $close ?></div>
    <?php if (!$upcomingInterviews): ?>
      <div class="empty-state compact"><?= !empty($bookingEnabled) ? 'No upcoming interviews. Interviews appear here once Solo Parents book a date or MSWDO staff assign a schedule.' : 'No upcoming interviews.' ?></div>
    <?php else: ?>
      <?php foreach ($upcomingInterviews as $iv): ?>
        <a href="<?= $appLink((int) $iv['application_id']) ?>" class="app-card text-decoration-none" style="color:inherit">
          <div>
            <div class="app-ref"><?= e($iv['reference_no']) ?></div>
            <div class="app-date"><?= e($iv['full_name']) ?> · <?= ($iv['booking_source'] ?? '') === 'STAFF' ? 'scheduled by MSWDO' : 'chosen by the Solo Parent' ?></div>
          </div>
          <div class="app-date"><?= e(interview_schedule_text($iv['scheduled_at'], interview_is_date_only($iv), 'D, M j, Y g:i A')) ?></div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="review-card dash-panel" id="id-release-queue" hidden>
    <div class="review-card-head"><div class="title">ID Release</div><span class="d-flex gap-2 align-items-center"><a class="small" href="<?= base_url('staff/index.php?status=Approved') ?>">See all (<?= (int) $stats['awaitingRelease'] ?>)</a><?= $close ?></span></div>
    <div class="small text-muted mb-2">Approved applications waiting for the ID handover. Schedule the release, then confirm the handover with the dates on the card. A red pill means the release date passed and the ID is still at the office.</div>
    <?php if (!$idReleaseQueue): ?>
      <div class="empty-state compact"><div class="empty-state-title">No IDs waiting</div>Approved applications appear here until their ID is handed over.</div>
    <?php else: ?>
      <?php foreach ($idReleaseQueue as $r): ?>
        <a href="<?= $appLink((int) $r['id'], '#id-release') ?>" class="app-card text-decoration-none" style="color:inherit">
          <div>
            <div class="app-ref"><?= e($r['reference_no']) ?></div>
            <div class="app-date"><?= e($r['full_name']) ?></div>
          </div>
          <?php if ($r['release_at'] && strtotime((string) $r['release_at']) < time()): ?>
            <span class="status-pill" style="background:#fde8e8;color:#a51c1c">Date passed · <?= e(date('M j', strtotime($r['release_at']))) ?></span>
          <?php elseif ($r['release_at']): ?>
            <span class="status-pill" style="background:#dcf3e2;color:#1c7a3f">Release <?= e(date('M j, g:i A', strtotime($r['release_at']))) ?></span>
          <?php else: ?>
            <span class="status-pill" style="background:#fdf1d6;color:#7a5f12">Schedule release</span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="review-card dash-panel" id="failed-texts" hidden>
    <div class="review-card-head"><div class="title">Failed Texts</div><span class="d-flex gap-2 align-items-center"><a class="small" href="<?= base_url('staff/sms.php') ?>">Open Failed Texts</a><?= $close ?></span></div>
    <div class="small text-muted mb-2">Texts that were not sent. Retry them, or press Send now for an ID expiry text, on the Failed Texts page.</div>
    <?php if (!$failedTexts): ?><div class="empty-state compact">No failed texts are listed here<?= $failedTextCount ? '; open Failed Texts for the ID expiry texts not sent' : '' ?>.</div><?php endif; ?>
    <?php foreach ($failedTexts as $t): $help = SMSService::failureHelp((string) $t['error']); ?>
      <a href="<?= $appLink((int) $t['application_id']) ?>" class="app-card text-decoration-none" style="color:inherit">
        <div><div class="app-ref"><?= e(sms_kind_label($t['template_key'])) ?> · <?= e($t['reference_no'] ?: 'Application #' . (int) $t['application_id']) ?></div><div class="app-date"><?= e($help['cause']) ?></div></div>
        <span class="status-pill" style="background:#fde8e8;color:#a51c1c">Failed</span>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="review-card dash-panel" id="staff-recent" hidden>
    <div class="review-card-head"><div class="title">Recently Processed by MSWDO Staff</div><?= $close ?></div>
    <?php if (!$recentlyProcessed): ?>
      <div class="empty-state compact">No applications have been processed yet. Recent MSWDO staff activity will be listed here.</div>
    <?php else: ?>
      <?php foreach ($recentlyProcessed as $app): $st = status_style($app['status']); ?>
        <a href="<?= $appLink((int) $app['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
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

  <div class="d-flex flex-wrap gap-2" style="margin-top:8px">
    <a href="<?= base_url('staff/index.php') ?>" class="btn-navy text-decoration-none">View All Applications</a>
    <a href="<?= base_url('staff/appointments.php') ?>" class="btn-outline text-decoration-none" data-i18n>Appointment Management</a>
    <a href="<?= base_url('staff/applicants.php') ?>" class="btn-outline text-decoration-none" data-i18n>Solo Parent Records</a>
    <a href="<?= base_url('staff/reports.php') ?>" class="btn-outline text-decoration-none" data-i18n>Reports</a>
  </div>
</div>
<script>
  (() => {
    // The lists stay hidden; a tile or a section link opens one below the tiles (and closes the others). The open list is kept in
    // the address (#id), so after an action that returns to the dashboard (e.g. Did not attend -> #needs-outcome) it opens again.
    const root = document.getElementById('staff-dashboard');
    const panels = [...root.querySelectorAll('.dash-panel')];
    const hint = document.getElementById('dash-panel-hint');
    const show = (id, scroll) => {
      let shown = null;
      panels.forEach(p => { const on = p.id === id; p.hidden = !on; if (on) shown = p; });
      root.querySelectorAll('[data-panel]').forEach(t => { const on = t.dataset.panel === id; t.classList.toggle('is-open', on); t.setAttribute('aria-expanded', on ? 'true' : 'false'); });
      hint.hidden = !!shown;
      try { history.replaceState(null, '', shown ? '#' + id : location.pathname + location.search); } catch (e) { /* ignore */ }
      if (shown && scroll) shown.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    root.querySelectorAll('[data-panel], .staff-section-links a').forEach(a => a.addEventListener('click', e => {
      const id = a.dataset.panel || a.hash.slice(1);
      if (!document.getElementById(id)) return;
      e.preventDefault();
      const open = !document.getElementById(id).hidden;
      show(open ? '' : id, !open);
    }));
    root.querySelectorAll('.js-panel-close').forEach(b => b.addEventListener('click', () => show('', false)));
    const start = location.hash.slice(1);
    if (start && panels.some(p => p.id === start)) show(start, true);
  })();
</script>
