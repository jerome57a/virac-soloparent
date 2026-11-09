<?php
/**
 * Staff QR verification: scan a slip's QR code or type its slip code /
 * reference number, and see whether it is Valid, Expired, Invalid or
 * Unknown, with only the details needed at the counter.
 *
 * @var array   $staff
 * @var string  $ownDashboard
 * @var string  $error
 * @var ?array  $outcome  QrVerificationService::verify() result
 * @var string  $input    what was scanned / typed
 */
$banners = [
    'VALID'   => ['✓', 'Valid', 'qr-valid'],
    'EXPIRED' => ['⏱', 'Expired', 'qr-expired'],
    'INVALID' => ['✗', 'Invalid', 'qr-invalid'],
    'UNKNOWN' => ['?', 'Unknown QR', 'qr-unknown'],
];
?>
<link rel="stylesheet" href="<?= public_asset('css/verification-id.css') ?>">
<div class="portal-container" style="max-width:600px">
  <?php require APP_ROOT . '/views/partials/staff-page-heading.php'; ?>
  <div style="font-size:13px;color:var(--muted);margin-bottom:20px">Scan the QR code on an applicant's slip or Solo Parent ID to confirm it is authentic, or type the slip code printed under the QR (or their reference number).</div>

  <?php if ($error): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>

  <?php if ($outcome): [$icon, $label, $class] = $banners[$outcome['result']]; $a = $outcome['app']; ?>
    <?php
    $releaseScheduled = $a && $a['status'] === 'Approved' && !empty($a['id_release_schedule']);
    if ($outcome['result'] === 'VALID' && $a && $a['status'] !== 'ID Released') {
        $label = $releaseScheduled ? 'Valid ID record - Release Scheduled' : 'Valid application slip';
    }
    ?>
    <div class="qr-result <?= $class ?>" role="status" aria-live="polite">
      <div class="qr-result-head"><span class="qr-result-icon" aria-hidden="true"><?= $icon ?></span> <?= e($label) ?></div>
      <div class="qr-result-msg"><?= e($outcome['message']) ?></div>
      <?php if ($a): $st = status_style($a['status']); ?>
        <?php
        if ($a['status'] === 'ID Released' || $releaseScheduled) {
            require APP_ROOT . '/views/staff/qr/id-card.php';
        } else {
            require APP_ROOT . '/views/staff/qr/application-details.php';
        }
        ?>
        <?php if ($releaseScheduled): ?>
          <div class="info-box mt-3" style="max-width:none"><b>Release Scheduled</b><br><?= e(date('F j, Y g:i A', strtotime($a['id_release_schedule']['scheduled_at']))) ?> at <?= e($a['id_release_schedule']['location']) ?><br><span class="small">The ID is awaiting physical handover. Confirm release in the application record after the applicant receives it.</span></div>
        <?php endif; ?>
        <div style="font-size:12px;color:var(--muted);margin-top:8px">Check the name against the applicant's valid government ID.</div>
        <a href="<?= application_url($staff, (int) $a['id']) ?>" class="btn-outline text-decoration-none mt-2" style="display:inline-block;padding:6px 14px;font-size:13px">Open full record</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <nav class="staff-section-links" aria-label="Verification methods" data-i18n-attrs="aria-label"><a href="#staff-qr-camera" data-i18n>Scan QR Code</a><a href="#staff-qr-manual" data-i18n>Enter a code</a></nav>
  <div class="staff-qr-grid">
  <div class="review-card" id="staff-qr-camera">
    <div class="review-card-head"><div class="title">Scan QR Code</div></div>
    <div id="qr-reader-wrap">
      <video id="qr-video" style="width:100%;max-width:400px;border-radius:8px;background:#000;display:none" muted></video>
      <canvas id="qr-canvas" style="display:none"></canvas>
      <button type="button" id="qr-start-btn" class="btn-navy"><?= $outcome ? 'Scan Another' : 'Start Camera' ?></button>
      <div id="qr-status" style="font-size:13px;color:var(--muted);margin-top:10px"></div>
    </div>
  </div>

  <div class="review-card" id="staff-qr-manual">
    <div class="review-card-head"><div class="title">Or Enter Slip Code / Reference Number</div></div>
    <form method="get" class="d-flex flex-wrap gap-2">
      <label class="staff-code-field"><span data-i18n>Slip code or reference number</span><input type="text" name="code" maxlength="300" autocomplete="off" placeholder="Slip code or reference no." title="e.g. CF4E-30DD-744D-3804 or VIRAC-SP-2026-0001" class="form-control" style="flex:1 1 240px;padding:10px;border:1px solid var(--input-border);border-radius:6px" required></label>
      <button type="submit" class="btn-navy" data-i18n>Verify</button>
    </form>
  </div>
  </div>
</div>

<script src="<?= public_asset('js/verification-id.js') ?>"></script>
<script src="<?= public_asset('js/jsQR.js') ?>"></script>
<script>
(function () {
  var startBtn = document.getElementById('qr-start-btn');
  var video = document.getElementById('qr-video');
  var canvas = document.getElementById('qr-canvas');
  var statusEl = document.getElementById('qr-status');
  if (!startBtn || typeof jsQR === 'undefined') return;

  var scanning = false;
  var stream = null;

  function stop() {
    scanning = false;
    if (stream) {
      stream.getTracks().forEach(function (t) { t.stop(); });
      stream = null;
    }
    video.style.display = 'none';
  }

  function tick() {
    if (!scanning) return;
    if (video.readyState === video.HAVE_ENOUGH_DATA) {
      canvas.width = video.videoWidth;
      canvas.height = video.videoHeight;
      var ctx = canvas.getContext('2d');
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
      var code = jsQR(imageData.data, imageData.width, imageData.height);
      if (code && code.data) {
        statusEl.textContent = 'QR code found — verifying...';
        stop();
        window.location.href = '?code=' + encodeURIComponent(code.data);
        return;
      }
    }
    requestAnimationFrame(tick);
  }

  startBtn.addEventListener('click', function () {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      statusEl.textContent = 'Camera access is not available in this browser. Type the slip code below instead.';
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
      stream = s;
      video.srcObject = s;
      video.style.display = 'block';
      video.setAttribute('playsinline', 'true');
      video.play();
      scanning = true;
      statusEl.textContent = 'Point the camera at the QR code...';
      requestAnimationFrame(tick);
    }).catch(function () {
      statusEl.textContent = 'Could not access the camera. Check browser permissions, or type the slip code below.';
    });
  });
})();
</script>
