<?php
/** @var array $preparationItems Normalized requirements with applicant_has. */
require_once APP_ROOT . '/models/Document.php';
$toPrepare = array_filter($preparationItems, [Document::class, 'needsPreparation']);
?>
<div class="info-box" style="max-width:none" role="status" data-preparation-summary>
  <strong data-i18n data-preparation-heading><?= $toPrepare ? 'Documents You Still Need to Prepare' : 'No unchecked required documents to prepare' ?></strong>
  <ul data-preparation-list<?= !$toPrepare ? ' hidden' : '' ?>>
    <?php foreach ($toPrepare as $item): ?>
      <li><a href="<?= base_url('applicant/dashboard.php?section=requirements&doc=' . rawurlencode($item['doc_key'])) ?>" data-i18n><?= e($item['label']) ?></a></li>
    <?php endforeach; ?>
  </ul>
  <p class="mb-0" data-i18n>Prepare the listed documents and bring them to your interview, along with all other required originals and photocopies as instructed by MSWDO. You may submit with unchecked requirements. Checkbox selections declare availability; MSWDO verifies physical documents and assesses eligibility.</p>
</div>
