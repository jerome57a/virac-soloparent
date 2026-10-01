<?php
/**
 * @var array  $staff
 * @var string $error
 * @var string $success
 * @var string $statusFilter
 * @var string $q
 * @var array  $templates
 * @var array  $logs    reference_no is null for account-level SMS (e.g. password reset)
 * @var array  $totals  sent, test, failed, pending
 */
$provider = SMSService::provider();
$pills = ['SENT' => ['#dcf3e2', '#1c7a3f'], 'FAILED' => ['#fde8e8', '#a51c1c'], 'PENDING' => ['#fdf1d6', '#8a6d1a']];
?>
<div class="portal-container">
  <a href="<?= base_url('admin/dashboard.php') ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">SMS Notification Management</h1>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <div class="info-box sms-mode" style="max-width:none">
    <?php if ($provider === 'test'): ?>
      <b>Test mode.</b> Messages are logged below but <b>not delivered</b>. To send real SMS, set <code>SMS_PROVIDER=semaphore</code> and <code>SMS_API_KEY</code> as server environment variables or in <code>config/local.php</code>.
    <?php else: ?>
      <b>Live: <?= e(ucfirst($provider)) ?>.</b> Sender name <code><?= e(SMS_SENDER_NAME) ?></code> · gateway <code><?= e((string) parse_url(SMS_API_URL, PHP_URL_HOST)) ?></code> · API key <?= SMS_API_KEY !== '' ? 'configured' : '<b style="color:var(--error-text)">missing — every message will fail</b>' ?> · up to <?= (int) SMS_MAX_ATTEMPTS ?> attempts per message.
    <?php endif; ?>
  </div>

  <div class="dash-quick-grid" style="margin-bottom:24px">
    <?php foreach (['sent' => 'Sent', 'test' => 'Test mode (not sent)', 'failed' => 'Failed', 'pending' => 'Pending'] as $key => $label): ?>
      <a href="?status=<?= $key ?>" class="dash-card text-decoration-none" style="margin-bottom:0;color:inherit"><div class="dash-card-label"><?= e($label) ?></div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= (int) $totals[$key] ?></div></a>
    <?php endforeach; ?>
  </div>

  <?php if ($error): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none" role="status"><?= e($success) ?></div><?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-5">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Send a Test SMS</div></div>
        <div style="font-size:13px;color:var(--muted);margin-bottom:10px">Checks the SMS setup end to end with a harmless message. In test mode it is only logged.</div>
        <form method="post" class="d-flex flex-wrap gap-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_test">
          <input type="tel" name="mobile" aria-label="Mobile number for the test SMS" required maxlength="20" placeholder="09XX XXX XXXX" class="form-control" style="flex:1 1 160px;padding:8px;border:1px solid var(--input-border);border-radius:6px">
          <button type="submit" class="btn-navy">Send Test</button>
        </form>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Notification Templates</div></div>
        <div style="font-size:12px;color:var(--faint);margin-bottom:14px">Each template lists the placeholders it can use; the first one is required. Max 500 characters (a standard SMS is 160).</div>
        <?php foreach ($templates as $t): $ph = SMSService::PLACEHOLDERS[$t['template_key']] ?? []; ?>
          <form method="post" class="d-flex flex-column gap-2 mb-3 pb-3" style="border-bottom:1px solid var(--border)">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_template">
            <input type="hidden" name="template_key" value="<?= e($t['template_key']) ?>">
            <div style="font-weight:700;font-size:13px;color:var(--ink)"><?= e($t['label']) ?> <span style="font-weight:400;color:var(--faint)"><?php foreach ($ph as $p): ?><code><?= e($p) ?></code> <?php endforeach; ?></span></div>
            <textarea name="body" rows="3" maxlength="500" class="form-control" style="padding:8px;border:1px solid var(--input-border);border-radius:6px;font-size:13px"><?= e($t['body']) ?></textarea>
            <button type="submit" class="btn-outline" style="align-self:flex-start;padding:6px 14px;font-size:12px">Save</button>
          </form>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="col-12 col-lg-7">
      <div class="review-card">
        <div class="review-card-head"><div class="title">SMS Logs</div></div>
        <form method="get" class="d-flex flex-wrap gap-2 mb-3">
          <input type="text" name="q" aria-label="Search by reference, mobile or message text" value="<?= e($q) ?>" placeholder="Search reference, mobile or text" class="form-control" style="max-width:260px;padding:8px;border:1px solid var(--input-border);border-radius:6px">
          <select name="status" class="form-select" style="max-width:190px;padding:8px;border:1px solid var(--input-border);border-radius:6px">
            <?php foreach (['ALL' => 'All statuses', 'sent' => 'Sent', 'test' => 'Test mode (not sent)', 'failed' => 'Failed', 'pending' => 'Pending'] as $key => $label): ?>
              <option value="<?= $key ?>" <?= $statusFilter === $key || ($statusFilter === 'skipped' && $key === 'test') ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn-navy">Filter</button>
        </form>

        <?php if (!$logs): ?><div style="font-size:13px;color:var(--muted)">No SMS logs match this filter.</div><?php endif; ?>
        <?php foreach ($logs as $l): [$bg, $fg] = $pills[$l['status']]; ?>
          <div class="app-card" style="align-items:flex-start">
            <div style="min-width:0;flex:1">
              <div class="app-ref"><?= e($l['reference_no'] ?? 'Account-level (no application)') ?> · <?= e($l['mobile']) ?></div>
              <div style="font-size:13px;color:var(--ink);margin:4px 0;overflow-wrap:anywhere"><?= e($l['message']) ?></div>
              <div class="app-date">
                <?= e(date('M j, Y g:i A', strtotime($l['sent_at']))) ?> · <?= e($l['provider']) ?><?= $l['template_key'] ? ' · ' . e($l['template_key']) : '' ?>
                <?= (int) $l['attempts'] > 1 ? ' · attempt ' . (int) $l['attempts'] : '' ?><?= $l['provider_message_id'] ? ' · id ' . e($l['provider_message_id']) : '' ?>
              </div>
              <?php if ($l['error']): ?><div class="doc-error"><?= e($l['error']) ?></div><?php endif; ?>
            </div>
            <div class="d-flex flex-column align-items-end gap-2">
              <span class="status-pill" style="background:<?= $bg ?>;color:<?= $fg ?>"><?= e(ucfirst(strtolower($l['status']))) ?><?= $l['test_mode'] ? ' · test' : '' ?></span>
              <?php if ($l['status'] === 'FAILED' && (int) $l['attempts'] < SMS_MAX_ATTEMPTS && $l['template_key'] !== 'password_reset'): ?>
                <form method="post" class="m-0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="retry">
                  <input type="hidden" name="log_id" value="<?= (int) $l['id'] ?>">
                  <button type="submit" class="btn-outline" style="padding:4px 12px;font-size:12px">Retry</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>