<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../controllers/AuthController.php';

$authController = new AuthController($pdo);
$error = $authController->handleStaffLogin();
$notice = $authController->takeNotice();

$pageTitle = 'MSWDO Staff Login';
require __DIR__ . '/../includes/header.php';
?>
<div class="portal-container" style="max-width:420px">
  <h1 class="font-heading mb-3" style="font-size:24px">MSWDO Staff Login</h1>
  <div class="auth-card">
    <?php if ($notice): ?><div class="info-box" style="max-width:none"><?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="auth-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <input class="form-control" type="text" name="username" aria-label="Username" placeholder="Username" autocomplete="username" required>
      <input class="form-control" type="password" name="password" aria-label="Password" placeholder="Password" autocomplete="current-password" required>
      <button type="submit" class="btn-navy">Log In</button>
    </form>
    <a href="<?= base_url('forgot-password.php') ?>" style="display:inline-block;font-size:13px;margin-top:12px">Forgot password?</a>
  </div>
  <div style="font-size:12px;color:var(--faint);margin-top:12px">This area is for MSWDO Virac personnel only.</div>
</div>
<?php
$footerVariant = 'portal';
require __DIR__ . '/../includes/footer.php';
