<?php
/**
 * @var array $myApplications
 * @var array $myDocumentsByApp   reference_no => uploaded document rows
 * @var array $requirementsByApp  application id => requirement rows
 * @var array $editableStatuses  application statuses in which documents can still be uploaded/replaced
 * @var array $lockedStatuses    document statuses that can no longer be replaced (already verified)
 */
?>
      <?php if (!$myApplications): ?>
        <div class="dash-card">
          <div style="font-size:14px;color:var(--muted)">No documents to show yet. Documents you upload during your application will appear here.</div>
        </div>
      <?php else: ?>
        <div style="font-size:12px;color:var(--faint);margin-bottom:12px">Accepted: <?= e(document_rules()['label']) ?>, max <?= e(format_mb(document_rules()['max_mb'])) ?> each.</div>
        <?php foreach ($myApplications as $app):
            $uploaded = array_column($myDocumentsByApp[$app['reference_no']] ?? [], null, 'doc_key');
            $editable = in_array($app['status'], $editableStatuses, true);
        ?>
          <div class="review-card">
            <div class="review-card-head">
              <div class="title"><?= e($app['reference_no']) ?> <span style="font-weight:400;color:var(--muted);font-size:13px">· <?= e($app['category_name']) ?></span></div>
            </div>
            <?php foreach ($requirementsByApp[$app['id']] as $r): $has = $uploaded[$r['doc_key']] ?? null; ?>
              <?php $needsAction = $has ? in_array($has['verification_status'], ['NEEDS_CORRECTION', 'REJECTED'], true) : (bool) $r['is_required']; ?>
              <div class="doc-row <?= $needsAction ? 'has-error' : '' ?>">
                <div style="min-width:0;flex:1 1 200px">
                  <div class="doc-label"><?= e($r['label']) ?> <span class="req"><?= $r['is_required'] ? '*' : '(optional)' ?></span></div>
                  <?php if ($has): ?>
                    <div class="doc-filename attached"><?= e($has['original_name']) ?> · <?= e(format_bytes((int) $has['size_bytes'])) ?></div>
                    <?= document_status_pill($has['verification_status'], 'font-size:11px') ?>
                    <?php if ($has['staff_remarks']): ?><div class="doc-error">📝 <?= e($has['staff_remarks']) ?></div><?php endif; ?>
                  <?php else: ?>
                    <div class="doc-filename"><?= $r['is_required'] ? 'Missing' : 'Not uploaded (optional)' ?></div>
                  <?php endif; ?>
                </div>
                <div class="doc-actions">
                  <?php if ($has): ?>
                    <a href="<?= base_url('download.php?doc_id=' . (int) $has['id']) ?>" target="_blank" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px">View</a>
                  <?php endif; ?>
                  <?php if ($editable && !($has && in_array($has['verification_status'], $lockedStatuses, true))): ?>
                    <form method="post" enctype="multipart/form-data" class="m-0">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="upload_doc">
                      <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                      <input type="hidden" name="doc_key" value="<?= e($r['doc_key']) ?>">
                      <label class="btn-doc-choose">
                        <?= $has ? 'Replace' : 'Choose file' ?>
                        <input type="file" name="file" accept="<?= e(document_rules()['accept']) ?>" class="visually-hidden" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                      </label>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
