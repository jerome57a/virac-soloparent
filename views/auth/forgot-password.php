<?php
/**
 * @var string $error
 * @var string $success
 */
?>
<div class="portal-container" style="max-width:460px">
  <h1 class="font-heading mb-3" style="font-size:24px" data-i18n>Forgot Password</h1>
  <div class="auth-card">
    <?php if ($error): ?><div class="auth-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if (empty($smsReset)): ?>
      <p style="font-size:14px;color:var(--muted)" data-i18n>Password resets are handled by the MSWDO office. Contact MSWDO Virac and give your username. An MSWDO admin will confirm who you are and give you a temporary password. You will be asked to choose a new password the next time you log in.</p>
      <?php $officeLines = array_filter([setting('office_name'), setting('office_address'), setting('office_hours'), setting('office_phone')]); ?>
      <?php if ($officeLines): ?>
        <div class="info-box" style="max-width:none">
          <?php foreach ($officeLines as $line): ?><div><?= e((string) $line) ?></div><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <p style="font-size:13px;color:var(--faint);margin-top:12px" data-i18n>MSWDO staff and MSWDO admins: ask your MSWDO admin to reset your password.</p>
    <?php elseif ($success): ?>
      <div class="info-box" style="max-width:none" role="status"><?= e($success) ?></div>
      <a href="<?= base_url('reset-password.php') ?>" class="btn-navy text-decoration-none text-center d-block" data-i18n>Enter reset code</a>
    <?php else: ?>
      <p style="font-size:14px;color:var(--muted)">Enter your Gmail address, existing username or the mobile number on your account. We'll text you a 6-digit reset code, valid for <?= (int) PASSWORD_RESET_CODE_MINUTES ?> minutes.</p>
      <p style="font-size:13px;color:var(--faint)" data-i18n>No code, or you no longer have that phone? Contact MSWDO Virac. An MSWDO admin can confirm who you are and give you a temporary password.</p>
      <p style="font-size:13px;color:var(--faint)" data-i18n>MSWDO staff and MSWDO admins: reset codes are sent only to a mobile number on your account. If you have none, ask your MSWDO admin to reset your password.</p>
      <form method="post" class="d-flex flex-column gap-3">
        <?= csrf_field() ?>
        <input class="form-control" type="text" name="identifier" aria-label="Gmail, username or mobile number" placeholder="Gmail, username or mobile number" required data-i18n-attrs="aria-label placeholder">
        <button type="submit" class="btn-navy" data-i18n>Send reset code</button>
      </form>
    <?php endif; ?>
  </div>
  <div style="font-size:13px;margin-top:12px">
    <a href="<?= base_url('login.php') ?>" data-i18n>Back to log in</a>
  </div>
</div>
