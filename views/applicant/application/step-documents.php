<?php
/** @var array $draft @var array $errors @var array $docDefs @var array $catOnlyKeys */
$standardDocs = array_values(array_filter($docDefs, fn($d) => !in_array($d['doc_key'], $catOnlyKeys, true)));
$categoryDocs = array_values(array_filter($docDefs, fn($d) => in_array($d['doc_key'], $catOnlyKeys, true)));
$groups = [['title' => 'Standard requirements', 'items' => $standardDocs]];
if ($categoryDocs) {
    $groups[] = ['title' => 'Category-specific requirements', 'items' => $categoryDocs];
}
?>
<form method="post" class="apply-step-form" data-preparation-form<?= !empty($isApplicationWizard) ? ' id="application-step-form"' : '' ?>>
  <?= csrf_field() ?>
  <?php if (!empty($isApplicationWizard)): ?><input type="hidden" name="wizard_step" value="3"><?php endif; ?>
  <div class="info-box apply-checklist-notice" role="note" data-i18n>Checked documents are automatically verified when you submit your application so staff can schedule your interview. Bring the physical documents to the interview.</div>
  <section class="checklist-card" aria-labelledby="checklist-heading">
    <header class="checklist-card-head">
      <h3 id="checklist-heading" data-i18n>Required Documents Checklist</h3>
      <p data-i18n>Select the documents you already have. Required documents may remain unchecked while you prepare them. You can still submit your application.</p>
    </header>
    <?php foreach ($groups as $group): if (!$group['items']) { continue; } ?>
      <section class="checklist-group" aria-label="<?= e($group['title']) ?>">
        <h4 data-i18n><?= e($group['title']) ?></h4>
        <?php foreach ($group['items'] as $d): $key = $d['doc_key']; ?>
          <label class="checklist-row">
            <span class="checklist-row-label">
              <span class="checklist-document-name" data-i18n><?= e($d['label']) ?></span>
              <span class="checklist-requirement" data-i18n><?= !empty($d['is_required']) ? 'Required' : 'Optional' ?></span>
            </span>
            <input class="checklist-checkbox" type="checkbox" <?= !empty($d['is_required']) ? 'data-required-document="' . e($d['label']) . '" data-requirement-guide="' . e(base_url('applicant/dashboard.php?section=requirements&doc=' . rawurlencode($key))) . '"' : '' ?> name="checklist[]" value="<?= e($key) ?>" <?= !empty($draft['checkedDocuments'][$key]) ? 'checked' : '' ?> aria-label="I have <?= e($d['label']) ?>">
          </label>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </section>
  <?php $preparationItems = array_map(fn($item) => $item + ['applicant_has' => !empty($draft['checkedDocuments'][$item['doc_key']])], $docDefs); require APP_ROOT . '/views/partials/requirements-preparation.php'; ?>
  <div class="form-nav-buttons">
    <?php if (empty($isApplicationWizard)): ?><button type="submit" name="action" value="save_draft" formnovalidate class="btn-outline" data-i18n>Save &amp; finish later</button><?php endif; ?>
    <button type="submit" name="action" value="back" formnovalidate class="btn-outline" data-i18n>&larr; Children</button>
    <button type="submit" name="action" value="continue" class="btn-navy" data-i18n>Continue to Review &rarr;</button>
  </div>
</form>
<script src="<?= base_url('public/js/requirements-preparation.js') ?>" defer></script>
