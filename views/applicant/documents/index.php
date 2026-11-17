<?php
/** @var array $myApplications @var array $checklistByApp @var array $uploadedByApp @var string|null $flashMessage */
$rules = document_rules();
$maxMb = (float) $rules['max_mb'];
$icons = [
    'check' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>',
    'clock' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    'alert' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v5M12 18h.01"/></svg>',
    'upload' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M4 20h16"/></svg>',
];
?>
<div class="applicant-documents docs-page">
  <?php if ($flashMessage): ?><div class="info-box" role="status"><?= e($flashMessage) ?></div><?php endif; ?>
  <?php if (!$myApplications): ?>
    <div class="empty-state"><div class="empty-state-title" data-i18n>No application documents yet</div><p data-i18n>Start an application to see and submit its required documents.</p><a href="?section=apply" class="btn-navy" data-i18n>Start an Application</a></div>
  <?php else: ?>
    <p class="docs-intro">Upload a clear photo of each required document. Staff check every file. If one is not accepted, you will see here why, and you can send a new file. <a href="?section=requirements">See what each document should look like &rarr;</a></p>
    <?php foreach ($myApplications as $app):
      $items = $checklistByApp[$app['id']] ?? [];
      $uploads = $uploadedByApp[$app['id']] ?? [];
      $editable = in_array($app['status'], Application::CHECKLIST_EDITABLE_STATUSES, true);
      $applicationStyle = status_style($app['status']);

      // One plain state per document: verified | review (waiting for staff) | fix (must replace) | missing (not uploaded yet).
      $rows = ['fix' => [], 'missing' => [], 'review' => [], 'verified' => []];
      $requiredTotal = $requiredDone = 0;
      foreach ($items as $item) {
          $upload = $uploads[$item['doc_key']] ?? null;
          $acceptedLegacy = $item['verification_status'] === 'Verified' && (!empty($item['reviewed_by']) || !empty($item['source_checklist_id']));
          $raw = $upload['verification_status'] ?? ($acceptedLegacy ? 'VERIFIED' : '');
          if ($raw === 'VERIFIED') { $state = 'verified'; }
          elseif ($raw === '') { $state = 'missing'; }
          elseif (in_array($raw, ['NEEDS_CORRECTION', 'INVALID', 'REJECTED'], true)) { $state = 'fix'; }
          else { $state = 'review'; }
          $required = !empty($item['is_required']);
          if ($required) { $requiredTotal++; if ($state === 'verified') { $requiredDone++; } }
          $rows[$state][] = ['item' => $item, 'upload' => $upload, 'legacy' => $acceptedLegacy && !$upload, 'required' => $required];
      }
      $missingRequired = array_values(array_filter($rows['missing'], fn ($r) => $r['required']));
      $missingOptional = array_values(array_filter($rows['missing'], fn ($r) => !$r['required']));
      $needAction = count($rows['fix']) + count($missingRequired);
      $percent = $requiredTotal > 0 ? (int) round($requiredDone * 100 / $requiredTotal) : 0;

      // The one sentence that tells the applicant what to do next.
      if (!$editable) { $nextTone = 'info'; $nextText = 'Uploads are closed while this application is ' . $app['status'] . '.'; }
      elseif ($rows['fix']) { $nextTone = 'fix'; $n = count($rows['fix']); $m = count($missingRequired); $nextText = 'MSWDO asked you to replace ' . $n . ' document' . ($n === 1 ? '' : 's') . ($m ? ' and ' . $m . ' required document' . ($m === 1 ? ' is' : 's are') . ' still missing' : '') . '. Find ' . ($n + $m === 1 ? 'it' : 'them') . ' under "Needs your action" below, read the reason, and upload a new file.'; }
      elseif ($needAction > 0) { $nextTone = 'todo'; $nextText = 'Upload the ' . $needAction . ' missing required document' . ($needAction === 1 ? '' : 's') . ' below so staff can review ' . ($needAction === 1 ? 'it' : 'them') . '.'; }
      elseif ($rows['review']) { $nextTone = 'wait'; $nextText = 'Everything is submitted. Staff are reviewing your documents. You do not need to do anything now; we will text you if something needs to be fixed.'; }
      else { $nextTone = 'done'; $nextText = 'All required documents are verified. Next, wait for MSWDO to schedule your interview, then check the Appointment page.'; }

      $renderCard = function (array $row, string $state) use ($app, $editable, $maxMb, $rules, $icons) {
          $item = $row['item']; $upload = $row['upload'];
          $canUpload = $editable && in_array($state, ['fix', 'missing', 'review'], true) && ($upload === null || ($upload['verification_status'] ?? '') !== 'VERIFIED');
          $inputId = 'document-' . (int) $app['id'] . '-' . preg_replace('/[^a-z0-9_-]/i', '', (string) $item['doc_key']);
          $pill = [
              'fix' => ['Replace this file', '#fde8e8', '#a51c1c', 'alert'],
              'missing' => [$row['required'] ? 'Not uploaded yet' : 'Optional', '#f1f3f5', '#555', 'upload'],
              'review' => ['Waiting for staff', '#fdf1d6', '#8a6d1a', 'clock'],
              'verified' => ['Verified', '#dcf3e2', '#1c7a3f', 'check'],
          ][$state];
          ?>
          <article class="docs-item docs-item--<?= $state ?>" id="doc-<?= (int) $app['id'] ?>-<?= preg_replace('/[^a-z0-9_-]/i', '', (string) $item['doc_key']) ?>">
            <div class="docs-item-head">
              <span class="docs-item-icon" style="background:<?= $pill[1] ?>;color:<?= $pill[2] ?>"><?= $icons[$pill[3]] ?></span>
              <div class="docs-item-title">
                <strong><?= e($item['label']) ?></strong>
                <span class="docs-item-sub"><?= $row['required'] ? 'Required' : 'Optional' ?></span>
              </div>
              <span class="status-pill" style="background:<?= $pill[1] ?>;color:<?= $pill[2] ?>"><?= e($pill[0]) ?></span>
            </div>

            <?php if ($state === 'fix'): ?>
              <div class="docs-reason" role="alert">
                <b>Why you need to send a new file</b>
                <p><?= !empty($upload['staff_remarks']) ? e($upload['staff_remarks']) : 'MSWDO could not accept this file. Please upload a clearer or correct one.' ?></p>
              </div>
            <?php elseif ($state === 'review'): ?>
              <p class="docs-note">Staff have your file and will check it soon. You can send a different file if you uploaded the wrong one.</p>
            <?php elseif ($state === 'verified'): ?>
              <p class="docs-note"><?= $row['legacy'] ? 'Verified earlier and kept for this renewal.' : 'Checked and accepted by MSWDO. Nothing to do.' ?></p>
            <?php elseif ($canUpload): ?>
              <p class="docs-note">This document has not been uploaded yet.</p>
            <?php endif; ?>

            <?php if ($upload): ?>
              <p class="docs-file">
                <span class="docs-file-label"><?= $state === 'fix' ? 'Your current file:' : 'Your file:' ?></span>
                <a href="<?= base_url('download.php?doc_id=' . (int) $upload['id']) ?>" target="_blank" rel="noopener"><?= e($upload['original_name']) ?></a>
                <span class="docs-file-date"><?= e(date('M j, Y g:i A', strtotime($upload['uploaded_at']))) ?></span>
              </p>
            <?php endif; ?>

            <?php if ($canUpload): ?>
              <form method="post" action="<?= base_url('applicant/dashboard.php?section=documents') ?>" enctype="multipart/form-data" class="docs-form" data-max-mb="<?= e((string) $maxMb) ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="upload_document"><input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>"><input type="hidden" name="doc_key" value="<?= e($item['doc_key']) ?>">
                <label class="docs-drop" for="<?= $inputId ?>">
                  <input class="docs-input" type="file" id="<?= $inputId ?>" name="file" accept=".png,.jpg,.jpeg,image/png,image/jpeg" required>
                  <span class="docs-drop-idle"><?= $icons['upload'] ?><span><b><?= $state === 'fix' ? 'Choose the new file' : ($upload ? 'Choose a different file' : 'Choose a file') ?></b><small>Tap here to pick a photo from your phone or computer</small></span></span>
                  <span class="docs-drop-chosen" hidden><img alt="" class="docs-preview"><span><b class="docs-chosen-name"></b><small class="docs-chosen-size"></small></span></span>
                </label>
                <div class="docs-form-msg" role="alert" hidden></div>
                <button type="submit" class="btn-navy docs-submit" disabled><?= $state === 'fix' ? 'Send new file' : ($upload ? 'Replace file' : 'Upload file') ?></button>
                <small class="docs-hint">JPG or PNG, up to <?= e(format_mb($maxMb)) ?>. Take the photo in good light and keep the whole document in view.</small>
              </form>
            <?php endif; ?>
          </article>
          <?php
      };
    ?>
      <section class="docs-app" aria-labelledby="document-checklist-<?= (int) $app['id'] ?>">
        <header class="docs-app-head">
          <div>
            <span class="docs-eyebrow"><?= $app['type'] === 'renewal' ? 'ID renewal' : 'Solo Parent ID application' ?></span>
            <h2 id="document-checklist-<?= (int) $app['id'] ?>"><?= e($app['reference_no']) ?></h2>
            <p><?= e($app['category_name']) ?></p>
          </div>
          <span class="status-pill" style="background:<?= e($applicationStyle['bg']) ?>;color:<?= e($applicationStyle['color']) ?>"><?= e($app['status']) ?></span>
        </header>

        <?php if ($requiredTotal > 0): ?>
          <div class="docs-progress" role="group" aria-label="Documents verified">
            <div class="docs-progress-text"><b><?= $requiredDone ?> of <?= $requiredTotal ?></b> required documents verified</div>
            <div class="docs-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $requiredTotal ?>" aria-valuenow="<?= $requiredDone ?>"><span style="width:<?= $percent ?>%"></span></div>
          </div>
        <?php endif; ?>
        <div class="docs-next docs-next--<?= $nextTone ?>" role="status"><?= e($nextText) ?><?php if ($nextTone === 'done'): ?> <a href="?section=appointment">Go to Appointment &rarr;</a><?php endif; ?></div>

        <?php $action = array_merge($rows['fix'], $missingRequired); ?>
        <?php if ($action && $editable): ?>
          <h3 class="docs-group-title docs-group-title--fix">Needs your action <span><?= count($action) ?></span></h3>
          <?php foreach ($rows['fix'] as $row) { $renderCard($row, 'fix'); } ?>
          <?php foreach ($missingRequired as $row) { $renderCard($row, 'missing'); } ?>
        <?php endif; ?>

        <?php if ($rows['review']): ?>
          <h3 class="docs-group-title">Waiting for staff to check <span><?= count($rows['review']) ?></span></h3>
          <?php foreach ($rows['review'] as $row) { $renderCard($row, 'review'); } ?>
        <?php endif; ?>

        <?php if ($missingOptional && $editable): ?>
          <h3 class="docs-group-title">Optional documents <span><?= count($missingOptional) ?></span></h3>
          <?php foreach ($missingOptional as $row) { $renderCard($row, 'missing'); } ?>
        <?php endif; ?>

        <?php if ($rows['verified']): ?>
          <h3 class="docs-group-title docs-group-title--ok">Verified <span><?= count($rows['verified']) ?></span></h3>
          <?php foreach ($rows['verified'] as $row) { $renderCard($row, 'verified'); } ?>
        <?php endif; ?>

        <?php if (!$editable && ($rows['fix'] || $rows['missing'])): ?>
          <?php foreach (array_merge($rows['fix'], $rows['missing']) as $row) { $renderCard($row, $row['upload'] ? 'fix' : 'missing'); } ?>
        <?php endif; ?>
        <?php if (!$items): ?><p class="docs-next docs-next--info">No document requirements are configured for this application.</p><?php endif; ?>
      </section>
    <?php endforeach; ?>

    <script>
    (() => {
      const fmt = (b) => b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
      document.querySelectorAll('.docs-form').forEach((form) => {
        const input = form.querySelector('.docs-input'), submit = form.querySelector('.docs-submit'), msg = form.querySelector('.docs-form-msg');
        const idle = form.querySelector('.docs-drop-idle'), chosen = form.querySelector('.docs-drop-chosen');
        const img = form.querySelector('.docs-preview'), maxBytes = parseFloat(form.dataset.maxMb || '5') * 1048576;
        const reset = (text) => { input.value = ''; chosen.hidden = true; idle.hidden = false; submit.disabled = true; msg.hidden = !text; msg.textContent = text || ''; };
        input.addEventListener('change', () => {
          const f = input.files && input.files[0];
          if (!f) return reset('');
          if (!/\.(jpe?g|png)$/i.test(f.name) || !/^image\/(jpeg|png)$/.test(f.type || 'image/jpeg')) return reset('Please choose a JPG or PNG photo.');
          if (f.size > maxBytes) return reset('That file is ' + fmt(f.size) + '. The limit is ' + fmt(maxBytes) + '. Please choose a smaller photo.');
          msg.hidden = true;
          form.querySelector('.docs-chosen-name').textContent = f.name;
          form.querySelector('.docs-chosen-size').textContent = fmt(f.size) + ' · ready to send';
          if (img.dataset.url) URL.revokeObjectURL(img.dataset.url);
          img.dataset.url = URL.createObjectURL(f); img.src = img.dataset.url;
          idle.hidden = true; chosen.hidden = false; submit.disabled = false;
        });
        form.addEventListener('submit', () => { submit.disabled = true; submit.textContent = 'Sending…'; });
        window.addEventListener('pageshow', () => { if (!input.files || !input.files.length) reset(''); });
      });
    })();
    </script>
  <?php endif; ?>
</div>
