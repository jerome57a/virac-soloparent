<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';

if (current_staff($pdo)) {
    redirect(base_url('admin/dashboard.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role IN ('mswd_social_worker','admin')");
    $stmt->execute([$username]);
    $account = $stmt->fetch();
    if (!$account || !password_verify($password, $account['password_hash'])) {
        $error = 'Incorrect username or password.';
    } elseif (!$account['is_active']) {
        $error = 'This account has been deactivated. Please contact your system administrator.';
    } else {
        login_staff($account);
        audit_log($pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Login');
        redirect(base_url('admin/dashboard.php'));
    }
}

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
  </div>
  <div style="font-size:12px;color:var(--faint);margin-top:12px">This area is for MSWDO Virac personnel only.</div>
</div>
<?php
$footerVariant = 'portal';
require __DIR__ . '/../includes/footer.php';
