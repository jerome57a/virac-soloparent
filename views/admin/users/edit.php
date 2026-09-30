<?php
/**
 * @var string $error
 * @var string $success
 * @var array  $target  the account being edited
 * @var array  $roleLabels
 */
?>
<div class="portal-container" style="max-width:560px">
  <a href="<?= base_url('admin/users.php') ?>" style="font-size:13px">&larr; Back to Manage Users</a>
  <h1 class="font-heading mt-2 mb-4" style="font-size:26px">Edit Account</h1>

  <?php if ($error): ?><div class="auth-error mb-3"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none"><?= e($success) ?></div><?php endif; ?>

  <div class="review-card">
    <div class="review-card-head"><div class="title"><?= e($roleLabels[$target['role']] ?? $target['role']) ?></div></div>
    <form method="post" class="d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <label style="font-size:13px;font-weight:600;color:var(--ink)">
        Full name
        <input class="form-control mt-1" type="text" name="full_name" value="<?= e($target['full_name']) ?>" required>
      </label>
      <label style="font-size:13px;font-weight:600;color:var(--ink)">
        Email address
        <input class="form-control mt-1" type="email" name="email" value="<?= e($target['email']) ?>" required>
      </label>
      <label style="font-size:13px;font-weight:600;color:var(--ink)">
        Mobile number
        <input class="form-control mt-1" type="text" name="mobile" value="<?= e($target['mobile']) ?>" placeholder="09XXXXXXXXX">
      </label>
      <div style="font-size:12px;color:var(--faint)">Username: <b><?= e($target['username']) ?></b> (cannot be changed). Use Reset Access on Manage Users to reset the password.</div>
      <button type="submit" class="btn-navy" style="align-self:flex-start">Save Changes</button>
    </form>
  </div>

  <a href="<?= base_url('admin/audit_log.php?q=' . urlencode($target['full_name'])) ?>" class="btn-outline text-decoration-none">View Account Activity</a>
</div>
