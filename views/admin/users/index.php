<?php
/**
 * @var array  $staff  the signed-in admin
 * @var string $error
 * @var string $success
 * @var array  $form           create-form values to redisplay after an error
 * @var array  $filters        role, status, q
 * @var array  $roleLabels
 * @var array  $statusFilters
 * @var array  $users          one page of accounts
 * @var array  $pager
 */
$field = 'padding:9px;border:1px solid var(--input-border);border-radius:6px;font-size:14px';
$roleStyle = ['admin' => ['#1b3a6b', '#fff'], 'mswd_social_worker' => ['#dce8f5', '#1b3a6b'], 'applicant' => ['#eef0f3', '#3a4250']];
?>
<div class="portal-container">
  <a href="<?= base_url('admin/dashboard.php') ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">Manage Users</h1>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <?php if ($error): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none" role="status"><?= e($success) ?></div><?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-4">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Create Account</div></div>
        <form method="post" class="d-flex flex-column gap-2">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <label class="user-label">Full name<input class="form-control" type="text" name="fullName" value="<?= e($form['fullName'] ?? '') ?>" maxlength="150" required></label>
          <label class="user-label">Email address<input class="form-control" type="email" name="email" value="<?= e($form['email'] ?? '') ?>" maxlength="150" required></label>
          <label class="user-label">Mobile number <span class="opt">(optional)</span><input class="form-control" type="text" name="mobile" value="<?= e($form['mobile'] ?? '') ?>" placeholder="09XXXXXXXXX" maxlength="20"></label>
          <label class="user-label">Username<input class="form-control" type="text" name="username" value="<?= e($form['username'] ?? '') ?>" pattern="[A-Za-z0-9._\-]{3,50}" maxlength="50" autocomplete="off" required></label>
          <label class="user-label">Initial password<input class="form-control" type="password" name="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" placeholder="Min. <?= PASSWORD_MIN_LENGTH ?>, letters + numbers" required></label>
          <label class="user-label">Role
            <select name="role" class="form-select" required>
              <option value="">Select role</option>
              <?php foreach ($roleLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= ($form['role'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label style="font-size:13px;display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="mustChange" value="1" <?= !$form || !empty($form['mustChange']) ? 'checked' : '' ?> style="margin-top:3px"> Require a new password at first sign-in</label>
          <button type="submit" class="btn-navy">Create Account</button>
        </form>
      </div>
    </div>

    <div class="col-12 col-lg-8">
      <form method="get" class="record-filters mb-3" role="search" aria-label="Filter accounts">
        <input type="text" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="Name, username, email or mobile" aria-label="Search accounts" style="<?= $field ?>;flex:2 1 220px">
        <select name="role" aria-label="Role" style="<?= $field ?>">
          <option value="">All roles</option>
          <?php foreach ($roleLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= $filters['role'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <select name="status" aria-label="Account status" style="<?= $field ?>">
          <option value="">Any status</option>
          <?php foreach ($statusFilters as $key => $label): ?><option value="<?= e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn-navy">Filter</button>
        <?php if ($filters['q'] !== '' || $filters['role'] !== '' || $filters['status'] !== ''): ?><a href="<?= e(base_url('admin/users.php')) ?>" class="btn-outline text-decoration-none">Clear</a><?php endif; ?>
      </form>
      <div style="font-size:13px;color:var(--muted);margin-bottom:10px" role="status"><?= (int) $pager['total'] ?> account<?= (int) $pager['total'] === 1 ? '' : 's' ?></div>

      <?php if (!$users): ?>
        <div style="font-size:14px;color:var(--muted)">No accounts match this filter.</div>
      <?php endif; ?>

      <?php foreach ($users as $u): [$rbg, $rfg] = $roleStyle[$u['role']] ?? ['#eef0f3', '#3a4250']; $isLocked = $u['locked_until'] && strtotime($u['locked_until']) > time(); ?>
        <a href="<?= e(base_url('admin/user_edit.php?id=' . (int) $u['id'])) ?>" class="app-card text-decoration-none" style="color:inherit;align-items:flex-start">
          <div style="min-width:0">
            <div class="app-ref"><?= e($u['full_name']) ?> <span style="color:var(--faint);font-weight:400">@<?= e($u['username']) ?></span><?= (int) $u['id'] === (int) $staff['id'] ? ' <span style="font-size:12px;color:var(--faint);font-weight:400">(you)</span>' : '' ?></div>
            <div class="app-date"><?= e($u['email']) ?><?php if ($u['mobile']): ?> · <?= e($u['mobile']) ?><?php endif; ?></div>
            <div class="app-date">Created <?= e(date('M j, Y', strtotime($u['created_at']))) ?> · Last sign-in <?= $u['last_login_at'] ? e(date('M j, Y g:i A', strtotime($u['last_login_at']))) : 'never' ?></div>
          </div>
          <div class="d-flex flex-column align-items-end gap-1" style="white-space:nowrap">
            <span class="status-pill" style="background:<?= $rbg ?>;color:<?= $rfg ?>"><?= e($roleLabels[$u['role']] ?? $u['role']) ?></span>
            <span class="status-pill" style="background:<?= $u['is_active'] ? '#dcf3e2' : '#fde8e8' ?>;color:<?= $u['is_active'] ? '#1c7a3f' : '#a51c1c' ?>"><?= $u['is_active'] ? 'Active' : 'Deactivated' ?></span>
            <?php if ($isLocked): ?><span class="status-pill" style="background:#fde8e8;color:#a51c1c">Locked out</span><?php endif; ?>
            <?php if ($u['must_change_password']): ?><span class="status-pill" style="background:#fdf1d6;color:#8a6d1a">Temporary password</span><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
      <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
    </div>
  </div>
</div>
