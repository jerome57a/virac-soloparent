<?php
/**
 * @var string $ownDashboard
 * @var string $error
 */
?>
<div class="portal-container" style="max-width:560px">
  <a href="<?= e($ownDashboard) ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <h1 class="font-heading mt-2 mb-3" style="font-size:26px">QR Verification</h1>
  <div style="font-size:13px;color:var(--muted);margin-bottom:20px">Scan an applicant's QR code (from their application slip or Solo Parent ID) or enter their reference number directly to open their record.</div>

  <?php if ($error): ?><div class="auth-error mb-3"><?= e($error) ?></div><?php endif; ?>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Scan QR Code</div></div>
    <div id="qr-reader-wrap">
      <video id="qr-video" style="width:100%;max-width:400px;border-radius:8px;background:#000;display:none"></video>
      <canvas id="qr-canvas" style="display:none"></canvas>
      <button type="button" id="qr-start-btn" class="btn-navy">Start Camera</button>
      <div id="qr-status" style="font-size:13px;color:var(--muted);margin-top:10px"></div>
    </div>
  </div>

  <div class="review-card">
    <div class="review-card-head"><div class="title">Or Enter Reference Number</div></div>
    <form method="get" class="d-flex flex-wrap gap-2">
      <input type="text" name="ref" placeholder="e.g. VIRAC-SP-2026-0001" class="form-control" style="flex:1 1 220px;padding:10px;border:1px solid var(--input-border);border-radius:6px" required>
      <button type="submit" class="btn-navy">Look Up</button>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
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
        statusEl.textContent = 'Found: ' + code.data + ' — opening record...';
        stop();
        window.location.href = '?ref=' + encodeURIComponent(code.data);
        return;
      }
    }
    requestAnimationFrame(tick);
  }

  startBtn.addEventListener('click', function () {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      statusEl.textContent = 'Camera access is not available in this browser.';
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
      stream = s;
      video.srcObject = s;
      video.style.display = 'block';
      video.setAttribute('playsinline', 'true');
      video.play();
      scanning = true;
      statusEl.textContent = 'Point the camera at a QR code...';
      requestAnimationFrame(tick);
    }).catch(function () {
      statusEl.textContent = 'Could not access the camera. Check browser permissions, or use the reference number field below.';
    });
  });
})();
</script>

