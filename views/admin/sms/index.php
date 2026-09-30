<?php
/**
 * @var array  $staff
 * @var string $error
 * @var string $success
 * @var string $statusFilter
 * @var string $q
 * @var array  $templates
 * @var array  $logs    reference_no is null for account-level SMS (e.g. password reset)
 * @var array  $totals  sent, failed, skipped
 */
?>
<div class="portal-container">
  <a href="<?= base_url('admin/dashboard.php') ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">SMS Notification Management</h1>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <?php if (!SMS_API_KEY): ?>
    <div class="info-box" style="max-width:none">No SMS provider is configured (<code>SMS_API_KEY</code> is blank in <code>config/config.php</code>), so messages are logged only, not actually delivered. This is normal for a development/demo setup.</div>
  <?php endif; ?>

  <div class="dash-quick-grid" style="margin-bottom:24px">
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Sent</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $totals['sent'] ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Failed</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $totals['failed'] ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Logged Only (no API key)</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $totals['skipped'] ?></div></div>
  </div>

  <?php if ($error): ?><div class="auth-error mb-3"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none"><?= e($success) ?></div><?php endif; ?>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Notification Templates</div></div>
    <div style="font-size:12px;color:var(--faint);margin-bottom:14px">Placeholders: <code>{ref}</code> <code>{status}</code> <code>{remarks}</code> <code>{when}</code> <code>{location}</code> <code>{code}</code> <code>{minutes}</code> (only the ones relevant to each template are filled in).</div>
    <?php foreach ($templates as $t): ?>
      <form method="post" class="d-flex flex-column gap-2 mb-3 pb-3" style="border-bottom:1px solid var(--border)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_template">
        <input type="hidden" name="template_key" value="<?= e($t['template_key']) ?>">
        <div style="font-weight:700;font-size:13px;color:var(--ink)"><?= e($t['label']) ?></div>
        <textarea name="body" rows="2" class="form-control" style="padding:8px;border:1px solid var(--input-border);border-radius:6px;font-size:13px"><?= e($t['body']) ?></textarea>
        <button type="submit" class="btn-outline" style="align-self:flex-start;padding:6px 14px;font-size:12px">Save</button>
      </form>
    <?php endforeach; ?>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">SMS Logs</div></div>
    <form method="get" class="d-flex flex-wrap gap-2 mb-3">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search reference no. or mobile" class="form-control" style="max-width:260px;padding:8px;border:1px solid var(--input-border);border-radius:6px">
      <select name="status" class="form-select" style="max-width:180px;padding:8px;border:1px solid var(--input-border);border-radius:6px">
        <option value="ALL" <?= $statusFilter === 'ALL' ? 'selected' : '' ?>>All statuses</option>
        <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Sent</option>
        <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
        <option value="skipped" <?= $statusFilter === 'skipped' ? 'selected' : '' ?>>Logged only</option>
      </select>
      <button type="submit" class="btn-navy">Filter</button>
    </form>

    <?php if (!$logs): ?><div style="font-size:13px;color:var(--muted)">No SMS logs match this filter.</div><?php endif; ?>
    <?php foreach ($logs as $l):
        $pillBg = ['sent' => '#dcf3e2', 'failed' => '#fde8e8']["$l[provider_status]"] ?? '#fdf1d6';
        $pillColor = ['sent' => '#1c7a3f', 'failed' => '#a51c1c']["$l[provider_status]"] ?? '#8a6d1a';
    ?>
      <div class="app-card" style="align-items:flex-start">
        <div>
          <div class="app-ref"><?= e($l['reference_no'] ?? 'Account-level (no application)') ?> · <?= e($l['mobile']) ?></div>
          <div style="font-size:13px;color:var(--ink);margin:4px 0"><?= e($l['message']) ?></div>
          <div class="app-date"><?= e(date('M j, Y g:i A', strtotime($l['sent_at']))) ?></div>
        </div>
        <span class="status-pill" style="background:<?= e($pillBg) ?>;color:<?= e($pillColor) ?>"><?= e($l['provider_status']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
