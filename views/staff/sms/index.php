<?php
/**
 * Failed texts to applicants that still need staff attention (SmsLog::needingAttention()): each with Retry when it can be retried.
 *
 * @var array  $staff
 * @var string $ownDashboard
 * @var array  $rows       id, application_id, reference_no, template_key, attempts, error, sent_at, can_retry
 * @var ?array $smsNotice  outcome of the retry just made (shown once)
 */
?>
<div class="portal-container" style="max-width:900px">
  <?php require APP_ROOT . '/views/partials/staff-page-heading.php'; ?>
  <?php if (!empty($smsNotice)) { require APP_ROOT . '/views/staff/applications/sms-notice.php'; } ?>
  <?php if (!$rows): ?>
    <div class="info-box" style="max-width:none" role="status" data-i18n>No failed texts need attention. A failed text stays here for <?= (int) SmsLog::ATTENTION_DAYS ?> days, until it is resent or replaced by a newer text of the same kind.</div>
  <?php else: ?>
    <p class="small text-muted"><?= count($rows) ?> failed <?= count($rows) === 1 ? 'text' : 'texts' ?> from the last <?= (int) SmsLog::ATTENTION_DAYS ?> days. Each leaves this list when it is resent, replaced by a newer text of the same kind, or after <?= (int) SmsLog::ATTENTION_DAYS ?> days.</p>
    <div class="review-card">
      <ul class="ac-list mb-0">
        <?php foreach ($rows as $r): ?>
          <li>
            <span class="ac-pill is-small is-fix" style="align-self:flex-start">Failed</span>
            <span>
              <strong><?= e(sms_kind_label($r['template_key'])) ?></strong>
              <span class="small text-muted">· <?= e(date('M j, g:i A', strtotime((string) $r['sent_at']))) ?><?= (int) $r['attempts'] > 1 ? ' · attempt ' . (int) $r['attempts'] : '' ?></span>
              · <a href="<?= application_url($staff, (int) $r['application_id']) ?>"><?= e($r['reference_no'] ?: 'Application #' . (int) $r['application_id']) ?></a>
              <?php if ($r['error']): $help = SMSService::failureHelp($r['error']); ?><br><span class="small" style="color:var(--ac-fail)"><b>Cause:</b> <?= e($help['cause']) ?> <b>What to do:</b> <?= e($help['action']) ?><br>Details: <?= e($r['error']) ?></span><?php endif; ?>
              <?php if ($r['can_retry']): ?>
                <form method="post" class="d-inline ms-2">
                  <?= csrf_field() ?>
                  <input type="hidden" name="form_action" value="retry_sms">
                  <input type="hidden" name="sms_log_id" value="<?= (int) $r['id'] ?>">
                  <button type="submit" class="btn-outline" style="padding:3px 12px;font-size:12px">Retry SMS</button>
                </form>
              <?php else: ?>
                <br><span class="small text-muted"><?= e($r['template_key'] === 'document_correction' ? 'Open the application and confirm the correction text again from the document review card.' : ((int) $r['attempts'] >= SMS_MAX_ATTEMPTS ? 'All ' . SMS_MAX_ATTEMPTS . ' attempts were used. Contact the Solo Parent another way.' : 'This kind of text cannot be resent from here.')) ?></span>
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>
