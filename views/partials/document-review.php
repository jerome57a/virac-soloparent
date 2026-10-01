<?php
/**
 * Documents card on an application page: each requirement with its file,
 * preview, status, verifier and full status trail. MSWD staff also get the
 * verification form; administrators see the same card read-only.
 *
 * @var array $docs             Document::forReview() rows (id NULL = not uploaded)
 * @var array $docHistory       document_id => status trail rows, oldest first
 * @var bool  $canVerify        show the verification forms
 * @var bool  $documentsEditable application still in progress (verdicts allowed)
 * @var array $verificationStatuses (when $canVerify)
 * @var array $documentErrors   doc id => error message from the last save (when $canVerify)
 */
$documentErrors = $documentErrors ?? [];
$uploadedDocs = array_filter($docs, fn($d) => $d['id'] !== null);
$counts = array_count_values(array_column($uploadedDocs, 'verification_status'));
$missingRequired = array_filter($docs, fn($d) => $d['id'] === null && $d['is_required']);
// Errors shown beside a document's form stay there; any other (unknown document, frozen application) goes at the top.
$formDocIds = $canVerify && $documentsEditable ? array_map('intval', array_column($uploadedDocs, 'id')) : [];
$generalDocErrors = array_diff_key($documentErrors, array_flip($formDocIds));
?>
<div class="review-card" id="documents">
  <div class="review-card-head">
    <div class="title">Documents</div>
    <div style="font-size:12px;color:var(--muted)">
      <?= (int) ($counts['VERIFIED'] ?? 0) ?> of <?= count($uploadedDocs) ?> verified<?php
      foreach (['NEEDS_CORRECTION', 'REJECTED', 'FOR_VERIFICATION'] as $code) {
          if (!empty($counts[$code])) {
              echo ' · ' . (int) $counts[$code] . ' ' . e(strtolower(document_status_label($code)));
          }
      } ?>
    </div>
  </div>

  <?php foreach ($generalDocErrors as $message): ?>
    <div class="step-alert" role="alert"><?= e($message) ?></div>
  <?php endforeach; ?>
  <?php if ($missingRequired): ?>
    <div class="step-alert">Missing required document<?= count($missingRequired) === 1 ? '' : 's' ?>: <?= e(implode(', ', array_column($missingRequired, 'label'))) ?></div>
  <?php endif; ?>
  <?php if ($canVerify && !$documentsEditable): ?>
    <div style="font-size:13px;color:var(--muted);margin-bottom:10px">This application has a final decision, so document verdicts are frozen.</div>
  <?php endif; ?>

  <?php foreach ($docs as $d): $docId = $d['id'] !== null ? (int) $d['id'] : null; ?>
    <div class="doc-review" <?= $docId ? 'id="doc-' . $docId . '"' : '' ?>>
      <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <div style="min-width:0;flex:1 1 240px">
          <?php if ($d['category_specific']): ?><div class="doc-tag">CATEGORY REQUIREMENT</div><?php endif; ?>
          <div class="doc-label"><?= e($d['label']) ?> <span class="req"><?= $d['is_required'] ? '*' : '(optional)' ?></span></div>
          <?php if ($docId): ?>
            <div class="doc-filename"><?= e($d['original_name']) ?> · <?= e(strtoupper(pathinfo($d['stored_path'], PATHINFO_EXTENSION))) ?> · <?= e(format_bytes((int) $d['size_bytes'])) ?> · uploaded <?= e(date('M j, Y g:i A', strtotime($d['uploaded_at']))) ?></div>
          <?php else: ?>
            <div class="doc-filename"><?= $d['is_required'] ? 'Not uploaded' : 'Not uploaded (optional)' ?></div>
          <?php endif; ?>
        </div>
        <?php if ($docId): ?>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <?= document_status_pill($d['verification_status']) ?>
            <a href="<?= base_url('download.php?doc_id=' . $docId) ?>" target="_blank" rel="noopener" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px">Open</a>
            <a href="<?= base_url('download.php?doc_id=' . $docId . '&download=1') ?>" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px">Download</a>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($docId): ?>
        <?php if (str_starts_with($d['mime'], 'image/')): ?>
          <a href="<?= base_url('download.php?doc_id=' . $docId) ?>" target="_blank" rel="noopener" class="doc-preview"><img src="<?= base_url('download.php?doc_id=' . $docId) ?>" alt="Preview of <?= e($d['label']) ?>" loading="lazy"></a>
        <?php endif; ?>

        <?php if ($d['verified_by'] || $d['verified_at']): ?>
          <div class="doc-verifier"><?= e(document_status_label($d['verification_status'])) ?> by <?= e($d['verifier_name'] ?? 'a former staff member') ?> on <?= e(date('M j, Y g:i A', strtotime($d['verified_at']))) ?></div>
        <?php endif; ?>
        <?php if ($d['staff_remarks']): ?><div class="doc-error">📝 <?= e($d['staff_remarks']) ?></div><?php endif; ?>

        <?php if ($canVerify && $documentsEditable): ?>
          <form method="post" action="#doc-<?= $docId ?>" class="doc-verify-form">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update_document_status">
            <input type="hidden" name="doc_id" value="<?= $docId ?>">
            <input type="hidden" name="expected_status" value="<?= e($d['verification_status']) ?>">
            <select name="verification_status" class="form-select" aria-label="Verification status for <?= e($d['label']) ?>">
              <?php foreach ($verificationStatuses as $vs): ?>
                <option value="<?= e($vs) ?>" <?= $d['verification_status'] === $vs ? 'selected' : '' ?>><?= e(document_status_label($vs)) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="staff_remarks" maxlength="500" value="<?= e($d['staff_remarks'] ?? '') ?>" placeholder="Remarks for the applicant" title="Required when marking Needs Correction or Rejected" class="form-control" aria-label="Remarks for <?= e($d['label']) ?>">
            <button type="submit" class="btn-navy">Save</button>
          </form>
          <?php if (!empty($documentErrors[$docId])): ?><div class="field-error mt-1" role="alert"><?= e($documentErrors[$docId]) ?></div><?php endif; ?>
        <?php endif; ?>

        <?php $trail = $docHistory[$docId] ?? []; if ($trail): ?>
          <details class="doc-trail">
            <summary>History (<?= count($trail) ?>)</summary>
            <ol>
              <?php foreach (array_reverse($trail) as $h): ?>
                <li>
                  <span class="when"><?= e(date('M j, Y g:i A', strtotime($h['created_at']))) ?></span>
                  <?= e($h['actor_name']) ?> <span class="who">(<?= e(User::ROLE_LABELS[$h['actor_role']] ?? $h['actor_role']) ?>)</span>:
                  <?= $h['from_status'] ? e(document_status_label($h['from_status'])) . ' → ' : 'Submitted as ' ?><b><?= e(document_status_label($h['to_status'])) ?></b>
                  <?php if ($h['remarks']): ?><div class="note"><?= e($h['remarks']) ?></div><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ol>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$docs): ?><div style="font-size:13px;color:var(--muted)">No documents on file.</div><?php endif; ?>
</div>
