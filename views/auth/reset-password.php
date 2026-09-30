<?php
/**
 * @var string $error
 * @var string $success
 */
?>
<div class="portal-container" style="max-width:460px">
  <h1 class="font-heading mb-3" style="font-size:24px">Reset Password</h1>
  <div class="auth-card">
    <?php if ($error): ?><div class="auth-error"><?= e($error) ?></div><?php endif; ?>
    <?php if ($success): ?>
      <div class="info-box" style="max-width:none"><?= e($success) ?></div>
      <a href="<?= base_url('login.php') ?>" class="btn-navy text-decoration-none text-center d-block">Go to log in</a>
    <?php else: ?>
      <form method="post" class="d-flex flex-column gap-3">
        <?= csrf_field() ?>
        <input class="form-control" type="text" name="identifier" placeholder="Username or mobile number" value="<?= e((string) ($_POST['identifier'] ?? '')) ?>" required>
        <input class="form-control" type="text" name="code" placeholder="6-digit code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required>
        <input class="form-control" type="password" name="new_password" placeholder="New password" minlength="6" required>
        <button type="submit" class="btn-navy">Reset password</button>
      </form>
      <a href="<?= base_url('forgot-password.php') ?>" style="display:inline-block;font-size:13px;margin-top:12px">Didn't get a code? Request a new one</a>
    <?php endif; ?>
  </div>
</div>
