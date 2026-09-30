<?php
/**
 * @var array  $staff
 * @var string $ownDashboard
 * @var string $error
 * @var string $success
 * @var array  $slots  with booked count
 * @var int    $defaultCapacity
 */
?>
<div class="portal-container">
  <a href="<?= e($ownDashboard) ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">Appointment Slots</h1>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>
  <div style="font-size:13px;color:var(--muted);margin-bottom:20px;max-width:640px">Applicants pick their own interview/assessment appointment from the open slots below. Create a slot for each date/time block you can accommodate, with how many applicants it can hold.</div>

  <?php if ($error): ?><div class="auth-error mb-3"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none"><?= e($success) ?></div><?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-5">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Add Slot</div></div>
        <form method="post" class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            Date
            <input type="date" name="slot_date" min="<?= e(date('Y-m-d')) ?>" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px" required>
          </label>
          <div class="d-flex gap-2">
            <label style="font-size:13px;font-weight:600;color:var(--ink);flex:1">
              Start time
              <input type="time" name="start_time" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px" required>
            </label>
            <label style="font-size:13px;font-weight:600;color:var(--ink);flex:1">
              End time
              <input type="time" name="end_time" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px" required>
            </label>
          </div>
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            Capacity (applicants)
            <input type="number" name="capacity" min="1" max="100" value="<?= (int) $defaultCapacity ?>" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px" required>
          </label>
          <button type="submit" class="btn-navy">Add Slot</button>
        </form>
      </div>
    </div>

    <div class="col-12 col-lg-7">
      <?php if (!$slots): ?>
        <div style="font-size:14px;color:var(--muted)">No upcoming slots yet. Add one to let applicants book appointments.</div>
      <?php endif; ?>
      <?php foreach ($slots as $s): ?>
        <div class="app-card">
          <div>
            <div class="app-ref"><?= e(date('F j, Y', strtotime($s['slot_date']))) ?></div>
            <div class="app-date"><?= e(date('g:i A', strtotime($s['start_time']))) ?> &ndash; <?= e(date('g:i A', strtotime($s['end_time']))) ?></div>
            <div class="app-date"><?= (int) $s['booked'] ?> / <?= (int) $s['capacity'] ?> booked</div>
          </div>
          <?php if ((int) $s['booked'] === 0): ?>
            <form method="post" class="m-0" onsubmit="return confirm('Delete this appointment slot?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="slot_id" value="<?= (int) $s['id'] ?>">
              <button type="submit" class="btn-outline" style="padding:6px 12px;font-size:12px">Delete</button>
            </form>
          <?php else: ?>
            <span class="status-pill" style="background:#dce8f5;color:#1b3a6b">Booked</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
