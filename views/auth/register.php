<?php
/**
 * Create Account form, rendered inside views/auth/login.php's card.
 *
 * @var array $oldSignup  fullName, mobile, email
 */
?>
<form method="post" class="d-flex flex-column gap-3">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="signup">
  <label class="user-label" data-i18n>Full name
    <input class="form-control" type="text" name="fullName" placeholder="Juan Dela Cruz" value="<?= e($oldSignup['fullName']) ?>" maxlength="150" autocomplete="name" required>
  </label>
  <label class="user-label" data-i18n>Username
    <input class="form-control" type="text" name="username" placeholder="juan.delacruz" value="<?= e($oldSignup['username'] ?? '') ?>" minlength="3" maxlength="30" pattern="[A-Za-z0-9][A-Za-z0-9._\-]*" autocomplete="username" title="3-30 characters: letters, numbers, dots, underscores or hyphens, with at least one letter." data-i18n-attrs="title" required>
  </label>
  <div style="font-size:12px;color:var(--faint);margin-top:-6px" data-i18n>You will sign in with this username and your password. It cannot be changed later.</div>
  <label class="user-label" data-i18n>Mobile number
    <input class="form-control" type="tel" name="mobile" placeholder="09XXXXXXXXX" value="<?= e($oldSignup['mobile']) ?>" inputmode="tel" autocomplete="tel" required>
  </label>
  <label class="user-label" data-i18n>Email address (optional)
    <input class="form-control" type="email" name="email" placeholder="yourname@gmail.com" value="<?= e($oldSignup['email']) ?>" maxlength="150" pattern="[^@\s]+@[gG][mM][aA][iI][lL]\.[cC][oO][mM]" autocomplete="email" title="Enter a Gmail address ending in @gmail.com, or leave this blank." data-i18n-attrs="title">
  </label>
  <label class="user-label" data-i18n>Password
    <input class="form-control" type="password" name="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
  </label>
  <label class="user-label" data-i18n>Confirm password
    <input class="form-control" type="password" name="password_confirm" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
  </label>
  <div style="font-size:12px;color:var(--faint);margin-top:-6px" data-i18n>At least <?= PASSWORD_MIN_LENGTH ?> characters, with at least one letter and one number. Your mobile number is used for SMS updates and password recovery.</div>
  <button type="submit" class="btn-navy" data-i18n>Create Account</button>
  <div style="font-size:12px;color:var(--faint)" data-i18n>Your account is stored securely in the MSWDO Virac database.</div>
</form>
