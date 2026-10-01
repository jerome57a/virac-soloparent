<?php
/**
 * Public status lookup (no login): reference number plus the last 4 digits
 * of the mobile number on the application. The applicant's name is masked.
 *
 * @var string $query
 * @var string $mobile4
 * @var ?array $result
 * @var string $error
 */
?>
<div style="max-width:560px">
  <div style="font-size:14px;color:var(--muted);margin-bottom:14px">Enter your application reference number and the last 4 digits of the mobile number you gave on your application. No login required.</div>
  <form method="get" class="d-flex flex-wrap gap-2 mb-4">
    <input type="hidden" name="tab" value="status">
    <label class="visually-hidden" for="status-ref">Reference number</label>
    <input type="text" id="status-ref" name="ref" placeholder="e.g. VIRAC-SP-2026-0001" value="<?= e($query) ?>" maxlength="40" required class="form-control" style="flex:2 1 220px;padding:11px;border:1px solid var(--input-border);border-radius:6px">
    <label class="visually-hidden" for="status-m4">Last 4 digits of your mobile number</label>
    <input type="text" id="status-m4" name="m4" placeholder="Mobile last 4" value="<?= e($mobile4) ?>" inputmode="numeric" pattern="\d{4}" maxlength="4" required autocomplete="off" class="form-control" style="flex:1 1 120px;padding:11px;border:1px solid var(--input-border);border-radius:6px">
    <button type="submit" class="btn-navy" style="padding:11px 20px">Check</button>
  </form>

  <?php if ($result): $st = status_style($result['status']); ?>
    <div style="border:1px solid var(--border);border-radius:8px;padding:18px 20px">
      <div style="font-weight:700;font-size:15px;margin-bottom:6px;color:var(--ink)"><?= e($result['reference_no']) ?></div>
      <div style="font-size:13px;color:var(--muted);margin-bottom:10px">Submitted <?= e(date('M j, Y', strtotime($result['submitted_at']))) ?> · Applicant: <?= e(mask_name($result['full_name'])) ?></div>
      <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($result['status']) ?></div>
    </div>
  <?php elseif ($error !== ''): ?>
    <div style="font-size:14px;color:var(--red)" role="alert"><?= e($error) ?></div>
  <?php endif; ?>
</div>
