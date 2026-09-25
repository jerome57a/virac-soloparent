<?php
/** @var PDO $pdo */
$query = trim((string) ($_GET['ref'] ?? ''));
$result = null;
$notFound = false;

if ($query !== '') {
    $stmt = $pdo->prepare('SELECT * FROM applications WHERE reference_no = ?');
    $stmt->execute([$query]);
    $result = $stmt->fetch();
    $notFound = !$result;
}
?>
<div style="max-width:520px">
  <div style="font-size:14px;color:var(--muted);margin-bottom:14px">Enter your application reference number to check its status. No login required.</div>
  <form method="get" class="d-flex gap-2 mb-4">
    <input type="hidden" name="tab" value="status">
    <input type="text" name="ref" placeholder="e.g. VIRAC-SP-2026-0001" value="<?= e($query) ?>" class="form-control" style="flex:1;padding:11px;border:1px solid var(--input-border);border-radius:6px">
    <button type="submit" class="btn-navy" style="padding:11px 20px">Check</button>
  </form>

  <?php if ($result): $st = status_style($result['status']); ?>
    <div style="border:1px solid var(--border);border-radius:8px;padding:18px 20px">
      <div style="font-weight:700;font-size:15px;margin-bottom:6px;color:var(--ink)"><?= e($result['reference_no']) ?></div>
      <div style="font-size:13px;color:var(--muted);margin-bottom:10px">Submitted <?= e(date('M j, Y', strtotime($result['submitted_at']))) ?> · Applicant: <?= e(mask_name($result['full_name'])) ?></div>
      <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($result['status']) ?></div>
    </div>
  <?php elseif ($notFound): ?>
    <div style="font-size:14px;color:var(--red)">No application found with that reference number.</div>
  <?php endif; ?>
</div>
