<?php
/**
 * One application row on the applicant record, with its appointment,
 * document-verification tally, and latest decision remark.
 *
 * @var array $app
 * @var array $interviewsByApp
 * @var array $docCountsByApp
 * @var array $latestDecisionByApp
 */
$st = status_style($app['status']);
$iv = $interviewsByApp[$app['id']] ?? null;
$docCounts = $docCountsByApp[$app['id']] ?? [];
$latestRemark = $latestDecisionByApp[$app['id']]['remarks'] ?? null;
$docParts = [];
foreach (['Verified', 'Needs Correction', 'Pending'] as $label) {
    if (!empty($docCounts[$label])) {
        $docParts[] = $docCounts[$label] . ' ' . $label;
    }
}
?>
      <a href="<?= base_url('staff/application.php?id=' . (int) $app['id']) ?>" class="app-card text-decoration-none" style="color:inherit;align-items:flex-start">
        <div>
          <div class="app-ref"><?= e($app['reference_no']) ?></div>
          <div class="app-date"><?= e($app['category_name']) ?> · Submitted <?= e(date('M j, Y', strtotime($app['submitted_at']))) ?></div>
          <?php if ($iv): ?><div class="app-date">📅 Appointment: <?= e(date('M j, Y g:i A', strtotime($iv['scheduled_at']))) ?> at <?= e($iv['location']) ?></div><?php endif; ?><?php if ($docCounts): ?><div class="app-date">📄 Documents: <?= array_sum($docCounts) ?> uploaded (<?= e(implode(', ', $docParts)) ?>)</div><?php endif; ?><?php if ($latestRemark): ?><div class="app-date">📝 Latest: <?= e($latestRemark) ?></div><?php endif; ?>

        </div>
        <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></div>
      </a>
