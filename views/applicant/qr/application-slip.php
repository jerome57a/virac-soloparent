<?php
/**
 * Printable QR application slip. The QR code holds only a random token
 * (as a link to the MSWDO staff verification page) -- no personal data.
 *
 * @var array   $app
 * @var ?array  $slip          the active qr_application_slips row (null once the application is rejected)
 * @var ?string $qrUri         QR image data URI (null if the QR library isn't installed)
 * @var ?string $slipCode      short code for manual entry
 * @var ?array  $appointment   booked appointment, if any
 * @var ?string $renewalOfRef  for a renewal: the reference of the ID it renews
 * @var ?string $flashMessage
 * @var bool    $canReplace    owner or MSWD staff (administrators can't replace a slip)
 */
$st = status_style($app['status']);
$expired = $slip && $slip['expires_at'] && strtotime($slip['expires_at']) < time();
?>
<div class="portal-container">
  <?php if ($flashMessage): ?><div class="info-box slip-no-print" style="max-width:480px;margin:20px auto 0" role="status"><?= e($flashMessage) ?></div><?php endif; ?>

  <div class="slip-card">
    <div class="slip-head">
      <img src="<?= asset('images/logo_virac.png') ?>" alt="Municipality of Virac" style="height:52px;object-fit:contain">
      <div>
        <div class="slip-office">Municipal Social Welfare and Development Office</div>
        <h1 class="slip-title">Solo Parent ID — <?= $app['type'] === 'renewal' ? 'Renewal' : 'Application' ?> Slip</h1>
      </div>
    </div>

    <div class="ref"><?= e($app['reference_no']) ?></div>

    <?php if (!$slip): ?>
      <div class="slip-void">This application was not approved, so its QR slip is no longer valid.</div>
    <?php elseif ($qrUri): ?>
      <img src="<?= e($qrUri) ?>" alt="QR code for MSWDO verification" class="slip-qr">
      <div class="slip-code">Slip code: <b><?= e($slipCode) ?></b></div>
      <?php if ($expired): ?><div class="slip-void">This slip expired on <?= e(format_date_long(substr($slip['expires_at'], 0, 10))) ?>.</div><?php endif; ?>
    <?php else: ?>
      <div class="slip-void">QR image unavailable on this server (QR library not installed). Slip code: <b><?= e($slipCode) ?></b></div>
    <?php endif; ?>

    <div class="slip-grid">
      <div><span>Applicant</span><?= e($app['full_name']) ?></div>
      <div><span>Application type</span><?= $app['type'] === 'renewal' ? 'ID Renewal' . ($renewalOfRef ? ' (renews ' . e($renewalOfRef) . ')' : '') : 'New Solo Parent ID' ?></div>
      <div><span>Category</span><?= e($app['category_name']) ?></div>
      <div><span>Date submitted</span><?= e(date('F j, Y', strtotime($app['submitted_at']))) ?></div>
      <div><span>Status</span><span class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></span> <small>as of <?= e(date('M j, Y')) ?></small></div>
      <?php if ($app['status'] === 'ID Released' && $app['id_expires_at']): ?><div><span>ID valid until</span><?= e(format_date_long($app['id_expires_at'])) ?></div><?php endif; ?>
      <?php if ($appointment): ?><div><span>Appointment</span><?= e(date('F j, Y g:i A', strtotime($appointment['scheduled_at']))) ?> · <?= e($appointment['location']) ?></div><?php endif; ?>
      <?php if ($slip): ?><div><span>Slip issued</span><?= e(date('F j, Y', strtotime($slip['issued_at']))) ?></div><?php endif; ?>
    </div>

    <div class="slip-instructions">
      <b>Instructions</b>
      <ol>
        <li>Bring this slip (printed or on your phone) and one valid government ID when you visit the MSWDO office<?= $appointment ? ' for your appointment' : '' ?>.</li>
        <li>Bring the original copies of the documents you uploaded; MSWDO may ask to see them.</li>
        <li>Only MSWDO staff can scan the QR code. It does not contain your personal details.</li>
        <li>Check your status any time online with your reference number and the last 4 digits of your mobile number. Don't post photos of this slip publicly — if you lose it, get a new one from your account.</li>
      </ol>
    </div>

    <div class="slip-actions slip-no-print">
      <button type="button" onclick="window.print()" class="btn-navy">🖨️ Print / Save as PDF</button>
      <?php if ($slip && $canReplace): ?>
        <form method="post" class="m-0" onsubmit="return confirm('Issue a new QR code? The current slip will stop working.')">
          <?= csrf_field() ?>
          <button type="submit" name="action" value="replace_slip" class="btn-outline">Lost it? Get a new QR</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>