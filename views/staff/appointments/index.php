<?php
/**
 * @var array   $staff
 * @var string  $ownDashboard
 * @var string  $error
 * @var ?string $flashMessage
 * @var bool    $canManage          MSWD staff (cancel / mark missed); administrators view only
 * @var array   $awaitingOutcome    past appointments still BOOKED
 * @var array   $todayAppointments  today's, any status but CANCELLED
 * @var array   $upcomingByDate     Y-m-d => BOOKED appointments after today
 */
$apptStyles = [
    'BOOKED' => ['#dce8f5', '#1b3a6b', 'Booked'], 'COMPLETED' => ['#dcf3e2', '#1c7a3f', 'Completed'],
    'MISSED' => ['#fde8e8', '#a51c1c', 'Missed'], 'CANCELLED' => ['#f1f1f1', '#555', 'Cancelled'],
];
/** One appointment row: link to the application, time, status, and (for MSWD) the actions allowed now. */
$row = function (array $iv, string $timeFormat) use ($staff, $canManage, $apptStyles): void {
    [$bg, $fg, $label] = $apptStyles[$iv['status']];
    $canRecord = Appointment::canRecordResult($iv);
    $canMarkMissed = Appointment::canMarkMissed($iv);
    // Timed rows keep $timeFormat exactly; a date-only row shows its date (date formats) or "Date only" (time-only format).
    $dateOnly = interview_is_date_only($iv);
    $when = preg_match('/[djFMnYyl]/', $timeFormat) ? interview_schedule_text($iv['scheduled_at'], $dateOnly, $timeFormat) : interview_time_text($iv['scheduled_at'], $dateOnly, $timeFormat);
    ?>
    <div class="appt-row">
      <a href="<?= application_url($staff, (int) $iv['application_id']) ?>" class="text-decoration-none" style="color:inherit;min-width:0;flex:1 1 220px">
        <div class="app-ref"><?= e($iv['reference_no']) ?> <span style="font-weight:400;color:var(--muted)">· <?= e($iv['full_name']) ?></span></div>
        <div class="app-date"><?= e($when) ?> · <?= e($iv['location']) ?></div>
      </a>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="status-pill" style="background:<?= e($bg) ?>;color:<?= e($fg) ?>"><?= e($label) ?></span>
        <?php if ($canManage && $iv['status'] === 'BOOKED'): ?>
          <?php if ($canRecord): ?>
            <a href="<?= application_url($staff, (int) $iv['application_id']) ?>#interview" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px">Record result</a>
          <?php endif; ?>
          <?php if ($canMarkMissed): ?>
            <form method="post" class="m-0" onsubmit="return confirm('Mark this appointment as missed (no-show)?');">
              <?= csrf_field() ?>
              <input type="hidden" name="appointment_id" value="<?= (int) $iv['id'] ?>">
              <button type="submit" name="action" value="missed" class="btn-outline" style="padding:6px 12px;font-size:12px">Mark missed</button>
            </form>
          <?php endif; ?>
          <details class="slot-cancel">
            <summary class="btn-outline" style="padding:6px 12px;font-size:12px">Cancel…</summary>
            <form method="post" class="d-flex flex-column gap-2 mt-2">
              <?= csrf_field() ?>
              <input type="hidden" name="appointment_id" value="<?= (int) $iv['id'] ?>">
              <input type="text" name="reason" aria-label="Reason for cancelling (sent to the Solo Parent)" maxlength="255" required placeholder="Reason (sent to the Solo Parent)" class="form-control" style="font-size:13px;padding:6px 10px">
              <button type="submit" name="action" value="cancel" class="btn-red" style="padding:6px 12px;font-size:12px">Cancel &amp; notify</button>
            </form>
          </details>
        <?php endif; ?>
      </div>
    </div>
    <?php
};
?>
<div class="portal-container">
<?php if (($staff['role'] ?? '') === 'admin'): ?>
  <?php require APP_ROOT . '/views/partials/admin-page-heading.php'; ?>

<?php else: ?>
  <?php require APP_ROOT . '/views/partials/staff-page-heading.php'; ?>

