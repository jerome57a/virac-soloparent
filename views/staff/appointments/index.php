<?php
/**
 * Appointment Management (MSWDO staff) / Appointment Overview (admin, view only).
 * One "Next step" card, four counts that jump to their section, then the sections in the order the work is done:
 * Needs a result, Today, Needs an interview date, Waiting for the Solo Parent, Upcoming (by day), ID release date passed, Interview days.
 * Every row opens the right card of the application (Interview or Schedule); each row shows one main button, Cancel sits under "More".
 *
 * @var array   $staff
 * @var string  $error
 * @var ?string $flashMessage
 * @var bool    $canManage          MSWD staff (record, mark missed, cancel); administrators view only
 * @var array   $awaitingOutcome    past appointments still BOOKED
 * @var array   $todayAppointments  today's, any status but CANCELLED
 * @var array   $upcomingByDate     Y-m-d => BOOKED appointments after today
 * @var array   $awaitingSchedule   ready for an interview date (staff)
 * @var array   $waitingForDate     the Solo Parent may pick a date themselves (staff)
 * @var array   $overdueReleases    ID release date passed, ID not collected
 */
require_once APP_ROOT . '/includes/icons.php';
$isAdmin = ($staff['role'] ?? '') === 'admin';
$awaitingSchedule = $awaitingSchedule ?? [];
$waitingForDate = $waitingForDate ?? [];
$overdueReleases = $overdueReleases ?? [];
$dateState = $dateState ?? 'off'; $dateError = $dateError ?? ''; $interviewDates = $interviewDates ?? []; $defaultCapacity = $defaultCapacity ?? 0;
$upcomingCount = array_sum(array_map('count', $upcomingByDate));
$todayBooked = array_values(array_filter($todayAppointments, fn($iv) => $iv['status'] === 'BOOKED'));
$capacityByDate = array_column($interviewDates, null, 'interview_date');
$apptStyles = [
    'BOOKED' => ['info', 'Booked'], 'COMPLETED' => ['ok', 'Completed'], 'MISSED' => ['err', 'Missed'], 'CANCELLED' => ['dis', 'Cancelled'],
];

/** The date or time box at the start of a row. $mode: past (date + weekday), day (time, or "Day / any time"). */
$when = function (array $iv, string $mode): string {
    $dateOnly = interview_is_date_only($iv);
    $t = strtotime((string) $iv['scheduled_at']);
    if ($mode === 'past') {
        return '<b>' . e(date('M j', $t)) . '</b><small>' . e(date('D', $t)) . '</small>';
    }
    return $dateOnly ? '<b data-i18n>Day</b><small data-i18n>any time</small>' : '<b>' . e(date('g:i', $t)) . '</b><small>' . e(date('A', $t)) . '</small>';
};

