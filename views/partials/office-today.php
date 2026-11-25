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
    <label for="ot-ref" data-i18n>Check in a Solo Parent</label>
    <input class="ot-field" id="ot-ref" name="checkin" value="<?= e((string) ($office['query'] ?? '')) ?>" maxlength="60" autocomplete="off" placeholder="Reference number, e.g. VIRAC-SP-2026-0062 or 0062" data-i18n-attrs="placeholder">
    <button class="btn-navy ot-find" type="submit" data-i18n>Find</button>
    <span class="ot-hint" data-i18n>You can also type a name or mobile number. Always compare with the Solo Parent's valid ID.</span>
  </form>
  <?php elseif (($staff['role'] ?? '') === 'mswd_social_worker'): ?>
  <p class="ot-note" data-i18n>Check-in is not set up yet (database/migrate_office_checkin.php). Today's interviews are listed below.</p>
  <?php endif; ?>

  <?php if ($canCheckIn && $office['query'] !== null): ?>
    <div class="ot-result" role="region" aria-label="Check-in result">
    <?php if (!$office['matches']): ?>
      <div class="ot-case is-err"><b data-i18n>Not found</b><p>No application matches "<?= e($office['query']) ?>". Check the number, or search by name or mobile number.</p><a class="btn-outline ot-btn" href="<?= e($page) ?>#office-today" data-i18n>Clear</a></div>
    <?php elseif (count($office['matches']) > 1): ?>
      <div class="ot-case is-info"><b><?= count($office['matches']) ?> applications match "<?= e($office['query']) ?>"</b><p data-i18n>Choose the one whose name and birthdate match the valid ID.</p>
        <ul class="ot-pick">
          <?php foreach ($office['matches'] as $m): ?>
            <li><a href="<?= e($page . '?checkin=' . rawurlencode($m['reference_no'])) ?>#office-today"><b translate="no"><?= e($m['reference_no']) ?></b> · <?= e($m['full_name']) ?> <small><?= e($m['status']) ?><?= $m['type'] === 'renewal' ? ' · Renewal' : '' ?></small></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php else:
      $a = $sit['app']; $kind = $sit['kind'];
      [$tone, $badge] = match ($kind) {
          'today' => ['ok', 'Booked for today'], 'already' => ['info', 'Already checked in'],
          'other_day' => ['warn', 'Booked for ' . date('D, M j', strtotime((string) $sit['date']))], 'no_date' => ['warn', 'No interview date yet'],
          'docs' => ['err', 'Documents not verified yet'], 'done' => ['info', 'Interview already recorded'],
          'release_today' => ['ok', 'ID release today'], 'release_overdue' => ['err', 'Release date passed'],
          'release_later' => ['warn', 'Release on ' . date('M j', strtotime((string) $sit['date']))], 'release_unscheduled' => ['warn', 'Approved · no release date'],
          default => ['err', 'Nothing to do today'],
      };
      $isRelease = str_starts_with($kind, 'release_');
      $kids = array_map(function ($c) use ($today) { $age = SoloParentChildRules::age((string) $c['birthdate'], $today); $first = explode(' ', trim((string) $c['full_name']))[0]; return $first . ($age !== null ? ', ' . $age : ''); }, $sit['children']);
    ?>
      <div class="ot-confirm" role="group" aria-labelledby="ot-confirm-title">
        <div class="ot-confirm-h"><b id="ot-confirm-title" data-i18n>Is this the Solo Parent at the counter?</b><span class="ot-pill is-<?= $tone ?>"><?= e($badge) ?></span></div>
        <dl class="ot-facts">
          <div><dt data-i18n>Name</dt><dd><?= e($a['full_name']) ?></dd></div>
          <div><dt data-i18n>Reference number</dt><dd translate="no"><?= e($a['reference_no']) ?><?= $a['type'] === 'renewal' ? ' <span class="ot-pill is-info">Renewal</span>' : '' ?></dd></div>
          <?php if ($kind !== 'release_unscheduled'): ?>
          <div><dt data-i18n>Birthdate</dt><dd><?= !empty($a['birthdate']) ? e(date('F j, Y', strtotime((string) $a['birthdate']))) : '—' ?></dd></div>
          <div><dt data-i18n>Barangay</dt><dd><?= e((string) ($a['barangay'] ?? '—')) ?></dd></div>
          <div><dt data-i18n>Category</dt><dd><?= e((string) ($a['category_name'] ?? '—')) ?></dd></div>
          <div><dt data-i18n>Children</dt><dd><?= count($kids) ?><?= $kids ? ' (' . e(implode('; ', array_slice($kids, 0, 3))) . (count($kids) > 3 ? '…' : '') . ')' : '' ?></dd></div>
          <?php endif; ?>
        </dl>
        <?php if (in_array($kind, ['release_today', 'release_overdue', 'release_later'], true)): $rt = strtotime((string) $sit['release']['scheduled_at']); ?>
          <div class="ot-rel<?= $kind === 'release_overdue' ? ' is-late' : '' ?>"><span class="ot-rel-d"><small><?= e(date('M', $rt)) ?></small><b><?= e(date('j', $rt)) ?></b></span>
            <div class="ot-rel-t"><b><?= $kind === 'release_today' ? 'Release today, ' . e(date('g:i A', $rt)) : ('Release ' . ($kind === 'release_overdue' ? 'was ' : 'is scheduled for ') . e(date('l, F j', $rt))) ?></b>
              <span><?= $kind === 'release_overdue' ? 'Not collected yet. The ID kept waiting at the office.' : e((string) $sit['release']['location']) . ' · ' . e(date('g:i A', $rt)) . ($kind === 'release_later' ? '. The Solo Parent came early; staff can still hand it over today.' : '') ?></span></div></div>
        <?php endif; ?>
        <?php if ($kind === 'release_unscheduled'): ?><p class="ot-msg" data-i18n>The application is approved, but the ID release has not been scheduled. Open it to set the release date and time.</p><?php endif; ?>
        <?php if (in_array($kind, ['today', 'other_day', 'no_date', 'release_today', 'release_overdue', 'release_later'], true)): ?>
          <div class="ot-idcheck"><?= ui_icon('id-card', 18) ?><span><b data-i18n>Check the valid ID.</b> <span data-i18n><?= $isRelease ? 'The name and birthdate must match. The ID is handed only to the Solo Parent, in person.' : 'The name and birthdate must match. A reference number alone does not prove who the person is.' ?></span></span></div>
        <?php endif; ?>
        <?php if ($kind === 'other_day'): ?><p class="ot-msg">The interview is on <b><?= e(date('l, F j, Y', strtotime((string) $sit['date']))) ?></b>, not today.</p><?php endif; ?>
        <?php if ($kind === 'no_date'): ?><p class="ot-msg" data-i18n>The documents are verified, but no interview date was set. Scheduling today also sends the Solo Parent the usual interview text.</p><?php endif; ?>
        <?php if ($kind === 'docs'): ?><p class="ot-msg"><?= (int) $sit['outstanding'] ?> required document<?= (int) $sit['outstanding'] === 1 ? '' : 's' ?> still need checking. The interview can be held once they are verified.</p><?php endif; ?>
        <?php if ($kind === 'already'): ?><p class="ot-msg">Arrived <?= e(date('g:i A', strtotime((string) $sit['appointment']['arrived_at']))) ?><?= $sit['position'] ? '. Number ' . (int) $sit['position'] . ' in the waiting list.' : '.' ?></p><?php endif; ?>
        <?php if ($kind === 'done'): ?><p class="ot-msg" data-i18n>The interview result is already recorded. Open the application for the next step.</p><?php endif; ?>
        <?php if ($kind === 'closed'): ?><p class="ot-msg"><?= $a['status'] === 'ID Released' ? 'The ID was already released' . (!empty($a['id_expires_at']) ? ' (valid until ' . e(date('F j, Y', strtotime((string) $a['id_expires_at']))) . ')' : '') . '.' : 'This application is <b>' . e($a['status']) . '</b>. Open it to see why.' ?></p><?php endif; ?>
        <div class="ot-confirm-a">
          <a class="btn-outline ot-btn" href="<?= e($page) ?>#office-today" data-i18n>Not this person</a>
          <?php if (in_array($kind, ['today', 'other_day', 'no_date'], true)): $act = ['today' => 'arrive', 'other_day' => 'move_arrive', 'no_date' => 'schedule_arrive'][$kind]; ?>
            <form method="post" action="<?= e(base_url('staff/checkin.php')) ?>" class="m-0">
              <?= csrf_field() ?>
              <input type="hidden" name="application_id" value="<?= (int) $a['id'] ?>">
              <input type="hidden" name="return_to" value="<?= e($officeReturn) ?>">
              <button type="submit" name="action" value="<?= $act ?>" class="ot-arrive" data-i18n><?= $kind === 'today' ? 'Arrived · open the interview' : 'Schedule today and check in' ?></button>
            </form>
          <?php elseif ($isRelease): ?>
            <a class="btn-navy ot-btn" href="<?= e($open((int) $a['id'], 'id-release')) ?>" data-i18n>Open ID release</a>
          <?php elseif ($kind === 'docs'): ?>
            <a class="btn-navy ot-btn" href="<?= e($open((int) $a['id'], 'checklist')) ?>" data-i18n>Open the documents</a>
          <?php elseif ($kind === 'already'): ?>
            <a class="btn-navy ot-btn" href="<?= e($open((int) $a['id'])) ?>" data-i18n>Open interview</a>
          <?php else: ?>
            <a class="btn-navy ot-btn" href="<?= e(application_url($staff, (int) $a['id'])) ?>" data-i18n>Open the application</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($compact): $next = $board['waiting'][0] ?? null; ?>
  <div class="ot-line">
    <div class="ot-line-c">
      <a href="<?= e($listPage) ?>#ot-waiting"><b><?= count($board['waiting']) ?></b> <span data-i18n>waiting</span></a>
      <a href="<?= e($listPage) ?>#ot-expected"><b><?= count($board['expected']) ?></b> <span data-i18n>expected</span></a>
      <a href="<?= e($listPage) ?>#ot-done"><b><?= count($board['done']) ?></b> <span data-i18n>done today</span></a>
    </div>
    <a class="ot-all" href="<?= e($listPage) ?>#office-today"><span data-i18n>See today's list</span> →</a>
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
      <div class="ot-acts"><?php if ($canCheckIn): ?><a class="btn-outline ot-btn" href="<?= e($page . '?checkin=' . rawurlencode($r['reference_no'])) ?>#office-today" data-i18n>Check in</a><?php else: ?><a class="btn-outline ot-btn" href="<?= e(application_url($staff, (int) $r['application_id'])) ?>" data-i18n>Open</a><?php endif; ?></div>
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
