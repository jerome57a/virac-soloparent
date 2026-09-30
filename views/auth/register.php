<?php
/**
 * Create Account form, rendered inside views/auth/login.php's card.
 *
 * @var array $oldSignup
 */
?>
<form method="post" class="d-flex flex-column gap-3">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="signup">
  <input class="form-control" type="text" name="fullName" placeholder="Full name" value="<?= e($oldSignup['fullName']) ?>" required>
  <input class="form-control" type="email" name="email" placeholder="Email address" value="<?= e($oldSignup['email']) ?>" required>
  <input class="form-control" type="text" name="username" placeholder="Username" value="<?= e($oldSignup['username']) ?>" required>
  <input class="form-control" type="password" name="password" placeholder="Password" minlength="6" required>
  <button type="submit" class="btn-navy">Create Account</button>
  <div style="font-size:12px;color:var(--faint)">Your account is stored securely in the MSWDO Virac database.</div>
</form>
