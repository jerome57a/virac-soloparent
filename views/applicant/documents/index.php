<?php /** @var array $myApplications @var array $checklistByApp @var array $editableStatuses */ ?>
<div class="applicant-document-checklists" data-i18n>
<?php if (!$myApplications): ?>
  <div class="empty-state document-checklist-empty"><div class="empty-state-title" data-i18n>No checklist yet</div><p data-i18n>Start an application to see the documents you need.</p><a href="?section=apply" class="btn-navy" data-i18n>Start an Application</a></div>
<?php else: ?>
  <div class="document-checklist-intro"><p data-i18n>Mark the documents you already have. Bring the physical documents to your interview. If documents are still missing after scheduling, staff must receive them before approval and ID release.</p><a href="?section=requirements" data-i18n>Browse requirement guides &rarr;</a></div>
  <?php foreach ($myApplications as $app):
    $editable = in_array($app['status'], $editableStatuses, true) && empty($scheduledInterviewByApp[$app['id']]);
    $items = $checklistByApp[$app['id']] ?? [];
    $availableCount = count(array_filter($items, fn($item) => !empty($item['applicant_has'])));
    $verifiedCount = count(array_filter($items, fn($item) => $item['verification_status'] === 'Verified'));
    $correctionCount = count(array_filter($items, fn($item) => $item['verification_status'] === 'Needs Correction'));
    $groups = [
      ['title' => 'Required documents', 'items' => array_filter($items, fn($item) => !empty($item['is_required']))],
      ['title' => 'Optional documents', 'items' => array_filter($items, fn($item) => empty($item['is_required']))],
    ];
    $applicationStyle = status_style($app['status']);
  ?>
  <section class="document-checklist-card" aria-labelledby="document-checklist-<?= (int) $app['id'] ?>">
    <header class="document-checklist-header">
      <div><span class="document-checklist-eyebrow" data-i18n><?= $app['type'] === 'renewal' ? 'ID renewal' : 'Solo Parent ID application' ?></span><h2 id="document-checklist-<?= (int) $app['id'] ?>"><?= e($app['reference_no']) ?></h2><p data-i18n><?= e($app['category_name']) ?></p></div>
      <span class="status-pill" style="background:<?= e($applicationStyle['bg']) ?>;color:<?= e($applicationStyle['color']) ?>" data-i18n><?= e($app['status']) ?></span>
    </header>
    <dl class="document-checklist-summary" aria-label="Saved checklist summary">
      <div><dt data-i18n>Saved as available</dt><dd><?= $availableCount ?> <span>of <?= count($items) ?></span></dd></div>
      <div><dt data-i18n>Verified</dt><dd><?= $verifiedCount ?></dd></div>
      <div><dt data-i18n>Needs correction</dt><dd><?= $correctionCount ?></dd></div>
    </dl>
    <?php if (!$editable): ?><p class="document-checklist-lock" role="note" data-i18n><?= !empty($scheduledInterviewByApp[$app['id']]) ? 'Your submitted checklist is read-only after interview scheduling. Staff will confirm any missing documents when received.' : 'This checklist is read-only while your application is ' . e($app['status']) . '.' ?></p><?php endif; ?>
    <form method="post" data-preparation-form>
      <?php $preparationItems = $items; require APP_ROOT . '/views/partials/requirements-preparation.php'; ?>
      <?= csrf_field() ?><input type="hidden" name="action" value="save_checklist"><input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
      <?php foreach ($groups as $group): if (!$group['items']) continue; ?>
        <section class="document-checklist-group" aria-label="<?= e($group['title']) ?>">
          <h3 data-i18n><?= e($group['title']) ?><span><?= count($group['items']) ?></span></h3>
          <?php foreach ($group['items'] as $item):
            $verificationClass = match ($item['verification_status']) { 'Verified' => 'is-verified', 'Needs Correction' => 'needs-correction', default => 'is-pending' };
            $acceptedRecord = $item['verification_status'] === 'Verified' && (!empty($item['reviewed_by']) || !empty($item['source_checklist_id']));
          ?>
          <div class="document-checklist-item<?= !$editable ? ' is-read-only' : '' ?>">
            <label class="document-checklist-select">
              <input type="checkbox" <?= !empty($item['is_required']) ? 'data-required-document="' . e($item['label']) . '" data-requirement-guide="' . e(base_url('applicant/dashboard.php?section=requirements&doc=' . rawurlencode($item['doc_key']))) . '"' : '' ?> data-staff-accepted="<?= ($acceptedRecord && (empty($item['expires_at']) || $item['expires_at'] >= date('Y-m-d'))) ? '1' : '0' ?>" name="checklist[]" value="<?= e($item['doc_key']) ?>" <?= !empty($item['applicant_has']) ? 'checked' : '' ?> <?= !$editable || $acceptedRecord || (isset($item['required_snapshot']) && !$item['required_snapshot'] && empty($item['is_required'])) ? 'disabled' : '' ?>>
              <span><span class="document-checklist-name" data-i18n><?= e($item['label']) ?></span><span class="document-checklist-required" data-i18n><?= !empty($item['is_required']) ? 'Required' : 'Optional' ?></span></span>
            </label>
            <?php if (!empty($item['is_required'])): ?><p class="document-checklist-remark" data-preparation-item-note<?= !Document::needsPreparation($item) ? ' hidden' : '' ?> data-i18n><strong data-i18n>Unchecked requirement:</strong> Prepare this document and bring it to your interview.</p><?php endif; ?>
            <div class="document-checklist-item-meta"><span class="document-verification <?= $verificationClass ?>" data-i18n><?= $item['verification_status'] === 'Verified' && !$acceptedRecord ? 'Automatically verified' : e($item['verification_status']) ?></span><a href="<?= base_url('applicant/dashboard.php?section=requirements&doc=' . rawurlencode($item['doc_key'])) ?>" target="_blank" rel="noopener" aria-label="How to get <?= e($item['label']) ?> (opens in a new tab)" data-i18n>How to get it <span aria-hidden="true">&nearr;</span></a></div>
            <?php if (!empty($item['staff_remarks'])): ?><p class="document-checklist-remark"><strong data-i18n>Staff note:</strong> <?= e($item['staff_remarks']) ?></p><?php endif; ?>
            <?php if (!empty($item['is_required']) && !empty($item['applicant_has']) && $item['verification_status'] === 'Pending'): ?><p class="document-checklist-remark" data-i18n>Bring or submit this document to MSWDO. Staff must record it as received before approval and ID release.</p><?php endif; ?>
            <?php if (isset($item['required_snapshot']) && !$item['required_snapshot'] && empty($item['is_required'])): ?><p class="document-checklist-remark" data-i18n>Accepted document retained from the existing ID. No new submission is required.</p><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>
      <?php if (!$items): ?><p class="document-checklist-lock" data-i18n>No documents are listed for this application. Contact MSWDO for guidance.</p><?php endif; ?>
      <?php if ($editable && $items): ?><footer class="document-checklist-actions"><p data-i18n>Click Save checklist to keep your changes.</p><button type="submit" class="btn-navy" data-i18n>Save checklist</button></footer><?php endif; ?>
    </form>
  </section>
  <?php endforeach; ?>
<?php endif; ?>
</div>

<script src="<?= base_url('public/js/requirements-preparation.js') ?>" defer></script>
