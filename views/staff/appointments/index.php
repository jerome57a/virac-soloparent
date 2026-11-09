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
 * @var array   $slotsByDate        Y-m-d => slots with booked count
 */
$apptStyles = [
    'BOOKED' => ['#dce8f5', '#1b3a6b', 'Booked'], 'COMPLETED' => ['#dcf3e2', '#1c7a3f', 'Completed'],
    'MISSED' => ['#fde8e8', '#a51c1c', 'Missed'], 'CANCELLED' => ['#f1f1f1', '#555', 'Cancelled'],
];
/** One appointment row: link to the application, time, status, and (for MSWD) the actions allowed now. */
$row = function (array $iv, string $timeFormat) use ($staff, $canManage, $apptStyles): void {
    [$bg, $fg, $label] = $apptStyles[$iv['status']];
    $started = strtotime($iv['scheduled_at']) <= time();
    ?>
    <div class="appt-row">
      <a href="<?= application_url($staff, (int) $iv['application_id']) ?>" class="text-decoration-none" style="color:inherit;min-width:0;flex:1 1 220px">
        <div class="app-ref"><?= e($iv['reference_no']) ?> <span style="font-weight:400;color:var(--muted)">· <?= e($iv['full_name']) ?></span></div>
        <div class="app-date"><?= e(date($timeFormat, strtotime($iv['scheduled_at']))) ?> · <?= e($iv['location']) ?><?= $iv['slot_id'] ? '' : ' · set by staff' ?></div>
      </a>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="status-pill" style="background:<?= e($bg) ?>;color:<?= e($fg) ?>"><?= e($label) ?></span>
        <?php if ($canManage && $iv['status'] === 'BOOKED'): ?>
          <?php if ($started): ?>
            <a href="<?= application_url($staff, (int) $iv['application_id']) ?>#interview" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px">Record result</a>
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
              <input type="text" name="reason" aria-label="Reason for cancelling (sent to the applicant)" maxlength="255" required placeholder="Reason (sent to the applicant)" class="form-control" style="font-size:13px;padding:6px 10px">
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
  <div class="staff-page-actions"><a href="#staff-needs-schedule" class="btn-navy" data-i18n>Assign schedule</a><a href="<?= base_url('staff/appointment_slots.php') ?>" class="btn-outline" data-i18n>Manage Slots</a></div>
  <div class="staff-summary">
    <a href="#admin-appointments-today"><span data-i18n>Today's appointments</span><strong><?= count($todayAppointments) ?></strong></a>
    <a href="#admin-appointments-upcoming"><span data-i18n>Upcoming appointments</span><strong><?= array_sum(array_map('count', $upcomingByDate)) ?></strong></a>
    <a href="#admin-appointment-slots"><span data-i18n>Available appointment slots</span><strong><?= array_sum(array_map(fn($slots) => count(array_filter($slots, fn($slot) => $slot['status'] === 'AVAILABLE' && (int) $slot['booked'] < (int) $slot['capacity'])), $slotsByDate)) ?></strong></a>
  </div>
  <?php endif; ?>
  <?php if (($staff['role'] ?? '') === 'admin'): ?><div class="admin-page-actions"><a href="<?= base_url('admin/appointment_slots.php') ?>" class="btn-navy">Manage Slots</a></div><?php endif; ?>
  <?php if (($staff['role'] ?? '') === 'admin'): ?>
  <div class="admin-page-summary">
    <a href="#admin-appointments-today"><span data-i18n>Today's appointments</span><strong><?= count($todayAppointments) ?></strong><small data-i18n>View today's schedule</small></a>
    <a href="#admin-appointments-upcoming"><span data-i18n>Upcoming appointments</span><strong><?= array_sum(array_map('count', $upcomingByDate)) ?></strong><small data-i18n>After today</small></a>
    <div><span data-i18n>Awaiting staff outcome</span><strong><?= count($awaitingOutcome) ?></strong><small data-i18n>Past appointments still marked booked</small></div>
  </div>
  <?php endif; ?>
  <?php if ($error): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($flashMessage): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>

  <?php if ($canManage): ?>
  <div class="review-card" id="staff-needs-schedule">
    <div class="review-card-head"><div class="title" data-i18n>Needs a schedule</div></div>
    <p class="small text-muted" data-i18n>Open an application to assign its first interview or replace a cancelled or missed appointment. Oldest applications first, up to 100.</p>
    <?php if (empty($awaitingSchedule)): ?><div class="empty-state compact" data-i18n>No applications are waiting for a schedule.</div><?php endif; ?>
    <?php foreach ($awaitingSchedule ?? [] as $waiting): ?>
      <a href="<?= e(application_url($staff, (int) $waiting['id']) . '#interview') ?>" class="app-card text-decoration-none" style="color:inherit">
        <div><div class="app-ref"><?= e($waiting['reference_no']) ?> &middot; <?= e($waiting['full_name']) ?></div><div class="app-date" data-i18n><?= $waiting['appointment_status'] === 'MISSED' ? 'Missed appointment — replacement needed' : ($waiting['appointment_status'] === 'CANCELLED' ? 'Cancelled appointment — replacement needed' : 'First interview not yet scheduled') ?></div></div>
        <span class="staff-open-record" data-i18n>Assign schedule</span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($awaitingOutcome): ?>
    <div class="review-card" style="border-color:var(--red)">
      <div class="review-card-head"><div class="title">Needs Outcome (<?= count($awaitingOutcome) ?>)</div></div>
      <div style="font-size:13px;color:var(--muted);margin-bottom:8px"><?= $canManage ? 'These appointments have passed but are still marked booked. Record the interview result, or mark a no-show as missed then assign a replacement schedule if needed.' : 'These past appointments still need an outcome from MSWD staff. Open an application to review its interview details.' ?></div>
      <?php if ($staff['role'] === 'admin'): ?><div class="admin-list-scroll" role="region" aria-label="Appointments needing an outcome" tabindex="0"><?php endif; ?>
      <?php foreach ($awaitingOutcome as $iv) { $row($iv, 'M j, Y g:i A'); } ?>
      <?php if ($staff['role'] === 'admin'): ?></div><?php endif; ?>
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

  <div class="review-card">
    <div class="review-card-head"><div class="title" id="admin-appointment-slots">Available appointment slots</div></div>
    <?php if (!$slotsByDate): ?>
      <div style="font-size:13px;color:var(--muted)">No upcoming slots configured. <a href="<?= base_url(role_portal_path($staff, 'appointment_slots.php')) ?>">Add one.</a></div>
    <?php endif; ?>
    <?php if ($staff['role'] === 'admin' && $slotsByDate): ?><div class="admin-list-scroll" role="region" aria-label="Upcoming appointment slots" tabindex="0"><?php endif; ?>
    <?php foreach ($slotsByDate as $date => $items): ?>
      <div style="margin-bottom:10px">
        <div style="font-weight:700;font-size:13px;color:var(--ink);margin-bottom:6px"><?= e(date('l, F j, Y', strtotime($date))) ?></div>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($items as $s):
              $cancelled = $s['status'] === 'CANCELLED';
              $full = (int) $s['booked'] >= (int) $s['capacity'];
              [$bg, $fg] = $cancelled ? ['#f1f1f1', '#555'] : ($full ? ['#fde8e8', '#a51c1c'] : ['#dcf3e2', '#1c7a3f']);
          ?>
            <span class="status-pill" style="background:<?= $bg ?>;color:<?= $fg ?>">
              <?= e(date('g:i A', strtotime($s['start_time']))) ?>&ndash;<?= e(date('g:i A', strtotime($s['end_time']))) ?>
              <?= $cancelled ? '(cancelled)' : '(' . (int) $s['booked'] . '/' . (int) $s['capacity'] . ($full ? ' full' : '') . ')' ?>
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if ($staff['role'] === 'admin' && $slotsByDate): ?></div><?php endif; ?>
  </div>
</div>
