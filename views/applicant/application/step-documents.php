<?php
/** @var array $draft @var array $errors @var array $docDefs @var array $catOnlyKeys @var array $categoryMap */
$categoryName = $draft['categoryId'] ? ($categoryMap[$draft['categoryId']] ?? '') : '';
?>

<?php if ($categoryName): ?>
  <div style="font-size:13px;color:var(--body-text);background:var(--surface-alt);border-radius:6px;padding:10px 14px;margin-bottom:12px">
    Category: <b><?= e($categoryName) ?></b>
  </div>
<?php endif; ?>

<?php foreach ($docDefs as $d):
    $docKey = $d['doc_key'];
    $meta = $draft['documents'][$docKey] ?? null;
    $err = $draft['docErrors'][$docKey] ?? ($errors['doc_' . $docKey] ?? '');
    $isCat = in_array($docKey, $catOnlyKeys, true);
?>
  <div class="doc-row <?= $err ? 'has-error' : '' ?>">
    <div style="min-width:0;flex:1 1 200px">
      <?php if ($isCat): ?><div class="doc-tag">CATEGORY REQUIREMENT</div><?php endif; ?>
      <div class="doc-label"><?= e($d['label']) ?> <span class="req"><?= $d['is_required'] ? '*' : '(optional)' ?></span></div>
      <div class="doc-filename <?= $meta ? 'attached' : '' ?>"><?= $meta ? e($meta['original_name']) : 'No file selected' ?></div>
      <?php if ($err): ?><div class="doc-error"><?= e($err) ?></div><?php endif; ?>
    </div>
    <div class="doc-actions">
      <?php if ($meta): ?>
        <form method="post" class="m-0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="remove_doc">
          <input type="hidden" name="doc_key" value="<?= e($docKey) ?>">
          <button type="submit" formnovalidate class="btn-doc-remove">Remove</button>
        </form>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data" class="m-0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload_doc">
        <input type="hidden" name="doc_key" value="<?= e($docKey) ?>">
        <label class="btn-doc-choose">
          <?= $meta ? 'Replace' : 'Choose file' ?>
          <input type="file" name="file" data-doc-input accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" style="display:none" onchange="this.form.submit()">
        </label>
      </form>
    </div>
  </div>
<?php endforeach; ?>
<div style="font-size:12px;color:var(--faint);margin-bottom:20px">Accepted: JPG, PNG, or PDF, max 5MB each.</div>

<form method="post">
  <?= csrf_field() ?>
  <div class="form-nav-buttons">
    <button type="submit" name="action" value="back" formnovalidate class="btn-outline">Back</button>
    <button type="submit" name="action" value="continue" class="btn-navy">Continue</button>
  </div>
</form>
