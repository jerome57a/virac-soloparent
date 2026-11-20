<?php
/**
 * Sign-up step 2: confirm the mobile number with the texted code. The account is created only after the code is entered.
 *
 * @var string  $verifyMobile  masked number the code was sent to
 * @var ?string $codeHint      the code, only while the SMS provider is the log-only test provider
 * @var int     $resendWait    seconds until another code may be requested
 */
?>
<p style="font-size:14px;color:var(--muted)"><span data-i18n>We sent a 6-digit code by SMS to</span> <b><?= e($verifyMobile) ?></b>. <span data-i18n>Enter it below to finish creating your account.</span></p>
<?php if (!empty($codeHint)): ?>
  <div class="info-box" style="max-width:none" role="status"><span data-i18n>SMS is not delivered on this server. Your code is:</span> <b style="letter-spacing:2px"><?= e($codeHint) ?></b></div>
<?php endif; ?>
<form method="post" class="d-flex flex-column gap-3">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="verify_mobile">
  <label class="user-label" data-i18n>Verification code
    <input class="form-control" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" minlength="6" autocomplete="one-time-code" placeholder="123456" required autofocus data-i18n-attrs="placeholder">
  </label>
  <button type="submit" class="btn-navy" data-i18n>Verify and create account</button>
</form>
<div class="d-flex flex-wrap gap-3 align-items-center" style="margin-top:14px;font-size:13px">
  <form method="post" class="m-0">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="resend_code">
    <button type="submit" class="btn-outline" style="padding:6px 14px;font-size:13px" data-i18n>Send a new code</button>
  </form>
  <form method="post" class="m-0">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cancel_verify">
    <button type="submit" class="btn-outline" style="padding:6px 14px;font-size:13px" data-i18n>Change my details</button>
  </form>
</div>
<?php if ($resendWait > 0): ?>
  <div style="font-size:12px;color:var(--faint);margin-top:8px"><span data-i18n>You can ask for a new code in</span> <?= (int) $resendWait ?> <span data-i18n>seconds.</span></div>
<?php endif; ?>
