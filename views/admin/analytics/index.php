<?php
/**
 * @var array     $totals        applicants, new, renewals, approved, rejected
 * @var array     $monthly       rows of ym, n (last 12 months)
 * @var int       $monthlyMax
 * @var array     $byBarangay    rows of barangay, n (top 15)
 * @var int       $barangayMax
 * @var array     $bySex
 * @var array     $byCivilStatus
 * @var array     $byCategory
 * @var array     $expiringIds
 * @var array     $appointments  total, today, upcoming, completed, selfBooked, staffScheduled
 * @var array     $slotUtil      total_capacity, total_booked
 * @var int|float $slotUtilPct
 */
?>
<div class="portal-container">
  <a href="<?= base_url('admin/dashboard.php') ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">Analytics &amp; Reports</h1>
    <div class="d-flex gap-2">
      <button type="button" onclick="window.print()" class="btn-outline" style="padding:8px 16px;font-size:13px">🖨️ Print</button>
      <a href="<?= base_url('admin/export_csv.php?export=applications') ?>" class="btn-navy text-decoration-none" style="padding:8px 16px;font-size:13px">Download CSV</a>
    </div>
  </div>

  <div class="dash-quick-grid" style="margin-bottom:24px">
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Total Applicants</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $totals['applicants'] ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">New Applications</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $totals['new'] ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Renewal Applications</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $totals['renewals'] ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Approved</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $totals['approved'] ?></div></div>
    <div class="dash-card" style="margin-bottom:0"><div class="dash-card-label">Rejected</div><div style="font-size:28px;font-weight:700;color:var(--ink)"><?= $totals['rejected'] ?></div></div>
  </div>

  <div class="row g-4">
    <div class="col-12 col-lg-6">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Applications — Last 12 Months</div></div>
        <?php foreach ($monthly as $m): ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <div style="width:70px;font-size:12px;color:var(--muted)"><?= e(date('M Y', strtotime($m['ym'] . '-01'))) ?></div>
            <div style="flex:1;background:var(--border);border-radius:4px;overflow:hidden;height:16px">
              <div style="width:<?= round($m['n'] / $monthlyMax * 100) ?>%;background:var(--navy);height:100%"></div>
            </div>
            <div style="width:26px;font-size:12px;color:var(--ink);text-align:right"><?= (int) $m['n'] ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$monthly): ?><div style="font-size:13px;color:var(--muted)">No data yet.</div><?php endif; ?>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Solo Parents by Barangay (Top 15)</div></div>
        <?php foreach ($byBarangay as $b): ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <div style="width:110px;font-size:12px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($b['barangay']) ?></div>
            <div style="flex:1;background:var(--border);border-radius:4px;overflow:hidden;height:16px">
              <div style="width:<?= round($b['n'] / $barangayMax * 100) ?>%;background:var(--red);height:100%"></div>
            </div>
            <div style="width:26px;font-size:12px;color:var(--ink);text-align:right"><?= (int) $b['n'] ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$byBarangay): ?><div style="font-size:13px;color:var(--muted)">No data yet.</div><?php endif; ?>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">By Category</div></div>
        <?php foreach ($byCategory as $c): ?>
          <div class="review-row"><div class="k"><?= e($c['name']) ?></div><div class="v"><?= (int) $c['n'] ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Demographics</div></div>
        <div style="font-size:12px;font-weight:700;color:var(--muted);margin-bottom:6px">Sex</div>
        <?php foreach ($bySex as $s): ?>
          <div class="review-row"><div class="k"><?= e($s['sex']) ?></div><div class="v"><?= (int) $s['n'] ?></div></div>
        <?php endforeach; ?>
        <div style="font-size:12px;font-weight:700;color:var(--muted);margin:14px 0 6px">Civil Status</div>
        <?php foreach ($byCivilStatus as $c): ?>
          <div class="review-row"><div class="k"><?= e($c['civil_status']) ?></div><div class="v"><?= (int) $c['n'] ?></div></div>
        <?php endforeach; ?>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">IDs Expiring Within 60 Days</div></div>
        <?php if (!$expiringIds): ?>
          <div style="font-size:13px;color:var(--muted)">No IDs expiring soon.</div>
        <?php endif; ?>
        <?php foreach ($expiringIds as $ex): ?>
          <div class="review-row"><div class="k"><?= e($ex['reference_no']) ?> — <?= e($ex['full_name']) ?></div><div class="v"><?= e(date('M j, Y', strtotime($ex['id_expires_at']))) ?></div></div>
        <?php endforeach; ?>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Appointment Statistics</div></div>
        <div class="review-grid">
          <div class="review-row"><div class="k">Total appointments</div><div class="v"><?= $appointments['total'] ?></div></div>
          <div class="review-row"><div class="k">Today</div><div class="v"><?= $appointments['today'] ?></div></div>
          <div class="review-row"><div class="k">Upcoming</div><div class="v"><?= $appointments['upcoming'] ?></div></div>
          <div class="review-row"><div class="k">Completed (result recorded)</div><div class="v"><?= $appointments['completed'] ?></div></div>
          <div class="review-row"><div class="k">Self-booked by applicant</div><div class="v"><?= $appointments['selfBooked'] ?></div></div>
          <div class="review-row"><div class="k">Scheduled by staff</div><div class="v"><?= $appointments['staffScheduled'] ?></div></div>
        </div>
        <div style="font-size:12px;font-weight:700;color:var(--muted);margin:14px 0 6px">Upcoming Slot Utilization</div>
        <div class="d-flex align-items-center gap-2">
          <div style="flex:1;background:var(--border);border-radius:4px;overflow:hidden;height:16px">
            <div style="width:<?= $slotUtilPct ?>%;background:var(--navy);height:100%"></div>
          </div>
          <div style="width:90px;font-size:12px;color:var(--ink);text-align:right"><?= (int) $slotUtil['total_booked'] ?> / <?= (int) $slotUtil['total_capacity'] ?> (<?= $slotUtilPct ?>%)</div>
        </div>
      </div>
    </div>
  </div>
</div>
