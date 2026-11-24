<?php
/**
 * Wizard step 3: upload each required supporting document.
 *
 * @var array $draft    documents[<doc_key>] holds the staged file (original_name, size) for each uploaded requirement
 * @var array $errors   documents = the still-missing summary; upload_<doc_key> = why one file was refused or is gone
 * @var array $docDefs  the requirements of the chosen category
 * @var array $catOnlyKeys
 */
require_once APP_ROOT . '/includes/icons.php';
require_once APP_ROOT . '/services/SoloParentChildRules.php';
$proofDocs = array_values(array_filter($docDefs, fn($d) => $d['doc_key'] === SoloParentChildRules::PROOF_KEY));
$standardDocs = array_values(array_filter($docDefs, fn($d) => !in_array($d['doc_key'], $catOnlyKeys, true) && $d['doc_key'] !== SoloParentChildRules::PROOF_KEY));
$categoryDocs = array_values(array_filter($docDefs, fn($d) => in_array($d['doc_key'], $catOnlyKeys, true)));
$groups = [['title' => 'Standard requirements', 'items' => $standardDocs]];
if ($categoryDocs) { $groups[] = ['title' => 'Category-specific requirements', 'items' => $categoryDocs]; }
if ($proofDocs) { $groups[] = ['title' => 'For your child with a disability', 'items' => $proofDocs]; }
$maxMb = (string) document_rules()['max_mb'];
?>
<link rel="stylesheet" href="<?= public_asset('css/children.css') ?>">
<form method="post" enctype="multipart/form-data" class="apply-step-form"<?= !empty($isApplicationWizard) ? ' id="application-step-form"' : '' ?>>
  <?= csrf_field() ?>
  <?php if (!empty($isApplicationWizard)): ?><input type="hidden" name="wizard_step" value="3"><?php endif; ?>
  <div class="info-box apply-checklist-notice" role="note" data-i18n>Upload a clear scan or photo of each required document (Accepted formats: PNG, JPG and JPEG only; up to <?= e($maxMb) ?> MB). Your files are submitted with your application. MSWDO staff review each file afterwards and may ask you to replace one; an uploaded file is not verified until MSWDO staff confirm it.</div>
  <?php require APP_ROOT . '/views/partials/bring-pictures-note.php'; ?>
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
        <div class="checklist-row doc-upload-card" style="flex-wrap:wrap">
          <span class="checklist-row-label"><label for="<?= $inputId ?>" class="checklist-document-name" data-i18n><?= e($d['label']) ?></label><span class="checklist-requirement" data-i18n><?= !empty($d['is_required']) ? 'Required' : 'Optional' ?></span></span>
          <div style="flex-basis:100%" class="doc-upload-body">
            <?php if (!empty($d['reason'])): ?><p class="doc-proof-reason"><?= ui_icon('info', 16) ?><span data-i18n><?= e($d['reason']) ?></span></p><?php endif; ?>
            <?php if ($staged && !$problem): ?>
              <p class="small mb-1 doc-upload-status is-ready"><strong data-i18n>File ready to submit:</strong> <?= e($staged['original_name'] ?? '') ?><?php if (!empty($staged['size'])): ?> (<?= e(number_format(((int) $staged['size']) / 1024, 0)) ?> KB)<?php endif; ?></p>
            <?php elseif (!empty($d['is_required'])): ?>
              <p class="small mb-1 text-muted doc-upload-status" data-i18n>No file uploaded yet.</p>
            <?php endif; ?>
            <input class="form-control" type="file" id="<?= $inputId ?>" name="application_file[<?= e($docKey) ?>]" accept=".png,.jpg,.jpeg,image/png,image/jpeg"<?= $problem ? ' aria-invalid="true"' : '' ?>>
            <p class="doc-upload-selected small mb-0" data-selected-for="<?= $inputId ?>" aria-live="polite" hidden></p>
            <span class="opt doc-upload-hint"><span data-i18n><?= $staged && !$problem ? 'Choose another file only to replace it.' : 'Choose a file to upload.' ?></span> PNG, JPG or JPEG, up to <?= e($maxMb) ?> MB.</span>
            <?php if ($problem): ?><div class="field-error" role="alert"><?= e($problem) ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>
  <?php if (!$docDefs): ?><p data-i18n>No document requirements are configured for your category. Contact MSWDO if you expected some.</p><?php endif; ?>
  <script>
    // Display-only: show the chosen filename under each file input. The form itself is unchanged.
    document.querySelectorAll('.doc-upload-card input[type=file]').forEach(function (input) {
      input.addEventListener('change', function () {
        var out = document.querySelector('[data-selected-for="' + input.id + '"]');
        if (!out) return;
        var f = input.files && input.files[0];
        out.textContent = f ? 'Selected: ' + f.name + ' (' + Math.max(1, Math.round(f.size / 1024)) + ' KB)' : '';
        out.hidden = !f;
      });
    });
  </script>
  <div class="form-nav-buttons">
    <?php if (empty($isApplicationWizard)): ?><button type="submit" name="action" value="save_draft" formnovalidate class="btn-outline" data-i18n>Save &amp; finish later</button><?php endif; ?>
    <button type="submit" name="action" value="back" formnovalidate class="btn-outline"><svg class="nav-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 12H5M11 6l-6 6 6 6"/></svg><span data-i18n>Children</span></button>
    <button type="submit" name="action" value="upload" formnovalidate class="btn-outline" data-i18n>Upload files</button>
    <button type="submit" name="action" value="continue" class="btn-navy"><span data-i18n>Continue to Review</span><svg class="nav-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
  </div>
</form>
