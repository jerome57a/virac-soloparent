<?php
/**
 * @var string $ownDashboard
 * -- plus the variables of views/partials/notification-list.php
 */
?>
<div class="portal-container">
  <?php require APP_ROOT . '/views/partials/admin-page-heading.php'; ?>

  <div class="row g-4">
    <div class="col-12" id="admin-inbox">
      <div class="admin-section-caption"><div><h2 data-i18n>Your notifications</h2><p data-i18n>Updates for your account.</p></div><span class="admin-count-badge"><b><?= (int) $notifPage['unread'] ?></b><span data-i18n>Unread</span></span></div>
      <?php require APP_ROOT . '/views/partials/notification-list.php'; ?>
    </div>
  </div>
</div>
