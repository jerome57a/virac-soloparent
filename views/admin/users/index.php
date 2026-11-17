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
$userIcon = require APP_ROOT . '/includes/user-management-icons.php';
$createRole = isset($roleLabels[$form['role'] ?? '']) ? $form['role'] : '';
$isApplicant = $createRole === 'applicant';
?>
<div class="portal-container user-management">
  <?php require APP_ROOT . '/views/partials/admin-page-heading.php'; ?>

  <?php if ($error && ($_POST['action'] ?? '') !== 'create'): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none" role="status"><?= e($success) ?></div><?php endif; ?>

  <div class="row g-4 admin-editor-layout">
    <div class="col-12 col-lg-8" id="admin-user-list">
      <div class="info-box" style="max-width:none">
        <p class="mb-2" data-i18n>Deactivate blocks sign-in and keeps the account's records. Activate restores access.</p>
        <p class="mb-0" data-i18n>Delete permanently removes an account. Accounts with applications or interview scheduling records must be deactivated so their history is preserved.</p>
      </div>
      <form method="get" class="record-filters mb-3" role="search" aria-label="Filter accounts">
        <label class="admin-filter-field"><span data-i18n>Search</span><input type="text" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="Name, username, email or mobile" aria-label="Search accounts" style="<?= $field ?>;flex:2 1 220px"></label>
        <label class="admin-filter-field"><span data-i18n>Role</span><select name="role" aria-label="Role" style="<?= $field ?>">
          <option value="">All roles</option>
          <?php foreach ($roleLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= $filters['role'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select></label>
        <label class="admin-filter-field"><span data-i18n>Status</span><select name="status" aria-label="Account status" style="<?= $field ?>">
          <option value="">Any status</option>
          <?php foreach ($statusFilters as $key => $label): ?><option value="<?= e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select></label>
        <button type="submit" class="btn-navy user-action"><?= $userIcon('filter') ?> Apply Filters</button>
        <?php if ($filters['q'] !== '' || $filters['role'] !== '' || $filters['status'] !== ''): ?><a href="<?= e(base_url('admin/users.php')) ?>" class="btn-outline user-action" data-i18n><?= $userIcon('clear') ?> Clear</a><?php endif; ?>
      </form>
      <div class="user-list-summary" role="status">
        <span><?php if ($users): ?>Showing <?= (int) $pager['offset'] + 1 ?>&ndash;<?= (int) $pager['offset'] + count($users) ?> of <?= (int) $pager['total'] ?> accounts<?php else: ?>0 accounts<?php endif; ?></span>
        <span><?= UserManagementService::PER_PAGE ?> per page</span>
      </div>

      <?php if (!$users): ?>
        <div class="empty-state compact">No accounts match this filter. Try another name, role or account status.</div>
      <?php endif; ?>

      <?php if ($users): ?>
      <div class="user-list-scroll" role="region" aria-label="User accounts" tabindex="0">
        <table class="user-list-table">
          <caption class="visually-hidden">User accounts with role, status and account actions</caption>
          <thead><tr><th scope="col" data-i18n>Account</th><th scope="col">Role</th><th scope="col" data-i18n>Status</th><th scope="col" class="user-actions-heading" data-i18n>Actions</th></tr></thead>
          <tbody>
      <?php foreach ($users as $u): [$rbg, $rfg] = $roleStyle[$u['role']] ?? ['#eef0f3', '#3a4250']; $isLocked = $u['locked_until'] && strtotime($u['locked_until']) > time(); $hasRecords = (int) $u['application_count'] > 0 || !empty($u['has_staff_records']); ?>
        <tr>
          <td class="user-account-cell">
            <a href="<?= e(base_url('admin/user_edit.php?id=' . (int) $u['id'])) ?>" class="user-account-name"><?= e($u['full_name']) ?><?= (int) $u['id'] === (int) $staff['id'] ? ' <span class="user-self-label">(you)</span>' : '' ?></a>
            <div class="user-account-meta"><?= e($u['username'] ? '@' . $u['username'] : ($u['email'] ?: $u['mobile'])) ?></div>
            <div class="user-account-meta">Last sign-in: <?= $u['last_login_at'] ? e(date('M j, Y', strtotime($u['last_login_at']))) : 'Never' ?></div>
            <?php if ((int) $u['application_count'] > 0): ?>
            <div class="user-account-meta"><span data-i18n>Deletion unavailable: applications on file.</span> (<?= (int) $u['application_count'] ?>)</div>
            <?php elseif (!empty($u['has_staff_records'])): ?>
            <div class="user-account-meta" data-i18n>Deletion unavailable: interview scheduling history.</div>
            <?php endif; ?>
          </td>
          <td class="user-role-cell"><span class="user-mobile-label" aria-hidden="true">Role</span>
            <span class="status-pill" style="background:<?= $rbg ?>;color:<?= $rfg ?>"><?= e($roleLabels[$u['role']] ?? $u['role']) ?></span>
          </td>
          <td class="user-status-cell"><span class="user-mobile-label" aria-hidden="true" data-i18n>Status</span><div class="user-status-badges">
            <span class="status-pill" style="background:<?= $u['is_active'] ? '#dcf3e2' : '#fde8e8' ?>;color:<?= $u['is_active'] ? '#1c7a3f' : '#a51c1c' ?>" data-i18n><?= $u['is_active'] ? 'Active' : 'Deactivated' ?></span>
            <?php if ($isLocked): ?><span class="status-pill" style="background:#fde8e8;color:#a51c1c">Locked out</span><?php endif; ?>
            <?php if ($u['must_change_password']): ?><span class="status-pill" style="background:#fdf1d6;color:#8a6d1a">Temporary password</span><?php endif; ?>
          </div></td>
          <td><div class="user-table-actions">
          <a href="<?= e(base_url('admin/user_edit.php?id=' . (int) $u['id'])) ?>" class="btn-outline user-action" aria-label="Edit account <?= e($u['username'] ?: $u['full_name']) ?>" data-i18n><?= $userIcon('edit') ?> Edit</a>
        <?php if ((int) $u['id'] !== (int) $staff['id']): ?>
          <?php if ($hasRecords): ?>
          <form method="post" class="m-0" onsubmit="return confirm(<?= e(json_encode($u['is_active'] ? 'Deactivate this account? Sign-in will be blocked and all records will be kept.' : 'Activate this account? The user will be able to sign in again.')) ?>);">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $u['is_active'] ? 'deactivate' : 'activate' ?>">
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
            <button type="submit" class="<?= $u['is_active'] ? 'btn-danger-outline' : 'btn-outline user-action-success' ?> user-action" aria-label="<?= e(($u['is_active'] ? 'Deactivate account ' : 'Activate account ') . $u['full_name']) ?>"><?= $userIcon('power') ?> <span data-i18n><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></span></button>
          </form>
          <?php else: ?>
          <form method="post" class="m-0" onsubmit="return confirm(<?= e(json_encode('Permanently delete the account ' . ($u['username'] ?: $u['full_name']) . '? This cannot be undone.')) ?>);">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
            <button type="submit" class="btn-danger-outline user-action" aria-label="Delete account <?= e($u['username'] ?: $u['full_name']) ?>" title="Delete account <?= e($u['username'] ?: $u['full_name']) ?>"><?= $userIcon('delete') ?> <span data-i18n>Delete</span></button>
          </form>
          <?php endif; ?>
        <?php endif; ?>
          </div></td>
        </tr>
      <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
      <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
    </div>

    <div class="col-12 col-lg-4" id="admin-user-create">
      <div class="review-card">
        <div class="review-card-head"><div class="title" data-i18n>Create Account</div></div>
        <?php if ($error && ($_POST['action'] ?? '') === 'create'): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="d-flex flex-column gap-2" data-role-account-form>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <label class="user-label"><span data-i18n>Role</span>
            <select name="role" class="form-select" required>
              <option value="" data-i18n>Select role</option>
              <?php foreach ($roleLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= $createRole === $key ? 'selected' : '' ?> data-i18n><?= e($label) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label class="user-label"><span data-i18n>Full name</span><input class="form-control" type="text" name="fullName" value="<?= e($form['fullName'] ?? '') ?>" maxlength="150" autocomplete="name" required></label>
          <div data-applicant-fields <?= !$isApplicant ? 'hidden' : '' ?>>
            <label class="user-label"><span data-i18n>Mobile number</span><input class="form-control" type="tel" name="mobile" value="<?= e($form['mobile'] ?? '') ?>" placeholder="09XXXXXXXXX" maxlength="20" autocomplete="tel" <?= $isApplicant ? 'required' : 'disabled' ?>></label>
            <label class="user-label"><span data-i18n>Gmail address (optional)</span><input class="form-control" type="email" name="email" value="<?= e($form['email'] ?? '') ?>" placeholder="yourname@gmail.com" maxlength="150" autocomplete="email" pattern="[^@\s]+@[gG][mM][aA][iI][lL]\.[cC][oO][mM]" title="Enter a Gmail address ending in @gmail.com, or leave this blank." data-i18n-attrs="title" aria-describedby="create-applicant-help" <?= $isApplicant ? '' : 'disabled' ?>></label>
            <div id="create-applicant-help" style="font-size:12px;color:var(--faint)" data-i18n>Mobile number and Gmail address (if provided) are contact details for SMS and password recovery. Solo Parents sign in with their username.</div>
          </div>
          <div data-account-username <?= $createRole === '' ? 'hidden' : '' ?>>
          <label class="user-label"><span data-i18n>Username</span><input class="form-control" type="text" name="username" value="<?= e($form['username'] ?? '') ?>"
            pattern="<?= $isApplicant ? e(UserManagementService::APPLICANT_USERNAME_PATTERN_HTML) : '[A-Za-z0-9._\x2D]{3,50}' ?>" maxlength="<?= $isApplicant ? 30 : 50 ?>" autocomplete="off" aria-describedby="create-username-help"
            data-pattern-applicant="<?= e(UserManagementService::APPLICANT_USERNAME_PATTERN_HTML) ?>" data-max-applicant="30"
            data-pattern-personnel="[A-Za-z0-9._\x2D]{3,50}" data-max-personnel="50"
            <?= $createRole === '' ? 'disabled' : 'required' ?>></label>
          <div id="create-username-help" style="font-size:12px;color:var(--faint)">
            <span data-username-help="applicant" <?= $isApplicant ? '' : 'hidden' ?> data-i18n>3 to 30 characters: letters, numbers, dots, hyphens or underscores, with at least one letter. The Solo Parent signs in with it and cannot change it later.</span>
            <span data-username-help="personnel" <?= $isApplicant ? 'hidden' : '' ?> data-i18n>3 to 50 characters: letters, numbers, dots, dashes or underscores.</span>
          </div>
          </div>
          <label class="user-label"><span data-i18n>Initial password</span><input class="form-control" type="password" name="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" aria-describedby="create-password-help" required></label>
          <div id="create-password-help" style="font-size:12px;color:var(--faint)" data-i18n>At least <?= PASSWORD_MIN_LENGTH ?> characters, with at least one letter and one number.</div>
          <label class="user-label"><span data-i18n>Confirm password</span><input class="form-control" type="password" name="password_confirm" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required></label>
          <div data-personnel-fields <?= $isApplicant || $createRole === '' ? 'hidden' : '' ?>>
            <label style="font-size:13px;display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="mustChange" value="1" <?= !$form || !empty($form['mustChange']) ? 'checked' : '' ?> <?= $isApplicant || $createRole === '' ? 'disabled' : '' ?> style="margin-top:3px"><span data-i18n>Require a new password at first sign-in</span></label>
          </div>
          <button type="submit" class="btn-navy user-action user-action-create"><?= $userIcon('add') ?> <span data-i18n>Create Account</span></button>
        </form>
      </div>
    </div>
  </div>
</div>
