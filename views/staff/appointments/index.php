<?php
/**
 * @var string $ownDashboard
 * @var array  $todayAppointments
 * @var array  $upcomingByDate  Y-m-d => interviews
 * @var array  $slotsByDate     Y-m-d => slots with booked count
 */
?>
<div class="portal-container">
  <a href="<?= e($ownDashboard) ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">Appointment Management</h1>
    <a href="<?= base_url('staff/appointment_slots.php') ?>" class="btn-outline text-decoration-none" style="padding:8px 16px;font-size:13px">Manage Slots</a>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Today's Appointments (<?= e(date('F j, Y')) ?>)</div></div>
    <?php if (!$todayAppointments): ?>
      <div style="font-size:13px;color:var(--muted)">No appointments scheduled for today.</div>
    <?php endif; ?>
    <?php foreach ($todayAppointments as $iv): ?>
      <a href="<?= base_url('staff/application.php?id=' . (int) $iv['application_id']) ?>" class="app-card text-decoration-none" style="color:inherit">
        <div>
          <div class="app-ref"><?= e($iv['reference_no']) ?></div>
          <div class="app-date"><?= e($iv['full_name']) ?> · <?= e($iv['location']) ?></div>
        </div>
        <div class="app-date"><?= e(date('g:i A', strtotime($iv['scheduled_at']))) ?></div>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Upcoming (Calendar)</div></div>
    <?php if (!$upcomingByDate): ?>
      <div style="font-size:13px;color:var(--muted)">No upcoming appointments beyond today.</div>
    <?php endif; ?>
    <?php foreach ($upcomingByDate as $date => $items): ?>
      <div style="margin-bottom:14px">
        <div style="font-weight:700;font-size:13px;color:var(--ink);margin-bottom:6px"><?= e(date('l, F j, Y', strtotime($date))) ?></div>
        <?php foreach ($items as $iv): ?>
          <a href="<?= base_url('staff/application.php?id=' . (int) $iv['application_id']) ?>" class="app-card text-decoration-none" style="color:inherit">
            <div>
              <div class="app-ref"><?= e($iv['reference_no']) ?></div>
              <div class="app-date"><?= e($iv['full_name']) ?> · <?= e($iv['location']) ?></div>
            </div>
            <div class="app-date"><?= e(date('g:i A', strtotime($iv['scheduled_at']))) ?></div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Available Slots</div></div>
    <?php if (!$slotsByDate): ?>
      <div style="font-size:13px;color:var(--muted)">No open slots configured. <a href="<?= base_url('staff/appointment_slots.php') ?>">Add one.</a></div>
    <?php endif; ?>
    <?php foreach ($slotsByDate as $date => $items): ?>
      <div style="margin-bottom:10px">
        <div style="font-weight:700;font-size:13px;color:var(--ink);margin-bottom:6px"><?= e(date('l, F j, Y', strtotime($date))) ?></div>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($items as $s): $full = (int) $s['booked'] >= (int) $s['capacity']; ?>
            <span class="status-pill" style="background:<?= $full ? '#fde8e8' : '#dcf3e2' ?>;color:<?= $full ? '#a51c1c' : '#1c7a3f' ?>">
              <?= e(date('g:i A', strtotime($s['start_time']))) ?>&ndash;<?= e(date('g:i A', strtotime($s['end_time']))) ?> (<?= (int) $s['booked'] ?>/<?= (int) $s['capacity'] ?>)
            </span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
