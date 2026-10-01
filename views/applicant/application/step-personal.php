<?php
/**
 * @var array $draft
 * @var array $errors
 * @var array $categories
 * @var array $categoryDocs  category id => labels of its category-only requirements
 */
$invalid = fn(string $key) => isset($errors[$key]) ? 'is-invalid' : '';
?>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="continue">

  <div class="field-grid">
    <label class="field-label field-full">
      <span>Full name *</span>
      <input type="text" name="fullName" placeholder="Juan Dela Cruz" value="<?= e($draft['fullName']) ?>" autocomplete="name" class="<?= $invalid('fullName') ?>">
      <?php if (!empty($errors['fullName'])): ?><span class="field-error"><?= e($errors['fullName']) ?></span><?php endif; ?>
    </label>

    <label class="field-label">
      <span>Barangay *</span>
      <input type="text" name="barangay" placeholder="e.g. Concepcion" value="<?= e($draft['barangay']) ?>" class="<?= $invalid('barangay') ?>">
      <?php if (!empty($errors['barangay'])): ?><span class="field-error"><?= e($errors['barangay']) ?></span><?php endif; ?>
    </label>

    <label class="field-label">
      <span>Complete address *</span>
      <input type="text" name="address" placeholder="House no., street, purok" value="<?= e($draft['address']) ?>" autocomplete="street-address" class="<?= $invalid('address') ?>">
      <?php if (!empty($errors['address'])): ?><span class="field-error"><?= e($errors['address']) ?></span><?php endif; ?>
    </label>

    <label class="field-label">
      <span>Mobile number *</span>
      <input type="tel" value="<?= e($draft['contact']) ?>" readonly aria-readonly="true" class="<?= $invalid('contact') ?>">
      <span class="field-hint">Your confirmed account number — application updates are texted here. To use a different number, <a href="<?= base_url('applicant/dashboard.php?section=profile') ?>">change it on My Profile</a>.</span>
      <?php if (!empty($errors['contact'])): ?><span class="field-error"><?= e($errors['contact']) ?></span><?php endif; ?>
    </label>

    <label class="field-label">
      <span>Email *</span>
      <input type="email" name="email" placeholder="name@email.com" value="<?= e($draft['email']) ?>" autocomplete="email" class="<?= $invalid('email') ?>">
      <?php if (!empty($errors['email'])): ?><span class="field-error"><?= e($errors['email']) ?></span><?php endif; ?>
    </label>

    <label class="field-label">
      <span>Birthdate *</span>
      <input type="date" name="birthdate" value="<?= e($draft['birthdate']) ?>" max="<?= e(today_iso()) ?>" class="<?= $invalid('birthdate') ?>">
      <?php if (!empty($errors['birthdate'])): ?><span class="field-error"><?= e($errors['birthdate']) ?></span><?php endif; ?>
    </label>

    <label class="field-label">
      <span>Sex *</span>
      <select name="sex" class="<?= $invalid('sex') ?>">
        <option value="">Select</option>
        <option value="Female" <?= $draft['sex'] === 'Female' ? 'selected' : '' ?>>Female</option>
        <option value="Male" <?= $draft['sex'] === 'Male' ? 'selected' : '' ?>>Male</option>
      </select>
      <?php if (!empty($errors['sex'])): ?><span class="field-error"><?= e($errors['sex']) ?></span><?php endif; ?>
    </label>

    <label class="field-label field-full">
      <span>Civil status *</span>
      <select name="civilStatus" class="<?= $invalid('civilStatus') ?>">
        <option value="">Select civil status</option>
        <?php foreach (CIVIL_STATUS_OPTIONS as $c): ?>
          <option value="<?= e($c) ?>" <?= $draft['civilStatus'] === $c ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($errors['civilStatus'])): ?><span class="field-error"><?= e($errors['civilStatus']) ?></span><?php endif; ?>
    </label>

    <label class="field-label field-full">
      <span>Solo parent category *</span>
      <select name="categoryId" id="circumstance-select" class="<?= $invalid('circumstance') ?>">
        <option value="">Select your category</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) $draft['categoryId'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($errors['circumstance'])): ?><span class="field-error"><?= e($errors['circumstance']) ?></span><?php endif; ?>
      <div id="category-doc-box" class="info-box" style="margin-bottom:0;max-width:none;display:none"></div>
    </label>
  </div>

  <div class="form-nav-buttons">
    <button type="submit" name="action" value="save_draft" formnovalidate class="btn-outline">Save &amp; finish later</button>
    <button type="submit" name="action" value="continue" class="btn-navy">Continue</button>
  </div>
</form>

<script>
  window.CATEGORY_DOCS = <?= json_encode($categoryDocs, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
