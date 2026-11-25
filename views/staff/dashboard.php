<?php
require_once __DIR__ . '/../../includes/icons.php';
/**
 * MSWD Social Worker dashboard -- the processing queue at a glance. Every "Needs attention" tile and every section link goes straight
 * to the page where that work is done (Applications, Appointment Management, Document Verification Queue, Failed Texts, ...), so
 * nothing long is listed here. All counts are live queries.
 *
 * @var array $staff
 * @var array $stats       pending, underVerification, forRevision, pendingRenewals, todaysAppointments, needsOutcome, approved,
 *                         readyToSchedule, awaitingRelease, releaseOverdue, rejected
 * @var array $docBacklog  applications, documents, resent_applications (awaiting verification)
 * @var array $followUp    missed and not rebooked for 7 days
 * @var int   $failedTextCount  failed texts and ID expiry texts not sent
 */
$followUp = $followUp ?? [];
$failedTextCount = $failedTextCount ?? 0;
/** One tile: a link to the page where the work is done. */
$tileIcons = [
    'Pending Review' => 'file-text', 'Under Verification' => 'search', 'Documents to Verify' => 'file-check', 'Re-uploaded Documents' => 'rotate-cw',
    'Interviews to Confirm' => 'clipboard-check', 'Ready to Schedule' => 'calendar-plus', 'Pending Corrections' => 'send', 'Pending Renewals' => 'refresh-cw',
    'Expected Today' => 'calendar-check', 'ID Release' => 'id-card', 'ID Release Date Passed' => 'triangle-alert', 'Missed and Not Rebooked' => 'phone',
    'Failed Texts' => 'message-square-warning', 'Approved' => 'circle-check', 'Rejected' => 'circle-x',
];
$tile = function (string $label, int $value, string $sub, string $href, bool $alert = false, bool $i18n = false, bool $review = false) use ($tileIcons): void {
    $tone = ($alert || $review) && $value > 0 ? ($label === 'Failed Texts' || $label === 'ID Release Date Passed' || $label === 'Missed and Not Rebooked' ? 'gi-red' : 'gi-amber') : 'gi-blue'; ?>
    <a href="<?= e($href) ?>" class="stat-tile has-icon<?= $review ? ' is-review' : '' ?><?= $value === 0 ? ' is-zero' : '' ?>">
      <span class="gi-chip <?= $tone ?>"><?= ui_icon($tileIcons[$label] ?? 'info', 22) ?></span>
      <span class="stat-body">
        <div class="stat-label"<?= $i18n ? ' data-i18n' : '' ?>><?= e($label) ?></div>
        <div class="stat-value <?= $alert && $value > 0 ? 'stat-alert' : '' ?>"><?= $value ?></div>
        <div class="stat-sub"><?= e($sub) ?></div>
      </span>
      <span class="stat-chevron"><?= ui_icon('chevron-right', 18) ?></span>
    </a>
<?php };
?>
<div class="portal-container" id="staff-dashboard">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <div>
      <h1 class="page-title">MSWD Dashboard</h1>
      <div style="font-size:13px;color:var(--muted)">Your processing queue at a glance · <?= e(date('l, F j, Y')) ?></div>
    </div>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>

  <?php if (!empty($flashMessage)): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>
  <?php if (!empty($smsNotice)) { require APP_ROOT . '/views/staff/applications/sms-notice.php'; } ?>
  <?php if (!empty($office)) { $officeCompact = true; require APP_ROOT . '/views/partials/office-today.php'; } ?>
  <div class="dash-section-title" data-i18n>Other work</div>
  <div class="stat-grid stat-grid-quad">
    <?php
    // New applications and renewals to review come first and stand out: staff must never miss them.
    $tile('Pending Review', (int) $stats['pending'], 'new applications · open the list', base_url('staff/index.php?type=new&status=Pending Review'), false, true, true);
    $tile('Pending Renewals', (int) $stats['pendingRenewals'], 'renewal requests · open the list', base_url('staff/index.php?type=renewal&status=Pending Review'), false, true, true);
    $tile('Under Verification', (int) $stats['underVerification'], 'being checked', base_url('staff/index.php?status=Under Verification'), false, true);
    $tile('Documents to Verify', (int) $docBacklog['documents'], 'across ' . (int) $docBacklog['applications'] . ' application' . ((int) $docBacklog['applications'] === 1 ? '' : 's'), base_url('staff/verification.php'), true);
    $tile('Re-uploaded Documents', (int) $docBacklog['resent_applications'], 'Solo Parents who sent a document again', base_url('staff/verification.php?filter=resent'), true);
    $tile('Interviews to Confirm', (int) $stats['needsOutcome'], 'date passed, no result yet', base_url('staff/appointments.php#staff-needs-outcome'), true);
    $tile('Ready to Schedule', (int) $stats['readyToSchedule'], 'documents verified, no date yet', base_url('staff/appointments.php#staff-needs-schedule'), true);
    $tile('Pending Corrections', (int) $stats['forRevision'], 'waiting on Solo Parent', base_url('staff/index.php?status=For Revision'));
    $tile('ID Release', (int) $stats['awaitingRelease'], 'approved, ID not handed over', base_url('staff/index.php?status=Approved'));
    $tile('ID Release Date Passed', (int) ($stats['releaseOverdue'] ?? 0), 'ID still at the office', base_url('staff/appointments.php#staff-release-overdue'), true);
    $tile('Missed and Not Rebooked', count($followUp), 'no new date for 7 days: call them', base_url('staff/follow_up.php'), true);
    $tile('Failed Texts', (int) $failedTextCount, 'SMS not sent', base_url('staff/sms.php'), true);
    ?>
  </div>


  <div class="dash-section-title">Decisions to date</div>
  <div class="stat-grid">
    <?php
    $tile('Approved', (int) $stats['approved'], 'incl. IDs released', base_url('staff/index.php?status=Approved'), false, true);
    $tile('Rejected', (int) $stats['rejected'], 'not approved', base_url('staff/index.php?status=Rejected'), false, true);
    ?>
  </div>

  <div class="d-flex flex-wrap gap-2" style="margin-top:8px">
    <a href="<?= base_url('staff/index.php') ?>" class="btn-navy text-decoration-none">View All Applications</a>
    <a href="<?= base_url('staff/appointments.php') ?>" class="btn-outline text-decoration-none" data-i18n>Appointment Management</a>
    <a href="<?= base_url('staff/applicants.php') ?>" class="btn-outline text-decoration-none" data-i18n>Solo Parent Records</a>
    <a href="<?= base_url('staff/reports.php') ?>" class="btn-outline text-decoration-none" data-i18n>Reports</a>
  </div>
</div>
