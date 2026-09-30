<?php
/**
 * @var array   $app
 * @var string  $statusUrl
 * @var ?string $qrUri
 */
$st = status_style($app['status']);
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

    <button type="button" onclick="window.print()" class="btn-navy slip-print-btn" style="margin-top:16px">🖨️ Print / Download</button>
  </div>
</div>

