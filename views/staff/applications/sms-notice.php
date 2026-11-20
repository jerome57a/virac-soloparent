<?php
/**
 * The outcome of an SMS staff just triggered: green when the gateway accepted it, red with a Retry button when it failed,
 * amber when nothing was sent because SMS is switched off. Shown once. Colours follow the light and dark themes.
 *
 * @var array $smsNotice state (sent|failed|skipped), text, log_id
 */
$state = in_array($smsNotice['state'] ?? '', ['sent', 'failed', 'skipped'], true) ? $smsNotice['state'] : 'failed';
$icon = ['sent' => '✓', 'failed' => '✕', 'skipped' => '!'][$state];
$title = ['sent' => 'SMS sent', 'failed' => 'SMS failed', 'skipped' => 'SMS not sent'][$state];
?>
<div class="sms-alert is-<?= e($state) ?>" role="<?= $state === 'sent' ? 'status' : 'alert' ?>" id="sms-notice">
  <span class="ac-mark is-<?= $state === 'sent' ? 'pass' : ($state === 'failed' ? 'fail' : 'flag') ?>" aria-hidden="true"><?= e($icon) ?></span>
  <div class="sms-alert-body">
    <strong><?= e($title) ?></strong>
    <div><?= e((string) ($smsNotice['text'] ?? '')) ?></div>
    <?php if ($state !== 'sent' && !empty($smsNotice['log_id'])): ?>
      <form method="post" class="mt-2 mb-0">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="retry_sms">
        <input type="hidden" name="sms_log_id" value="<?= (int) $smsNotice['log_id'] ?>">
        <button type="submit" class="btn-navy" style="padding:6px 16px;font-size:13px">Retry SMS</button>
      </form>
    <?php endif; ?>
  </div>
</div>
