<?php
/** @var PDO $pdo */

$authMode = $_GET['mode'] ?? 'login';
if (!in_array($authMode, ['login', 'signup'], true)) {
    $authMode = 'login';
}
$authError = '';
$oldSignup = ['fullName' => '', 'email' => '', 'username' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $authMode = 'login';
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role = 'applicant'");
        $stmt->execute([$username]);
        $account = $stmt->fetch();
        if (!$account || !password_verify($password, $account['password_hash'])) {
            $authError = 'Incorrect username or password.';
        } else {
            login_user($account);
            redirect(base_url('portal.php?tab=overview'));
        }
    } elseif ($action === 'signup') {
        $authMode = 'signup';
        $fullName = trim((string) ($_POST['fullName'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $oldSignup = ['fullName' => $fullName, 'email' => $email, 'username' => $username];

        if ($fullName === '' || $username === '' || $password === '') {
            $authError = 'Please fill in full name, username, and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $authError = 'Enter a valid email address.';
        } elseif (strlen($password) < 6) {
            $authError = 'Password must be at least 6 characters.';
        } else {
            $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $check->execute([$username]);
            if ($check->fetch()) {
                $authError = 'That username is already taken.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $pdo->prepare('INSERT INTO users (full_name, email, username, password_hash, role) VALUES (?, ?, ?, ?, ?)');
                $ins->execute([$fullName, $email, $username, $hash, 'applicant']);
                $newUser = [
                    'id' => (int) $pdo->lastInsertId(),
                    'full_name' => $fullName,
                    'email' => $email,
                    'username' => $username,
                    'mobile' => null,
                    'role' => 'applicant',
                ];
                login_user($newUser);
                redirect(base_url('portal.php?tab=apply'));
            }
        }
    }
}
?>
<div class="auth-card">
  <div class="d-flex gap-2 mb-3">
    <a href="<?= base_url('portal.php?tab=login&mode=login') ?>" class="auth-tab text-decoration-none text-center <?= $authMode === 'login' ? 'active' : '' ?>">Log In</a>
    <a href="<?= base_url('portal.php?tab=login&mode=signup') ?>" class="auth-tab text-decoration-none text-center <?= $authMode === 'signup' ? 'active' : '' ?>">Create Account</a>
  </div>

  <?php if ($authError): ?>
    <div class="auth-error"><?= e($authError) ?></div>
  <?php endif; ?>

  <?php if ($authMode === 'signup'): ?>
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
  <?php else: ?>
    <form method="post" class="d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">
      <input class="form-control" type="text" name="username" placeholder="Username" required>
      <input class="form-control" type="password" name="password" placeholder="Password" required>
      <button type="submit" class="btn-navy">Log In</button>
    </form>
  <?php endif; ?>
</div>
