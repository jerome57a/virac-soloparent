<?php
/**
 * @var array $applicant
 * @var array $newApps
 * @var array $renewals
 * @var array $interviewsByApp
 * @var array $docCountsByApp
 * @var array $latestDecisionByApp
 */
?>
<div class="portal-container">
  <a href="<?= base_url('staff/applicants.php') ?>" style="font-size:13px">&larr; Back to Applicant Records</a>

  <div class="review-card mt-3">
    <div class="review-card-head"><div class="title">Profile</div></div>
    <div class="d-flex align-items-center gap-3 mb-3">
      <?php if ($applicant['profile_photo']): ?>
        <img src="<?= base_url('avatar.php?user_id=' . (int) $applicant['id']) ?>" alt="" style="width:56px;height:56px;border-radius:50%;object-fit:cover">
      <?php else: ?>
        <div class="dash-user-avatar" style="width:56px;height:56px;font-size:22px"><?= e(mb_strtoupper(mb_substr(trim($applicant['full_name']), 0, 1))) ?></div>
      <?php endif; ?>
      <div>
        <div style="font-weight:700;font-size:16px;color:var(--ink)"><?= e($applicant['full_name']) ?></div>
        <div style="font-size:13px;color:var(--muted)">@<?= e($applicant['username']) ?></div>
      </div>
    </div>
    <div class="review-grid">
      <div class="review-row"><div class="k">Email</div><div class="v"><?= e($applicant['email']) ?></div></div>
      <div class="review-row"><div class="k">Mobile</div><div class="v"><?= e($applicant['mobile'] ?: '—') ?></div></div>
      <div class="review-row"><div class="k">Account status</div><div class="v"><span class="status-pill" style="background:<?= $applicant['is_active'] ? '#dcf3e2' : '#fde8e8' ?>;color:<?= $applicant['is_active'] ? '#1c7a3f' : '#a51c1c' ?>"><?= $applicant['is_active'] ? 'Active' : 'Deactivated' ?></span></div></div>
      <div class="review-row"><div class="k">Registered</div><div class="v"><?= e(date('F j, Y', strtotime($applicant['created_at']))) ?></div></div>
    </div>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Applications (<?= count($newApps) ?>)</div></div>
    <?php if (!$newApps): ?>
      <div style="font-size:13px;color:var(--muted)">No new applications on file.</div>
    <?php endif; ?>
    <?php foreach ($newApps as $app) { require __DIR__ . '/application-card.php'; } ?>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Renewal History (<?= count($renewals) ?>)</div></div>
    <?php if (!$renewals): ?>
      <div style="font-size:13px;color:var(--muted)">No renewal requests on file.</div>
    <?php endif; ?>
    <?php foreach ($renewals as $app) { require __DIR__ . '/application-card.php'; } ?>
  </div>
</div>
