<?php
/**
 * @var array  $staff
 * @var string $ownDashboard
 * @var array  $filters     q, barangay, status, type, account, sort (validated)
 * @var array  $barangays
 * @var array  $statuses
 * @var array  $applicants  each with app_count, last_activity, profile_barangay and up to 3 'applications'
 * @var array  $pager
 */
$active = array_filter(array_diff_key($filters, ['sort' => 1]), fn($v) => $v !== '');
$field = 'padding:9px;border:1px solid var(--input-border);border-radius:6px;font-size:14px';
?>
<div class="portal-container">
<?php if (($staff['role'] ?? '') === 'admin'): ?>
  <?php require APP_ROOT . '/views/partials/admin-page-heading.php'; ?>

<?php else: ?>
  <?php require APP_ROOT . '/views/partials/staff-page-heading.php'; ?>

<?php endif; ?>

  <form method="get" class="record-filters mb-3" id="admin-record-filters" role="search" aria-label="Search applicant records">
    <label class="admin-filter-field"><span data-i18n>Search</span><input type="text" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="Name, reference no., username, email or mobile" aria-label="Name, reference number, username, email or mobile" style="<?= $field ?>;flex:2 1 260px"></label>
    <label class="admin-filter-field"><span data-i18n>Barangay</span><select name="barangay" aria-label="Barangay" style="<?= $field ?>" data-i18n-attrs="aria-label">
      <option value="">All barangays</option>
      <?php foreach ($barangays as $b): ?><option value="<?= e($b) ?>" <?= $filters['barangay'] === $b ? 'selected' : '' ?>><?= e($b) ?></option><?php endforeach; ?>
    </select></label>
    <label class="admin-filter-field"><span data-i18n>Status</span><select name="status" aria-label="Application status" style="<?= $field ?>" data-i18n-attrs="aria-label">
      <option value="">Any status</option>
      <?php foreach ($statuses as $s): ?><option value="<?= e($s) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
      <option value="NONE" <?= $filters['status'] === 'NONE' ? 'selected' : '' ?>>No application yet</option>
    </select></label>
    <label class="admin-filter-field"><span data-i18n>Application type</span><select name="type" aria-label="Application type" style="<?= $field ?>" data-i18n-attrs="aria-label">
      <option value="">New &amp; renewal</option>
      <?php foreach (RecordService::TYPES as $k => $label): ?><option value="<?= $k ?>" <?= $filters['type'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
    <label class="admin-filter-field"><span data-i18n>Account status</span><select name="account" aria-label="Account status" style="<?= $field ?>">
      <option value="">All accounts</option>
      <?php foreach (RecordService::ACCOUNT_STATES as $k => $label): ?><option value="<?= $k ?>" <?= $filters['account'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
    <label class="admin-filter-field"><span data-i18n>Sort by</span><select name="sort" aria-label="Sort by" style="<?= $field ?>">
      <?php foreach (RecordService::SORTS as $k => $label): ?><option value="<?= $k ?>" <?= $filters['sort'] === $k ? 'selected' : '' ?>>Sort: <?= e($label) ?></option><?php endforeach; ?>
    </select></label>
    <button type="submit" class="btn-navy" data-i18n>Search</button>
    <?php if ($active): ?><a href="<?= e(base_url(role_portal_path($staff, 'applicants.php'))) ?>" class="btn-outline text-decoration-none" data-i18n>Clear</a><?php endif; ?>
  </form>

  <div id="admin-record-list" style="font-size:13px;color:var(--muted);margin-bottom:12px" role="status">
    <?= (int) $pager['total'] ?> applicant record<?= (int) $pager['total'] === 1 ? '' : 's' ?><?= $active ? ' match these filters' : '' ?>.
    <?php if ($filters['status'] !== '' && $filters['status'] !== 'NONE' || $filters['type'] !== ''): ?>Applications shown are the ones that match.<?php endif; ?>
  </div>

  <?php if (!$applicants): ?>
    <div class="empty-state">No applicant records match this search.</div>
  <?php endif; ?>

  <?php foreach ($applicants as $a): ?>
    <a href="<?= e(base_url(role_portal_path($staff, 'applicant_view.php') . '?id=' . (int) $a['id'])) ?>" class="app-card text-decoration-none" style="color:inherit;align-items:flex-start">
      <div style="min-width:0">
        <div class="app-ref"><?= e($a['full_name']) ?> <span style="color:var(--faint);font-weight:400">@<?= e($a['username']) ?></span></div>
        <div class="app-date"><?= e($a['email']) ?><?php if ($a['mobile']): ?> · <?= e($a['mobile']) ?><?php if (SMSService::confirmedNumber($a['mobile'], $a['mobile_verified_at']) === null): ?> (not confirmed)<?php endif; ?><?php endif; ?><?php if ($a['profile_barangay']): ?> · Brgy. <?= e($a['profile_barangay']) ?><?php endif; ?></div>
        <div class="app-date"><?= (int) $a['app_count'] ?> application<?= (int) $a['app_count'] === 1 ? '' : 's' ?><?php if ($a['last_activity']): ?> · latest submitted <?= e(date('M j, Y', strtotime($a['last_activity']))) ?><?php endif; ?></div>
        <?php if ($a['applications']): ?>
          <div class="d-flex flex-wrap gap-2 mt-2">
            <?php foreach ($a['applications'] as $app): $st = status_style($app['status']); ?>
              <span class="record-chip" data-i18n><b><?= e($app['reference_no']) ?></b> · <?= $app['type'] === 'renewal' ? 'Renewal' : 'New' ?> · <span class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>;font-size:11px;padding:1px 7px"><?= e($app['status']) ?></span></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <span class="status-pill" style="background:<?= $a['is_active'] ? '#dcf3e2' : '#fde8e8' ?>;color:<?= $a['is_active'] ? '#1c7a3f' : '#a51c1c' ?>" data-i18n><?= $a['is_active'] ? 'Active' : 'Deactivated' ?></span>
      <span class="admin-record-open staff-open-record"><span data-i18n>Open record</span><span aria-hidden="true">&rarr;</span></span>
    </a>
  <?php endforeach; ?>

<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
</div>
