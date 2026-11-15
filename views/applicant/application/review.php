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
    ['title' => 'Supporting Documents', 'step' => 3, 'rows' => array_map(
        fn($d) => ['k' => $d['label'], 'v' => !empty($d['is_required']) ? 'Required; upload after submitting' : 'Optional'],
        $docDefs
    )],
];
?>

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
