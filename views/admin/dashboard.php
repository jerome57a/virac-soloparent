<?php
require_once __DIR__ . '/../../includes/icons.php';
/**
 * MSWDO admin dashboard (the approved design): four numbers, what needs attention, and the latest activity, side by side on a wide
 * screen. The setup checklist shows only until the system is set up. Everything else (the status breakdown, account / SMS / audit
 * details, quick access) is in the sidebar. ReportService supplies the counts; application links are monitoring only.
 */
$pending = (int) $stats['pendingNew'] + (int) $stats['pendingRenewals'];
$attentionRows = [
    ['Failed SMS', 'SMS that did not go through', (int) $stats['smsFailed'], base_url('admin/sms.php?status=failed'), 'message-square-warning', 'red'],
    ['Locked accounts', 'Waiting to be unlocked', (int) $userStats['locked'], base_url('admin/users.php?status=locked'), 'lock', 'amber'],
    ['ID expiry SMS not sent', 'Reminders still to send', (int) ($expiryNotSentCount ?? 0), base_url('admin/expiry_sms.php'), 'send', 'amber'],
    ['ID release date passed', 'Approved IDs still at the office', (int) ($releaseOverdueCount ?? 0), base_url('admin/applications.php?status=Approved'), 'id-card', 'amber'],
    ['Waiting more than 7 days', 'Applications with no staff action', (int) ($staleCount ?? 0), base_url('admin/applications.php?waiting=7'), 'clock', 'amber'],
];
$metrics = [
    ['Applications', (int) $stats['totalApplications'], base_url('admin/applications.php'), 'file-text', 'blue'],
    ['Awaiting review', $pending, base_url('admin/applications.php?status=Pending+Review'), 'clock', 'amber'],
    ['Solo Parents', (int) $userStats['applicants'], base_url('admin/users.php?role=applicant'), 'users', 'green'],
    ['Expiring IDs', (int) $stats['expiringIds'], base_url('admin/reports.php?type=expiring'), 'id-card', 'amber'],
];
/** Initials for an activity row: the first letter and the last digit (Staff1 -> S1), or the first letters of two words. */
$initials = static function (string $name): string {
    $words = preg_split('/[\s._-]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $second = preg_match('/(\d)$/', $name, $m) ? $m[1] : (isset($words[1]) ? mb_substr($words[1], 0, 1) : mb_substr($words[0], 1, 1));
    return mb_strtoupper(mb_substr($words[0], 0, 1) . $second);
};
$when = static function (string $at): string {
    $t = strtotime($at);
    if (date('Y-m-d', $t) === date('Y-m-d')) { return date('g:i A', $t); }
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) { return 'Yesterday'; }
    return date('M j', $t);
};
?>
<link rel="stylesheet" href="<?= public_asset('css/guidance.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/admin-dashboard.css') ?>">
<div class="portal-container admin-dashboard adm" id="admin-dashboard">
  <h1 class="visually-hidden" data-i18n>MSWDO Admin Dashboard</h1>

  <?php if (!empty($setup) && $setup['done'] < $setup['total']): ?>
  <section class="gi-setup" id="admin-setup" aria-labelledby="admin-setup-title">
    <div class="gi-setup-head"><span class="gi-chip gi-lg"><?= ui_icon('clipboard-check', 24) ?></span><div><h2 id="admin-setup-title" data-i18n>Set up the system</h2><p><?= (int) $setup['done'] ?> <span data-i18n>of</span> <?= (int) $setup['total'] ?> <span data-i18n>steps done. Finish these once; this box then disappears.</span></p></div></div>
    <div class="gi-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= (int) $setup['total'] ?>" aria-valuenow="<?= (int) $setup['done'] ?>"><i style="width:<?= (int) round($setup['done'] / max(1, $setup['total']) * 100) ?>%"></i></div>
    <ul class="gi-checklist">
      <?php foreach ($setup['steps'] as $setupStep): ?>
        <li class="gi-check<?= $setupStep['done'] ? ' is-done' : '' ?>"><span class="gi-box"><?= ui_icon($setupStep['done'] ? 'circle-check' : 'circle-dot', 22) ?></span><span class="gi-check-text"><strong data-i18n><?= e($setupStep['label']) ?></strong><small data-i18n><?= e($setupStep['hint']) ?></small></span><?php if (!$setupStep['done']): ?><a href="<?= e($setupStep['href']) ?>"><span data-i18n>Open</span><?= ui_icon('arrow-right', 16) ?></a><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <div class="adm-metrics" id="admin-overview">
    <?php foreach ($metrics as [$label, $value, $href, $icon, $tone]): ?>
      <a class="adm-metric" href="<?= e($href) ?>"><span class="adm-chip is-<?= e($tone) ?>"><?= ui_icon($icon, 24) ?></span><span><small data-i18n><?= e($label) ?></small><strong><?= number_format($value) ?></strong></span></a>
    <?php endforeach; ?>
  </div>

  <div class="adm-cols">
    <section class="adm-panel" id="admin-attention" aria-labelledby="admin-attention-title">
      <div class="adm-panel-h"><h2 id="admin-attention-title" data-i18n>Needs attention</h2></div>
      <div class="adm-list">
        <?php foreach ($attentionRows as [$label, $hint, $count, $href, $icon, $tone]): ?>
          <a class="adm-item" href="<?= e($href) ?>"><span class="adm-chip sm<?= $count > 0 ? ' is-' . e($tone) : '' ?>"><?= ui_icon($icon, 20) ?></span><span class="adm-item-t"><b data-i18n><?= e($label) ?></b><small data-i18n><?= e($hint) ?></small></span><span class="adm-count<?= $count === 0 ? ' is-zero' : '' ?>"><?= number_format($count) ?></span><?= ui_icon('chevron-right', 18) ?></a>
        <?php endforeach; ?>
      </div>
    </section>
    <section class="adm-panel" id="admin-activity" aria-labelledby="admin-activity-title">
      <div class="adm-panel-h"><h2 id="admin-activity-title" data-i18n>Recent activity</h2><a class="adm-more" href="<?= base_url('admin/audit_log.php') ?>"><span data-i18n>Audit log</span><?= ui_icon('chevron-right', 16) ?></a></div>
      <?php if (!$recentActivity): ?>
        <p class="adm-empty" data-i18n>No activity yet.</p>
      <?php else: ?>
        <div class="adm-list">
          <?php foreach (array_slice($recentActivity, 0, 5) as $log): $actor = (string) ($log['actor_name'] ?? 'System'); ?>
            <div class="adm-item adm-act"><span class="adm-avatar" aria-hidden="true"><?= e($initials($actor)) ?></span><span class="adm-item-t"><b><?= e($log['action']) ?></b><small><?= e($actor) ?></small></span><time datetime="<?= e(date('c', strtotime($log['created_at']))) ?>"><?= e($when((string) $log['created_at'])) ?></time></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>
