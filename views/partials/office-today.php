<?php
/**
 * "Today at the office" (staff dashboard and Appointment Management): the check-in box, then who is waiting (arrival order), who is
 * expected but not here yet, and who is done today. Typing a reference number (or its last 4 digits, a name or a mobile number)
 * shows one card to compare with the Solo Parent's valid ID; "Arrived" records the time and opens the interview result.
 * MSWDO admins see the lists only.
 *
 * @var array  $staff
 * @var array  $office        OfficeCheckInService::panel(): board {waiting, expected, done, ready}, query, matches, situation
 * @var string $officeReturn  dashboard | appointments
 * @var bool   $officeCompact (dashboard) the box, one line of counts and the next person only; the full lists are on Appointment Management
 */
require_once APP_ROOT . '/includes/icons.php';
require_once APP_ROOT . '/services/SoloParentChildRules.php';
$board = $office['board'];
$canCheckIn = ($staff['role'] ?? '') === 'mswd_social_worker' && $board['ready'];
$page = base_url($officeReturn === 'appointments' ? 'staff/appointments.php' : 'staff/dashboard.php');
$open = fn(int $appId, string $anchor = 'decision') => application_url($staff, $appId) . '#' . $anchor;
$today = date('Y-m-d');
$sit = $office['situation'];
$compact = !empty($officeCompact);
$listPage = base_url($staff['role'] === 'admin' ? 'admin/appointments.php' : 'staff/appointments.php');
?>
<link rel="stylesheet" href="<?= public_asset('css/office-today.css') ?>">
<section class="ot<?= $compact ? ' is-compact' : '' ?>" id="office-today" aria-labelledby="ot-title">
  <div class="ot-h"><h2 id="ot-title" data-i18n>Today at the office</h2><small><?= e(date('l, F j')) ?> · <span data-i18n>office hours 7:00 AM to 5:00 PM</span></small></div>

  <?php if ($canCheckIn): ?>
  <form class="ot-checkin" method="get" action="<?= e($page) ?>#office-today" role="search">
    <label for="ot-ref" data-i18n>Open a Solo Parent's application</label>
    <input class="ot-field" id="ot-ref" name="checkin" value="<?= e((string) ($office['query'] ?? '')) ?>" maxlength="60" autocomplete="off" placeholder="Reference number, e.g. VIRAC-SP-2026-0062 or 0062" data-i18n-attrs="placeholder">
    <button class="btn-navy ot-find" type="submit" data-i18n>Open</button>
    <span class="ot-hint" data-i18n>You can also type a name or mobile number.</span>
  </form>
  <?php elseif (($staff['role'] ?? '') === 'mswd_social_worker'): ?>
  <p class="ot-note" data-i18n>Check-in is not set up yet (database/migrate_office_checkin.php). Today's interviews are listed below.</p>
  <?php endif; ?>

  <?php if ($canCheckIn && $office['query'] !== null): ?>
    <div class="ot-result" role="region" aria-label="Check-in result">
    <?php if (!$office['matches']): ?>
      <div class="ot-case is-err"><b data-i18n>Not found</b><p>No application matches "<?= e($office['query']) ?>". Check the number, or search by name or mobile number.</p><a class="btn-outline ot-btn" href="<?= e($page) ?>#office-today" data-i18n>Clear</a></div>
    <?php elseif (count($office['matches']) > 1): ?>
      <div class="ot-case is-info"><b><?= count($office['matches']) ?> applications match "<?= e($office['query']) ?>"</b><p data-i18n>Choose the Solo Parent to open.</p>
        <ul class="ot-pick">
          <?php foreach ($office['matches'] as $m): ?>
            <li><a href="<?= e(application_url($staff, (int) $m['id'])) ?>"><b translate="no"><?= e($m['reference_no']) ?></b> · <?= e($m['full_name']) ?> <small><?= e($m['status']) ?><?= $m['type'] === 'renewal' ? ' · Renewal' : '' ?></small></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($compact): $next = $board['waiting'][0] ?? null; ?>
  <div class="ot-line">
    <div class="ot-line-c">
      <a href="<?= e($listPage) ?>#staff-today-card"><b><?= count($board['waiting']) ?></b> <span data-i18n>waiting</span></a>
      <a href="<?= e($listPage) ?>#staff-today-card"><b><?= count($board['expected']) ?></b> <span data-i18n>expected</span></a>
      <a href="<?= e($listPage) ?>#staff-today-card"><b><?= count($board['done']) ?></b> <span data-i18n>done today</span></a>
    </div>
    <a class="ot-all" href="<?= e($listPage) ?>"><span data-i18n>See today's work</span> →</a>
  </div>
  <?php if ($next): ?>
  <div class="ot-next"><small data-i18n>Next</small><span class="ot-qn">1</span>
    <div class="ot-who"><b><span translate="no"><?= e($next['reference_no']) ?></span> · <?= e($next['full_name']) ?></b><small>Arrived <?= e(date('g:i A', strtotime((string) $next['arrived_at']))) ?> · <?= $next['type'] === 'renewal' ? 'renewal' : 'new application' ?></small></div>
    <a class="btn-navy ot-btn" href="<?= e($open((int) $next['application_id'])) ?>" data-i18n><?= $canCheckIn ? 'Open interview' : 'Open' ?></a>
  </div>
  <?php endif; ?>
