<?php
/**
 * Read-only monitoring view of one application (decisions happen in staff/application.php).
 *
 * @var array  $app
 * @var ?array $interview
 * @var array  $children
 * @var array  $docs
 * @var array  $history
 */
$st = status_style($app['status']);
?>
<div class="portal-container">
  <a href="<?= base_url('admin/applications.php') ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span data-i18n>Back</span></a>
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mt-2 mb-4">
    <div>
      <h1 class="font-heading m-0" style="font-size:26px"><?= e($app['reference_no']) ?> <?php if ($app['type'] === 'renewal'): ?><span class="doc-tag" style="vertical-align:middle" data-i18n>RENEWAL</span><?php endif; ?></h1>
      <div style="font-size:13px;color:var(--muted)" data-i18n>Submitted <?= e(date('F j, Y g:i A', strtotime($app['submitted_at']))) ?><?php if ($app['reviewer_name']): ?> · Reviewed by <?= e($app['reviewer_name']) ?><?php endif; ?></div>
    </div>
    <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>;font-size:14px"><?= e($app['status']) ?></div>
  </div>
  <div class="info-box" style="max-width:none">👁️ Read-only monitoring view. Decisions on this application are made by MSWDO staff in their processing queue.</div>

  <div class="row g-4 mt-2">
    <div class="col-12 col-lg-7">
      <?php if (isset($renewalOf)) { require APP_ROOT . '/views/partials/renewal-of.php'; } ?>
      <div class="review-card">
        <div class="review-card-head"><div class="title" data-i18n>Personal Info</div></div>
        <div class="review-grid">
          <div class="review-row"><div class="k" data-i18n>Full name</div><div class="v"><?= e($app['full_name']) ?></div></div>
          <div class="review-row"><div class="k" data-i18n>Birthdate</div><div class="v"><?= e(format_date_long($app['birthdate'])) ?></div></div>
          <div class="review-row"><div class="k" data-i18n>Address</div><div class="v"><?= e($app['address']) ?>, Brgy. <?= e($app['barangay']) ?></div></div>
          <?php require APP_ROOT . '/views/partials/mobile-numbers.php'; ?>
          <div class="review-row"><div class="k" data-i18n>Email</div><div class="v"><?= e($app['email']) ?></div></div>
          <div class="review-row"><div class="k" data-i18n>Sex</div><div class="v"><?= e($app['sex']) ?></div></div>
          <div class="review-row"><div class="k" data-i18n>Civil status</div><div class="v"><?= e($app['civil_status']) ?></div></div>
          <div class="review-row"><div class="k" data-i18n>Category</div><div class="v"><?= e($app['category_name']) ?></div></div>
          <div class="review-row"><div class="k">Eligibility assessment</div><div class="v"><?= e($app['eligibility_status']) ?><?= $app['eligibility_remarks'] ? ' — ' . e($app['eligibility_remarks']) : '' ?></div></div>
        </div>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title" data-i18n>Children</div></div>
        <div class="review-grid">
          <?php foreach ($children as $i => $c): ?>
            <div class="review-row"><div class="k" data-i18n>Child <?= $i + 1 ?></div><div class="v"><?= e($c['full_name']) ?> · <?= e(format_date_long($c['birthdate'])) ?></div></div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php require APP_ROOT . '/views/partials/document-review.php'; ?>
    </div>

    <div class="col-12 col-lg-5">
      <?php if ($interview): ?>
        <div class="review-card">
          <div class="review-card-head"><div class="title">Interview / Appointment</div></div>
          <div class="review-grid">
            <div class="review-row"><div class="k">Scheduled</div><div class="v"><?= e(interview_schedule_text($interview['scheduled_at'], interview_is_date_only($interview))) ?></div></div>
            <div class="review-row"><div class="k" data-i18n>Location</div><div class="v"><?= e($interview['location']) ?></div></div>
            <div class="review-row"><div class="k" data-i18n>Status</div><div class="v"><?= e(ucfirst(strtolower($interview['status']))) ?><?= $interview['cancel_reason'] ? ' — ' . e($interview['cancel_reason']) : '' ?></div></div>
            <div class="review-row"><div class="k">Booked by</div><div class="v"><?= $interview['scheduled_by'] ? 'MSWDO staff' : (($interview['notes'] ?? '') === 'Interview automatically scheduled on application submission.' ? 'System (automatically scheduled)' : 'Solo Parent (self-booked)') ?></div></div>
            <?php if ($interview['result']): ?><div class="review-row"><div class="k">Result</div><div class="v"><?= e($interview['result']) ?></div></div><?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

<?php require APP_ROOT . '/views/partials/case-record.php'; ?>
<?php require APP_ROOT . '/views/partials/status-history.php'; ?>
    </div>
  </div>
</div>
