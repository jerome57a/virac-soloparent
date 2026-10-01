<?php
/**
 * Create Account form, rendered inside views/auth/login.php's card.
 *
 * @var array $oldSignup  fullName, email, mobile, username
 */
?>
<form method="post" class="d-flex flex-column gap-3">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="signup">
  <input class="form-control" type="text" name="fullName" aria-label="Full name" placeholder="Full name" value="<?= e($oldSignup['fullName']) ?>" maxlength="150" autocomplete="name" required>
  <input class="form-control" type="email" name="email" aria-label="Email address" placeholder="Email address" value="<?= e($oldSignup['email']) ?>" maxlength="150" autocomplete="email" required>
  <input class="form-control" type="tel" name="mobile" aria-label="Mobile number" placeholder="Mobile number (09XXXXXXXXX)" value="<?= e($oldSignup['mobile']) ?>" inputmode="tel" autocomplete="tel" required>
  <input class="form-control" type="text" name="username" aria-label="Username" placeholder="Username" value="<?= e($oldSignup['username']) ?>" pattern="(?=.*[A-Za-z])[A-Za-z0-9._]{4,30}" title="4 to 30 characters: letters, numbers, dots, or underscores, with at least one letter" autocomplete="username" required>
  <input class="form-control" type="password" name="password" aria-label="Password" placeholder="Password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
  <input class="form-control" type="password" name="password_confirm" aria-label="Confirm password" placeholder="Confirm password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required>
  <div style="font-size:12px;color:var(--faint);margin-top:-6px">At least <?= PASSWORD_MIN_LENGTH ?> characters, with at least one letter and one number. Your mobile number is used for SMS updates and password recovery.</div>
  <button type="submit" class="btn-navy">Create Account</button>
  <div style="font-size:12px;color:var(--faint)">Your account is stored securely in the MSWDO Virac database.</div>
</form>
