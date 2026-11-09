<?php /** Signed-in personnel account; password handling remains in StaffController. */ ?>
<div class="portal-container personnel-account">
  <a href="<?= e($ownDashboard) ?>" class="account-back"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10 6-6 6 6 6M4 12h16"/></svg><span data-i18n>Back to Dashboard</span></a>
  <header class="account-heading">
    <div><h1 class="font-heading" data-i18n>My Account</h1><p data-i18n>View your account details and keep your password up to date.</p></div>
    <span class="account-role" data-i18n><?= e($roleLabels[$staff['role']] ?? $staff['role']) ?></span>
  </header>
  <?php if (!empty($staff['must_change_password'])): ?>
    <div class="auth-error account-feedback" role="alert" data-i18n>An administrator reset your access. Choose a new password to continue using the system.</div>
  <?php endif; ?>
  <?php if ($error): ?><div class="auth-error account-feedback" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box account-feedback" role="status"><?= e($success) ?></div><?php endif; ?>
  <div class="account-columns">
    <section class="account-panel" aria-labelledby="account-details-title">
      <div class="account-panel-heading"><h2 id="account-details-title" data-i18n>Account details</h2><p data-i18n>Your registered information.</p></div>
      <dl class="account-details">
        <div><dt data-i18n>Name</dt><dd><?= e($staff['full_name']) ?></dd></div>
        <div><dt data-i18n>Username</dt><dd><?= e($staff['username']) ?></dd></div>
        <div><dt data-i18n>Role</dt><dd data-i18n><?= e($roleLabels[$staff['role']] ?? $staff['role']) ?></dd></div>
        <div><dt data-i18n>Email</dt><dd><?= e($staff['email']) ?></dd></div>
        <div><dt data-i18n>Mobile number</dt><dd><?= e($staff['mobile'] ?: '—') ?></dd></div>
        <div><dt data-i18n>Last sign-in</dt><dd><?= $staff['last_login_at'] ? e(date('M j, Y g:i A', strtotime($staff['last_login_at']))) : '—' ?></dd></div>
      </dl>
      <p class="account-note" data-i18n>To change your name, email or mobile number, ask an administrator.</p>
    </section>
    <section class="account-panel" id="password" aria-labelledby="account-password-title">
      <div class="account-panel-heading"><h2 id="account-password-title" data-i18n>Change password</h2><p data-i18n>Use a password you do not use for other accounts.</p></div>
      <form method="post" class="account-password-form">
        <?= csrf_field() ?>
        <label for="account-current-password"><span data-i18n>Current password</span><input id="account-current-password" class="form-control" type="password" name="current_password" autocomplete="current-password" required></label>
        <label for="account-new-password"><span data-i18n>New password</span><input id="account-new-password" class="form-control" type="password" name="new_password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" aria-describedby="account-password-help" required></label>
        <p class="account-password-help" id="account-password-help" data-i18n>At least <?= PASSWORD_MIN_LENGTH ?> characters, with at least one letter and one number.</p>
        <label for="account-confirm-password"><span data-i18n>Confirm new password</span><input id="account-confirm-password" class="form-control" type="password" name="new_password_confirm" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required></label>
        <div class="account-form-actions"><button type="submit" class="btn-navy" data-i18n>Update Password</button></div>
      </form>
    </section>
  </div>
</div>
