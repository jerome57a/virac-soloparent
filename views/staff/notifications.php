<?php
/** @var string $ownDashboard  -- plus the variables of views/partials/notification-list.php */
?>
<div class="portal-container" style="max-width:760px">
  <a href="<?= e($ownDashboard) ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <h1 class="font-heading mt-2 mb-3" style="font-size:26px">Notifications</h1>
  <?php require APP_ROOT . '/views/partials/notification-list.php'; ?>
</div>