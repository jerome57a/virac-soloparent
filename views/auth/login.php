<?php
/**
 * Combined Log In / Create Account card. Rendered both inside the portal
 * tab (includes/portal/login.php) and by the standalone login.php /
 * register.php, which pass their own tab URLs.
 *
 * @var string $authMode    'login' | 'signup'
 * @var string  $authError
 * @var ?string $authNotice  one-time notice, e.g. session expired
 * @var array   $oldSignup
 * @var string $loginTabUrl
 * @var string $signupTabUrl
 */
$returnKey = $_SESSION['applicant_return'] ?? null;
if (applicant_return_path($returnKey)) {
    $loginTabUrl = base_url('login.php?next=' . rawurlencode($returnKey));
    $signupTabUrl = base_url('register.php?next=' . rawurlencode($returnKey));
}
?>
<div class="auth-card">
  <div class="d-flex gap-2 mb-3">
    <a href="<?= e($loginTabUrl) ?>" class="auth-tab text-decoration-none text-center <?= $authMode === 'login' ? 'active' : '' ?>"<?= $authMode === 'login' ? ' aria-current="page"' : '' ?> data-i18n>Log In</a>
    <a href="<?= e($signupTabUrl) ?>" class="auth-tab text-decoration-none text-center <?= $authMode !== 'login' ? 'active' : '' ?>"<?= $authMode !== 'login' ? ' aria-current="page"' : '' ?> data-i18n>Create Account</a>
  </div>

  <?php if (!empty($authNotice)): ?>
    <div class="info-box" style="max-width:none" role="status"><?= e($authNotice) ?></div>
  <?php endif; ?>
  <?php if ($authError): ?>
    <div class="auth-error" role="alert"><?= e($authError) ?></div>
  <?php endif; ?>

  <?php if ($authMode === 'verify'): ?>
    <?php require __DIR__ . '/verify-mobile.php'; ?>
  <?php elseif ($authMode === 'signup'): ?>
    <?php require __DIR__ . '/register.php'; ?>
  <?php else: ?>
    <p style="font-size:14px;color:var(--muted)" data-i18n>Sign in with your username and password. Solo Parents, MSWDO staff and MSWDO admins all use this page.</p>
    <form method="post" class="d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">
      <label class="user-label" data-i18n>Username
        <input class="form-control" type="text" name="username" autocomplete="username" maxlength="150" required>
      </label>
      <div style="font-size:12px;color:var(--faint)" data-i18n>Registered before usernames were introduced? Sign in once with your mobile number or Gmail address, and you will be asked to choose a username.</div>
      <label class="user-label" data-i18n>Password
        <input class="form-control" type="password" name="password" autocomplete="current-password" required>
      </label>
      <button type="submit" class="btn-navy" data-i18n>Sign In</button>
    </form>
    <a href="<?= base_url('forgot-password.php') ?>" style="display:inline-block;font-size:13px;margin-top:12px" data-i18n>Forgot password?</a>
    <div style="font-size:13px;margin-top:12px"><span data-i18n>New Solo Parent?</span> <a href="<?= e($signupTabUrl) ?>" data-i18n>Create Account</a></div>
  <?php endif; ?>
</div>
