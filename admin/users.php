<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';

$staff = require_admin_login($pdo);

$validRoles = ['applicant', 'mswd_social_worker', 'admin'];
$roleLabels = ['applicant' => 'Applicant', 'mswd_social_worker' => 'MSWD Social Worker', 'admin' => 'Administrator'];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $fullName = trim((string) ($_POST['fullName'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? '');

        if ($fullName === '' || $username === '' || $password === '') {
            $error = 'Please fill in full name, username, and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif (!in_array($role, $validRoles, true)) {
            $error = 'Select a valid role.';
        } else {
            $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $check->execute([$username]);
            if ($check->fetch()) {
                $error = 'That username is already taken.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('INSERT INTO users (full_name, email, username, password_hash, role) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$fullName, $email, $username, $hash, $role]);
                $newUserId = (int) $pdo->lastInsertId();
                audit_log($pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'User Creation', 'user', $newUserId, $username . ' (' . $roleLabels[$role] . ')');
                $success = 'Account created for ' . $username . '.';
            }
        }
    } elseif ($action === 'toggle_active') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        if ($targetId === (int) $staff['id']) {
            $error = 'You cannot deactivate your own account.';
        } else {
            $target = $pdo->prepare('SELECT username, is_active FROM users WHERE id = ?');
            $target->execute([$targetId]);
            $target = $target->fetch();
            $pdo->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?')->execute([$targetId]);
            if ($target) {
                $newState = $target['is_active'] ? 'Deactivated' : 'Activated';
                audit_log($pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Account ' . $newState, 'user', $targetId, $target['username']);
            }
            $success = 'Account status updated.';
        }
    } elseif ($action === 'change_role') {
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $newRole = (string) ($_POST['role'] ?? '');
        if ($targetId === (int) $staff['id']) {
            $error = 'You cannot change your own role.';
        } elseif (!in_array($newRole, $validRoles, true)) {
            $error = 'Select a valid role.';
        } else {
            $target = $pdo->prepare('SELECT username, role FROM users WHERE id = ?');
            $target->execute([$targetId]);
            $target = $target->fetch();
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $targetId]);
            if ($target) {
                audit_log($pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'User Role Change', 'user', $targetId, $target['username'] . ': ' . $roleLabels[$target['role']] . ' → ' . $roleLabels[$newRole]);
            }
            $success = 'Role updated.';
        }
    }
}

$roleFilter = (string) ($_GET['role'] ?? 'ALL');
$q = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT id, full_name, email, username, mobile, role, is_active, created_at FROM users WHERE 1=1';
$params = [];
if ($roleFilter !== 'ALL' && in_array($roleFilter, $validRoles, true)) {
    $sql .= ' AND role = ?';
    $params[] = $roleFilter;
}
if ($q !== '') {
    $sql .= ' AND (username LIKE ? OR full_name LIKE ? OR email LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
}
$sql .= ' ORDER BY created_at DESC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$pageTitle = 'Manage Users';
require __DIR__ . '/../includes/header.php';
?>
<div class="portal-container">
  <a href="<?= base_url('admin/dashboard.php') ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">Manage Users</h1>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <?php if ($error): ?><div class="auth-error mb-3"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none"><?= e($success) ?></div><?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-5">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Create Account</div></div>
        <form method="post" class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <input class="form-control" type="text" name="fullName" placeholder="Full name" required>
          <input class="form-control" type="email" name="email" placeholder="Email address" required>
          <input class="form-control" type="text" name="username" placeholder="Username" required>
          <input class="form-control" type="password" name="password" placeholder="Password" minlength="6" required>
          <select name="role" class="form-select" required>
            <option value="">Select role</option>
            <?php foreach ($roleLabels as $key => $label): ?>
              <option value="<?= e($key) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn-navy">Create Account</button>
        </form>
      </div>
    </div>

    <div class="col-12 col-lg-7">
      <form method="get" class="d-flex flex-wrap gap-2 mb-3">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search name, username, or email" class="form-control" style="max-width:260px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
        <select name="role" class="form-select" style="max-width:200px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
          <option value="ALL" <?= $roleFilter === 'ALL' ? 'selected' : '' ?>>All roles</option>
          <?php foreach ($roleLabels as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $roleFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-navy">Filter</button>
      </form>

      <?php if (!$users): ?>
        <div style="font-size:14px;color:var(--muted)">No accounts match this filter.</div>
      <?php endif; ?>

      <?php foreach ($users as $u): ?>
        <div class="app-card" style="align-items:flex-start">
          <div>
            <div class="app-ref"><?= e($u['full_name']) ?> <span style="color:var(--faint);font-weight:400">@<?= e($u['username']) ?></span></div>
            <div class="app-date"><?= e($u['email']) ?><?php if ($u['mobile']): ?> · <?= e($u['mobile']) ?><?php endif; ?></div>
            <div class="app-date">Created <?= e(date('M j, Y', strtotime($u['created_at']))) ?></div>
          </div>
          <div class="d-flex flex-column align-items-end gap-2">
            <span class="status-pill" style="background:<?= $u['is_active'] ? '#dcf3e2' : '#fde8e8' ?>;color:<?= $u['is_active'] ? '#1c7a3f' : '#a51c1c' ?>"><?= $u['is_active'] ? 'Active' : 'Deactivated' ?></span>
            <?php if ((int) $u['id'] === (int) $staff['id']): ?>
              <span style="font-size:12px;color:var(--faint)">This is you</span>
            <?php else: ?>
              <form method="post" class="d-flex gap-2 align-items-center flex-wrap m-0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="change_role">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <select name="role" onchange="this.form.submit()" class="form-select" style="padding:6px 10px;font-size:12px">
                  <?php foreach ($roleLabels as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $u['role'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
              <form method="post" class="m-0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_active">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <button type="submit" class="btn-outline" style="padding:6px 12px;font-size:12px"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php
$footerVariant = 'portal';
require __DIR__ . '/../includes/footer.php';
