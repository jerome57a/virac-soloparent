<?php
/**
 * @var string $error
 * @var string $success
 * @var array  $current  setting key => current value (see SettingsService::current())
 * @var array  $recentReminders
 * @var array  $recentApptReminders
 */
?>
<div class="portal-container">
  <a href="<?= base_url('admin/dashboard.php') ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <h1 class="font-heading mt-2 mb-4" style="font-size:26px">System Settings</h1>

  <?php if ($error): ?><div class="auth-error mb-3"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none"><?= e($success) ?></div><?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-6">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Appointment Settings</div></div>
        <form method="post" class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            Default appointment location
            <input class="form-control mt-1" type="text" name="appointment_location" value="<?= e($current['appointment_location']) ?>" required>
          </label>
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            ID validity (years before renewal)
            <input class="form-control mt-1" type="number" name="id_validity_years" min="1" max="10" value="<?= (int) $current['id_validity_years'] ?>" required>
          </label>
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            Renewal reminder period (days before expiry)
            <input class="form-control mt-1" type="number" name="renewal_reminder_days" min="1" max="180" value="<?= (int) $current['renewal_reminder_days'] ?>" required>
          </label>
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            Appointment reminder window (hours before appointment)
            <input class="form-control mt-1" type="number" name="appointment_reminder_hours" min="1" max="168" value="<?= (int) $current['appointment_reminder_hours'] ?>" required>
          </label>
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            Default appointment capacity (pre-filled when staff add a new slot)
            <input class="form-control mt-1" type="number" name="appointment_default_capacity" min="1" max="100" value="<?= (int) $current['appointment_default_capacity'] ?>" required>
          </label>
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            Document upload max size (MB)
            <input class="form-control mt-1" type="number" step="0.5" name="document_max_size_mb" min="0.5" max="20" value="<?= e((string) $current['document_max_size_mb']) ?>" required>
          </label>
          <div style="font-size:12px;color:var(--faint)">To manage available appointment date/time slots, use <a href="<?= base_url('staff/appointment_slots.php') ?>">Appointment Slots</a>. Allowed document types (JPG/PNG/PDF) are fixed in <code>config/config.php</code>.</div>
          <button type="submit" class="btn-navy" style="align-self:flex-start">Save Settings</button>
        </form>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Renewal Reminders</div></div>
        <div style="font-size:13px;color:var(--muted);margin-bottom:14px">
          Automatically texts every applicant whose released ID expires within <?= (int) $current['renewal_reminder_days'] ?> day(s), once per ID (never twice for the same expiry). Runs daily via a scheduled task
          (<code>database/send_renewal_reminders.php</code>), or trigger it manually below.
        </div>
        <form method="post" class="mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_reminders_now">
          <button type="submit" class="btn-outline">Send Reminders Now</button>
        </form>
        <div style="font-size:12px;font-weight:700;color:var(--muted);margin-bottom:6px">Recently Sent</div>
        <?php if (!$recentReminders): ?>
          <div style="font-size:13px;color:var(--muted)">No renewal reminders sent yet.</div>
        <?php endif; ?>
        <?php foreach ($recentReminders as $r): ?>
          <div class="review-row"><div class="k"><?= e($r['reference_no']) ?> — <?= e($r['full_name']) ?></div><div class="v"><?= e(date('M j, Y', strtotime($r['sent_at']))) ?></div></div>
        <?php endforeach; ?>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Appointment Reminders</div></div>
        <div style="font-size:13px;color:var(--muted);margin-bottom:14px">
          Automatically texts every applicant with an interview/appointment starting within <?= (int) $current['appointment_reminder_hours'] ?> hour(s), once per scheduled time (rescheduling resets it). Runs via a scheduled task
          (<code>database/send_appointment_reminders.php</code>), or trigger it manually below.
        </div>
        <form method="post" class="mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_appointment_reminders_now">
          <button type="submit" class="btn-outline">Send Reminders Now</button>
        </form>
        <div style="font-size:12px;font-weight:700;color:var(--muted);margin-bottom:6px">Recently Sent</div>
        <?php if (!$recentApptReminders): ?>
          <div style="font-size:13px;color:var(--muted)">No appointment reminders sent yet.</div>
        <?php endif; ?>
        <?php foreach ($recentApptReminders as $r): ?>
          <div class="review-row"><div class="k"><?= e($r['reference_no']) ?> — <?= e($r['full_name']) ?></div><div class="v"><?= e(date('M j, Y g:i A', strtotime($r['scheduled_at']))) ?></div></div>
        <?php endforeach; ?>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Application Requirements</div></div>
        <div style="font-size:13px;color:var(--muted);margin-bottom:14px">Add, edit, or remove the documents required per solo-parent category.</div>
        <a href="<?= base_url('admin/requirements.php') ?>" class="btn-outline text-decoration-none">Manage Requirements</a>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Notification Settings</div></div>
        <div style="font-size:13px;color:var(--muted);margin-bottom:14px">SMS notification templates and delivery logs.</div>
        <a href="<?= base_url('admin/sms.php') ?>" class="btn-outline text-decoration-none">Manage Notifications</a>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="review-card">
        <div class="review-card-head"><div class="title">System Configuration</div></div>
        <div class="review-grid">
          <div class="review-row"><div class="k">App name</div><div class="v"><?= e(APP_NAME) ?></div></div>
          <div class="review-row"><div class="k">Base URL</div><div class="v"><?= e(BASE_URL) ?></div></div>
          <div class="review-row"><div class="k">Max upload size</div><div class="v"><?= e((string) $current['document_max_size_mb']) ?> MB (editable at left)</div></div>
          <div class="review-row"><div class="k">Allowed document types</div><div class="v"><?= e(strtoupper(implode(', ', ALLOWED_UPLOAD_EXT))) ?></div></div>
          <div class="review-row"><div class="k">SMS provider</div><div class="v"><?= e(SMS_PROVIDER) ?> (<?= SMS_API_KEY ? 'configured' : 'not configured — log only' ?>)</div></div>
          <div class="review-row"><div class="k">Timezone</div><div class="v"><?= e(date_default_timezone_get()) ?></div></div>
          <div class="review-row"><div class="k">PHP version</div><div class="v"><?= e(PHP_VERSION) ?></div></div>
        </div>
        <div style="font-size:12px;color:var(--faint);margin-top:12px">Everything except max upload size and document types is set in <code>config/config.php</code> and requires a code change to adjust.</div>
      </div>
    </div>
  </div>
</div>
