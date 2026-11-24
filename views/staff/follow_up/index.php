<?php
require_once __DIR__ . '/../../../includes/icons.php';
/**
 * Missed and Not Rebooked: Solo Parents whose interview was missed (or released by the system for lack of an outcome) and who have not
 * chosen a new date for 7 days. Staff call them; no SMS is sent. If the system released the interview and they really came, staff can
 * still record that they attended.
 *
 * @var array $staff
 * @var array $rows   Appointment::missedNotRebooked() rows
 */
?>
<div class="portal-container" style="max-width:900px">
  <?php require APP_ROOT . '/views/partials/staff-page-heading.php'; ?>

  <div class="info-box" style="max-width:none" role="note">Call each Solo Parent and ask them to log in and choose a new interview date. If the system released the interview because nobody recorded the outcome and they really came, open it and record the result.</div>

  <div class="review-card">
    <?php if (!$rows): ?>
      <div class="gi-empty"><span class="gi-chip gi-xl gi-green"><?= ui_icon('circle-check', 28) ?></span><strong data-i18n>Nobody to call</strong><p><span data-i18n>Solo Parents who missed their interview and have not rebooked for 7 days will be listed here.</span></p><a class="gi-btn is-outline" href="<?= e(base_url('staff/dashboard.php')) ?>"><?= ui_icon('house', 17) ?><span data-i18n>Back to the Dashboard</span></a></div>
    <?php else: ?>
      <?php foreach ($rows as $f): $released = ($f['notes'] ?? '') === AppointmentService::RELEASE_NOTE; ?>
        <div class="appt-row">
          <a href="<?= base_url('staff/application.php?id=' . (int) $f['application_id']) ?>#interview" class="text-decoration-none" style="color:inherit;min-width:0;flex:1 1 220px">
            <div class="app-ref"><?= e($f['reference_no']) ?> <span style="font-weight:400;color:var(--muted)">· <?= e($f['full_name']) ?></span></div>
            <div class="app-date"><?= e($f['mobile']) ?> · missed <?= e(date('M j, Y', strtotime((string) $f['scheduled_at']))) ?><?= $released ? ' (no outcome was recorded)' : '' ?></div>
          </a>
          <a href="<?= base_url('staff/application.php?id=' . (int) $f['application_id']) ?>#interview" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px"><?= $released ? 'Actually attended / open' : 'Open' ?></a>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
