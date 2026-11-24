<?php
/**
 * Step 2 of a new application: the children. RA 11861: a child qualifies when 22 or younger, or over 22 with a disability (then a PWD ID
 * or medical certificate is asked in the Documents step). At least one child must qualify; the server checks this again.
 *
 * @var array $draft @var array $errors
 */
require_once APP_ROOT . '/includes/icons.php';
require_once APP_ROOT . '/services/SoloParentChildRules.php';
$hasPwd = !empty($draft['hasPwdChild']);
?>
<link rel="stylesheet" href="<?= public_asset('css/children.css') ?>">
<form method="post"<?= !empty($isApplicationWizard) ? ' id="application-step-form"' : '' ?>>
  <?= csrf_field() ?>
  <?php if (!empty($isApplicationWizard)): ?><input type="hidden" name="wizard_step" value="2"><?php endif; ?>
  <input type="hidden" name="action" value="continue">
  <input type="hidden" name="idx" id="remove-idx" value="">

  <div data-child-rules data-on-date="<?= e(today_iso()) ?>" data-max="<?= (int) SoloParentChildRules::MAX_AGE ?>" data-pwd="<?= $hasPwd ? '1' : '0' ?>">
    <fieldset class="cr-question">
      <legend><?= ui_icon('info', 16) ?><span data-i18n>Do you have a child with a disability (PWD)?</span></legend>
      <div class="cr-radios">
        <label><input type="radio" class="js-pwd-answer" name="has_pwd_child" value="1" <?= $hasPwd ? 'checked' : '' ?>><span data-i18n>Yes</span></label>
        <label><input type="radio" class="js-pwd-answer" name="has_pwd_child" value="0" <?= $hasPwd ? '' : 'checked' ?>><span data-i18n>No</span></label>
      </div>
      <small data-i18n>A child over 22 can still qualify if they have a disability.</small>
      <?php if (!empty($errors['pwd'])): ?><span class="field-error" role="alert" data-i18n><?= e($errors['pwd']) ?></span><?php endif; ?>
    </fieldset>

    <?php foreach ($draft['children'] as $i => $child): ?>
      <div class="child-card js-child">
        <div class="child-card-head">
          <div style="font-weight:700;font-size:14px;color:var(--ink)" data-i18n>Child <?= $i + 1 ?></div>
          <?php if (count($draft['children']) > 1): ?>
            <button type="submit" name="action" value="remove_child" formnovalidate onclick="document.getElementById('remove-idx').value='<?= $i ?>'" class="btn-remove-child" data-i18n>Remove</button>
          <?php endif; ?>
        </div>
        <div class="field-grid narrow">
          <label class="field-label" data-i18n>
            <span data-i18n>Full name *</span>
            <input type="text" name="child_name[]" placeholder="Child's full name" value="<?= e($child['name']) ?>" class="<?= isset($errors['cn' . $i]) ? 'is-invalid' : '' ?>" data-i18n-attrs="placeholder">
            <?php if (!empty($errors['cn' . $i])): ?><span class="field-error" data-i18n><?= e($errors['cn' . $i]) ?></span><?php endif; ?>
          </label>
          <label class="field-label" data-i18n>
            <span data-i18n>Birthdate *</span>
            <input type="date" name="child_birthdate[]" class="js-child-birth <?= isset($errors['cd' . $i]) ? 'is-invalid' : '' ?>" max="<?= e(today_iso()) ?>" value="<?= e($child['birthdate']) ?>">
            <?php if (!empty($errors['cd' . $i])): ?><span class="field-error" data-i18n><?= e($errors['cd' . $i]) ?></span><?php endif; ?>
          </label>
        </div>
        <label class="cr-tick"><input type="checkbox" class="js-child-pwd" name="child_disability[<?= $i ?>]" value="1" <?= !empty($child['disability']) ? 'checked' : '' ?>><span><?= ui_icon('shield-check', 16) ?><span data-i18n>This child has a disability (PWD)</span></span></label>
        <div class="cr-live js-child-live" role="status"></div>
      </div>
    <?php endforeach; ?>

    <button type="submit" name="action" value="add_child" formnovalidate class="btn-add-child" data-i18n>+ Add another child</button>

    <div class="cr-none js-child-none" role="alert"<?= !empty($errors['children']) ? '' : ' hidden' ?>>
      <?= ui_icon('triangle-alert', 22) ?>
      <div><b data-i18n>No child qualifies, so a Solo Parent ID cannot be issued (RA 11861).</b><span data-i18n>Add a child who is 22 or younger, or tick "This child has a disability" for a child over 22. If you need help, contact MSWDO.</span></div>
    </div>
  </div>

  <div class="form-nav-buttons">
    <?php if (empty($isApplicationWizard)): ?><button type="submit" name="action" value="save_draft" formnovalidate class="btn-outline" data-i18n>Save &amp; finish later</button><?php endif; ?>
    <button type="submit" name="action" value="back" formnovalidate class="btn-outline"><svg class="nav-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 12H5M11 6l-6 6 6 6"/></svg><span data-i18n>Personal Info</span></button>
    <button type="submit" name="action" value="continue" class="btn-navy js-child-submit"><span data-i18n>Continue to Documents</span><svg class="nav-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
  </div>
  <p class="cr-why js-child-why" hidden><?= ui_icon('lock', 14) ?><span data-i18n>Continue unlocks when at least one child qualifies.</span></p>
</form>
<script src="<?= public_asset('js/child-rules.js') ?>" defer></script>
