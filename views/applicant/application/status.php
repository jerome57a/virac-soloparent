<?php
/**
 * @var array $myApplications
 * @var array $latestRemarksByApp  application_id => latest status_history row
 */
?>
      <?php if (!$myApplications): ?>
        <div class="dash-card">
          <div style="font-size:14px;color:var(--muted);margin-bottom:14px">You have not submitted any applications yet.</div>
          <a href="<?= base_url('portal.php?tab=apply') ?>" class="btn-red text-decoration-none">Apply Online Now</a>
        </div>
      <?php else: ?>
        <?php foreach ($myApplications as $app): $st = status_style($app['status']); $stage = application_stage_info($app['status']); $latestRemark = $latestRemarksByApp[$app['id']]['remarks'] ?? null; ?>
          <div class="review-card">
            <div class="review-card-head">
              <div class="title"><?= e($app['reference_no']) ?> <span class="doc-tag" style="vertical-align:middle"><?= $app['type'] === 'renewal' ? 'RENEWAL' : 'NEW' ?></span></div>
            </div>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
              <div style="font-size:13px;color:var(--muted)">Submitted <?= e(date('M j, Y', strtotime($app['submitted_at']))) ?></div>
              <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></div>
            </div>

            <div class="step-bar" style="margin-bottom:16px">
              <?php foreach ($stage['steps'] as $i => $s): ?>
                <div class="step-item <?= $s['state'] === 'done' ? 'done' : ($s['state'] === 'active' ? 'active' : '') ?>">
                  <div class="step-circle"><?= $s['state'] === 'done' ? '✓' : $i + 1 ?></div>
                  <div class="step-label"><?= e($s['label']) ?></div>
                  <div class="step-progress"></div>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if ($stage['rejected']): ?>
              <div class="step-alert">❌ This application was not approved.<?php if ($latestRemark): ?> <?= e($latestRemark) ?><?php endif; ?></div>
            <?php elseif ($stage['needsCorrection']): ?>
              <div class="step-alert">⚠️ Needs Correction<?php if ($latestRemark): ?>: <?= e($latestRemark) ?><?php endif; ?> &mdash; go to Upload Documents to fix and re-upload.</div>
            <?php elseif ($latestRemark): ?>
              <div class="info-box" style="max-width:none">📝 <?= e($latestRemark) ?></div>
            <?php endif; ?>

            <?php if ($app['interview_at']): ?>
              <div class="info-box" style="max-width:none">📅 Interview scheduled for <b><?= e(date('F j, Y g:i A', strtotime($app['interview_at']))) ?></b> at <?= e($app['interview_location']) ?>.</div>
            <?php endif; ?>

            <div class="d-flex gap-2 flex-wrap mt-2">
              <a href="<?= base_url('slip.php?ref=' . urlencode($app['reference_no'])) ?>" class="btn-outline text-decoration-none" style="padding:8px 16px;font-size:13px">View Slip</a>
              <a href="?section=documents" class="btn-outline text-decoration-none" style="padding:8px 16px;font-size:13px">View Documents</a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
