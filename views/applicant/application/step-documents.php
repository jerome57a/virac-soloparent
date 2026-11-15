<?php
/** @var array $draft @var array $errors @var array $docDefs @var array $catOnlyKeys */
$standardDocs = array_values(array_filter($docDefs, fn($d) => !in_array($d['doc_key'], $catOnlyKeys, true)));
$categoryDocs = array_values(array_filter($docDefs, fn($d) => in_array($d['doc_key'], $catOnlyKeys, true)));
$groups = [['title' => 'Standard requirements', 'items' => $standardDocs]];
if ($categoryDocs) { $groups[] = ['title' => 'Category-specific requirements', 'items' => $categoryDocs]; }
?>
<form method="post" class="apply-step-form"<?= !empty($isApplicationWizard) ? ' id="application-step-form"' : '' ?>>
  <?= csrf_field() ?>
  <?php if (!empty($isApplicationWizard)): ?><input type="hidden" name="wizard_step" value="3"><?php endif; ?>
  <div class="info-box apply-checklist-notice" role="note" data-i18n>After submitting your application, upload the required files from Documents in your applicant portal. Staff must verify the files before approval.</div>
  <?php foreach ($groups as $group): if (!$group['items']) continue; ?>
    <section class="checklist-card mb-3" aria-label="<?= e($group['title']) ?>">
      <header class="checklist-card-head"><h3 data-i18n><?= e($group['title']) ?></h3></header>
      <?php foreach ($group['items'] as $d): ?>
        <div class="checklist-row"><span class="checklist-row-label"><span class="checklist-document-name" data-i18n><?= e($d['label']) ?></span><span class="checklist-requirement" data-i18n><?= !empty($d['is_required']) ? 'Required' : 'Optional' ?></span></span>
          <a href="<?= base_url('applicant/dashboard.php?section=requirements&doc=' . rawurlencode($d['doc_key'])) ?>" target="_blank" rel="noopener" data-i18n>View guide</a>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>
  <div class="form-nav-buttons">
    <?php if (empty($isApplicationWizard)): ?><button type="submit" name="action" value="save_draft" formnovalidate class="btn-outline" data-i18n>Save &amp; finish later</button><?php endif; ?>
    <button type="submit" name="action" value="back" formnovalidate class="btn-outline" data-i18n>&larr; Children</button>
    <button type="submit" name="action" value="continue" class="btn-navy" data-i18n>Continue to Review &rarr;</button>
  </div>
</form>