/** One appointment row: who and where (opens the application), its status, and for MSWDO staff the one action that fits now. */
$row = function (array $iv, string $mode) use ($staff, $canManage, $apptStyles, $when): void {
    [$tone, $label] = $apptStyles[$iv['status']] ?? ['dis', $iv['status']];
    $dateOnly = interview_is_date_only($iv);
    $canRecord = Appointment::canRecordResult($iv);
    $canMarkMissed = Appointment::canMarkMissed($iv);
    $url = application_url($staff, (int) $iv['application_id']);
    $detail = [];
    if ($mode === 'past') {
        $detail[] = $dateOnly ? 'Date only' : date('g:i A', strtotime((string) $iv['scheduled_at']));
    } elseif ($dateOnly) {
        $detail[] = 'Date only';
    }
    $detail[] = (string) $iv['location'];
    if (($iv['application_type'] ?? '') === 'renewal') {
        $detail[] = 'Renewal';
    }
    ?>
    <div class="ap-row">
      <span class="ap-when"><?= $when($iv, $mode) ?></span>
      <a class="ap-who" href="<?= $url ?>#interview">
        <b><span translate="no"><?= e($iv['reference_no']) ?></span> · <?= e($iv['full_name']) ?></b>
        <small><?= e(implode(' · ', array_filter($detail))) ?></small>
      </a>
      <div class="ap-acts">
        <?php if ($iv['status'] !== 'BOOKED' || $mode !== 'past'): ?><span class="ap-pill is-<?= e($tone) ?>" data-i18n><?= e($label) ?></span><?php endif; ?>
        <?php if ($canManage && $iv['status'] === 'BOOKED'): ?>
          <?php if ($canRecord): ?><a href="<?= $url ?>#interview" class="btn-navy ap-btn" data-i18n>Record result</a><?php endif; ?>
          <?php if ($canMarkMissed): ?>
            <form method="post" class="m-0" onsubmit="return confirm('Mark this appointment as missed (no-show)?');">
              <?= csrf_field() ?>
              <input type="hidden" name="appointment_id" value="<?= (int) $iv['id'] ?>">
              <button type="submit" name="action" value="missed" class="btn-outline ap-btn" data-i18n>Mark missed</button>
            </form>
          <?php endif; ?>
          <details class="ap-more">
            <summary class="ap-more-btn"><span data-i18n>More</span><?= ui_icon('chevron-down', 14) ?></summary>
            <form method="post" class="ap-more-panel">
              <?= csrf_field() ?>
              <input type="hidden" name="appointment_id" value="<?= (int) $iv['id'] ?>">
              <label class="ap-more-label" for="cancel-reason-<?= (int) $iv['id'] ?>" data-i18n>Cancel this appointment</label>
              <input type="text" id="cancel-reason-<?= (int) $iv['id'] ?>" name="reason" maxlength="255" required placeholder="Reason (sent to the Solo Parent)" class="form-control">
              <button type="submit" name="action" value="cancel" class="btn-red ap-btn" data-i18n>Cancel &amp; notify</button>
            </form>
          </details>
        <?php elseif (!$canManage || $iv['status'] !== 'BOOKED'): ?>
          <a href="<?= $url ?>#interview" class="btn-outline ap-btn" data-i18n>Open</a>
        <?php endif; ?>
      </div>
    </div>
    <?php
};

/** A section card: icon, title, count, an optional line of guidance. */
$head = function (string $icon, string $tone, string $title, ?int $count, ?string $note = null, ?string $countText = null, ?string $sub = null): void { ?>
    <div class="ap-sec-h">
      <span class="ap-si is-<?= e($tone) ?>" aria-hidden="true"><?= ui_icon($icon, 17) ?></span>
      <h2><span data-i18n><?= e($title) ?></span><?php if ($sub): ?> <small class="ap-sub"><?= e($sub) ?></small><?php endif; ?></h2>
      <?php if ($countText !== null): ?><span class="ap-cnt-text"><?= e($countText) ?></span><?php elseif ($count !== null): ?><span class="ap-cnt is-<?= e($count > 0 ? $tone : 'dis') ?>"><?= (int) $count ?></span><?php endif; ?>
    </div>
    <?php if ($note): ?><p class="ap-sec-d" data-i18n><?= e($note) ?></p><?php endif; ?>
<?php };
$empty = function (string $text): void { ?>
    <div class="ap-empty"><?= ui_icon('circle-check', 18) ?><span data-i18n><?= e($text) ?></span></div>
<?php };

