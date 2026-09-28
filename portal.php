<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/data.php';

$validTabs = ['overview', 'login', 'apply', 'myapps', 'status'];
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, $validTabs, true)) {
    $tab = 'overview';
}

$user = current_user($pdo);
if ($tab === 'login' && $user) {
    $tab = 'overview';
}

$pageTitle = 'Solo Parent ID Services — ' . APP_NAME;
require __DIR__ . '/includes/header.php';
?>

<div class="portal-container">
  <div class="portal-tabs">
    <div class="portal-tabs-links">
      <?php
      $tabLabels = ['overview' => 'Overview', 'apply' => 'Apply Online', 'myapps' => 'My Applications', 'status' => 'Check Status'];
      foreach ($tabLabels as $key => $label):
      ?>
        <a href="<?= base_url('portal.php?tab=' . $key) ?>" class="portal-tab <?= $tab === $key ? 'active' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="portal-tabs-auth">
      <?php if ($user): ?>
        <div class="signed-in-pill">
          <div style="font-size:14px;color:var(--ink)">Signed in as <b><?= e($user['full_name']) ?></b></div>
          <form method="post" action="<?= base_url('logout.php') ?>" class="m-0">
            <?= csrf_field() ?>
            <button type="submit" class="btn-outline" style="padding:6px 14px;font-size:13px">Log out</button>
          </form>
        </div>
      <?php else: ?>
        <a href="<?= base_url('portal.php?tab=login') ?>" class="btn-navy text-decoration-none" style="padding:10px 20px">Log In / Create Account</a>
      <?php endif; ?>
    </div>
  </div>

  <?php
  switch ($tab) {
      case 'login':
          require __DIR__ . '/includes/portal/login.php';
          break;
      case 'apply':
          require __DIR__ . '/includes/portal/apply.php';
          break;
      case 'myapps':
          require __DIR__ . '/includes/portal/myapps.php';
          break;
      case 'status':
          require __DIR__ . '/includes/portal/status.php';
          break;
      default:
          require __DIR__ . '/includes/portal/overview.php';
  }
  ?>
</div>

<?php
$footerVariant = 'portal';
require __DIR__ . '/includes/footer.php';
