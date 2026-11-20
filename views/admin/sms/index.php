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
$actionIcon = require APP_ROOT . '/includes/user-management-icons.php';
?>
<div class="portal-container">
  <?php require APP_ROOT . '/views/partials/admin-page-heading.php'; ?>

  <?php if ($provider !== 'test'): ?>
  <div class="info-box sms-mode" style="max-width:none">
      <b>Live: <?= e(ucfirst($provider)) ?>.</b> Gateway <code><?= e((string) parse_url(SMS_API_URL, PHP_URL_HOST)) ?></code> · API token <?= SMS_API_KEY !== '' ? 'configured' : '<b style="color:var(--error-text)">missing — every message will fail</b>' ?> · up to <?= (int) SMS_MAX_ATTEMPTS ?> attempts per message.
  </div>
  <?php endif; ?>

  <div class="dash-quick-grid sms-stats" style="margin-bottom:24px">
    <?php foreach (['sent' => 'Sent', 'failed' => 'Failed', 'pending' => 'Pending'] as $key => $label): ?>
      <a href="?status=<?= $key ?>" class="dash-card text-decoration-none" style="margin-bottom:0;color:inherit"><div class="dash-card-label"><?= e($label) ?></div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= (int) $totals[$key] ?></div></a>
    <?php endforeach; ?>
  </div>

  <?php if ($error): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none" role="status"><?= e($success) ?></div><?php endif; ?>

  <div class="row g-4 admin-editor-layout">
    <div class="col-12 col-lg-8" id="admin-sms-logs">
      <div class="review-card">
        <div class="review-card-head"><div class="title" data-i18n>SMS Logs</div></div>
        <form method="get" class="record-filters mb-3" role="search" aria-label="Filter SMS logs">
          <label class="admin-filter-field"><span data-i18n>Search</span><input type="text" name="q" aria-label="Search by reference, mobile or message text" value="<?= e($q) ?>" placeholder="Search reference, mobile or text" class="form-control" style="max-width:260px;padding:8px;border:1px solid var(--input-border);border-radius:6px"></label>
          <label class="admin-filter-field"><span data-i18n>Status</span><select name="status" class="form-select" aria-label="SMS status" style="max-width:190px;padding:8px;border:1px solid var(--input-border);border-radius:6px">
            <?php foreach (['ALL' => 'All statuses', 'sent' => 'Sent', 'failed' => 'Failed', 'pending' => 'Pending'] as $key => $label): ?>
              <option value="<?= $key ?>" <?= $statusFilter === $key || ($statusFilter === 'skipped' && $key === 'test') ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select></label>
          <button type="submit" class="btn-navy"><?= $actionIcon('filter') ?> Apply Filters</button>
          <?php if ($q !== '' || $statusFilter !== 'ALL'): ?><a href="<?= e(base_url('admin/sms.php')) ?>" class="btn-outline" data-i18n><?= $actionIcon('clear') ?> Clear</a><?php endif; ?>
        </form>

        <?php require APP_ROOT . '/views/partials/admin-list-meta.php'; ?>
        <?php if (!$logs): ?><div class="empty-state compact">No SMS logs match this filter.</div><?php else: ?>
        <div class="table-responsive" role="region" aria-label="SMS logs" tabindex="0">
        <table class="table"><caption class="visually-hidden">SMS messages and delivery status</caption>
        <thead><tr><th scope="col">Recipient</th><th scope="col">Message</th><th scope="col" data-i18n>Status</th><th scope="col">Action</th></tr></thead><tbody>
        <?php foreach ($logs as $l): [$bg, $fg] = $pills[$l['status']] ?? ['#eef1f5', '#49617e']; ?>
          <tr>
            <td><span class="admin-cell-title"><?= e($l['mobile']) ?></span>
              <div class="admin-cell-meta"><?= e($l['reference_no'] ?? 'Account-level SMS') ?></div>
              <?php
              // A readable message type, so an invitation is told apart from a booking confirmation without opening the row.
              $typeLabel = SMSService::TEMPLATES[$l['template_key'] ?? ''][0] ?? ([
                  'interview_custom' => 'Interview scheduled (custom message)', 'interview_date_assigned_custom' => 'Interview date scheduled by staff (custom message)', 'interview_booking_custom' => 'Interview booking invitation (custom message)',
                  'id_release_scheduled' => 'ID release scheduled', 'id_release_custom' => 'ID release scheduled (custom message)',
              ][$l['template_key'] ?? ''] ?? null);
              ?>
              <?php if ($typeLabel): ?><div class="admin-cell-meta"><b><?= e($typeLabel) ?></b></div><?php endif; ?>
              <div class="admin-cell-meta"><?= e(date('M j, Y g:i A', strtotime($l['sent_at']))) ?></div>
            </td>
            <td><details class="sms-message-details"><summary>View message &amp; details</summary>
              <div class="sms-message-text"><?= e($l['message']) ?></div>
              <div class="admin-cell-meta">
                <?= e(date('M j, Y g:i A', strtotime($l['sent_at']))) ?> · <?= e($l['provider']) ?><?= $l['template_key'] ? ' · ' . e($l['template_key']) : '' ?>
                <?= (int) $l['attempts'] > 1 ? ' · attempt ' . (int) $l['attempts'] : '' ?><?= $l['provider_message_id'] ? ' · id ' . e($l['provider_message_id']) : '' ?>
              </div>
              <?php if ($l['error']): ?><div class="doc-error"><?= e($l['error']) ?></div><?php endif; ?>
            </details></td>
            <td><span class="status-pill" style="background:<?= $bg ?>;color:<?= $fg ?>"><?= e(ucfirst(strtolower($l['status']))) ?></span></td>
            <td>
              <?php if ($l['status'] === 'FAILED' && (int) $l['attempts'] < SMS_MAX_ATTEMPTS && !in_array($l['template_key'], ['password_reset', 'mobile_verification', 'document_correction'], true)): ?>
                <form method="post" class="m-0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="retry">
                  <input type="hidden" name="log_id" value="<?= (int) $l['id'] ?>">
                  <button type="submit" class="btn-outline" aria-label="Retry SMS <?= (int) $l['id'] ?>"><?= $actionIcon('retry') ?> Retry</button>
                </form>
              <?php else:
                // Say why a failed message has no Retry button, so the administrator knows what to do instead.
                $noRetry = $l['status'] === 'FAILED'
                    ? (in_array($l['template_key'], ['password_reset', 'mobile_verification'], true) ? 'The Solo Parent must request a new code.'
                        : ($l['template_key'] === 'document_correction' ? 'Staff must resend it from the application review page.'
                            : ((int) $l['attempts'] >= SMS_MAX_ATTEMPTS ? 'Retry limit reached (' . (int) SMS_MAX_ATTEMPTS . ' attempts).' : null)))
                    : ($l['status'] === 'PENDING' ? 'Outcome not recorded yet.' : null); ?>
                <span class="admin-cell-meta"><?= $noRetry !== null ? e($noRetry) : '&mdash;' ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
        <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
      </div>
    </div>

    <div class="col-12 col-lg-4">
      <div class="review-card">
        <div class="review-card-head"><div class="title" id="admin-sms-test">Send a Test SMS</div></div>
        <div style="font-size:13px;color:var(--muted);margin-bottom:10px">Checks the SMS setup end to end with a harmless message.</div>
        <form method="post" class="d-flex flex-wrap gap-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_test">
          <input type="tel" name="mobile" aria-label="Mobile number for the test SMS" required maxlength="20" placeholder="09XX XXX XXXX" class="form-control" style="flex:1 1 160px;padding:8px;border:1px solid var(--input-border);border-radius:6px">
          <button type="submit" class="btn-navy"><?= $actionIcon('send') ?> Send Test</button>
        </form>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title" id="admin-sms-templates">Notification Templates</div></div>
        <div style="font-size:12px;color:var(--faint);margin-bottom:14px">Each template lists the placeholders it can use; the first one is required. Max 500 characters (a standard SMS is 160).</div>
        <div class="sms-template-list">
        <?php foreach ($templates as $t): if (!isset(SMSService::TEMPLATES[$t['template_key']])) { continue; /* retired template: row kept in the database, no longer managed */ } $ph = SMSService::PLACEHOLDERS[$t['template_key']] ?? []; $isEditing = ($editedTemplate ?? '') === $t['template_key']; ?>
          <?php if ($t['template_key'] === 'document_correction'): ?>
          <details class="admin-editor">
          <summary><?= e($t['label']) ?> <span class="admin-cell-meta">(system-managed)</span></summary>
          <div class="admin-editor-content d-flex flex-column gap-2">
            <div class="admin-cell-meta"><strong>System-managed, read-only.</strong> This is the exact approved wording sent to Solo Parents. It is fixed in the system and is not read from the stored template, so it cannot be edited here. The Solo Parent name, document names and application reference are filled in automatically, and staff remarks are never included.</div>
            <div class="admin-cell-meta">Placeholders filled in: <code>{applicant_name}</code> <code>{document}</code> <code>{ref}</code></div>
            <div class="sms-message-text" aria-label="Approved correction SMS wording"><?= e(SMSService::compact() ? SMSService::CORRECTION_BODY_COMPACT : SMSService::CORRECTION_BODY) ?></div>
            <div class="admin-cell-meta">Staff send it one batch at a time from the application review page, after previewing and confirming it. You can switch this message on or off under Settings &rarr; Notifications.</div>
          </div>
          </details>
          <?php continue; endif; ?>
          <details class="admin-editor" <?= $isEditing ? 'open' : '' ?>>
          <summary><?= e($t['label']) ?></summary>
          <form method="post" class="admin-editor-content d-flex flex-column gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_template">
            <input type="hidden" name="template_key" value="<?= e($t['template_key']) ?>">
            <div class="admin-cell-meta">Placeholders: <?php foreach ($ph as $p): ?><code><?= e($p) ?></code> <?php endforeach; ?></div>
            <label for="sms-template-<?= e($t['template_key']) ?>" class="user-label">Message text</label>
            <textarea id="sms-template-<?= e($t['template_key']) ?>" data-admin-count name="body" rows="3" maxlength="500" class="form-control" required><?= e($isEditing && is_string($_POST['body'] ?? null) ? $_POST['body'] : $t['body']) ?></textarea>
            <button type="submit" class="btn-outline" style="align-self:flex-start"><?= $actionIcon('save') ?> Save Template</button>
          </form>
          </details>
        <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>
