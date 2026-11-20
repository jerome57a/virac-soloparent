<?php
/**
 * The applicant's own profile. Only the fields in the form are editable;
 * username and account status are shown read-only.
 *
 * @var array   $user
 * @var string  $profileError
 * @var string  $profileSuccess
 * @var array   $form               full_name, email, mobile, birthdate, sex, civil_status, barangay, address
 * @var ?string $profileUpdatedAt
 * @var array   $civilStatusOptions
 */
$missing = array_keys(array_filter(['birthdate' => 1, 'sex' => 1, 'civil_status' => 1, 'barangay' => 1, 'address' => 1], fn($_, $k) => $form[$k] === '', ARRAY_FILTER_USE_BOTH));
?>
<link rel="stylesheet" href="<?= public_asset('css/applicant-account.css') ?>">
<div class="profile-page">
      <?php
      // The password and username forms show their own result beside the form (they sit far down the page); everything else shows it here.
      $profileAction = $profileAction ?? '';
      $ownResult = in_array($profileAction, ['change_password', 'set_username'], true);
      ?>
      <?php if ($profileError && !$ownResult): ?><div class="auth-error mb-3" role="alert" data-i18n><?= e($profileError) ?></div><?php endif; ?>
      <?php if ($profileSuccess && $profileAction !== 'change_password'): ?><div class="info-box profile-completion-note" role="status" data-i18n><?= e($profileSuccess) ?></div><?php endif; ?>

      <?php if (empty($user['username']) || !empty($user['must_change_password'])): ?>
        <div class="auth-error profile-required" role="alert">
          <strong data-i18n>Action required</strong>
          <ul>
            <?php if (empty($user['username'])): ?><li><a href="#username" data-i18n>Choose your username</a></li><?php endif; ?>
            <?php if (!empty($user['must_change_password'])): ?><li><a href="#password" data-i18n>Choose a new password</a></li><?php endif; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($missing): ?>
        <?php $missingLabels = ['birthdate' => 'Birthdate', 'sex' => 'Sex', 'civil_status' => 'Civil status', 'barangay' => 'Barangay', 'address' => 'Complete address']; ?>
        <div class="info-box profile-missing">
          <strong data-i18n>Complete your profile</strong>
          <span data-i18n>These details are filled into your application form for you:</span>
          <ul class="profile-chips"><?php foreach ($missing as $k): ?><li data-i18n><?= e($missingLabels[$k] ?? $k) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

  <div class="profile-workspace">
    <aside class="profile-sidebar" aria-label="Profile photo and account details">
      <div class="profile-identity">
        <?php if ($user['profile_photo']): ?>
          <img class="profile-avatar" src="<?= base_url('avatar.php?user_id=' . (int) $user['id']) ?>" alt="Your profile photo">
        <?php else: ?>
          <div class="profile-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(trim($user['full_name']), 0, 1))) ?></div>
        <?php endif; ?>
        <div class="account-eyebrow" data-i18n>SOLO PARENT PROFILE</div><h3><?= e($user['full_name']) ?></h3>
        <span data-i18n>Solo Parent</span>
        <?php if (!empty($user['username'])): ?><span class="profile-handle"><?= e($user['username']) ?></span><?php endif; ?>
      </div>
      <div class="review-card">
        <div class="review-card-head"><div class="title" data-i18n>Profile Photo</div></div>
        <div class="profile-photo-row">
          <form method="post" enctype="multipart/form-data" class="profile-photo-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload_photo">
            <label class="btn-doc-choose" data-i18n>
              <?= $user['profile_photo'] ? 'Change Photo' : 'Choose Photo' ?>
              <input type="file" name="photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png" class="visually-hidden" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
            </label>
          </form>
          <?php if ($user['profile_photo']): ?>
            <form method="post" class="m-0" onsubmit="return confirm('Remove your profile photo?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="remove_photo">
              <button type="submit" class="btn-doc-remove" data-i18n>Remove</button>
            </form>
          <?php endif; ?>
        </div>
        <div style="font-size:12px;color:var(--faint);margin-top:10px" data-i18n>JPG or PNG, max 2MB. Only you and MSWDO staff can see it.</div>
      </div>

      <div class="profile-progress"><div><strong data-i18n>Personal details</strong><span><?= 5 - count($missing) ?> of 5 filled</span></div><progress max="5" value="<?= 5 - count($missing) ?>" aria-label="Personal profile fields filled"></progress><p data-i18n>Saved profile details prefill new applications.</p></div>
      <?php if (empty($user['username'])): ?>
      <div class="review-card" id="username">
        <div class="review-card-head"><div class="title" data-i18n>Choose your username</div></div>
        <div class="auth-error mb-3" role="alert" data-i18n>Your account was created before usernames were introduced. Choose a username now; you will use it, with your password, to sign in from now on. It cannot be changed later.</div>
        <?php if ($profileError && $profileAction === 'set_username'): ?><div class="auth-error mb-3" role="alert" data-i18n><?= e($profileError) ?></div><?php endif; ?>
        <form method="post" action="<?= e(base_url('applicant/dashboard.php?section=profile')) ?>#username" class="profile-password-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="set_username">
          <label class="field-label"><span data-i18n>Username</span><input class="form-control" type="text" name="username" minlength="3" maxlength="30" pattern="[A-Za-z0-9][A-Za-z0-9._\-]*" autocomplete="username" aria-label="Username" placeholder="juan.delacruz" title="3-30 characters: letters, numbers, dots, underscores or hyphens, with at least one letter." required data-i18n-attrs="aria-label placeholder title"></label>
          <button type="submit" class="btn-navy" style="align-self:flex-start" data-i18n>Save Username</button>
        </form>
      </div>
      <?php endif; ?>
      <div class="review-card">
        <div class="review-card-head"><div class="title" data-i18n>Account</div></div>
        <div class="review-grid">
          <?php if (!empty($user['username'])): ?>
          <div class="review-row"><div class="k" data-i18n>Username</div><div class="v"><?= e($user['username']) ?> <span style="color:var(--faint)" data-i18n>(cannot be changed)</span></div></div>
          <?php endif; ?>
          <div class="review-row"><div class="k" data-i18n>Mobile number</div><div class="v"><?= e((string) $user['mobile']) ?></div></div>
          <div class="review-row"><div class="k" data-i18n>Gmail address</div><div class="v"><?= $user['email'] ? e($user['email']) : '<span style="color:var(--faint)" data-i18n>Not provided</span>' ?></div></div>
          <div class="review-row"><div class="k" data-i18n>Account type</div><div class="v" data-i18n>Solo Parent</div></div>
        </div>
      </div>

    </aside>
    <div class="profile-main">
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_info">

        <div class="review-card">
          <div class="review-card-head"><div class="title" data-i18n>Personal Information</div></div>
          <div class="field-grid">
            <label class="field-label field-full">
              <span data-i18n>Full name *</span>
              <input type="text" name="full_name" value="<?= e($form['full_name']) ?>" maxlength="150" autocomplete="name" required>
            </label>
            <label class="field-label">
              <span data-i18n>Birthdate</span>
              <input type="date" name="birthdate" value="<?= e($form['birthdate']) ?>" min="1900-01-01" max="<?= e(today_iso()) ?>">
            </label>
            <label class="field-label">
              <span data-i18n>Sex</span>
              <select name="sex">
                <option value="" data-i18n>Select</option>
                <?php foreach (['Female', 'Male'] as $s): ?>
                  <option value="<?= e($s) ?>" <?= $form['sex'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="field-label field-full">
              <span data-i18n>Civil status</span>
              <select name="civil_status">
                <option value="" data-i18n>Select civil status</option>
                <?php foreach ($civilStatusOptions as $c): ?>
                  <option value="<?= e($c) ?>" <?= $form['civil_status'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
        </div>

        <div class="review-card">
          <div class="review-card-head"><div class="title" data-i18n>Contact &amp; Address</div></div>
          <div class="field-grid">
            <label class="field-label">
              <span data-i18n>Email address (optional)</span>
              <input type="email" name="email" value="<?= e($form['email']) ?>" maxlength="150" autocomplete="email">
            </label>
            <label class="field-label">
              <span data-i18n>Mobile number *</span>
              <input type="tel" name="mobile" value="<?= e($form['mobile']) ?>" placeholder="09XXXXXXXXX" inputmode="tel" autocomplete="tel" required>
            </label>
            <label class="field-label">
              <span data-i18n>Current password</span>
              <input type="password" name="mobile_confirm_password" autocomplete="current-password" placeholder="Only needed to change your mobile number">
            </label>
            <label class="field-label">
              <span data-i18n>Barangay</span>
              <input type="text" name="barangay" value="<?= e($form['barangay']) ?>" placeholder="e.g. Concepcion" maxlength="100" data-i18n-attrs="placeholder">
            </label>
            <label class="field-label">
              <span data-i18n>Complete address</span>
              <input type="text" name="address" value="<?= e($form['address']) ?>" placeholder="House no., street, purok" maxlength="255" autocomplete="street-address" data-i18n-attrs="placeholder">
            </label>
          </div>
          <div style="font-size:12px;color:var(--faint);margin-top:4px" data-i18n>Your mobile number receives SMS updates and password-reset codes. It must be unique to your account; changing it asks for your current password.</div>
        </div>

        <div class="profile-save-row">
          <button type="submit" class="btn-navy" data-i18n>Save Profile</button>
          <?php if ($profileUpdatedAt): ?><span style="font-size:12px;color:var(--faint)">Last updated <?= e(date('M j, Y g:i A', strtotime($profileUpdatedAt))) ?></span><?php endif; ?>
        </div>
      </form>

      <div class="review-card" id="password">
        <div class="review-card-head"><div class="title" data-i18n>Change Password</div></div>
        <?php if (!empty($user['must_change_password'])): ?>
          <div class="auth-error mb-3" role="alert" data-i18n>MSWDO reset your access. Enter the temporary password you were given as your current password, then choose a new one to continue.</div>
        <?php endif; ?>
        <?php if ($profileAction === 'change_password'): ?>
          <?php if ($profileError): ?><div class="auth-error mb-3" role="alert" data-i18n><?= e($profileError) ?></div><?php endif; ?>
          <?php if ($profileSuccess): ?><div class="info-box mb-3" role="status" data-i18n><?= e($profileSuccess) ?></div><?php endif; ?>
        <?php endif; ?>
        <form method="post" action="<?= e(base_url('applicant/dashboard.php?section=profile')) ?>#password" class="profile-password-form">
          <label class="field-label"><span data-i18n>Current password</span><input class="form-control" type="password" name="current_password" aria-label="Current password" placeholder="Current password" autocomplete="current-password" required data-i18n-attrs="aria-label placeholder"></label>
          <label class="field-label"><span data-i18n>New password</span><input class="form-control" type="password" name="new_password" aria-label="New password" placeholder="New password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required data-i18n-attrs="aria-label placeholder"></label>
          <label class="field-label"><span data-i18n>Confirm new password</span><input class="form-control" type="password" name="new_password_confirm" aria-label="Confirm new password" placeholder="Confirm new password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required data-i18n-attrs="aria-label placeholder"></label>
          <div class="profile-match" id="password-match" role="status" aria-live="polite" hidden>
            <span data-match="ok" data-i18n>Passwords match.</span><span data-match="bad" data-i18n>The two passwords do not match.</span>
          </div>
          <div style="font-size:12px;color:var(--faint)" data-i18n>At least <?= PASSWORD_MIN_LENGTH ?> characters, with at least one letter and one number.</div>
          <button type="submit" class="btn-outline" style="align-self:flex-start" data-i18n>Update Password</button>
        </form>
      </div>
    </div>
  </div>
</div>
<script>
// Live check while typing the new password twice; the browser also refuses to submit a mismatch.
(function () {
  var n = document.querySelector('[name=new_password]'), c = document.querySelector('[name=new_password_confirm]'), box = document.getElementById('password-match');
  if (!n || !c || !box) { return; }
  var ok = box.querySelector('[data-match=ok]'), bad = box.querySelector('[data-match=bad]');
  function check() {
    if (c.value === '') { box.hidden = true; c.setCustomValidity(''); return; }
    var same = n.value === c.value;
    box.hidden = false; ok.hidden = !same; bad.hidden = same;
    box.className = 'profile-match ' + (same ? 'is-ok' : 'is-bad');
    c.setCustomValidity(same ? '' : bad.textContent.trim());
  }
  n.addEventListener('input', check); c.addEventListener('input', check);
})();
</script>
