<?php
/** Applicant ID viewer; access is checked by ApplicantController. */
$isReleased = $idApplication && $idApplication['status'] === 'ID Released';
$isExpired = !empty($idExpiryInfo['expired']);
$renewalInProgress = $renewalInProgress ?? null;
$idStatusLabel = $renewalInProgress ? 'Renewal in progress' : ($isExpired ? 'Expired' : ($isReleased ? 'ID Released' : 'Release Scheduled'));
?>
<link rel="stylesheet" href="<?= public_asset('css/applicant-id.css') ?>">
<div class="applicant-id-page">
  <?php if ($idApplication): ?>
    <link rel="stylesheet" href="<?= public_asset('css/verification-id.css') ?>">
    <header class="applicant-id-heading">
      <div><div class="applicant-id-eyebrow">MSWDO Virac</div><h1 data-i18n>My Solo Parent ID</h1><p data-i18n>Your ID details and collection information in one place.</p></div>
      <span class="applicant-id-status <?= $renewalInProgress ? 'is-scheduled' : ($isExpired ? 'is-expired' : ($isReleased ? 'is-released' : 'is-scheduled')) ?>"><span aria-hidden="true"></span><?= e($idStatusLabel) ?></span>
    </header>

    <div class="applicant-id-layout">
      <section class="applicant-id-panel applicant-id-preview" aria-labelledby="id-preview-title">
        <div class="applicant-id-panel-heading"><h2 id="id-preview-title" data-i18n>Your identification card</h2><span id="id-visible-side" aria-live="polite" data-i18n>Front side</span></div>
        <div class="applicant-id-stage">
          <div class="applicant-id-card-wrap">
            <?php
            $a = $idApplication;
            $verificationCard = $idApplication;
            $verificationCard['children'] = $idChildren;
            require APP_ROOT . '/views/partials/solo-parent-id-card.php';
            ?>
          </div>
        </div>
        <div class="applicant-id-preview-actions">
          <p data-i18n>Tap the card or use the button to see the other side.</p>
          <button type="button" id="id-flip-control" class="applicant-id-flip" aria-controls="verification-id" aria-pressed="false"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M3 10a9 9 0 0 1 15-6l3 3M21 3v4h-4M21 14a9 9 0 0 1-15 6l-3-3M3 21v-4h4"/></svg><span data-i18n>View back of ID</span></button>
        </div>
        <dl class="applicant-id-details">
          <div><dt data-i18n>Cardholder</dt><dd><?= e($idApplication['full_name']) ?></dd></div>
          <div><dt data-i18n>Reference number</dt><dd class="applicant-id-reference"><?= e($idApplication['reference_no']) ?></dd></div>
          <div><dt data-i18n>Category</dt><dd><?= e($idApplication['category_name']) ?></dd></div>
          <?php $idValidFrom = $idApplication['id_valid_from'] ?? (($idReleaseSchedule['released_at'] ?? null) ? substr((string) $idReleaseSchedule['released_at'], 0, 10) : null); ?>
          <?php if ($isReleased && $idValidFrom): ?><div><dt data-i18n>Valid from</dt><dd><?= e(format_date_long($idValidFrom)) ?></dd></div><?php endif; ?>
          <div><dt data-i18n>Valid until</dt><dd><?= $idExpiryInfo ? e(format_date_long($idExpiryInfo['date'])) : 'Set upon physical release' ?></dd></div>
        </dl>
      </section>

      <aside class="applicant-id-sidebar" aria-label="Release and verification details">
        <section class="applicant-id-panel applicant-id-release" aria-labelledby="id-release-title">
          <div class="applicant-id-panel-heading"><h2 id="id-release-title"><?= $isReleased ? 'Release details' : 'Collect your ID' ?></h2></div>
          <?php if ($idReleaseSchedule): ?>
            <div class="applicant-id-appointment">
              <div class="applicant-id-date-icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4m8-4v4M3 11h18m-12 4h6"/></svg></div>
              <div><div class="applicant-id-eyebrow"><?= $isReleased && $idReleaseSchedule['released_at'] ? 'Handed over' : 'Release appointment' ?></div><strong><?= e(date('F j, Y', strtotime($isReleased && $idReleaseSchedule['released_at'] ? $idReleaseSchedule['released_at'] : $idReleaseSchedule['scheduled_at']))) ?></strong><span><?= e(date('g:i A', strtotime($isReleased && $idReleaseSchedule['released_at'] ? $idReleaseSchedule['released_at'] : $idReleaseSchedule['scheduled_at']))) ?></span></div>
            </div>
            <div class="applicant-id-location"><span data-i18n>Location</span><strong><?= e($idReleaseSchedule['location']) ?></strong></div>
          <?php endif; ?>
          <?php if (!$isReleased && $idReleaseSchedule && strtotime($idReleaseSchedule['scheduled_at']) < time()): ?>
            <p class="applicant-id-release-note" data-i18n>Your release date has passed, but your ID is still kept at the MSWDO office. You can collect it during office hours. If you would like a new date, contact MSWDO.</p>
          <?php endif; ?>
          <p class="applicant-id-release-note"><?= $isReleased ? 'MSWDO staff has confirmed the physical release of your Solo Parent ID.' : 'Bring a valid government ID. MSWDO staff will confirm physical release when you receive your card.' ?></p>
          <?php if ($renewalInProgress): ?><p class="applicant-id-renewal-note">Your renewal <b translate="no"><?= e($renewalInProgress['reference_no']) ?></b> is being processed. Keep this ID until MSWDO releases the new one.</p><a class="btn-navy applicant-id-link" href="<?= base_url('applicant/dashboard.php?section=renewal&type=status') ?>" data-i18n>View Renewal Status</a>
          <?php elseif ($isExpired): ?><p class="applicant-id-expired-note" data-i18n>Your ID has expired. Check your renewal options to keep your details up to date.</p><a class="btn-outline applicant-id-link" href="<?= base_url('applicant/dashboard.php?section=renewal') ?>" data-i18n>View Renewal Options</a><?php endif; ?>
        </section>

      </aside>
    </div>
    <script src="<?= public_asset('js/verification-id.js') ?>" defer></script>
  <?php else: ?>
    <section class="applicant-id-empty" data-i18n>
      <div class="applicant-id-empty-icon" aria-hidden="true"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="4" width="20" height="16" rx="3"/><circle cx="8" cy="10" r="2"/><path d="M5 16c0-3 6-3 6 0m3-6h5m-5 4h5"/></svg></div>
      <div class="applicant-id-eyebrow" data-i18n>My Solo Parent ID</div>
      <h1><?= $latestApplication ? 'Your ID is being prepared' : 'Your ID starts here' ?></h1>
      <?php if ($latestApplication): $st = status_style($latestApplication['status']); ?>
        <p data-i18n>Application <strong><?= e($latestApplication['reference_no']) ?></strong> is currently <strong data-i18n><?= e($latestApplication['status']) ?></strong>. Your ID will be available here once MSWDO schedules its release.</p>
        <a href="<?= base_url('applicant/dashboard.php?section=application') ?>" class="btn-navy applicant-id-link" data-i18n>View Application</a>
      <?php else: ?>
        <p data-i18n>Apply for a Solo Parent ID to access your card and release details here.</p>
        <a href="<?= base_url('applicant/dashboard.php?section=apply') ?>" class="btn-navy applicant-id-link" data-i18n>Apply Online Now</a>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</div>
