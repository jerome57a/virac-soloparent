<?php
/**
 * Wizard step 3: upload each required supporting document.
 *
 * @var array $draft    documents[<doc_key>] holds the staged file (original_name, size) for each uploaded requirement
 * @var array $errors   documents = the still-missing summary; upload_<doc_key> = why one file was refused or is gone
 * @var array $docDefs  the requirements of the chosen category
 * @var array $catOnlyKeys
 */
$standardDocs = array_values(array_filter($docDefs, fn($d) => !in_array($d['doc_key'], $catOnlyKeys, true)));
$categoryDocs = array_values(array_filter($docDefs, fn($d) => in_array($d['doc_key'], $catOnlyKeys, true)));
$groups = [['title' => 'Standard requirements', 'items' => $standardDocs]];
if ($categoryDocs) { $groups[] = ['title' => 'Category-specific requirements', 'items' => $categoryDocs]; }
$maxMb = (string) document_rules()['max_mb'];
?>
<form method="post" enctype="multipart/form-data" class="apply-step-form"<?= !empty($isApplicationWizard) ? ' id="application-step-form"' : '' ?>>
  <?= csrf_field() ?>
  <?php if (!empty($isApplicationWizard)): ?><input type="hidden" name="wizard_step" value="3"><?php endif; ?>
  <div class="info-box apply-checklist-notice" role="note" data-i18n>Upload a clear scan or photo of each required document (JPG, PNG or PDF, up to <?= e($maxMb) ?> MB). Your files are submitted with your application. MSWDO staff review each file afterwards and may ask you to replace one; an uploaded file is not verified until staff confirm it.</div>
  <?php if (!empty($errors['documents'])): ?><div class="field-error mb-2" role="alert" data-i18n><?= e($errors['documents']) ?></div><?php endif; ?>
  <?php foreach ($groups as $group): if (!$group['items']) continue; ?>
    <section class="checklist-card mb-3" aria-label="<?= e($group['title']) ?>">
      <header class="checklist-card-head"><h3 data-i18n><?= e($group['title']) ?></h3></header>
      <?php foreach ($group['items'] as $d):
        $docKey = $d['doc_key'];
        $staged = $draft['documents'][$docKey] ?? null;
        $inputId = 'application-file-' . e($docKey);
        $problem = $errors['upload_' . $docKey] ?? null;
      ?>
        <div class="checklist-row" style="flex-wrap:wrap">
          <span class="checklist-row-label"><label for="<?= $inputId ?>" class="checklist-document-name" data-i18n><?= e($d['label']) ?></label><span class="checklist-requirement" data-i18n><?= !empty($d['is_required']) ? 'Required' : 'Optional' ?></span></span>
          <a href="<?= base_url('applicant/dashboard.php?section=requirements&doc=' . rawurlencode($docKey)) ?>" target="_blank" rel="noopener" data-i18n>View guide</a>
          <div style="flex-basis:100%">
            <?php if ($staged && !$problem): ?>
              <p class="small mb-1"><strong data-i18n>File ready to submit:</strong> <?= e($staged['original_name'] ?? '') ?><?php if (!empty($staged['size'])): ?> (<?= e(number_format(((int) $staged['size']) / 1024, 0)) ?> KB)<?php endif; ?></p>
            <?php elseif (!empty($d['is_required'])): ?>
              <p class="small mb-1 text-muted" data-i18n>No file uploaded yet.</p>
            <?php endif; ?>
            <input class="form-control" type="file" id="<?= $inputId ?>" name="application_file[<?= e($docKey) ?>]" accept=".jpg,.jpeg,.png,.pdf"<?= $problem ? ' aria-invalid="true"' : '' ?>>
            <span class="opt" data-i18n><?= $staged && !$problem ? 'Choose another file only to replace it.' : 'Choose a file to upload.' ?></span>
            <?php if ($problem): ?><div class="field-error" role="alert"><?= e($problem) ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>
  <?php if (!$docDefs): ?><p data-i18n>No document requirements are configured for your category. Contact MSWDO if you expected some.</p><?php endif; ?>
  <div class="form-nav-buttons">
    <?php if (empty($isApplicationWizard)): ?><button type="submit" name="action" value="save_draft" formnovalidate class="btn-outline" data-i18n>Save &amp; finish later</button><?php endif; ?>
    <button type="submit" name="action" value="back" formnovalidate class="btn-outline" data-i18n>&larr; Children</button>
    <button type="submit" name="action" value="upload" formnovalidate class="btn-outline" data-i18n>Upload files</button>
    <button type="submit" name="action" value="continue" class="btn-navy" data-i18n>Continue to Review &rarr;</button>
  </div>
</form>