<?php endif; ?>

  <?php if (($staff['role'] ?? '') === 'mswd_social_worker'): ?>
  <div class="staff-page-actions"><a href="#staff-needs-schedule" class="btn-navy">Schedule interview dates</a></div>
  <div class="staff-summary">
    <a href="#admin-appointments-today"><span data-i18n>Today's appointments</span><strong><?= count($todayAppointments) ?></strong></a>
    <a href="#admin-appointments-upcoming"><span data-i18n>Upcoming appointments</span><strong><?= array_sum(array_map('count', $upcomingByDate)) ?></strong></a>
    <?php if (!empty($waitingForDate)): ?><a href="#staff-waiting-for-date"><span>Waiting for a date</span><strong><?= count($waitingForDate) ?></strong></a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if (($staff['role'] ?? '') === 'admin'): ?>
  <div class="info-box" style="max-width:none">
    <p class="mb-2" data-i18n>As administrator, you monitor interview appointments.</p>
    <p class="mb-0" data-i18n>MSWD staff schedule each Solo Parent's interview day; the Solo Parent can keep it or pick another day. MSWD staff handle individual appointment changes and record interview results or missed appointments.</p>
  </div>
  <?php endif; ?>
  <?php if (($staff['role'] ?? '') === 'admin'): ?>
  <div class="admin-page-summary">
    <a href="#admin-appointments-today"><span data-i18n>Today's appointments</span><strong><?= count($todayAppointments) ?></strong><small data-i18n>View today's schedule</small></a>
    <a href="#admin-appointments-upcoming"><span data-i18n>Upcoming appointments</span><strong><?= array_sum(array_map('count', $upcomingByDate)) ?></strong><small data-i18n>After today</small></a>
    <div><span data-i18n>Awaiting staff outcome</span><strong><?= count($awaitingOutcome) ?></strong><small data-i18n>Past appointments still marked booked</small></div>
  </div>
  <?php endif; ?>
  <?php if ($error): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if (!empty($smsNotice)) { require APP_ROOT . '/views/staff/applications/sms-notice.php'; } ?>
  <?php if ($flashMessage): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>

  <?php if ($canManage): ?>
  <div class="review-card" id="staff-needs-schedule">
    <div class="review-card-head"><div class="title">Needs an interview date</div></div>
    <p class="small text-muted">Once a Solo Parent's required documents are verified, open the application and schedule the interview date: the Solo Parent is texted and can keep it or pick another day. Oldest applications first, up to 100.</p>
    <?php if (empty($awaitingSchedule)): ?><div class="empty-state compact" data-i18n>No applications are waiting for a schedule.</div><?php endif; ?>
    <?php foreach ($awaitingSchedule ?? [] as $waiting): ?>
      <a href="<?= e(application_url($staff, (int) $waiting['id']) . '#schedule-interview') ?>" class="app-card text-decoration-none" style="color:inherit">
        <div><div class="app-ref"><?= e($waiting['reference_no']) ?> &middot; <?= e($waiting['full_name']) ?></div><div class="app-date" data-i18n><?= $waiting['appointment_status'] === 'MISSED' ? 'Missed appointment — replacement needed' : ($waiting['appointment_status'] === 'CANCELLED' ? 'Cancelled appointment — replacement needed' : 'No interview date yet') ?></div></div>
        <span class="staff-open-record">Open application</span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($canManage && !empty($waitingForDate)): ?>
  <div class="review-card" id="staff-waiting-for-date">
    <div class="review-card-head"><div class="title">Waiting for the Solo Parent to choose a new date (<?= count($waitingForDate) ?>)</div></div>
    <p class="small text-muted">These Solo Parents can pick a date themselves (for example after cancelling). You can also schedule one for them. Their booking appears under Upcoming appointments as soon as it is set.</p>
    <?php foreach ($waitingForDate as $waiting): ?>
      <a href="<?= e(application_url($staff, (int) $waiting['id']) . '#schedule-interview') ?>" class="app-card text-decoration-none" style="color:inherit">
        <div><div class="app-ref"><?= e($waiting['reference_no']) ?> &middot; <?= e($waiting['full_name']) ?></div><div class="app-date">Invited <?= e(date('M j, Y g:i A', strtotime($waiting['opened_at']))) ?><?= (int) $waiting['reschedules_used'] > 0 ? ' · rescheduling' : '' ?></div></div>
        <span class="staff-open-record">Open application</span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($awaitingOutcome): ?>
    <div class="review-card" style="border-color:var(--red)">
      <div class="review-card-head"><div class="title"><span data-i18n><?= $canManage ? 'Needs Outcome' : 'Awaiting MSWD Staff Outcome' ?></span> (<?= count($awaitingOutcome) ?>)</div></div>
      <div style="font-size:13px;color:var(--muted);margin-bottom:8px"><?= $canManage ? 'These appointments have passed but are still marked booked. Record the interview result, or mark a no-show as missed then assign a replacement schedule if needed.' : 'These past appointments still need an outcome from MSWD staff. Open an application to review its interview details.' ?></div>
      <?php if ($staff['role'] === 'admin'): ?><div class="admin-list-scroll" role="region" aria-label="Appointments needing an outcome" tabindex="0"><?php endif; ?>
      <?php foreach ($awaitingOutcome as $iv) { $row($iv, 'M j, Y g:i A'); } ?>
      <?php if ($staff['role'] === 'admin'): ?></div><?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($overdueReleases)): ?>
    <div class="review-card">
      <div class="review-card-head"><div class="title"><span data-i18n>ID release date passed</span> (<?= count($overdueReleases) ?>)</div></div>
      <div style="font-size:13px;color:var(--muted);margin-bottom:8px" data-i18n>These Solo Parents have not collected their ID yet. The ID keeps waiting at the office: confirm the release when they come, or set a new date if they ask.</div>
      <?php foreach ($overdueReleases as $r): ?>
        <div class="appt-row">
          <a href="<?= application_url($staff, (int) $r['application_id']) ?>" class="text-decoration-none" style="color:inherit;min-width:0;flex:1 1 220px">
            <div class="app-ref"><?= e($r['reference_no']) ?> <span style="font-weight:400;color:var(--muted)">· <?= e($r['full_name']) ?></span></div>
            <div class="app-date">Was <?= e(date('M j, Y g:i A', strtotime($r['scheduled_at']))) ?> · <?= e($r['location']) ?></div>
          </a>
          <span class="staff-open-record">Open application</span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="review-card">
    <div class="review-card-head"><div class="title" id="admin-appointments-today">Today's Appointments (<?= e(date('F j, Y')) ?>)</div></div>
    <?php if (!$todayAppointments): ?>
      <div style="font-size:13px;color:var(--muted)">No appointments scheduled for today.</div>
    <?php endif; ?>
    <?php if ($staff['role'] === 'admin' && $todayAppointments): ?><div class="admin-list-scroll" role="region" aria-label="Today's appointments" tabindex="0"><?php endif; ?>
    <?php foreach ($todayAppointments as $iv) { $row($iv, 'g:i A'); } ?>
    <?php if ($staff['role'] === 'admin' && $todayAppointments): ?></div><?php endif; ?>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title" id="admin-appointments-upcoming">Upcoming appointments</div></div>
    <?php if (!$upcomingByDate): ?>
      <div style="font-size:13px;color:var(--muted)">No upcoming appointments beyond today.</div>
    <?php endif; ?>
    <?php if ($staff['role'] === 'admin' && $upcomingByDate): ?><div class="admin-list-scroll" role="region" aria-label="Upcoming appointments" tabindex="0"><?php endif; ?>
    <?php foreach ($upcomingByDate as $date => $items): ?>
      <div style="margin-bottom:14px">
        <div style="font-weight:700;font-size:13px;color:var(--ink);margin-bottom:6px"><?= e(date('l, F j, Y', strtotime($date))) ?> · <?= count($items) ?> booked</div>
        <?php foreach ($items as $iv) { $row($iv, 'g:i A'); } ?>
      </div>
    <?php endforeach; ?>
    <?php if ($staff['role'] === 'admin' && $upcomingByDate): ?></div><?php endif; ?>
  </div>

  <?php
  // Interview dates (date-only booking). Hidden while the rollout flag is off; the only thing shown for a refused
  // action on a disabled feature is its error. Management forms are for MSWD staff only (applications.process).
  $dateState = $dateState ?? 'off'; $dateError = $dateError ?? ''; $interviewDates = $interviewDates ?? []; $defaultCapacity = $defaultCapacity ?? 0;
  ?>
  <?php if ($dateState === 'off' && $dateError !== ''): ?><div class="auth-error mb-3" role="alert"><?= e($dateError) ?></div><?php endif; ?>
