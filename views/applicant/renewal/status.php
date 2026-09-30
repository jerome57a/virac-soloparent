<?php
/** @var ?array $latestRenewal */
?>
        <?php if (!$latestRenewal): ?>
          <div class="dash-card">
            <div style="font-size:14px;color:var(--muted)">You have no renewal requests yet.</div>
          </div>
        <?php else: $st = status_style($latestRenewal['status']); ?>
          <div class="dash-card">
            <div class="dash-status-row">
              <div>
                <div class="dash-status-ref"><?= e($latestRenewal['reference_no']) ?></div>
                <div class="dash-status-cat">Submitted <?= e(date('M j, Y', strtotime($latestRenewal['submitted_at']))) ?></div>
              </div>
              <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($latestRenewal['status']) ?></div>
            </div>
            <?php if ($latestRenewal['interview_at']): ?>
              <div class="info-box" style="max-width:none;margin-bottom:0">📅 Interview scheduled for <b><?= e(date('F j, Y g:i A', strtotime($latestRenewal['interview_at']))) ?></b> at <?= e($latestRenewal['interview_location']) ?>.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
