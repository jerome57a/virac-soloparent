<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../controllers/AuthController.php';

$error = (new AuthController($pdo))->handleStaffLogin();

$pageTitle = 'MSWDO Staff Login';
require __DIR__ . '/../includes/header.php';
?>
<div class="portal-container" style="max-width:420px">
  <h1 class="font-heading mb-3" style="font-size:24px">MSWDO Staff Login</h1>
  <div class="auth-card">
    <?php if ($error): ?><div class="auth-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <input class="form-control" type="text" name="username" placeholder="Username" required>
      <input class="form-control" type="password" name="password" placeholder="Password" required>
      <button type="submit" class="btn-navy">Log In</button>
    </form>
    <a href="<?= base_url('forgot-password.php') ?>" style="display:inline-block;font-size:13px;margin-top:12px">Forgot password?</a>
  </div>
  <div style="font-size:12px;color:var(--faint);margin-top:12px">This area is for MSWDO Virac personnel only.</div>
</div>
<?php
$footerVariant = 'portal';
require __DIR__ . '/../includes/footer.php';
