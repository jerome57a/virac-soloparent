<?php
/**
 * @var array  $staff
 * @var string $ownDashboard
 * @var string $q
 * @var string $barangayFilter
 * @var array  $applicants  each with app_count and last_submitted
 * @var array  $pager
 */
?>
<div class="portal-container">
  <a href="<?= e($ownDashboard) ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">Applicant Records</h1>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <form method="get" class="d-flex flex-wrap gap-2 mb-4">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search name, username, email, or mobile" class="form-control" style="max-width:320px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
    <input type="text" name="barangay" value="<?= e($barangayFilter) ?>" placeholder="Barangay" class="form-control" style="max-width:160px;padding:10px;border:1px solid var(--input-border);border-radius:6px">
    <button type="submit" class="btn-navy">Search</button>
  </form>

  <?php if (!$applicants): ?>
    <div style="font-size:14px;color:var(--muted)">No applicant accounts match this search.</div>
  <?php endif; ?>

  <?php foreach ($applicants as $a): ?>
    <a href="<?= base_url('staff/applicant_view.php?id=' . (int) $a['id']) ?>" class="app-card text-decoration-none" style="color:inherit">
      <div>
        <div class="app-ref"><?= e($a['full_name']) ?> <span style="color:var(--faint);font-weight:400">@<?= e($a['username']) ?></span></div>
        <div class="app-date"><?= e($a['email']) ?><?php if ($a['mobile']): ?> · <?= e($a['mobile']) ?><?php endif; ?></div>
        <div class="app-date"><?= (int) $a['app_count'] ?> application<?= (int) $a['app_count'] === 1 ? '' : 's' ?><?php if ($a['last_submitted']): ?> · last submitted <?= e(date('M j, Y', strtotime($a['last_submitted']))) ?><?php endif; ?></div>
      </div>
      <span class="status-pill" style="background:<?= $a['is_active'] ? '#dcf3e2' : '#fde8e8' ?>;color:<?= $a['is_active'] ? '#1c7a3f' : '#a51c1c' ?>"><?= $a['is_active'] ? 'Active' : 'Deactivated' ?></span>
    </a>
  <?php endforeach; ?>

<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
</div>
