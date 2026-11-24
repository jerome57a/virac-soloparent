<?php
/**
 * The texts sent to this applicant, newest first, with their status. A failed one that can still be retried has a Retry button.
 *
 * @var array $smsLogs rows from SmsLog::forApplication(): template_key, status, attempts, error, mobile, sent_at, can_retry
 */
if (!$smsLogs) {
    return;
}
$kinds = [
    'submitted' => 'Application received', 'status_change' => 'Status update', 'interview_scheduled' => 'Interview scheduled', 'interview_custom' => 'Interview scheduled (custom)',
    'interview_date_assigned' => 'Interview date set by MSWDO staff', 'interview_date_assigned_custom' => 'Interview date set by MSWDO staff (custom)', 'interview_date_booked' => 'Interview date booked',
    'interview_booking_open' => 'Interview booking opened', 'interview_booking_custom' => 'Interview booking opened (custom)', 'appointment_reminder' => 'Appointment reminder',
    'appointment_cancelled' => 'Appointment cancelled', 'appointment_missed' => 'Appointment missed', 'id_release_scheduled' => 'ID release scheduled', 'id_release_custom' => 'ID release scheduled (custom)',
    'renewal_submitted' => 'Renewal received', 'renewal_reminder' => 'ID expiry-day text', 'renewal_expired' => 'ID expired text (sent late)', 'document_correction' => 'Document correction',
];
$words = ['SENT' => ['Sent', 'pass'], 'FAILED' => ['Failed', 'fail'], 'PENDING' => ['Pending', 'flag']];
?>
<?php
// The texts are folded: one line with the counts; the list (and the failure details) opens when staff choose.
$smsFailed = count(array_filter($smsLogs, fn($l) => $l['status'] === 'FAILED'));
$smsSent = count(array_filter($smsLogs, fn($l) => $l['status'] === 'SENT'));
$smsOther = count($smsLogs) - $smsFailed - $smsSent;
?>
<details class="review-card sms-fold" id="sms">
  <summary>
    <span class="title" data-i18n>SMS to the Solo Parent</span>
    <span class="sms-fold-pills">
      <?php if ($smsSent): ?><span class="ac-pill is-small is-ready"><?= $smsSent ?> sent</span><?php endif; ?>
      <?php if ($smsFailed): ?><span class="ac-pill is-small is-fix"><?= $smsFailed ?> failed</span><?php endif; ?>
      <?php if ($smsOther): ?><span class="ac-pill is-small is-review"><?= $smsOther ?> other</span><?php endif; ?>
    </span>
    <span class="sms-alert-toggle" aria-hidden="true"><span class="when-closed">Show</span><span class="when-open">Hide</span></span>
  </summary>
  <ul class="ac-list mb-0">
    <?php foreach ($smsLogs as $l): [$word, $tone] = $words[$l['status']] ?? [ucfirst(strtolower((string) $l['status'])), 'flag']; ?>
      <li>
        <span class="ac-pill is-small <?= $tone === 'pass' ? 'is-ready' : ($tone === 'fail' ? 'is-fix' : 'is-review') ?>" style="align-self:flex-start"><?= e($word) ?></span>
        <span>
          <strong><?= e($kinds[$l['template_key']] ?? ($l['template_key'] ?: 'Message')) ?></strong>
          <span class="small text-muted">· <?= e(date('M j, g:i A', strtotime((string) $l['sent_at']))) ?><?= (int) $l['attempts'] > 1 ? ' · attempt ' . (int) $l['attempts'] : '' ?></span>
          <?php if ($l['status'] === 'FAILED' && $l['error']): $help = SMSService::failureHelp($l['error']); ?><br><span class="small" style="color:var(--ac-fail)"><b>Cause:</b> <?= e($help['cause']) ?> <b>What to do:</b> <?= e($help['action']) ?><br>Details: <?= e($l['error']) ?></span><?php endif; ?>
          <?php if ($l['can_retry']): ?>
            <form method="post" class="d-inline ms-2">
              <?= csrf_field() ?>
              <input type="hidden" name="form_action" value="retry_sms">
              <input type="hidden" name="sms_log_id" value="<?= (int) $l['id'] ?>">
              <button type="submit" class="btn-outline" style="padding:3px 12px;font-size:12px">Retry SMS</button>
            </form>
          <?php elseif ($l['status'] === 'FAILED'): ?>
            <br><span class="small text-muted"><?= e($l['template_key'] === 'document_correction' ? 'Confirm the correction text again from the document review card.' : ((int) $l['attempts'] >= SMS_MAX_ATTEMPTS ? 'All ' . SMS_MAX_ATTEMPTS . ' attempts were used. Contact the Solo Parent another way.' : (in_array($l['template_key'], SmsLog::NOT_RETRYABLE, true) ? 'The Solo Parent must request a new code.' : 'A newer text of this kind was already sent, so this one is not retried.'))) ?></span>
          <?php endif; ?>
        </span>
      </li>
    <?php endforeach; ?>
  </ul>
</details>
<script>
  // A link to #sms (or a failed text the staff member is looking for) opens the list.
  (() => { const d = document.getElementById('sms'); if (d && location.hash === '#sms') d.open = true; })();
</script>
