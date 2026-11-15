<?php
/** @var array $preparationItems Normalized requirements with applicant_has. */
require_once APP_ROOT . '/models/Document.php';
$toPrepare = array_filter($preparationItems, [Document::class, 'needsPreparation']);
?>
<div class="info-box" style="max-width:none" role="status" data-preparation-summary>
  <strong data-i18n data-preparation-heading><?= $toPrepare ? 'Documents You Still Need to Upload' : 'All required documents have been uploaded' ?></strong>
  <ul data-preparation-list<?= !$toPrepare ? ' hidden' : '' ?>>
    <?php foreach ($toPrepare as $item): ?>
      <li><a href="<?= base_url('applicant/dashboard.php?section=requirements&doc=' . rawurlencode($item['doc_key'])) ?>" data-i18n><?= e($item['label']) ?></a></li>
    <?php endforeach; ?>
  </ul>
  <p class="mb-0" data-i18n>Upload a file for each listed document from Documents in your applicant portal. An uploaded file is not verified until MSWDO staff review it, and staff may ask you to replace it. Bring originals and photocopies to your interview as instructed by MSWDO.</p>
</div>
