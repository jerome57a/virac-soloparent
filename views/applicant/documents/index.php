<?php
/** @var array $myApplications @var array $checklistByApp @var array $uploadedByApp @var string|null $flashMessage */
?>
<div class="applicant-documents">
  <?php if ($flashMessage): ?><div class="info-box" role="status"><?= e($flashMessage) ?></div><?php endif; ?>
  <?php if (!$myApplications): ?>
    <div class="empty-state"><div class="empty-state-title" data-i18n>No application documents yet</div><p data-i18n>Start an application to see and submit its required documents.</p><a href="?section=apply" class="btn-navy" data-i18n>Start an Application</a></div>
  <?php else: ?>
    <p class="document-checklist-intro" data-i18n>Upload a clear scan or photo for each required document. Staff will review each file and may ask you to replace it.</p>
    <p><a href="?section=requirements" data-i18n>Browse requirement guides &rarr;</a></p>
    <?php foreach ($myApplications as $app):
      $items = $checklistByApp[$app['id']] ?? [];
      $uploads = $uploadedByApp[$app['id']] ?? [];
      $editable = in_array($app['status'], Application::CHECKLIST_EDITABLE_STATUSES, true);
      $applicationStyle = status_style($app['status']);
    ?>
      <section class="document-checklist-card mb-4" aria-labelledby="document-checklist-<?= (int) $app['id'] ?>">
        <header class="document-checklist-header">
          <div><span class="document-checklist-eyebrow" data-i18n><?= $app['type'] === 'renewal' ? 'ID renewal' : 'Solo Parent ID application' ?></span><h2 id="document-checklist-<?= (int) $app['id'] ?>"><?= e($app['reference_no']) ?></h2><p><?= e($app['category_name']) ?></p></div>
          <span class="status-pill" style="background:<?= e($applicationStyle['bg']) ?>;color:<?= e($applicationStyle['color']) ?>"><?= e($app['status']) ?></span>
        </header>
        <?php if (!$editable): ?><p class="document-checklist-lock" role="note" data-i18n>Uploads are closed while this application is <?= e($app['status']) ?>.</p><?php endif; ?>
        <?php foreach ($items as $item):
          $upload = $uploads[$item['doc_key']] ?? null;
          $acceptedLegacy = $item['verification_status'] === 'Verified' && (!empty($item['reviewed_by']) || !empty($item['source_checklist_id']));
          $status = $upload['verification_status'] ?? ($acceptedLegacy ? 'VERIFIED' : '');
          $label = $status !== '' ? document_status_label($status) : 'Not submitted';
          $style = $status !== '' ? document_status_style($status) : ['bg' => '#f1f3f5', 'color' => '#555'];
          $canUpload = $editable && (!$upload || $upload['verification_status'] !== 'VERIFIED') && !$acceptedLegacy;
          $inputId = 'document-' . (int) $app['id'] . '-' . e($item['doc_key']);
        ?>
          <article class="document-checklist-item mb-3">
            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
              <div><strong><?= e($item['label']) ?></strong> <span class="document-checklist-required"><?= !empty($item['is_required']) ? 'Required' : 'Optional' ?></span></div>
              <span class="status-pill" style="background:<?= e($style['bg']) ?>;color:<?= e($style['color']) ?>"><?= e($label) ?></span>
            </div>
            <?php if ($upload): ?>
              <p class="small mb-1"><a href="<?= base_url('download.php?doc_id=' . (int) $upload['id']) ?>" target="_blank" rel="noopener"><?= e($upload['original_name']) ?></a> | <a href="<?= base_url('download.php?doc_id=' . (int) $upload['id'] . '&download=1') ?>" data-i18n>Download</a> · <?= e(date('M j, Y g:i A', strtotime($upload['uploaded_at']))) ?></p>
            <?php elseif ($acceptedLegacy): ?>
              <p class="small text-muted" data-i18n>Previously verified record retained for this renewal.</p>
            <?php endif; ?>
            <?php if (!empty($upload['staff_remarks'])): ?><p class="document-checklist-remark"><strong data-i18n>Staff remarks:</strong> <?= e($upload['staff_remarks']) ?></p><?php endif; ?>
            <?php if ($canUpload): ?>
              <form method="post" action="<?= base_url('applicant/dashboard.php?section=documents') ?>" enctype="multipart/form-data" class="mt-2 d-flex flex-wrap align-items-end gap-2">
                <?= csrf_field() ?><input type="hidden" name="action" value="upload_document"><input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>"><input type="hidden" name="doc_key" value="<?= e($item['doc_key']) ?>">
                <div><label class="form-label" for="<?= $inputId ?>"><?= $upload ? 'Choose replacement file' : 'Choose file' ?></label><input class="form-control" type="file" id="<?= $inputId ?>" name="file" accept=".png,.jpg,.jpeg,image/png,image/jpeg" required></div>
                <button type="submit" class="btn-navy"><?= $upload ? 'Replace document' : 'Upload document' ?></button>
              </form>
              <small class="text-muted" data-i18n>Accepted formats: JPG, JPEG, PNG only. Maximum size: <?= e((string) document_rules()['max_mb']) ?> MB.</small>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
        <?php if (!$items): ?><p class="document-checklist-lock" data-i18n>No document requirements are configured for this application.</p><?php endif; ?>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
