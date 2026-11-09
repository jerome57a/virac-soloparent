<?php
/** Printable application/renewal slip. The QR contains a verification token, not personal details. */
$st = status_style($app['status']);
$expired = $slip && $slip['expires_at'] && strtotime($slip['expires_at']) < time();
$isOwner = !empty($user) && (int) $user['id'] === (int) $app['user_id'];
?>
<div class="portal-container application-slip-page">
  <?php if ($flashMessage): ?><div class="info-box slip-flash slip-no-print" role="status"><?= e($flashMessage) ?></div><?php endif; ?>

  <div class="slip-page-intro slip-no-print">
    <div><span class="slip-eyebrow" data-i18n>Solo Parent ID <?= $app['type'] === 'renewal' ? 'renewal' : 'application' ?></span><h1 data-i18n>Your application slip</h1><p data-i18n>Keep a copy for your MSWDO visit. Use your reference number to follow your application.</p></div>
    <?php if ($isOwner): ?><a href="<?= base_url('applicant/dashboard.php?section=application') ?>" class="slip-account-link" data-i18n>&larr; My Application</a><?php endif; ?>
  </div>

  <article class="slip-card" aria-labelledby="slip-heading">
    <header class="slip-head">
      <img src="<?= asset('images/logo_virac.png') ?>" alt="Municipality of Virac" class="slip-logo">
      <div><div class="slip-office" data-i18n>Municipal Social Welfare and Development Office</div><h2 class="slip-title" id="slip-heading" data-i18n>Solo Parent ID &mdash; <?= $app['type'] === 'renewal' ? 'Renewal' : 'Application' ?> Slip</h2><div class="slip-municipality" data-i18n>Municipality of Virac</div></div>
    </header>

    <div class="slip-reference-bar">
      <div><span class="slip-eyebrow" data-i18n>Application reference</span><div class="ref"><?= e($app['reference_no']) ?></div></div>
      <div class="slip-status"><span class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>" data-i18n><?= e($app['status']) ?></span><small>As of <?= e(date('M j, Y')) ?></small></div>
    </div>

    <div class="slip-main-grid">
      <section class="slip-qr-panel" aria-labelledby="slip-qr-heading">
        <h3 id="slip-qr-heading" data-i18n>MSWDO verification</h3>
        <?php if (!$slip): ?>
          <div class="slip-void" role="status" data-i18n>This application was not approved, so its QR slip is no longer valid.</div>
        <?php else: ?>
          <?php if ($expired): ?><div class="slip-void" role="status">This slip expired on <?= e(format_date_long(substr($slip['expires_at'], 0, 10))) ?>.</div><?php endif; ?>
          <?php if ($qrUri): ?>
            <div class="slip-qr-frame"><img src="<?= e($qrUri) ?>" alt="QR code for MSWDO verification" class="slip-qr"></div>
          <?php else: ?><div class="slip-void" role="status" data-i18n>The QR image is currently unavailable. Give your slip code to MSWDO staff for manual verification.</div><?php endif; ?>
          <div class="slip-code"><span data-i18n>Slip code</span><strong><?= e($slipCode) ?></strong></div>
          <p class="slip-qr-caption" data-i18n>Show this slip on your phone or bring a printed copy. The QR code is for MSWDO staff verification.<?php if ($app['status'] !== 'ID Released'): ?> This is an application slip, not an issued Solo Parent ID.<?php endif; ?></p>
        <?php endif; ?>
      </section>

      <section class="slip-details-panel" aria-labelledby="slip-details-heading">
        <h3 id="slip-details-heading" data-i18n>Application details</h3>
        <dl class="slip-grid" data-i18n>
          <div><dt data-i18n>Applicant</dt><dd><?= e($app['full_name']) ?></dd></div>
          <div><dt data-i18n>Application type</dt><dd data-i18n><?= $app['type'] === 'renewal' ? 'ID Renewal' : 'New Solo Parent ID' ?><?php if ($app['type'] === 'renewal' && $renewalOfRef): ?><small>Renews <?= e($renewalOfRef) ?></small><?php endif; ?></dd></div>
          <div><dt data-i18n>Category</dt><dd data-i18n><?= e($app['category_name']) ?></dd></div>
          <div><dt data-i18n>Date submitted</dt><dd><?= e(date('F j, Y', strtotime($app['submitted_at']))) ?></dd></div>
          <?php if ($app['status'] === 'ID Released' && $app['id_expires_at']): ?><div><dt data-i18n>ID valid until</dt><dd><?= e(format_date_long($app['id_expires_at'])) ?></dd></div><?php endif; ?>
          <?php if ($slip): ?><div><dt data-i18n>Slip issued</dt><dd><?= e(date('F j, Y', strtotime($slip['issued_at']))) ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($appointment): ?><div class="slip-appointment"><h4 data-i18n>Your appointment</h4><p><?= e(date('F j, Y g:i A', strtotime($appointment['scheduled_at']))) ?></p><span><?= e($appointment['location']) ?></span></div><?php endif; ?>
        <?php if ($idReleaseSchedule): ?><div class="slip-appointment"><h4 data-i18n>Your ID release appointment</h4><p><?= e(date('F j, Y g:i A', strtotime($idReleaseSchedule['scheduled_at']))) ?></p><span><?= e($idReleaseSchedule['location']) ?></span></div><?php endif; ?>
      </section>
    </div>

    <section class="slip-instructions" aria-labelledby="slip-instructions-heading">
      <h3 id="slip-instructions-heading" data-i18n>What to bring to MSWDO</h3>
      <ol>
        <li>This slip, printed or on your phone, and one valid government ID<?= $appointment || $idReleaseSchedule ? ' for your appointment' : ' when you visit' ?>.</li>
        <li data-i18n>The originals and photocopies of the documents in your checklist, as instructed by MSWDO for document review.</li>
      </ol>
      <p data-i18n>Check your status online using your reference number. Keep this slip private. If it is lost, request a replacement from your account.</p>
    </section>

    <footer class="slip-actions slip-no-print">
      <div class="slip-print-action"><button type="button" onclick="window.print()" class="btn-navy" data-i18n><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9V3h12v6M6 18H3V9h18v9h-3M6 14h12v7H6z"/></svg>Print / Save as PDF</button><span data-i18n>Choose “Save as PDF” in the print window to keep a copy.</span></div>
      <?php if ($slip && $canReplace): ?>
        <form method="post" class="m-0" onsubmit="return confirm('Issue a new QR code? The current slip will stop working.')">
          <?= csrf_field() ?>
          <button type="submit" name="action" value="replace_slip" class="btn-outline" data-i18n>Replace lost QR slip</button>
        </form>
      <?php endif; ?>
    </footer>
  </article>
</div>
