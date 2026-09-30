<?php
/**
 * @var array  $staff  the signed-in admin (can't change their own role/status)
 * @var string $error
 * @var string $success
 * @var string $roleFilter
 * @var string $q
 * @var array  $roleLabels
 * @var array  $users
 */
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
      <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="?role=ALL" class="status-pill text-decoration-none" style="background:<?= $roleFilter === 'ALL' ? 'var(--navy)' : '#eef1f5' ?>;color:<?= $roleFilter === 'ALL' ? '#fff' : 'var(--ink)' ?>">All Accounts</a>
        <a href="?role=admin" class="status-pill text-decoration-none" style="background:<?= $roleFilter === 'admin' ? 'var(--navy)' : '#eef1f5' ?>;color:<?= $roleFilter === 'admin' ? '#fff' : 'var(--ink)' ?>">System Administrators</a>
        <a href="?role=mswd_social_worker" class="status-pill text-decoration-none" style="background:<?= $roleFilter === 'mswd_social_worker' ? 'var(--navy)' : '#eef1f5' ?>;color:<?= $roleFilter === 'mswd_social_worker' ? '#fff' : 'var(--ink)' ?>">MSWD Staff</a>
        <a href="?role=applicant" class="status-pill text-decoration-none" style="background:<?= $roleFilter === 'applicant' ? 'var(--navy)' : '#eef1f5' ?>;color:<?= $roleFilter === 'applicant' ? '#fff' : 'var(--ink)' ?>">Applicant Accounts</a>
      </div>

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
            <div class="d-flex gap-2">
              <a href="<?= base_url('admin/user_edit.php?id=' . (int) $u['id']) ?>" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px">Edit</a>
              <a href="<?= base_url('admin/audit_log.php?q=' . urlencode($u['full_name'])) ?>" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px">Activity</a>
            </div>
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
              <form method="post" class="m-0" onsubmit="return confirm('Reset this account\'s password to a new random one?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <button type="submit" class="btn-outline" style="padding:6px 12px;font-size:12px">Reset Access</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
