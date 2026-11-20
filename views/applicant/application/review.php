<?php
/** @var array $draft @var array $errors @var array $docDefs @var array $categoryMap @var ?string $submitLabel (default "Submit Application") */
$categoryName = $draft['categoryId'] ? ($categoryMap[$draft['categoryId']] ?? '') : '—';

$reviewSections = [
    ['title' => 'Personal Info', 'step' => 1, 'rows' => [
        ['k' => 'Full name', 'v' => $draft['fullName'] ?: '—'],
        ['k' => 'Birthdate', 'v' => format_date_long($draft['birthdate'])],
        ['k' => 'Address', 'v' => ($draft['address'] ?: '—') . ($draft['barangay'] ? ', Brgy. ' . $draft['barangay'] : '')],
        ['k' => 'Mobile', 'v' => $draft['contact'] ?: '—'],
        ['k' => 'Email', 'v' => $draft['email'] ?: '—'],
        ['k' => 'Sex', 'v' => $draft['sex'] ?: '—'],
        ['k' => 'Civil status', 'v' => $draft['civilStatus'] ?: '—'],
        ['k' => 'Solo parent category', 'v' => $categoryName],
    ]],
    ['title' => 'Children', 'step' => 2, 'rows' => array_map(
        fn($c, $i) => ['k' => 'Child ' . ($i + 1), 'v' => ($c['name'] ?: '—') . ' · ' . format_date_long($c['birthdate'])],
        $draft['children'], array_keys($draft['children'])
    )],
    ['title' => 'Supporting Documents', 'step' => 3, 'rows' => array_map(function ($d) use ($draft) {
        $staged = $draft['documents'][$d['doc_key']] ?? null;
        if ($staged) {
            return ['k' => $d['label'], 'v' => ($staged['original_name'] ?? 'Uploaded file') . ' — ready to submit'];
        }
        return ['k' => $d['label'], 'v' => !empty($d['is_required']) ? 'Required — not uploaded yet' : 'Optional — not uploaded'];
    }, $docDefs)],
];
// A required document with no staged file blocks submission; the server checks again when you submit.
$missingRequired = array_values(array_map(
    fn($d) => $d['label'],
    array_filter($docDefs, fn($d) => !empty($d['is_required']) && empty($draft['documents'][$d['doc_key']]))
));
?>

<?php if ($missingRequired): ?>
  <div class="step-alert" role="alert" data-i18n>Upload a file for each required document before you submit. Still needed: <?= e(implode(', ', $missingRequired)) ?>. Use Edit on Supporting Documents to add them.</div>
<?php endif; ?>
<?php
// Automatic pre-check of the draft (advisory). A failure here must never break the review page, so it is skipped on any error.
try {
    require_once APP_ROOT . '/services/AutoVerificationService.php';
    $preCheck =(new AutoVerificationService($pdo ?? ($GLOBALS['pdo'] ?? null)))->forDraft((int) ($user['id'] ?? 0), $draft, $docDefs);
    require __DIR__ . '/pre-check.php';
} catch (Throwable $e) {
    error_log('Automatic pre-check skipped: ' . $e->getMessage());
}
?>
<div class="info-box" style="max-width:none" role="note" data-i18n>Your uploaded files are sent to MSWDO with your application. MSWDO staff review each file after you submit; an uploaded file is not verified until MSWDO staff confirm it.</div>

<?php foreach ($reviewSections as $sec): ?>
  <div class="review-card">
    <div class="review-card-head">
      <div class="title"><?= e($sec['title']) ?></div>
      <?php if (!empty($isApplicationWizard)): ?>
        <button type="submit" form="application-step-form" name="goto_step" value="<?= (int) $sec['step'] ?>" formnovalidate class="wizard-review-edit" data-i18n>Edit</button>
      <?php else: ?>
      <form method="post" class="m-0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="goto">
        <input type="hidden" name="n" value="<?= (int) $sec['step'] ?>">
        <button type="submit" formnovalidate style="border:none;background:none;color:var(--navy);font-weight:600;font-size:13px;cursor:pointer;padding:8px 4px;min-height:40px" data-i18n>Edit</button>
      </form>
      <?php endif; ?>
    </div>
    <div class="review-grid">
      <?php foreach ($sec['rows'] as $r): ?>
        <div class="review-row">
          <div class="k"><?= e($r['k']) ?></div>
          <div class="v"><?= e($r['v']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>

<form method="post"<?= !empty($isApplicationWizard) ? ' id="application-step-form"' : '' ?> data-i18n>
  <?= csrf_field() ?>
  <?php if (!empty($isApplicationWizard)): ?><input type="hidden" name="wizard_step" value="4"><?php endif; ?>
  <label class="d-flex gap-2 align-items-start" style="font-size:14px;color:var(--body-text);line-height:1.5;cursor:pointer;margin-bottom:6px">
    <input type="checkbox" id="consent-checkbox" name="consent" value="1" <?= !empty($draft['consent']) ? 'checked' : '' ?> style="width:20px;height:20px;margin:1px 0 0;flex:none">
    <span data-i18n>I certify that the information above is true and correct, and I consent to MSWDO Virac processing my data for this application.</span>
  </label>
  <?php if (!empty($errors['consent'])): ?><div class="field-error mb-2" data-i18n><?= e($errors['consent']) ?></div><?php endif; ?>

  <div class="form-nav-buttons">
    <?php if (empty($isApplicationWizard)): ?><button type="submit" name="action" value="save_draft" formnovalidate class="btn-outline" data-i18n>Save &amp; finish later</button><?php endif; ?>
    <button type="submit" name="action" value="back" formnovalidate class="btn-outline" data-i18n>Back</button>
    <button type="submit" name="action" value="submit" id="submit-application-btn" class="btn-red" data-i18n><?= e($submitLabel ?? 'Submit Application') ?></button>
  </div>
</form>
