<?php
/**
 * Combined Log In / Create Account card. Rendered both inside the portal
 * tab (includes/portal/login.php) and by the standalone login.php /
 * register.php, which pass their own tab URLs.
 *
 * @var string $authMode    'login' | 'signup'
 * @var string $authError
 * @var array  $oldSignup
 * @var string $loginTabUrl
 * @var string $signupTabUrl
 */
?>
<div class="auth-card">
  <div class="d-flex gap-2 mb-3">
    <a href="<?= e($loginTabUrl) ?>" class="auth-tab text-decoration-none text-center <?= $authMode === 'login' ? 'active' : '' ?>">Log In</a>
    <a href="<?= e($signupTabUrl) ?>" class="auth-tab text-decoration-none text-center <?= $authMode === 'signup' ? 'active' : '' ?>">Create Account</a>
  </div>

  <?php if ($authError): ?>
    <div class="auth-error"><?= e($authError) ?></div>
  <?php endif; ?>

  <?php if ($authMode === 'signup'): ?>
    <?php require __DIR__ . '/register.php'; ?>
  <?php else: ?>
    <form method="post" class="d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">
      <input class="form-control" type="text" name="username" placeholder="Username" required>
      <input class="form-control" type="password" name="password" placeholder="Password" required>
      <button type="submit" class="btn-navy">Log In</button>
    </form>
    <a href="<?= base_url('forgot-password.php') ?>" style="display:inline-block;font-size:13px;margin-top:12px">Forgot password?</a>
  <?php endif; ?>
</div>
