<?php
/** Read-only overview of each required document: whether a file was uploaded and its separate staff verification result. */
$pendingCount = count(array_filter($docs, fn($d) => $d['verification_status'] === 'Pending'));
$notUploadedCount = count(array_filter($docs, fn($d) => !$d['applicant_has'] && $d['is_required']));
?>
<div class="review-card" id="documents">
  <div class="review-card-head"><div class="title" data-i18n>Document Requirements and Verification Overview</div></div>
  <div class="info-box" style="max-width:none" data-i18n>Documents are submitted by file upload. An uploaded file is not verified until MSWDO staff review it; MSWDO staff verification is recorded separately.</div>
  <div class="doc-summary"><span><?= count($docs) - $pendingCount ?> of <?= count($docs) ?> reviewed</span><span><?= $notUploadedCount ?> required documents not yet uploaded</span><span><?= $pendingCount ?> pending</span></div>
  <?php foreach ($docs as $d): $key = $d['doc_key']; ?>
    <div class="doc-review" id="doc-<?= e($key) ?>">
      <div class="doc-label"><?= e($d['label']) ?><?= $d['is_required'] ? ' *' : ' (optional)' ?></div>
      <div class="doc-filename" data-i18n>Uploaded file: <b data-i18n><?= $d['applicant_has'] ? 'Yes' : 'Not uploaded' ?></b></div>
      <div class="doc-filename" data-i18n>MSWDO Staff status: <b><?= e($d['verification_status']) ?></b><?php if ($d['reviewed_at']): ?> · <?= e($d['verifier_name'] ?? 'Former MSWDO staff') ?> · <?= e(date('M j, Y g:i A', strtotime($d['reviewed_at']))) ?><?php endif; ?></div>
      <?php if ($d['staff_remarks']): ?><div class="doc-error">MSWDO Staff remarks: <?= e($d['staff_remarks']) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$docs): ?><div class="doc-filename" data-i18n>No document requirements configured.</div><?php endif; ?>
</div>
