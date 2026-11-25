<?php
/**
 * MSWD Social Worker dashboard (the approved design): "Today at the office" (the search box, today's counts and See today's work),
 * the four work tiles, and the shortcut buttons. Only the work staff must start, in work order, for new applications and renewals
 * together; each application is in one list, the step it is at now:
 *   New forms               submitted, nobody has opened the form yet
 *   Re-uploaded documents   the Solo Parent sent a document again after a correction request
 *   Set the interview date  documents verified, no interview date yet
 *   Set the ID release date approved, no release date yet
 * A tile shows its title and how many are waiting (an orange top edge when there are some); it opens the list (staff/work.php).
 * Who comes to an interview or to collect the ID, the decision after the interview and confirming the handover start from the search
 * box: the Solo Parent shows the reference number and the application opens.
 *
 * @var array $staff
 * @var array $work    new_forms | resent | interview | release_date => ['rows' => [...], 'total' => int]
 * @var array $office  OfficeCheckInService::panel(): board {expected, done}, query, matches (one match never gets here: it opens)
 */
require_once __DIR__ . '/../../includes/icons.php';
$lists = [
    'new_forms' => ['New forms', base_url('staff/work.php?list=new_forms'), 'file-text'],
    'resent' => ['Re-uploaded documents', base_url('staff/work.php?list=resent'), 'rotate-cw'],
    'interview' => ['Set the interview date', base_url('staff/work.php?list=interview'), 'calendar-plus'],
    'release_date' => ['Set the ID release date', base_url('staff/work.php?list=release_date'), 'id-card'],
];
$office = $office ?? ['board' => ['expected' => [], 'done' => []], 'query' => null, 'matches' => []];
$query = (string) ($office['query'] ?? '');
$matches = $office['matches'] ?? [];
?>
<link rel="stylesheet" href="<?= public_asset('css/staff-queues.css') ?>">
<div class="portal-container" id="staff-dashboard">
  <h1 class="visually-hidden">MSWD Dashboard</h1>

  <?php if (!empty($flashMessage)): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>
  <?php if (!empty($smsNotice)) { require APP_ROOT . '/views/staff/applications/sms-notice.php'; } ?>

  <section class="td-card" id="office-today" aria-labelledby="td-title">
    <div class="td-head"><h2 id="td-title" data-i18n>Today at the office</h2><small data-i18n>Office hours 7:00 AM to 5:00 PM</small></div>
    <form class="td-search" method="get" action="<?= e(base_url('staff/dashboard.php')) ?>" role="search">
      <label class="td-field"><?= ui_icon('search', 18) ?><input type="search" id="ot-ref" name="checkin" value="<?= e($query) ?>" maxlength="60" autocomplete="off" aria-label="Find an application" placeholder="Reference number, name or mobile number" data-i18n-attrs="aria-label placeholder"></label>
      <button type="submit" class="td-open" data-i18n>Open</button>
    </form>
    <?php if ($query !== ''): ?>
      <div class="td-result" role="status">
        <?php if (!$matches): ?>
          <b data-i18n>Not found</b> <span>"<?= e($query) ?>"</span>
        <?php else: ?>
          <b><?= count($matches) ?> <span data-i18n>applications match</span> "<?= e($query) ?>"</b>
          <ul class="sq-list">
            <?php foreach ($matches as $m): ?>
              <li><a href="<?= e(base_url('staff/application.php?id=' . (int) $m['id'])) ?>">
                <span class="sq-who"><b translate="no"><?= e($m['reference_no']) ?></b><small><?= e($m['full_name']) ?></small></span>
                <span class="sq-tag"><?= ($m['type'] ?? '') === 'renewal' ? 'RENEWAL' : 'NEW' ?></span>
              </a></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="td-foot">
      <div class="td-pills">
        <span class="td-pill"><b><?= count($office['board']['expected'] ?? []) ?></b> <span data-i18n>expected</span></span>
        <span class="td-pill"><b><?= count($office['board']['done'] ?? []) ?></b> <span data-i18n>done today</span></span>
      </div>
      <a class="td-more" href="<?= e(base_url('staff/appointments.php')) ?>"><span data-i18n>See today's work</span> <?= ui_icon('chevron-right', 16) ?></a>
    </div>
  </section>

  <div class="wt-grid">
    <?php foreach ($lists as $key => [$title, $href, $icon]): $total = (int) ($work[$key]['total'] ?? 0); ?>
      <a class="wt-tile<?= $total > 0 ? ' has' : '' ?>" href="<?= e($href) ?>">
        <span class="wt-top"><span class="wt-chip"><?= ui_icon($icon, 24) ?></span><?= ui_icon('chevron-right', 20) ?></span>
        <span class="wt-n"><?= $total ?></span>
        <span class="wt-t" data-i18n><?= e($title) ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <nav class="wt-shortcuts" aria-label="Shortcuts">
    <a class="is-main" href="<?= base_url('staff/index.php') ?>"><?= ui_icon('file-text', 17) ?><span data-i18n>View All Applications</span></a>
    <a href="<?= base_url('staff/appointments.php') ?>"><?= ui_icon('calendar-check', 17) ?><span data-i18n>Appointment Management</span></a>
    <a href="<?= base_url('staff/applicants.php') ?>"><?= ui_icon('user', 17) ?><span data-i18n>Solo Parent Records</span></a>
    <a href="<?= base_url('staff/reports.php') ?>"><?= ui_icon('chart-column', 17) ?><span data-i18n>Reports</span></a>
  </nav>
</div>