// The one Next step for MSWDO staff, most urgent first.
$next = null;
if ($canManage) {
    if ($awaitingOutcome) {
        $first = $awaitingOutcome[0];
        $next = ['tone' => 'err', 'icon' => 'clipboard-check', 'title' => count($awaitingOutcome) === 1 ? '1 interview needs a result' : count($awaitingOutcome) . ' interviews need a result',
            'text' => 'Start with ' . $first['reference_no'] . ', interviewed ' . date('M j', strtotime((string) $first['scheduled_at'])) . '.',
            'button' => 'Record result', 'href' => application_url($staff, (int) $first['application_id']) . '#interview'];
    } elseif ($todayBooked) {
        $first = $todayBooked[0];
        $next = ['tone' => 'info', 'icon' => 'calendar', 'title' => count($todayBooked) === 1 ? '1 interview today' : count($todayBooked) . ' interviews today',
            'text' => 'When ' . $first['reference_no'] . ' (' . $first['full_name'] . ') arrives, record the result.', 'button' => 'See today', 'href' => '#staff-today-card'];
    } elseif ($awaitingSchedule) {
        $first = $awaitingSchedule[0];
        $next = ['tone' => 'warn', 'icon' => 'calendar-plus', 'title' => count($awaitingSchedule) === 1 ? '1 application needs an interview date' : count($awaitingSchedule) . ' applications need an interview date',
            'text' => 'Start with ' . $first['reference_no'] . ' (' . $first['full_name'] . '), waiting the longest.',
            'button' => 'Schedule date', 'href' => application_url($staff, (int) $first['id']) . '#schedule-interview'];
    } elseif ($overdueReleases) {
        $next = ['tone' => 'warn', 'icon' => 'id-card', 'title' => count($overdueReleases) === 1 ? '1 ID is waiting to be collected' : count($overdueReleases) . ' IDs are waiting to be collected',
            'text' => 'Confirm the release when the Solo Parent comes, or set a new date if they ask.', 'button' => 'See the list', 'href' => '#staff-release-overdue'];
    } else {
        $next = ['tone' => 'ok', 'icon' => 'circle-check', 'title' => 'All caught up', 'text' => 'No interview needs a result, none is booked for today, and no application is waiting for a date.', 'button' => null, 'href' => null];
    }
}
?>
<link rel="stylesheet" href="<?= public_asset('css/appointments.css') ?>">
<div class="portal-container ap-page">
<?php if ($isAdmin): ?>
  <?php require APP_ROOT . '/views/partials/admin-page-heading.php'; ?>
<?php else: ?>
  <?php require APP_ROOT . '/views/partials/staff-page-heading.php'; ?>
