<?php
/**
 * ID expiry SMS not sent (read only for the MSWDO admin). Each row is a Solo Parent whose ID expired in the last 30 days and who did not
 * get the one expiry SMS on that day. MSWDO staff send it from Failed Texts (Send now); the system never repeats it by itself.
 *
 * @var array $staff
 * @var array $rows  id, full_name, reference_no, mobile, id_expires_at
 */
?>
<div class="portal-container">
  <?php require APP_ROOT . '/views/partials/admin-page-heading.php'; ?>
  <section class="review-card" id="admin-expiry-sms">
    <?php if (!$rows): ?>
      <div class="empty-state compact"><div class="empty-state-title" data-i18n>Nothing to send</div><span data-i18n>Every ID that expired in the last 30 days got its expiry SMS.</span></div>
    <?php else: ?>
      <p class="small text-muted" data-i18n>These Solo Parents did not get their expiry SMS on the day. MSWDO staff can send it from Failed Texts (Send now). Their account already shows the ID as expired.</p>
      <?php foreach ($rows as $x): ?>
        <div class="app-card" style="cursor:default">
          <div>
            <div class="app-ref"><?= e($x['full_name']) ?> <span style="font-weight:400;color:var(--muted)">· <?= e($x['reference_no']) ?></span></div>
            <div class="app-date">Expired <?= e(date('M j, Y', strtotime((string) $x['id_expires_at']))) ?> · ending <?= e(substr((string) $x['mobile'], -4)) ?></div>
          </div>
          <span class="status-pill" style="background:#fff0dd;color:#7f4106" data-i18n>Not sent</span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
</div>
