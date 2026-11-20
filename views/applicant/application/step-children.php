<?php
/** @var array $draft @var array $errors */
?>
<form method="post"<?= !empty($isApplicationWizard) ? ' id="application-step-form"' : '' ?>>
  <?= csrf_field() ?>
  <?php if (!empty($isApplicationWizard)): ?><input type="hidden" name="wizard_step" value="2"><?php endif; ?>
  <input type="hidden" name="action" value="continue">
  <input type="hidden" name="idx" id="remove-idx" value="">

  <?php foreach ($draft['children'] as $i => $child): ?>
    <div class="child-card">
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
          <input type="date" name="child_birthdate[]" max="<?= e(today_iso()) ?>" value="<?= e($child['birthdate']) ?>" class="<?= isset($errors['cd' . $i]) ? 'is-invalid' : '' ?>">
          <?php if (!empty($errors['cd' . $i])): ?><span class="field-error" data-i18n><?= e($errors['cd' . $i]) ?></span><?php endif; ?>
        </label>
      </div>
    </div>
  <?php endforeach; ?>

  <button type="submit" name="action" value="add_child" formnovalidate class="btn-add-child" data-i18n>+ Add another child</button>

  <div class="form-nav-buttons">
    <?php if (empty($isApplicationWizard)): ?><button type="submit" name="action" value="save_draft" formnovalidate class="btn-outline" data-i18n>Save &amp; finish later</button><?php endif; ?>
    <button type="submit" name="action" value="back" formnovalidate class="btn-outline"><svg class="nav-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 12H5M11 6l-6 6 6 6"/></svg><span data-i18n>Personal Info</span></button>
    <button type="submit" name="action" value="continue" class="btn-navy"><span data-i18n>Continue to Documents</span><svg class="nav-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
  </div>
</form>