<?php endif; ?>

  <?php if ($isAdmin): ?>
  <div class="info-box" style="max-width:none">
    <p class="mb-0" data-i18n>You monitor interview appointments here. MSWDO staff schedule each interview day, record results and handle changes; the Solo Parent can keep the date or pick another day.</p>
  </div>
  <?php endif; ?>
  <?php if ($error): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if (!empty($smsNotice)) { require APP_ROOT . '/views/staff/applications/sms-notice.php'; } ?>
  <?php if ($flashMessage): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>

  <?php if ($next): ?>
  <div class="ap-next is-<?= e($next['tone']) ?>">
    <span class="ap-next-i" aria-hidden="true"><?= ui_icon($next['icon'], 22) ?></span>
    <div class="ap-next-t"><small data-i18n>Next step</small><b data-i18n><?= e($next['title']) ?></b><span><?= e($next['text']) ?></span></div>
    <?php if ($next['button']): ?><a class="btn-navy ap-next-btn" href="<?= e($next['href']) ?>" data-i18n><?= e($next['button']) ?></a><?php endif; ?>
  </div>
  <?php endif; ?>

  <nav class="ap-chips" aria-label="Appointment counts">
    <a class="ap-chip" href="#staff-needs-outcome"><span class="ap-si is-err" aria-hidden="true"><?= ui_icon('circle-alert', 18) ?></span><span><strong><?= count($awaitingOutcome) ?></strong><span data-i18n>Need a result</span></span></a>
    <a class="ap-chip" href="#staff-today-card"><span class="ap-si is-info" aria-hidden="true"><?= ui_icon('calendar', 18) ?></span><span><strong><?= count($todayAppointments) ?></strong><span data-i18n>Today</span></span></a>
    <?php if ($canManage): ?><a class="ap-chip" href="#staff-needs-schedule"><span class="ap-si is-warn" aria-hidden="true"><?= ui_icon('calendar-plus', 18) ?></span><span><strong><?= count($awaitingSchedule) ?></strong><span data-i18n>Need a date</span></span></a><?php endif; ?>
    <a class="ap-chip" href="#staff-upcoming-card"><span class="ap-si is-ok" aria-hidden="true"><?= ui_icon('calendar-check', 18) ?></span><span><strong><?= $upcomingCount ?></strong><span data-i18n>Upcoming</span></span></a>
  </nav>

  <section class="ap-sec<?= $awaitingOutcome ? ' is-urgent' : '' ?>" id="staff-needs-outcome">
    <?php $head('circle-alert', 'err', $canManage ? 'Needs a result' : 'Awaiting MSWDO staff outcome', count($awaitingOutcome),
        $awaitingOutcome ? ($canManage ? 'The interview day has passed. Record the result, or mark a no-show as missed.' : 'These past appointments still need an outcome from MSWDO staff.') : null); ?>
    <?php if (!$awaitingOutcome) { $empty('No interview is waiting for a result.'); } ?>
    <?php foreach ($awaitingOutcome as $iv) { $row($iv, 'past'); } ?>
  </section>

  <section class="ap-sec" id="staff-today-card">
    <span id="admin-appointments-today" class="ap-anchor"></span>
    <?php $head('calendar', 'info', 'Today', count($todayAppointments), null, null, date('l, F j')); ?>
    <?php if (!$todayAppointments) { $empty('No interviews today.'); } ?>
    <?php foreach ($todayAppointments as $iv) { $row($iv, 'day'); } ?>
  </section>

  <?php if ($canManage): ?>
  <section class="ap-sec" id="staff-needs-schedule">
    <?php $head('calendar-plus', 'warn', 'Needs an interview date', count($awaitingSchedule), $awaitingSchedule ? 'Documents are verified. Set a date; the Solo Parent is texted and can keep it or pick another day. Oldest first.' : null); ?>
    <?php if (!$awaitingSchedule) { $empty('No application is waiting for an interview date.'); } ?>
    <?php foreach ($awaitingSchedule as $waiting): $why = $waiting['appointment_status'] === 'MISSED' ? 'Missed the last appointment · replacement needed' : ($waiting['appointment_status'] === 'CANCELLED' ? 'Cancelled appointment · replacement needed' : 'No interview date yet'); ?>
      <div class="ap-row">
        <span class="ap-when"><b>—</b><small data-i18n>no date</small></span>
        <a class="ap-who" href="<?= e(application_url($staff, (int) $waiting['id']) . '#schedule-interview') ?>"><b><span translate="no"><?= e($waiting['reference_no']) ?></span> · <?= e($waiting['full_name']) ?></b><small data-i18n><?= e($why) ?></small></a>
        <div class="ap-acts"><a href="<?= e(application_url($staff, (int) $waiting['id']) . '#schedule-interview') ?>" class="btn-navy ap-btn" data-i18n>Schedule date</a></div>
      </div>
    <?php endforeach; ?>
  </section>

  <?php if ($dateState === 'ready' || $waitingForDate): ?>
  <section class="ap-sec" id="staff-waiting-for-date">
    <?php $head('clock', 'info', 'Waiting for the Solo Parent to pick a date', count($waitingForDate), $waitingForDate ? 'They can pick a date themselves (for example after cancelling). You can also schedule one for them.' : null); ?>
    <?php if (!$waitingForDate) { $empty('No one is choosing a new date right now.'); } ?>
    <?php foreach ($waitingForDate as $waiting): ?>
      <div class="ap-row">
        <span class="ap-when"><b><?= e(date('M j', strtotime($waiting['opened_at']))) ?></b><small data-i18n>invited</small></span>
        <a class="ap-who" href="<?= e(application_url($staff, (int) $waiting['id']) . '#schedule-interview') ?>"><b><span translate="no"><?= e($waiting['reference_no']) ?></span> · <?= e($waiting['full_name']) ?></b><small>Invited <?= e(date('M j, Y g:i A', strtotime($waiting['opened_at']))) ?><?= (int) $waiting['reschedules_used'] > 0 ? ' · rescheduling' : '' ?></small></a>
        <div class="ap-acts"><a href="<?= e(application_url($staff, (int) $waiting['id']) . '#schedule-interview') ?>" class="btn-outline ap-btn" data-i18n>Schedule for them</a></div>
      </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>
  <?php endif; ?>

  <section class="ap-sec" id="staff-upcoming-card">
    <span id="admin-appointments-upcoming" class="ap-anchor"></span>
    <?php $head('calendar-check', 'ok', 'Upcoming', $upcomingCount); ?>
    <?php if (!$upcomingByDate) { $empty('No upcoming appointments after today.'); } ?>
    <?php foreach ($upcomingByDate as $date => $items): $cap = $capacityByDate[$date] ?? null; ?>
      <div class="ap-day"><span><?= e(date('l, F j', strtotime($date))) ?></span><small><?= count($items) ?> booked<?= $cap && $cap['capacity_configured'] ? ' of ' . (int) $cap['effective_capacity'] : '' ?></small></div>
      <?php foreach ($items as $iv) { $row($iv, 'day'); } ?>
    <?php endforeach; ?>
  </section>

  <?php if ($overdueReleases): ?>
  <section class="ap-sec" id="staff-release-overdue">
    <?php $head('id-card', 'warn', 'ID release date passed', count($overdueReleases), 'Not collected yet. The ID keeps waiting at the office: confirm the release when they come, or set a new date if they ask.'); ?>
    <?php foreach ($overdueReleases as $r): $t = strtotime($r['scheduled_at']); ?>
      <div class="ap-row">
        <span class="ap-when"><b><?= e(date('M j', $t)) ?></b><small><?= e(date('D', $t)) ?></small></span>
        <a class="ap-who" href="<?= application_url($staff, (int) $r['application_id']) ?>"><b><span translate="no"><?= e($r['reference_no']) ?></span> · <?= e($r['full_name']) ?></b><small>Was <?= e(date('M j, Y g:i A', $t)) ?> · <?= e($r['location']) ?></small></a>
        <div class="ap-acts"><a href="<?= application_url($staff, (int) $r['application_id']) ?>" class="btn-outline ap-btn" data-i18n><?= $canManage ? 'Confirm release' : 'Open' ?></a></div>
      </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <?php if ($dateState === 'off' && $dateError !== ''): ?><div class="auth-error mb-3" role="alert"><?= e($dateError) ?></div><?php endif; ?>
  <?php if ($dateState !== 'off'): ?>
  <section class="ap-sec" id="interview-dates">
    <?php $head('users-round', 'info', 'Interview days', null, null, $dateState === 'ready' ? ($defaultCapacity >= 1 ? (int) $defaultCapacity . ' seats a day' : 'Seats a day not set') : null); ?>
    <?php if ($dateState === 'incomplete'): ?>
      <div class="info-box m-3" style="max-width:none" role="status">Interview date booking is not available because its setup is incomplete. Please contact the MSWDO admin.</div>
    <?php else: ?>
      <p class="ap-sec-d">Any office day (Monday to Friday) in the next 45 days can be chosen; weekends, holidays and announced closures are never offered. Only booked and completed interviews use a seat.<?php if ($defaultCapacity < 1): ?> The number of seats a day is not set: set it under System Settings.<?php endif; ?><?php if ($isAdmin): ?> Seats, holidays and closures are managed under <a href="<?= base_url('admin/settings.php') ?>">System Settings</a>.<?php endif; ?></p>
      <?php $bookedDays = array_values(array_filter($interviewDates, fn($d) => (int) $d['seats_used'] > 0)); ?>
      <?php if (!$bookedDays) { $empty('No Solo Parent has booked an upcoming interview day yet.'); } ?>
      <?php foreach ($bookedDays as $d): $pct = $d['capacity_configured'] && (int) $d['effective_capacity'] > 0 ? min(100, (int) round(100 * (int) $d['seats_used'] / (int) $d['effective_capacity'])) : 0; ?>
        <div class="ap-cap">
          <b><?= e(date('D, M j', strtotime($d['interview_date']))) ?></b>
          <?php if ($d['capacity_configured']): ?><span class="ap-bar" role="img" aria-label="<?= (int) $d['seats_used'] ?> of <?= (int) $d['effective_capacity'] ?> seats booked"><i class="<?= $d['full'] ? 'is-full' : '' ?>" style="width:<?= $pct ?>%"></i></span><?php endif; ?>
          <?php if ($d['full']): ?><span class="ap-pill is-err" data-i18n>Full</span><?php else: ?><small><?= (int) $d['seats_used'] ?><?= $d['capacity_configured'] ? ' of ' . (int) $d['effective_capacity'] : ' booked' ?></small><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