<?php if ($dateState !== 'off'): ?>
  <div class="review-card" id="interview-dates">
    <div class="review-card-head"><div class="title">Interview days</div></div>
    <?php if ($dateState === 'incomplete'): ?>
      <div class="info-box" style="max-width:none" role="status">Interview date booking is not available because its setup is incomplete. Please contact the system administrator.</div>
    <?php else: ?>
      <p class="small text-muted mb-2">Dates do not need to be opened. When you schedule a date the Solo Parent can keep it or choose any office day (Monday to Friday) in the next 45 days. Weekends, holidays and announced office closures are never offered. Each day takes up to <?= $defaultCapacity >= 1 ? (int) $defaultCapacity : '0 (not configured: set it under System Settings)' ?> Solo Parents; only BOOKED and COMPLETED interviews use a seat.<?php if (($staff['role'] ?? '') === 'admin'): ?> Capacity, holidays and closures are managed under <a href="<?= base_url('admin/settings.php') ?>">System Settings</a>.<?php endif; ?></p>
      <?php $bookedDays = array_values(array_filter($interviewDates, fn($d) => (int) $d['seats_used'] > 0)); ?>
      <?php if (!$bookedDays): ?><div style="font-size:13px;color:var(--muted)">No Solo Parent has booked an upcoming interview day yet.</div><?php endif; ?>
      <?php foreach ($bookedDays as $d): ?>
        <div class="appt-row">
          <div style="flex:1 1 240px;min-width:0">
            <div class="app-ref"><?= e(date('l, F j, Y', strtotime($d['interview_date']))) ?></div>
            <div class="app-date"><?= (int) $d['seats_used'] ?> booked<?= $d['capacity_configured'] ? ' of ' . (int) $d['effective_capacity'] . ' · ' . (int) $d['remaining'] . ' remaining' : '' ?></div>
          </div>
          <?php if ($d['full']): ?><span class="status-pill" style="background:#fde8e8;color:#a51c1c">Full</span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
