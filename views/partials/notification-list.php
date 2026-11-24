<?php
/**
 * Notification list shared by applicants, MSWD staff and administrators.
 *
 * @var array   $notifPage   NotificationService::page(): rows, pager, unread
 * @var bool    $unreadOnly
 * @var string  $baseUrl     app-relative URL of this page (filters/actions stay on it)
 * @var string  $notifRole   the viewer's role (decides where each notification leads)
 * @var ?string $flashMessage
 */
$sep = str_contains($baseUrl, '?') ? '&' : '?';
$unread = (int) $notifPage['unread'];
?>
<?php if ($flashMessage): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>

<div class="notif-toolbar">
  <nav class="notif-filters" aria-label="Filter notifications">
    <a href="<?= base_url($baseUrl) ?>" class="<?= $unreadOnly ? '' : 'active' ?>"<?= $unreadOnly ? '' : ' aria-current="page"' ?> data-i18n>All</a>
    <a href="<?= base_url($baseUrl . $sep . 'filter=unread') ?>" class="<?= $unreadOnly ? 'active' : '' ?>"<?= $unreadOnly ? ' aria-current="page"' : '' ?> data-i18n>Unread<?= $unread ? ' (' . $unread . ')' : '' ?></a>
  </nav>
  <?php if ($unread): ?>
    <form method="post" action="<?= base_url($baseUrl . ($unreadOnly ? $sep . 'filter=unread' : '')) ?>" class="m-0">
      <?= csrf_field() ?>
      <button type="submit" name="action" value="mark_all_read" class="btn-outline btn-sm" data-i18n>Mark all as read</button>
    </form>
  <?php endif; ?>
</div>

<?php if (!$notifPage['rows']): ?>
  <div class="empty-state" data-i18n>
    <?php if ($unreadOnly): ?>
      <div class="empty-state-title" data-i18n>You're all caught up</div>
      There are no unread notifications. <a href="<?= base_url($baseUrl) ?>" data-i18n>Show all notifications</a>
    <?php else: ?>
      <div class="empty-state-title" data-i18n>No notifications yet</div>
      Updates about applications, documents and appointments will appear here.
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php
// MSWDO staff and the admin: unread notifications that ask them to do something come first, under "Needs your action".
$rowsToShow = $notifPage['rows'];
$groups = [['title' => null, 'rows' => $rowsToShow]];
if ($notifRole !== 'applicant' && $rowsToShow) {
    $act = array_values(array_filter($rowsToShow, fn($n) => !$n['is_read'] && NotificationService::isAction($n)));
    $info = array_values(array_filter($rowsToShow, fn($n) => $n['is_read'] || !NotificationService::isAction($n)));
    $groups = [['title' => 'Needs your action', 'tone' => 'act', 'rows' => $act, 'empty' => 'Nothing needs your action right now.'], ['title' => 'For your information', 'tone' => 'info', 'rows' => $info]];
}
?>
<?php if ($notifRole === 'admin' && $notifPage['rows']): ?><div class="admin-list-scroll" role="region" aria-label="Notifications" tabindex="0" data-i18n-attrs="aria-label"><?php endif; ?>
<?php foreach ($groups as $group): ?>
<?php if ($group['title'] !== null && ($group['rows'] || !empty($group['empty']))): ?>
  <h2 class="notif-group-title is-<?= e($group['tone']) ?>"><span data-i18n><?= e($group['title']) ?></span> <span class="notif-group-count"><?= count($group['rows']) ?></span></h2>
  <?php if (!$group['rows']): ?><p class="notif-group-empty" data-i18n><?= e($group['empty']) ?></p><?php endif; ?>
<?php endif; ?>
<?php foreach ($group['rows'] as $n): [$label, $icon] = NotificationService::label($n['type']); $link = NotificationService::linkFor($n, $notifRole); ?>
  <div class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>">
    <div class="notif-icon" aria-hidden="true"><?= $icon ?></div>
    <div class="notif-body">
      <div class="notif-meta"><?= e($label) ?> · <?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?><?php if (!$n['is_read']): ?> · <b data-i18n>New</b><?php endif; ?></div>
      <div class="notif-text"><?= e($n['message']) ?></div>
      <div class="notif-actions">
        <?php if ($link): ?><a href="<?= base_url($baseUrl . $sep . 'open=' . (int) $n['id']) ?>" data-i18n><?= $notifRole !== 'applicant' && NotificationService::isAction($n) && !$n['is_read'] ? 'Open' : 'View' ?></a><?php endif; ?>
        <?php if (!$n['is_read']): ?>
          <form method="post" action="<?= base_url($baseUrl . ($unreadOnly ? $sep . 'filter=unread' : '')) ?>" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
            <button type="submit" name="action" value="mark_read" class="notif-link-btn" data-i18n>Mark as read</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php endforeach; ?>
<?php if ($notifRole === 'admin' && $notifPage['rows']): ?></div><?php endif; ?>

<?php $pager = $notifPage['pager']; require APP_ROOT . '/views/partials/pagination.php'; ?>
