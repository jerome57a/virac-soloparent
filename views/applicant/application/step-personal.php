<?php
/** Personal information step. @var array $draft @var array $errors @var array $categories @var array $categoryDocs @var bool $categoryRefit */
require_once APP_ROOT . '/services/CategoryCivilStatus.php';
require_once APP_ROOT . '/includes/icons.php';
$categoryRefit = $categoryRefit ?? false;
$civilNow = (string) ($draft['civilStatus'] ?? '');
// With a civil status chosen only the categories that fit it are listed; the ones for every civil status come last, under their own heading.
$visibleCategories = $civilNow !== '' ? CategoryCivilStatus::filter($categories, $civilNow) : $categories;
$situational = array_values(array_filter($visibleCategories, fn($c) => CategoryCivilStatus::allowed($c) !== null));
$general = array_values(array_filter($visibleCategories, fn($c) => CategoryCivilStatus::allowed($c) === null));
$categoryOption = fn(array $c) => '<option value="' . (int) $c['id'] . '"' . ((int) $draft['categoryId'] === (int) $c['id'] ? ' selected' : '') . ' data-i18n>' . e($c['name']) . '</option>';
$invalid = fn(string $key) => isset($errors[$key]) ? 'is-invalid' : '';
$fieldAccessibility = function (string $key, string $hintId = '') use ($errors): string {
    $hasError = !empty($errors[$key]);
    $description = trim($hintId . ($hasError ? ' personal-error-' . $key : ''));
    return ($hasError ? ' aria-invalid="true"' : '')
        . ($description !== '' ? ' aria-describedby="' . e($description) . '"' : '');
};
?>
<form method="post" id="application-step-form" class="apply-personal-info">
  <?= csrf_field() ?>
  <input type="hidden" name="wizard_step" value="1">
  <input type="hidden" name="action" value="continue">

  <section class="personal-info-card" aria-labelledby="personal-details-heading">
    <header class="personal-info-card-head">
      <span class="personal-info-section-number" aria-hidden="true">01</span>
      <div><h3 id="personal-details-heading" data-i18n>Personal details</h3><p data-i18n>Enter your details as they appear on your official records. Valid details also update My Profile when you continue or save your draft.</p></div>
    </header>
    <div class="field-grid personal-info-grid">
      <label class="field-label field-full" data-i18n>
        <span data-i18n>Full name <span class="personal-required">*</span></span>
        <input type="text" name="fullName" placeholder="e.g. Juan Dela Cruz" value="<?= e($draft['fullName']) ?>" autocomplete="name" class="<?= $invalid('fullName') ?>"<?= $fieldAccessibility('fullName') ?> data-i18n-attrs="placeholder">
        <?php if (!empty($errors['fullName'])): ?><span class="field-error" id="personal-error-fullName" data-i18n><?= e($errors['fullName']) ?></span><?php endif; ?>
      </label>
      <label class="field-label" data-i18n>
        <span data-i18n>Birthdate <span class="personal-required">*</span></span>
        <input type="date" name="birthdate" value="<?= e($draft['birthdate']) ?>" max="<?= e(today_iso()) ?>" autocomplete="bday" class="<?= $invalid('birthdate') ?>"<?= $fieldAccessibility('birthdate') ?>>
        <?php if (!empty($errors['birthdate'])): ?><span class="field-error" id="personal-error-birthdate" data-i18n><?= e($errors['birthdate']) ?></span><?php endif; ?>
      </label>
      <label class="field-label" data-i18n>
        <span data-i18n>Sex <span class="personal-required">*</span></span>
        <select name="sex" class="<?= $invalid('sex') ?>"<?= $fieldAccessibility('sex') ?>>
          <option value="" data-i18n>Select sex</option>
          <option value="Female" <?= $draft['sex'] === 'Female' ? 'selected' : '' ?> data-i18n>Female</option>
          <option value="Male" <?= $draft['sex'] === 'Male' ? 'selected' : '' ?> data-i18n>Male</option>
        </select>
        <?php if (!empty($errors['sex'])): ?><span class="field-error" id="personal-error-sex" data-i18n><?= e($errors['sex']) ?></span><?php endif; ?>
      </label>
      <label class="field-label" data-i18n>
        <span data-i18n>Civil status <span class="personal-required">*</span></span>
        <select name="civilStatus" class="<?= $invalid('civilStatus') ?>"<?= $fieldAccessibility('civilStatus') ?>>
          <option value="" data-i18n>Select civil status</option>
          <?php foreach (CIVIL_STATUS_OPTIONS as $c): ?>
            <option data-i18n value="<?= e($c) ?>" <?= $draft['civilStatus'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($errors['civilStatus'])): ?><span class="field-error" id="personal-error-civilStatus" data-i18n><?= e($errors['civilStatus']) ?></span><?php endif; ?>
      </label>
    </div>
  </section>

  <section class="personal-info-card" aria-labelledby="personal-contact-heading">
    <header class="personal-info-card-head">
      <span class="personal-info-section-number" aria-hidden="true">02</span>
      <div><h3 id="personal-contact-heading" data-i18n>Contact &amp; address</h3><p data-i18n>Use your current contact details and residential address.</p></div>
    </header>
    <div class="field-grid personal-info-grid">
      <label class="field-label" data-i18n>
        <span data-i18n>Mobile number <span class="personal-required">*</span><span class="personal-account-tag" data-i18n>Account number</span></span>
        <input type="tel" value="<?= e($draft['contact']) ?>" readonly aria-readonly="true" class="<?= $invalid('contact') ?>"<?= $fieldAccessibility('contact', 'personal-contact-hint') ?>>
        <span class="field-hint" id="personal-contact-hint" data-i18n>SMS updates go to this number. To change it, visit <a href="<?= base_url('applicant/dashboard.php?section=profile') ?>" data-i18n>My Profile</a>.</span>
        <?php if (!empty($errors['contact'])): ?><span class="field-error" id="personal-error-contact" data-i18n><?= e($errors['contact']) ?></span><?php endif; ?>
      </label>
      <label class="field-label" data-i18n>
        <span data-i18n>Email address (optional)</span>
        <input type="email" name="email" placeholder="name@email.com" value="<?= e($draft['email']) ?>" maxlength="150" autocomplete="email" class="<?= $invalid('email') ?>"<?= $fieldAccessibility('email', 'personal-email-hint') ?>>
        <span class="field-hint" id="personal-email-hint" data-i18n>You can leave this blank. Application updates are sent to your mobile number.</span>
        <?php if (!empty($errors['email'])): ?><span class="field-error" id="personal-error-email" data-i18n><?= e($errors['email']) ?></span><?php endif; ?>
      </label>
      <label class="field-label" data-i18n>
        <span data-i18n>Barangay <span class="personal-required">*</span></span>
        <input type="text" name="barangay" placeholder="e.g. Concepcion" value="<?= e($draft['barangay']) ?>" class="<?= $invalid('barangay') ?>"<?= $fieldAccessibility('barangay') ?> data-i18n-attrs="placeholder">
        <?php if (!empty($errors['barangay'])): ?><span class="field-error" id="personal-error-barangay" data-i18n><?= e($errors['barangay']) ?></span><?php endif; ?>
      </label>
      <label class="field-label" data-i18n>
        <span data-i18n>Complete address <span class="personal-required">*</span></span>
        <input type="text" name="address" placeholder="House no., street, purok" value="<?= e($draft['address']) ?>" autocomplete="street-address" class="<?= $invalid('address') ?>"<?= $fieldAccessibility('address') ?> data-i18n-attrs="placeholder">
        <?php if (!empty($errors['address'])): ?><span class="field-error" id="personal-error-address" data-i18n><?= e($errors['address']) ?></span><?php endif; ?>
      </label>
    </div>
  </section>


  <section class="personal-info-card personal-info-category" aria-labelledby="personal-category-heading">
    <header class="personal-info-card-head">
      <span class="personal-info-section-number" aria-hidden="true">03</span>
      <div><h3 id="personal-category-heading" data-i18n>Solo parent category</h3><p data-i18n>Select the category that best describes your circumstances.</p></div>
    </header>
    <label class="field-label" data-i18n>
      <span data-i18n>Category <span class="personal-required">*</span></span>
      <select name="categoryId" id="circumstance-select" class="<?= $invalid('circumstance') ?>"<?= $fieldAccessibility('circumstance', 'personal-category-hint') ?>>
        <option value="" <?= empty($draft['categoryId']) || !isset($categoryMap[$draft['categoryId']]) ? 'selected' : '' ?> data-i18n>Select your category</option>
        <?php foreach ($situational as $c) { echo $categoryOption($c), "\n"; } ?>
        <?php if ($general): ?><optgroup label="For any civil status" data-i18n-attrs="label"><?php foreach ($general as $c) { echo $categoryOption($c), "\n"; } ?></optgroup><?php endif; ?>
      </select>
      <span class="field-hint cf-first" id="category-civil-hint" hidden><?= ui_icon('info', 14) ?><span data-i18n>Choose your civil status first.</span></span>
      <span class="field-hint" id="personal-category-hint" data-i18n>Your category determines the additional documents required for your application.</span>
      <?php if (!empty($errors['circumstance'])): ?><span class="field-error" id="personal-error-circumstance" data-i18n><?= e($errors['circumstance']) ?></span><?php endif; ?>
    </label>
    <div id="category-filter-status" class="cf-status" aria-live="polite">
      <span class="cf-pill" id="category-filter-pill"<?= $civilNow === '' ? ' hidden' : '' ?>><span data-i18n>Showing categories for</span> <span data-i18n><?= e($civilNow) ?></span></span>
      <div class="cf-msg cf-info" id="category-filter-msg" role="note"<?= $categoryRefit ? '' : ' hidden' ?>><?= ui_icon('info', 16) ?><span id="category-filter-msg-text" data-i18n><?= $categoryRefit ? 'The category in your saved draft no longer fits your civil status. Please choose it again.' : '' ?></span></div>
    </div>
    <?php if (!empty($legacyCategoryName)): ?><div class="info-box personal-category-notice" role="note">Your saved draft used the retired category “<?= e($legacyCategoryName) ?>”. Select one of the current categories to continue; the rest of your saved draft is preserved.</div><?php endif; ?>
    <div id="category-doc-box" class="info-box personal-category-notice" style="display:none" aria-live="polite"></div>
    <a href="<?= base_url('applicant/dashboard.php?section=requirements') ?>" class="personal-requirements-guide-link" target="_blank" rel="noopener" data-i18n>How to get your requirements</a>
  </section>

  <div class="form-nav-buttons personal-info-actions">
    <button type="submit" name="action" value="continue" class="btn-navy"><span data-i18n>Continue to Children</span><svg class="nav-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
  </div>
</form>

<dialog id="application-requirement-dialog" class="application-requirement-dialog" aria-labelledby="application-requirement-title">
  <div class="application-requirement-toolbar">
    <div><h2 id="application-requirement-title" data-i18n>Requirements Guide</h2><p data-i18n>Your application stays open while you read.</p></div>
    <button type="button" class="btn-navy" id="application-requirement-back"><svg class="nav-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 12H5M11 6l-6 6 6 6"/></svg><span data-i18n>Back to application</span></button>
  </div>
  <div id="application-requirement-content" class="application-requirement-content" aria-busy="false"></div>
</dialog>

<script>
  window.CATEGORY_DOCS = <?= json_encode($categoryDocs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  window.CATEGORY_CIVIL = <?= json_encode(CategoryCivilStatus::forBrowser($categories), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<link rel="stylesheet" href="<?= public_asset('css/category-filter.css') ?>">
<script src="<?= public_asset('js/category-civil.js') ?>" defer></script>
