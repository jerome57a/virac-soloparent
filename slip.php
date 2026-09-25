<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/QrService.php';

$ref = trim((string) ($_GET['ref'] ?? ''));
$stmt = $pdo->prepare('SELECT a.*, c.name AS category_name FROM applications a JOIN categories c ON c.id = a.category_id WHERE a.reference_no = ?');
$stmt->execute([$ref]);
$app = $stmt->fetch();

if (!$app) {
    http_response_code(404);
    $pageTitle = 'Application not found';
    $navActive = '';
    $showSeal = false;
    require __DIR__ . '/includes/header.php';
    echo '<div class="portal-container"><p>No application found for that reference number.</p></div>';
    $footerVariant = 'portal';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$user = current_user($pdo);
$staff = current_staff($pdo);
$isOwner = $user && (int) $user['id'] === (int) $app['user_id'];
if (!$isOwner && !$staff) {
    redirect(base_url('portal.php?tab=login'));
}

$statusUrl = base_url('portal.php?tab=status&ref=' . urlencode($app['reference_no']));
$qrUri = qr_code_data_uri($statusUrl);
$st = status_style($app['status']);

$pageTitle = 'Application Slip — ' . $app['reference_no'];
$navActive = '';
$showSeal = false;
require __DIR__ . '/includes/header.php';
?>

<div class="portal-container">
  <div class="slip-card">
    <img src="<?= asset('images/logo_virac.png') ?>" alt="Municipality of Virac" style="height:56px;object-fit:contain;margin-bottom:8px">
    <div style="font-size:13px;color:var(--muted)">MSWDO Virac — Solo Parent ID Application Slip</div>
    <div class="ref"><?= e($app['reference_no']) ?></div>

    <?php if ($qrUri): ?>
      <img src="<?= e($qrUri) ?>" alt="QR code" style="width:180px;height:180px;margin:12px auto">
    <?php else: ?>
      <div style="font-size:12px;color:var(--faint);margin:16px 0">
        (QR image unavailable — run <code>composer install</code> to enable it.)<br>
        Status check link: <?= e($statusUrl) ?>
      </div>
    <?php endif; ?>

    <div class="text-start mt-3" style="font-size:14px">
      <div class="mb-1"><b>Applicant:</b> <?= e($app['full_name']) ?></div>
      <div class="mb-1"><b>Category:</b> <?= e($app['category_name']) ?></div>
      <div class="mb-1"><b>Submitted:</b> <?= e(date('F j, Y', strtotime($app['submitted_at']))) ?></div>
      <div class="mb-3"><b>Status:</b> <span class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></span></div>
    </div>

    <div style="font-size:12px;color:var(--faint)">Present this slip (printed or on your phone) when visiting the MSWDO office. Keep your reference number for status checks online.</div>

    <button type="button" class="btn-navy no-print mt-3" onclick="window.print()">Print Slip</button>
  </div>
</div>

<?php
$footerVariant = 'portal';
require __DIR__ . '/includes/footer.php';