</section>
<?php return; endif; ?>

  <div class="ot-counts">
    <div><strong><?= count($board['waiting']) ?></strong><span data-i18n>Waiting</span></div>
    <div><strong><?= count($board['expected']) ?></strong><span data-i18n>Expected, not here yet</span></div>
    <div><strong><?= count($board['done']) ?></strong><span data-i18n>Done today</span></div>
  </div>

  <div class="ot-lh" id="ot-waiting"><span data-i18n>Waiting</span><small data-i18n>first come, first served</small></div>
  <?php if (!$board['waiting']): ?><div class="ot-empty" data-i18n>No one is waiting.</div><?php endif; ?>
  <?php foreach ($board['waiting'] as $i => $r): ?>
    <div class="ot-row"><span class="ot-qn"><?= $i + 1 ?></span>
      <div class="ot-who"><b><span translate="no"><?= e($r['reference_no']) ?></span> · <?= e($r['full_name']) ?></b><small>Arrived <?= e(date('g:i A', strtotime((string) $r['arrived_at']))) ?> · <?= $r['type'] === 'renewal' ? '<span class="ot-pill is-info">Renewal</span>' : 'new application' ?><?= $r['type'] !== 'renewal' && !empty($r['category_name']) ? ' · ' . e($r['category_name']) : '' ?></small></div>
      <div class="ot-acts"><a class="btn-navy ot-btn" href="<?= e($open((int) $r['application_id'])) ?>" data-i18n><?= $canCheckIn ? 'Open interview' : 'Open' ?></a></div>
    </div>
  <?php endforeach; ?>

  <div class="ot-lh" id="ot-expected"><span data-i18n>Expected, not here yet</span><small data-i18n>booked for today</small></div>
  <?php if (!$board['expected']): ?><div class="ot-empty" data-i18n>No one else is booked for today.</div><?php endif; ?>
  <?php foreach ($board['expected'] as $r): ?>
    <div class="ot-row"><span class="ot-qn is-dim" aria-hidden="true">·</span>
      <div class="ot-who"><b><span translate="no"><?= e($r['reference_no']) ?></span> · <?= e($r['full_name']) ?></b><small><?= substr((string) $r['scheduled_at'], 11, 5) === '00:00' ? 'Any time today' : e(date('g:i A', strtotime((string) $r['scheduled_at']))) ?><?= $r['type'] === 'renewal' ? ' · Renewal' : '' ?></small></div>
      <div class="ot-acts"><a class="btn-outline ot-btn" href="<?= e(application_url($staff, (int) $r['application_id'])) ?>" data-i18n>Open</a></div>
    </div>
  <?php endforeach; ?>

  <div class="ot-lh" id="ot-done"><span data-i18n>Done today</span></div>
  <?php if (!$board['done']): ?><div class="ot-empty" data-i18n>No interview result recorded today yet.</div><?php endif; ?>
  <?php foreach ($board['done'] as $r): ?>
    <div class="ot-row"><span class="ot-qn is-dim" aria-hidden="true">✓</span>
      <div class="ot-who"><b><span translate="no"><?= e($r['reference_no']) ?></span> · <?= e($r['full_name']) ?></b><small><?= !empty($r['arrived_at']) ? 'Arrived ' . e(date('g:i A', strtotime((string) $r['arrived_at']))) . ' · ' : '' ?>result recorded <?= e(date('g:i A', strtotime((string) $r['result_recorded_at']))) ?></small></div>
      <div class="ot-acts"><span class="ot-pill is-<?= ($r['outcome'] ?? '') === 'Successful' ? 'ok' : 'err' ?>" data-i18n><?= e((string) ($r['outcome'] ?? 'Recorded')) ?></span></div>
    </div>
  <?php endforeach; ?>
</section>
