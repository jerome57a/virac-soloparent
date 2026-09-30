<?php
/**
 * @var string $ownDashboard
 * @var array  $counts   today, thisMonth, approved, rejected, pending, renewals, renewalsPending
 * @var array  $monthly  rows of ym, n
 * @var int    $monthlyMax
 */
?>
<div class="portal-container">
  <a href="<?= e($ownDashboard) ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <h1 class="font-heading mt-2 mb-4" style="font-size:26px">Reports</h1>

  <div class="dash-quick-grid" style="margin-bottom:24px">
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Today</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $counts['today'] ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">This Month</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $counts['thisMonth'] ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Approved</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $counts['approved'] ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Rejected</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $counts['rejected'] ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Pending</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $counts['pending'] ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Renewals (<?= $counts['renewalsPending'] ?> pending)</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $counts['renewals'] ?></div></div>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Applications — Last 6 Months</div></div>
    <?php foreach ($monthly as $m): ?>
      <div class="d-flex align-items-center gap-2 mb-2">
        <div style="width:70px;font-size:12px;color:var(--muted)"><?= e(date('M Y', strtotime($m['ym'] . '-01'))) ?></div>
        <div style="flex:1;background:var(--border);border-radius:4px;overflow:hidden;height:18px">
          <div style="width:<?= round($m['n'] / $monthlyMax * 100) ?>%;background:var(--navy);height:100%"></div>
        </div>
        <div style="width:30px;font-size:12px;color:var(--ink);text-align:right"><?= (int) $m['n'] ?></div>
      </div>
    <?php endforeach; ?>
    <?php if (!$monthly): ?><div style="font-size:13px;color:var(--muted)">No applications in this period.</div><?php endif; ?>
  </div>
</div>
