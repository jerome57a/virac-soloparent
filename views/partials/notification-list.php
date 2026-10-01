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
  <div class="notif-filters" role="tablist" aria-label="Filter notifications">
    <a href="<?= base_url($baseUrl) ?>" class="<?= $unreadOnly ? '' : 'active' ?>" role="tab" aria-selected="<?= $unreadOnly ? 'false' : 'true' ?>">All</a>
    <a href="<?= base_url($baseUrl . $sep . 'filter=unread') ?>" class="<?= $unreadOnly ? 'active' : '' ?>" role="tab" aria-selected="<?= $unreadOnly ? 'true' : 'false' ?>">Unread<?= $unread ? ' (' . $unread . ')' : '' ?></a>
  </div>
  <?php if ($unread): ?>
    <form method="post" action="<?= base_url($baseUrl . ($unreadOnly ? $sep . 'filter=unread' : '')) ?>" class="m-0">
      <?= csrf_field() ?>
      <button type="submit" name="action" value="mark_all_read" class="btn-outline" style="padding:6px 14px;font-size:13px">Mark all as read</button>
    </form>
  <?php endif; ?>
</div>

<?php if (!$notifPage['rows']): ?>
  <div class="dash-card"><div style="font-size:14px;color:var(--muted)"><?= $unreadOnly ? "You're all caught up — no unread notifications." : 'No notifications yet.' ?></div></div>
<?php endif; ?>

<?php foreach ($notifPage['rows'] as $n): [$label, $icon] = NotificationService::label($n['type']); $link = NotificationService::linkFor($n, $notifRole); ?>
  <div class="notif-item <?= $n['is_read'] ? '' : 'unread' ?>">
    <div class="notif-icon" aria-hidden="true"><?= $icon ?></div>
    <div class="notif-body">
      <div class="notif-meta"><?= e($label) ?> · <?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?><?php if (!$n['is_read']): ?> · <b>New</b><?php endif; ?></div>
      <div class="notif-text"><?= e($n['message']) ?></div>
      <div class="notif-actions">
        <?php if ($link): ?><a href="<?= base_url($baseUrl . $sep . 'open=' . (int) $n['id']) ?>">View</a><?php endif; ?>
        <?php if (!$n['is_read']): ?>
          <form method="post" action="<?= base_url($baseUrl . ($unreadOnly ? $sep . 'filter=unread' : '')) ?>" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
            <button type="submit" name="action" value="mark_read" class="notif-link-btn">Mark as read</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<?php $pager = $notifPage['pager']; require APP_ROOT . '/views/partials/pagination.php'; ?>
