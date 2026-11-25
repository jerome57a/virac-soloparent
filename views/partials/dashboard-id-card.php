<?php
/**
 * Solo Parent dashboard: "My Solo Parent ID" card. A preview drawn on the real card template with ONLY the ID number, the category
 * and the valid-until date: no name, address, birthdate, picture or children. "SAMPLE" crosses it, so a screenshot cannot pass
 * as the real ID. Tapping it opens My ID (the full card stays there).
 *
 * @var array      $renewal            ApplicantController::renewalInfo(): state none|in_progress|valid|due|expired|closed (+ daysLeft, expiresAt)
 * @var ?array     $idApplication      the released (or approved) ID application
 * @var ?array     $viewableIdApplication  the one My ID can show
 * @var ?array     $latestApplication
 */
require_once APP_ROOT . '/includes/icons.php';
$released = $idApplication && $idApplication['status'] === 'ID Released' && !empty($idApplication['id_expires_at']);
$state = $renewal['state'];
// Badge: tone and words for each state.
if ($released) {
    [$tone, $badge] = match ($state) {
        'in_progress' => ['info', 'Renewal in progress'], // the old ID stays the current record until the new one is released
        'due' => ['warn', 'Expires today'],
        'expired', 'closed' => ['err', 'Expired'],
        default => ['ok', 'Active'],
    };
} else {
    [$tone, $badge] = ['dis', 'Being prepared'];
}
$pending = $idApplication ?: (!empty($latestApplication) && $latestApplication['status'] !== 'Rejected' ? $latestApplication : null);
$isPast = $released && $idApplication['id_expires_at'] < date('Y-m-d');
$releaseToday = $releaseToday ?? null;
if ($releaseToday) { [$tone, $badge] = ['ok', 'Collect it today']; }
$myIdUrl = $viewableIdApplication ? base_url('applicant/dashboard.php?section=myid&ref=' . urlencode($viewableIdApplication['reference_no'])) : base_url('applicant/dashboard.php?section=myid');
?>
<link rel="stylesheet" href="<?= public_asset('css/id-sample.css') ?>">
<div class="dash-card idc">
  <div class="idc-head">
    <h2 class="dash-card-label dash-home-card-heading" data-i18n><?= ui_icon('id-card', 20) ?>My Solo Parent ID</h2>
    <?php if ($released || $pending || $releaseToday): ?><span class="idc-badge is-<?= e($tone) ?>"><span aria-hidden="true"></span><span data-i18n><?= e($badge) ?></span></span><?php endif; ?>
  </div>

  <?php if ($releaseToday): $counterRef = (string) $releaseToday['reference_no']; $counterRelease = $releaseToday; $counterRenewal = false; require APP_ROOT . '/views/partials/counter-card.php'; ?>
    <?php if (!$released): ?>
      <div class="idc-actions"><a href="?section=application" class="btn-outline text-decoration-none dash-card-link" data-i18n>View Application</a></div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($released): $expires = format_date_long($idApplication['id_expires_at']); ?>
    <a class="idc-sample" href="<?= e($myIdUrl) ?>" aria-label="Sample of your Solo Parent ID <?= e($idApplication['reference_no']) ?>, valid until <?= e($expires) ?>. Open My ID.">
      <img src="<?= asset('images/solo-parent-id/front-preview.jpg') ?>" alt="" width="900" height="514" loading="lazy">
      <span class="idc-noface" aria-hidden="true" data-i18n>No picture on the preview</span>
      <span class="idc-mask" aria-hidden="true"></span>
      <span class="idc-f idc-no" aria-hidden="true" translate="no"><?= e($idApplication['reference_no']) ?></span>
      <span class="idc-f idc-cat" aria-hidden="true"><?= e($idApplication['category_name'] ?? '') ?></span>
      <span class="idc-f idc-exp" aria-hidden="true"><?= e($expires) ?></span>
      <span class="idc-wm" aria-hidden="true" data-decorative><span>SAMPLE</span></span>
    </a>
    <dl class="idc-meta">
      <div><dt data-i18n>ID number</dt><dd translate="no"><?= e($idApplication['reference_no']) ?></dd></div>
      <div><dt data-i18n><?= $isPast ? 'Expired on' : 'Valid until' ?></dt><dd><?= e($expires) ?></dd></div>
    </dl>
    <p class="idc-note">
      <?php if ($state === 'in_progress'): ?>
        <?= ui_icon('refresh-cw', 15) ?><span>Renewal <b translate="no"><?= e($renewal['application']['reference_no']) ?></b> is being processed. Keep this ID until MSWDO releases the new one.</span>
      <?php elseif ($state === 'due'): ?>
        <?= ui_icon('clock', 15) ?><span data-i18n>Your ID expires today. Renewal is open now.</span>
      <?php elseif ($state === 'expired'): ?>
        <?= ui_icon('triangle-alert', 15) ?><span data-i18n>Your ID has expired. Renew now to keep your benefits.</span>
      <?php elseif ($state === 'closed'): ?>
        <?= ui_icon('triangle-alert', 15) ?><span data-i18n>The renewal period has ended. Apply again as a new application.</span>
      <?php else: ?>
        <?= ui_icon('info', 15) ?><span><?= (int) $renewal['daysLeft'] ?> day<?= (int) $renewal['daysLeft'] === 1 ? '' : 's' ?> left. Renewal opens on <?= e($expires) ?>, the day your ID expires.</span>
      <?php endif; ?>
    </p>
    <p class="idc-fine"><?= ui_icon('info', 14) ?><span data-i18n>Preview only. Your physical card is your official ID.</span></p>
    <div class="idc-actions">
      <?php if (in_array($state, ['due', 'expired'], true)): ?><a href="?section=renewal&type=new" class="btn-red text-decoration-none dash-card-link" data-i18n>Renew My ID</a><?php endif; ?>
      <?php if ($state === 'closed'): ?><a href="?section=apply" class="btn-red text-decoration-none dash-card-link" data-i18n>Apply Again</a><?php endif; ?>
      <?php if ($state === 'in_progress'): ?><a href="?section=renewal&type=status" class="btn-navy text-decoration-none dash-card-link" data-i18n>Renewal Status</a><?php endif; ?>
      <a href="<?= e($myIdUrl) ?>" class="<?= in_array($state, ['valid'], true) ? 'btn-navy' : 'btn-outline' ?> text-decoration-none dash-card-link" data-i18n>View My ID</a>
    </div>

  <?php elseif ($releaseToday): /* the counter card above is the whole card on the release day */ ?>
  <?php elseif ($pending): $ps = status_style($pending['status']); ?>
    <div class="idc-sample is-grey" aria-label="Your ID is being prepared" role="img">
      <img src="<?= asset('images/solo-parent-id/front-preview.jpg') ?>" alt="" width="900" height="514" loading="lazy">
      <span class="idc-wm" aria-hidden="true" data-decorative><span>IN PROCESS</span></span>
    </div>
    <dl class="idc-meta">
      <div><dt data-i18n>Application</dt><dd translate="no"><?= e($pending['reference_no']) ?></dd></div>
      <div><dt data-i18n>Status</dt><dd><span class="status-pill" style="background:<?= e($ps['bg']) ?>;color:<?= e($ps['color']) ?>"><?= e($pending['status']) ?></span></dd></div>
    </dl>
    <p class="idc-note"><?= ui_icon('info', 15) ?><span data-i18n><?= $pending['status'] === 'Approved' ? 'Approved. MSWDO will tell you when and where to collect your ID.' : 'Your ID number and dates appear here once MSWDO releases your ID.' ?></span></p>
    <div class="idc-actions">
      <?php if ($viewableIdApplication): ?><a href="<?= e($myIdUrl) ?>" class="btn-navy text-decoration-none dash-card-link" data-i18n>View My ID</a><?php endif; ?>
      <a href="?section=application" class="btn-outline text-decoration-none dash-card-link" data-i18n>View Application</a>
    </div>

  <?php else: ?>
    <div class="dash-home-empty"><h3 data-i18n>Your ID details will appear here</h3><p data-i18n>Once MSWDO approves and releases your ID, you can view its details and renewal schedule.</p></div>
  <?php endif; ?>
</div>
