<?php
/**
 * @var string $error
 * @var string $success
 */
?>
<div class="portal-container" style="max-width:460px">
  <h1 class="font-heading mb-3" style="font-size:24px" data-i18n>Reset Password</h1>
  <div class="auth-card">
    <?php if ($error): ?><div class="auth-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?>
      <div class="info-box" style="max-width:none" role="status"><?= e($success) ?></div>
      <a href="<?= base_url($loginUrl) ?>" class="btn-navy text-decoration-none text-center d-block" data-i18n>Go to log in</a>
    <?php else: ?>
      <form method="post" class="d-flex flex-column gap-3">
        <?= csrf_field() ?>
        <input class="form-control" type="text" name="identifier" aria-label="Gmail, username or mobile number" placeholder="Gmail, username or mobile number" value="<?= e((string) ($_POST['identifier'] ?? '')) ?>" required data-i18n-attrs="aria-label placeholder">
        <input class="form-control" type="text" name="code" aria-label="6-digit reset code" placeholder="6-digit code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required data-i18n-attrs="placeholder">
        <input class="form-control" type="password" name="new_password" aria-label="New password" placeholder="New password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required data-i18n-attrs="aria-label placeholder">
        <input class="form-control" type="password" name="new_password_confirm" aria-label="Confirm new password" placeholder="Confirm new password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required data-i18n-attrs="aria-label placeholder">
        <div style="font-size:12px;color:var(--faint);margin-top:-6px" data-i18n>At least <?= PASSWORD_MIN_LENGTH ?> characters, with at least one letter and one number.</div>
        <button type="submit" class="btn-navy" data-i18n>Reset password</button>
      </form>
      <a href="<?= base_url('forgot-password.php') ?>" style="display:inline-block;font-size:13px;margin-top:12px" data-i18n>Didn't get a code? Request a new one</a>
    <?php endif; ?>
  </div>
</div>
