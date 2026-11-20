<?php /** Renew an existing ID without repeating the new application wizard. */ ?>
<?php if (!$renewalSource): ?>
  <div class="info-box" data-i18n>You need a released ID before renewing. <a href="?section=application" data-i18n>View My Application</a>.</div>
<?php else: $w = $renewalWindow; ?>
  <div class="review-card">
    <div class="review-card-head"><div class="title" data-i18n>Renew your existing Solo Parent ID</div></div>
    <div class="review-grid" data-i18n>
      <?php foreach (['Reference'=>'reference_no','Name'=>'full_name','Category'=>'category_name'] as $label=>$key): ?>
        <div class="review-row"><div class="k" data-i18n><?= e($label) ?></div><div class="v"><?= e($renewalSource[$key]) ?></div></div>
      <?php endforeach; ?>
      <div class="review-row"><div class="k" data-i18n>ID expires</div><div class="v"><?= $w['expiresAt'] ? e(format_date_long($w['expiresAt'])) : 'Not recorded' ?></div></div>
      <div class="review-row"><div class="k" data-i18n>Children on file</div><div class="v"><?= e(implode(', ',array_column($renewalSource['children'],'full_name'))) ?></div></div>
    </div>
    <p class="mt-3" data-i18n>Your existing identity, children and accepted documents stay on file. Provide only the category renewal documents and replacements for expired documents. MSWDO staff review and approve renewal; a new interview is not required.</p>
  </div>
  <?php if ($renewalInProgress): ?>
    <div class="info-box" data-i18n>Renewal <?= e($renewalInProgress['reference_no']) ?> is already being processed. <a href="?section=renewal&amp;type=status" data-i18n>View Renewal Status</a>.</div>
  <?php elseif (!$w['open']): ?>
    <div class="info-box" data-i18n>Renewal opens on <?= e(format_date_long($w['opensOn'])) ?>, <?= (int) $w['windowDays'] ?> days before expiry.</div>
  <?php elseif (empty($renewalPlan['configured'])): ?>
    <div class="info-box" data-i18n>Renewal requirements for your category are not configured yet. <a href="?section=help" data-i18n>Contact MSWDO for the correct renewal documents</a>. You do not need to repeat the new-application documents.</div>
  <?php else: ?>
    <?php if ($flashMessage): ?><div class="info-box" role="status"><?= e($flashMessage) ?></div><?php endif; ?>
    <?php if ($submitError): ?><div class="auth-error" role="alert"><?= e($submitError) ?></div><?php endif; ?>
    <?php foreach ($errors as $message): ?><div class="auth-error" role="alert"><?= e($message) ?></div><?php endforeach; ?>
    <form method="post" enctype="multipart/form-data" class="review-card d-flex flex-column gap-3">
      <?= csrf_field() ?>
      <h2 class="font-heading" style="font-size:20px" data-i18n>Confirm your contact details</h2>
      <div class="field-grid">
        <label class="user-label" data-i18n>Barangay<input class="form-control" name="barangay" maxlength="100" required value="<?= e($draft['barangay']) ?>"></label>
        <label class="user-label" data-i18n>Complete address<input class="form-control" name="address" maxlength="255" required value="<?= e($draft['address']) ?>"></label>
        <label class="user-label" data-i18n>Confirmed mobile<input class="form-control" readonly value="<?= e($draft['contact']) ?>"><span class="opt" data-i18n>Change your number on My Profile.</span></label>
        <label class="user-label" data-i18n>Email (optional)<input class="form-control" type="email" name="email" maxlength="150" value="<?= e($draft['email']) ?>"></label>
      </div>
      <input type="hidden" name="civilStatus" value="<?= e($draft['civilStatus']) ?>">
      <h2 class="font-heading" style="font-size:20px" data-i18n>Accepted documents retained</h2>
      <?php if (!$renewalPlan['retained']): ?><p data-i18n>No accepted, unexpired documents are recorded on the current ID.</p><?php endif; ?>
      <?php foreach ($renewalPlan['retained'] as $item): ?>
        <div class="review-row"><div class="k" data-i18n><?= e($item['label']) ?></div><div class="v">Accepted on file; no resubmission. <?= !empty($item['expires_at']) ? 'Valid until ' . e(format_date_long($item['expires_at'])) : 'No expiry recorded.' ?></div></div>
      <?php endforeach; ?>
      <h2 class="font-heading" style="font-size:20px" data-i18n>Documents needed for this renewal</h2>
      <?php if (!$docDefs): ?><p data-i18n>Your category requirements are already accepted and unexpired. No new document uploads are needed.</p><?php endif; ?>
      <?php foreach ($docDefs as $item):
        $uploaded = $draft['documents'][$item['doc_key']] ?? null;
        $inputId = 'renewal-file-' . e($item['doc_key']);
      ?>
        <div class="user-label">
          <label class="form-label" for="<?= $inputId ?>"><strong><?= e($item['label']) ?></strong> <span class="opt"><?= e($item['reason']) ?></span></label>
          <?php if ($uploaded): ?><p class="small mb-1"><span data-i18n>Selected file:</span> <?= e($uploaded['original_name']) ?></p><?php endif; ?>
          <input class="form-control" type="file" id="<?= $inputId ?>" name="renewal_file[<?= e($item['doc_key']) ?>]" accept=".png,.jpg,.jpeg,image/png,image/jpeg">
          <span class="opt" data-i18n><?= $uploaded ? 'Choose another file only to replace it.' : 'Choose a file to upload.' ?> Accepted formats: PNG, JPG and JPEG only. Maximum size: <?= e((string) document_rules()['max_mb']) ?> MB.</span>
        </div>
      <?php endforeach; ?>
      <p class="opt" data-i18n>Each required document must be uploaded as a file before you can submit. MSWDO staff review the uploaded files and may ask you to replace one; an uploaded file is not verified until MSWDO staff confirm it. Document expiry uses recorded dates; no unrecorded expiry is assumed.</p>
      <label data-i18n><input type="checkbox" name="consent" value="1" <?= !empty($draft['consent']) ? 'checked' : '' ?> required> My existing record remains correct and I consent to this renewal. Contact MSWDO if my identity, category or children need changes.</label>
      <div class="d-flex flex-wrap gap-2"><button type="submit" name="action" value="save_draft" formnovalidate class="btn-outline" data-i18n>Save draft</button><button type="submit" name="action" value="submit" class="btn-navy" data-i18n>Submit Renewal</button></div>
    </form>
  <?php endif; ?>
<?php endif; ?>
