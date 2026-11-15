<?php
/** @var array $apps */
?>
<?php if (!$apps): ?>
  <div style="font-size:14px;color:var(--muted)" data-i18n>You have not submitted any applications yet.</div>
<?php endif; ?>

<?php foreach ($apps as $app): $st = status_style($app['status']); ?>
  <div class="app-card">
    <div>
      <div class="app-ref"><?= e($app['reference_no']) ?></div>
      <div class="app-date" data-i18n>Submitted <?= e(date('M j, Y', strtotime($app['submitted_at']))) ?></div>
    </div>
    <div class="d-flex align-items-center flex-wrap gap-2">
      <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>" data-i18n><?= e($app['status']) ?></div>
    </div>
  </div>
<?php endforeach; ?>
