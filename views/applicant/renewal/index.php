<?php
/**
 * @var ?array  $renewalSource   the released ID being renewed
 * @var ?string $renewalError
 * @var array   $renewalDraft
 * @var array   $renewalDocDefs
 */
?>
        <?php if (!$renewalSource): ?>
          <div class="dash-card">
            <div class="dash-card-label">No Released ID Yet</div>
            <div style="font-size:14px;color:var(--muted);margin:8px 0 14px">You can request a renewal once your Solo Parent ID has been approved and released by MSWDO. Check your application status in the meantime.</div>
            <a href="?section=application" class="btn-outline text-decoration-none">View My Application</a>
          </div>
        <?php else: ?>

          <?php if ($renewalError): ?><div class="step-alert"><?= e($renewalError) ?></div><?php endif; ?>

          <div class="dash-card">
            <div class="dash-card-label">Renewing</div>
            <div style="font-size:14px;color:var(--ink)"><b><?= e($renewalSource['reference_no']) ?></b> · <?= e($renewalSource['category_name']) ?></div>
            <div style="font-size:13px;color:var(--muted)">Issued to <?= e($renewalSource['full_name']) ?></div>
          </div>

          <?php foreach ($renewalDocDefs as $d):
              $docKey = $d['doc_key'];
              $meta = $renewalDraft['documents'][$docKey] ?? null;
              $err = $renewalDraft['docErrors'][$docKey] ?? ($renewalDraft['docErrors']['doc_' . $docKey] ?? '');
          ?>
            <div class="doc-row <?= $err ? 'has-error' : '' ?>">
              <div style="min-width:0;flex:1 1 200px">
                <div class="doc-label"><?= e($d['label']) ?> <span class="req"><?= $d['is_required'] ? '*' : '(optional)' ?></span></div>
                <div class="doc-filename <?= $meta ? 'attached' : '' ?>"><?= $meta ? e($meta['original_name']) : 'No file selected' ?></div>
                <?php if ($err): ?><div class="doc-error"><?= e($err) ?></div><?php endif; ?>
              </div>
              <div class="doc-actions">
                <?php if ($meta): ?>
                  <form method="post" class="m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="renewal_action" value="remove_doc">
                    <input type="hidden" name="doc_key" value="<?= e($docKey) ?>">
                    <button type="submit" class="btn-doc-remove">Remove</button>
                  </form>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="m-0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="renewal_action" value="upload_doc">
                  <input type="hidden" name="doc_key" value="<?= e($docKey) ?>">
                  <label class="btn-doc-choose">
                    <?= $meta ? 'Replace' : 'Choose file' ?>
                    <input type="file" name="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" style="display:none" onchange="this.form.submit()">
                  </label>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
          <div style="font-size:12px;color:var(--faint);margin-bottom:20px">Accepted: JPG, PNG, or PDF, max 5MB each.</div>

          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="renewal_action" value="submit">
            <label class="d-flex gap-2 align-items-start" style="font-size:14px;color:var(--body-text);line-height:1.5;cursor:pointer;margin-bottom:14px">
              <input type="checkbox" name="consent" value="1" style="width:20px;height:20px;margin:1px 0 0;flex:none">
              <span>I certify that the information above is true and correct, and I consent to MSWDO Virac processing my data for this renewal.</span>
            </label>
            <?php if (!empty($renewalDraft['docErrors']['consent'])): ?><div class="field-error mb-2"><?= e($renewalDraft['docErrors']['consent']) ?></div><?php endif; ?>
            <button type="submit" class="btn-red">Submit Renewal Request</button>
          </form>

        <?php endif; ?>
