<?php
/** @var array $renewalHistory */
?>
        <?php if (!$renewalHistory): ?>
          <div class="dash-card">
            <div style="font-size:14px;color:var(--muted)">No renewal requests on file yet.</div>
          </div>
        <?php else: ?>
          <?php foreach ($renewalHistory as $r): $st = status_style($r['status']); ?>
            <div class="app-card">
              <div>
                <div class="app-ref"><?= e($r['reference_no']) ?></div>
                <div class="app-date">Submitted <?= e(date('M j, Y', strtotime($r['submitted_at']))) ?></div>
              </div>
              <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($r['status']) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
