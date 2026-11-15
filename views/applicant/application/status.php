<?php
/**
 * @var array $myApplications
 * @var array $trackers  application_id => ApplicationTrackerService::track() result
 * @var array $user
 * @var ?array $applicationDraft
 */
?>
      <?php require __DIR__ . '/draft-card.php'; ?>
      <?php if (!$myApplications && !$applicationDraft): ?>
        <div class="dash-card">
          <div style="font-size:14px;color:var(--muted);margin-bottom:14px" data-i18n>You have not submitted any applications yet.</div>
          <a href="<?= base_url('applicant/dashboard.php?section=apply') ?>" class="btn-red text-decoration-none" data-i18n>Apply Online Now</a>
        </div>
      <?php else: ?>
        <?php foreach ($myApplications as $app): $st = status_style($app['status']); ?>
          <div class="review-card">
            <div class="review-card-head">
              <div class="title"><?= e($app['reference_no']) ?> <span class="doc-tag" style="vertical-align:middle" data-i18n><?= $app['type'] === 'renewal' ? 'RENEWAL' : 'NEW' ?></span></div>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
              <div style="font-size:13px;color:var(--muted)" data-i18n>Submitted <?= e(date('M j, Y', strtotime($app['submitted_at']))) ?></div>
              <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>" data-i18n><?= e($app['status']) ?></div>
            </div>

            <?php $tracker = $trackers[$app['id']]; $trackerUserId = (int) $user['id']; require __DIR__ . '/tracker.php'; ?>

            <?php if ($app['interview_at']): ?>
              <div class="info-box" style="max-width:none"><?= appointment_summary_html($app) ?></div>
              <?php if (($app['interview_status'] ?? '') === 'BOOKED'): ?>
                <?php $preparationItems = $tracker['checklist']; require APP_ROOT . '/views/partials/requirements-preparation.php'; ?>
              <?php endif; ?>
            <?php endif; ?>

            <div class="d-flex gap-2 flex-wrap mt-2">
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
