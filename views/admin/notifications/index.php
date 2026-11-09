<?php
/**
 * @var string $ownDashboard
 * @var string $broadcastError
 * @var array  $broadcasts    recent system_broadcasts
 * @var bool   $canBroadcast
 * -- plus the variables of views/partials/notification-list.php
 */
$audiences = ['applicants' => 'All applicants', 'staff' => 'All MSWD staff and administrators', 'everyone' => 'Everyone'];
?>
<div class="portal-container">
  <?php require APP_ROOT . '/views/partials/admin-page-heading.php'; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-7" id="admin-inbox">
      <div class="admin-section-caption"><div><h2 data-i18n>Your notifications</h2><p data-i18n>Updates for your account.</p></div><span class="admin-count-badge"><b><?= (int) $notifPage['unread'] ?></b><span data-i18n>Unread</span></span></div>
      <?php require APP_ROOT . '/views/partials/notification-list.php'; ?>
    </div>
    <?php if ($canBroadcast): ?>
      <div class="col-12 col-lg-5" id="admin-broadcast">
        <div class="review-card">
          <div class="review-card-head"><div class="title" data-i18n>Send a System Announcement</div></div>
          <div style="font-size:13px;color:var(--muted);margin-bottom:10px">Sent as a notification to every active account in the chosen group (it appears in their notifications and unread count).</div>
          <?php if ($broadcastError): ?><div class="auth-error" role="alert"><?= e($broadcastError) ?></div><?php endif; ?>
          <form method="post" class="d-flex flex-column gap-2" onsubmit="return confirm('Send this announcement now?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="broadcast">
            <label class="user-label"><span data-i18n>Announcement message</span><textarea data-admin-count name="message" aria-label="Announcement message" rows="3" maxlength="500" required placeholder="e.g. The MSWDO office is closed on Oct 31 (holiday). Appointments that day will be rescheduled." class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px"><?= $broadcastError && is_string($_POST['message'] ?? null) ? e($_POST['message']) : '' ?></textarea></label>
            <label class="user-label"><span data-i18n>Recipients</span><select name="audience" class="form-select" required>
              <?php foreach ($audiences as $key => $label): ?><option value="<?= e($key) ?>" <?= $broadcastError && ($_POST['audience'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></label>
            <button type="submit" class="btn-navy">Send Announcement</button>
          </form>
          <div style="font-size:12px;font-weight:700;color:var(--muted);margin:14px 0 6px">Recently Sent</div>
          <?php if (!$broadcasts): ?><div style="font-size:13px;color:var(--muted)">None yet.</div><?php endif; ?>
          <?php if ($broadcasts): ?><div class="admin-list-scroll" role="region" aria-label="Recent system announcements" tabindex="0"><?php endif; ?>
          <?php foreach ($broadcasts as $b): ?>
            <div class="case-entry">
              <div class="when"><?= e(date('M j, Y g:i A', strtotime($b['sent_at']))) ?> · <?= e($audiences[$b['audience']]) ?> (<?= (int) $b['recipients'] ?>) · <?= e($b['sent_by_name'] ?? 'former admin') ?></div>
              <div class="text"><?= e($b['message']) ?></div>
            </div>
          <?php endforeach; ?>
          <?php if ($broadcasts): ?></div><?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
