<?php
/**
 * The outcome of an SMS staff just triggered: green when the gateway accepted it; when it failed, or nothing was sent because SMS is
 * switched off, a compact one-line alert whose details (the full message and the Retry button) stay hidden until staff open them, so a
 * page with several alerts stays tidy. Shown once. Colours follow the light and dark themes.
 *
 * @var array $smsNotice state (sent|failed|skipped), text, log_id
 */
$state = in_array($smsNotice['state'] ?? '', ['sent', 'failed', 'skipped'], true) ? $smsNotice['state'] : 'failed';
$icon = ['sent' => '✓', 'failed' => '✕', 'skipped' => '!'][$state];
$title = ['sent' => 'SMS sent', 'failed' => 'SMS failed', 'skipped' => 'SMS not sent'][$state];
$markTone = $state === 'sent' ? 'pass' : ($state === 'failed' ? 'fail' : 'flag');
?>
<?php if ($state === 'sent'): ?>
<div class="sms-alert is-sent" role="status" id="sms-notice">
  <span class="ac-mark is-pass" aria-hidden="true"><?= e($icon) ?></span>
  <div class="sms-alert-body">
    <strong><?= e($title) ?></strong>
    <div><?= e((string) ($smsNotice['text'] ?? '')) ?></div>
  </div>
</div>
<?php else: $short = $state === 'failed' ? SMSService::failureHelp((string) ($smsNotice['text'] ?? ''))['cause'] : 'Nothing was sent.'; ?>
<div class="sms-alert is-<?= e($state) ?> sms-alert-fold" role="alert" id="sms-notice">
  <details>
    <summary>
      <span class="ac-mark is-<?= e($markTone) ?>" aria-hidden="true"><?= e($icon) ?></span>
      <span class="sms-alert-line"><strong><?= e($title) ?></strong><span class="sms-alert-short"><?= e($short) ?></span></span>
      <span class="sms-alert-toggle" aria-hidden="true"><span class="when-closed">Show details</span><span class="when-open">Hide details</span></span>
    </summary>
    <div class="sms-alert-body">
      <div><?= e((string) ($smsNotice['text'] ?? '')) ?></div>
      <?php if (!empty($smsNotice['log_id'])): ?>
        <form method="post" class="mt-2 mb-0">
          <?= csrf_field() ?>
          <input type="hidden" name="form_action" value="retry_sms">
          <input type="hidden" name="sms_log_id" value="<?= (int) $smsNotice['log_id'] ?>">
          <button type="submit" class="btn-navy" style="padding:6px 16px;font-size:13px">Retry SMS</button>
        </form>
      <?php endif; ?>
    </div>
  </details>
</div>
<?php endif; ?>
