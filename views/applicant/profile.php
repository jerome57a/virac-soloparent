<?php
/**
 * @var array  $user
 * @var string $profileError
 * @var string $profileSuccess
 */
?>
      <?php if ($profileError): ?><div class="auth-error mb-3"><?= e($profileError) ?></div><?php endif; ?>
      <?php if ($profileSuccess): ?><div class="info-box" style="max-width:none"><?= e($profileSuccess) ?></div><?php endif; ?>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Profile Photo</div></div>
        <div class="d-flex align-items-center gap-3 flex-wrap">
          <?php if ($user['profile_photo']): ?>
            <img src="<?= base_url('avatar.php?user_id=' . (int) $user['id']) ?>" alt="Profile photo" style="width:72px;height:72px;border-radius:50%;object-fit:cover">
          <?php else: ?>
            <div class="dash-user-avatar" style="width:72px;height:72px;font-size:28px;position:static"><?= e(mb_strtoupper(mb_substr(trim($user['full_name']), 0, 1))) ?></div>
          <?php endif; ?>
          <form method="post" enctype="multipart/form-data" class="d-flex align-items-center gap-2 flex-wrap m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_photo">
            <label class="btn-doc-choose">
              Choose Photo
              <input type="file" name="photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png" style="display:none" onchange="this.form.submit()">
            </label>
          </form>
        </div>
        <div style="font-size:12px;color:var(--faint);margin-top:10px">JPG or PNG, max 2MB.</div>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Personal Information</div></div>
        <form method="post" class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_info">
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            Full name
            <input class="form-control mt-1" type="text" name="full_name" value="<?= e($user['full_name']) ?>" required>
          </label>
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            Email address
            <input class="form-control mt-1" type="email" name="email" value="<?= e($user['email']) ?>" required>
          </label>
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            Mobile number
            <input class="form-control mt-1" type="text" name="mobile" value="<?= e($user['mobile']) ?>" placeholder="09XXXXXXXXX" required>
          </label>
          <div style="font-size:12px;color:var(--faint)">Username: <b><?= e($user['username']) ?></b> (cannot be changed)</div>
          <button type="submit" class="btn-navy" style="align-self:flex-start">Save Changes</button>
        </form>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Change Password</div></div>
        <form method="post" class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <input class="form-control" type="password" name="current_password" placeholder="Current password" required>
          <input class="form-control" type="password" name="new_password" placeholder="New password (min. 6 characters)" minlength="6" required>
          <button type="submit" class="btn-outline" style="align-self:flex-start">Update Password</button>
        </form>
      </div>
