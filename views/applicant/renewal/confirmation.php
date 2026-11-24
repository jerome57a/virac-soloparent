<?php
/**
 * Renew an existing Solo Parent ID (one page). The form opens before expiry so the Solo Parent can prepare it, can be submitted from
 * the expiration date, and closes after the renewal period (then a new application is needed). The Solo Parent may update civil
 * status and children (with "child with a disability", RA 11861); every renewal is followed by an interview.
 *
 * @var ?array  $renewalSource      the current released ID, with its children
 * @var ?array  $renewalInProgress  a renewal already being processed
 * @var ?array  $renewalWindow      RenewalService::window()
 * Form only (window open): $draft, $errors, $submitError, $docDefs, $renewalPlan, $flashMessage, $childOnDate
 */
$fmt = fn(?string $d) => $d ? format_date_long($d) : '—';
?>
<?php require_once APP_ROOT . '/includes/icons.php'; ?>
<link rel="stylesheet" href="<?= public_asset('css/children.css') ?>">
<?php if (!$renewalSource): ?>
  <div class="info-box" data-i18n>You need a released ID before renewing. <a href="?section=application" data-i18n>View My Application</a>.</div>
<?php else: $w = $renewalWindow; $today = date('Y-m-d'); ?>
  <div class="review-card">
    <div class="review-card-head"><div class="title" data-i18n>Renew your Solo Parent ID</div>
      <?php if ($w['closed']): ?><span class="status-pill" style="background:#fde8e8;color:#a51c1c" data-i18n>Renewal period ended</span>
      <?php elseif ($w['submitOpen']): ?><span class="status-pill" style="background:#dcf3e2;color:#1c7a3f" data-i18n>Ready to submit</span>
      <?php else: ?><span class="status-pill" style="background:#f1f1f1;color:#555" data-i18n>Not open yet</span><?php endif; ?>
    </div>
    <div class="review-grid">
      <div class="review-row"><div class="k" data-i18n>Reference</div><div class="v"><?= e($renewalSource['reference_no']) ?></div></div>
      <div class="review-row"><div class="k" data-i18n>Name</div><div class="v"><?= e($renewalSource['full_name']) ?></div></div>
      <div class="review-row"><div class="k" data-i18n>Category</div><div class="v"><?= e($renewalSource['category_name']) ?></div></div>
      <div class="review-row"><div class="k" data-i18n>ID expires</div><div class="v"><b><?= e($fmt($w['expiresAt'])) ?></b></div></div>
    </div>
    <?php if ($w['expiresAt']): ?>
      <ol class="renewal-timeline" aria-label="Renewal dates" style="list-style:none;display:flex;flex-wrap:wrap;gap:8px;padding:0;margin:14px 0 0">
        <?php foreach ([
            ['Renew online', 'from ' . $fmt($w['submitFrom']) . ' (the day your ID expires)', $w['submitOpen']],
            ['Renewal closes', $fmt($w['closesOn']) . ' — then apply again', $w['closed']],
        ] as $i => [$title, $when, $current]): ?>
          <li style="flex:1 1 180px;border:1px solid var(--border, #d9e1ec);border-radius:10px;padding:8px 10px;<?= $current ? 'border-color:#1b3a6b;box-shadow:0 0 0 2px rgba(27,58,107,.15);' : '' ?>">
            <div style="font-size:12px;color:var(--muted)">Step <?= $i + 1 ?></div>
            <div style="font-weight:700;color:var(--ink)" data-i18n><?= e($title) ?></div>
            <div class="small" style="color:var(--muted)"><?= e($when) ?></div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>

  <?php if ($renewalInProgress): ?>
    <div class="info-box" data-i18n>Renewal <?= e($renewalInProgress['reference_no']) ?> is already being processed. <a href="?section=renewal&amp;type=status" data-i18n>View Renewal Status</a>.</div>
  <?php elseif ($w['closed']): ?>
    <div class="review-card">
      <div class="review-card-head"><div class="title" data-i18n>The renewal period has ended</div></div>
      <p data-i18n>Your ID expired on <?= e($fmt($w['expiresAt'])) ?>, more than <?= (int) $w['graceDays'] ?> days ago, so it can no longer be renewed. Please apply again as a new application: you will submit the full set of documents and have an interview.</p>
      <a href="?section=apply" class="btn-navy text-decoration-none" style="display:inline-block" data-i18n>Apply for a Solo Parent ID</a>
    </div>
  <?php elseif (!$w['open']): ?>
    <div class="info-box" data-i18n>Renewal opens on <b><?= e($fmt($w['opensOn'])) ?></b>, the day your ID expires. Log in on that day to renew.</div>
  <?php elseif (empty($renewalPlan['configured'])): ?>
    <div class="info-box" data-i18n>Renewal requirements for your category are not configured yet. <a href="?section=help" data-i18n>Contact MSWDO for the correct renewal documents</a>.</div>
  <?php else: $childOnDate = $childOnDate ?? $today; $kids = $draft['children'] ?: [['name' => '', 'birthdate' => '', 'disability' => false]]; ?>
    <?php if ($flashMessage): ?><div class="info-box" role="status"><?= e($flashMessage) ?></div><?php endif; ?>
    <?php if ($submitError): ?><div class="auth-error" role="alert"><?= e($submitError) ?></div><?php endif; ?>
    <?php foreach ($errors as $message): ?><div class="auth-error" role="alert"><?= e($message) ?></div><?php endforeach; ?>

    <form method="post" enctype="multipart/form-data" class="d-flex flex-column gap-3" id="renewal-form">
      <?= csrf_field() ?>

      <section class="review-card d-flex flex-column gap-2">
        <h2 class="font-heading" style="font-size:19px;margin:0" data-i18n>1. Your contact details</h2>
        <div class="field-grid">
          <label class="user-label" data-i18n>Barangay<input class="form-control" name="barangay" maxlength="100" required value="<?= e($draft['barangay']) ?>"></label>
          <label class="user-label" data-i18n>Complete address<input class="form-control" name="address" maxlength="255" required value="<?= e($draft['address']) ?>"></label>
          <label class="user-label" data-i18n>Confirmed mobile<input class="form-control" readonly value="<?= e($draft['contact']) ?>"><span class="opt" data-i18n>Change your number on My Profile.</span></label>
          <label class="user-label" data-i18n>Email (optional)<input class="form-control" type="email" name="email" maxlength="150" value="<?= e($draft['email']) ?>"></label>
        </div>
      </section>

      <section class="review-card d-flex flex-column gap-2">
        <h2 class="font-heading" style="font-size:19px;margin:0" data-i18n>2. Civil status</h2>
        <label class="user-label" style="max-width:360px" data-i18n>Your civil status today
          <select name="civilStatus" class="form-select mt-1" id="renewal-civil" data-previous="<?= e((string) $renewalSource['civil_status']) ?>" required>
            <?php foreach ((defined('CIVIL_STATUS_OPTIONS') ? CIVIL_STATUS_OPTIONS : ['Single', 'Married', 'Widowed', 'Separated', 'Annulled']) as $c): ?><option value="<?= e($c) ?>" <?= ($draft['civilStatus'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
          </select>
        </label>
        <div class="small text-muted" data-i18n>On your current ID: <?= e((string) $renewalSource['civil_status']) ?>.</div>
        <div class="auth-error d-none" id="renewal-civil-note" role="status" style="max-width:none;margin:0" data-i18n>You changed your civil status to <b>Married</b>. A Solo Parent who married or lives with a partner may no longer qualify (RA 11861). You can still submit; MSWDO will check it at your interview.</div>
      </section>

      <section class="review-card d-flex flex-column gap-2">
        <h2 class="font-heading" style="font-size:19px;margin:0" data-i18n>3. Your children</h2>
        <p class="small mb-1" style="color:var(--muted)" data-i18n>List the children living with you and depending on you, who are unmarried and unemployed. Under RA 11861 a child qualifies when <b>22 years old or younger</b>, or <b>over 22 with a disability</b> that keeps them from fully taking care of themselves. At least one child must qualify.</p>
        <div data-child-rules data-on-date="<?= e($childOnDate) ?>" data-max="<?= (int) SoloParentChildRules::MAX_AGE ?>" data-pwd="<?= !empty($draft['hasPwdChild']) ? '1' : '0' ?>" data-proof="#renewal-proof">
          <fieldset class="cr-question">
            <legend><?= ui_icon('info', 16) ?><span data-i18n>Do you have a child with a disability (PWD)?</span></legend>
            <div class="cr-radios">
              <label><input type="radio" class="js-pwd-answer" name="has_pwd_child" value="1" <?= !empty($draft['hasPwdChild']) ? 'checked' : '' ?>><span data-i18n>Yes</span></label>
              <label><input type="radio" class="js-pwd-answer" name="has_pwd_child" value="0" <?= !empty($draft['hasPwdChild']) ? '' : 'checked' ?>><span data-i18n>No</span></label>
            </div>
            <small data-i18n>A child over 22 can still qualify if they have a disability.</small>
          </fieldset>
          <div id="renewal-children" class="d-flex flex-column gap-2">
            <?php foreach ($kids as $i => $child): ?>
              <div class="cr-row js-child renewal-child">
                <div class="row g-2 align-items-end">
                  <label class="col-12 col-md-6 user-label mb-0" data-i18n>Child's full name<input class="form-control" name="children[<?= $i ?>][name]" maxlength="150" value="<?= e((string) ($child['name'] ?? '')) ?>"></label>
                  <label class="col-7 col-md-4 user-label mb-0" data-i18n>Birthdate<input class="form-control js-child-birth" type="date" max="<?= e($today) ?>" name="children[<?= $i ?>][birthdate]" value="<?= e((string) ($child['birthdate'] ?? '')) ?>"></label>
                  <div class="col-5 col-md-2 text-md-end"><button type="button" class="btn-outline js-child-remove" style="padding:4px 10px;font-size:12px" data-i18n>Remove</button></div>
                </div>
                <label class="cr-tick"><input type="checkbox" class="js-child-pwd" name="children[<?= $i ?>][disability]" value="1" <?= !empty($child['disability']) ? 'checked' : '' ?>><span><?= ui_icon('shield-check', 16) ?><span data-i18n>This child has a disability (PWD)</span></span></label>
                <div class="cr-live js-child-live" role="status"></div>
              </div>
            <?php endforeach; ?>
          </div>
          <button type="button" id="renewal-add-child" class="btn-outline align-self-start mt-2" style="font-size:13px" data-i18n>+ Add a child</button>
          <div class="cr-none js-child-none mt-2" role="alert" hidden>
            <?= ui_icon('triangle-alert', 22) ?>
            <div><b data-i18n>No child qualifies, so a Solo Parent ID cannot be issued (RA 11861).</b><span data-i18n>Add a child who is 22 or younger, or tick "This child has a disability" for a child over 22. If you need help, contact MSWDO.</span></div>
          </div>
        </div>
      </section>

      <section class="review-card d-flex flex-column gap-2">
        <h2 class="font-heading" style="font-size:19px;margin:0" data-i18n>4. Documents</h2>
        <?php if ($renewalPlan['retained']): ?>
          <div class="small fw-semibold" style="color:var(--ink)" data-i18n>Kept from your current ID (no need to upload again)</div>
          <?php foreach ($renewalPlan['retained'] as $item): ?>
            <div class="review-row"><div class="k" data-i18n><?= e($item['label']) ?></div><div class="v small" data-i18n>Accepted on file. <?= !empty($item['expires_at']) ? 'Valid until ' . e(format_date_long($item['expires_at'])) : '' ?></div></div>
          <?php endforeach; ?>
        <?php endif; ?>
        <div class="small fw-semibold mt-2" style="color:var(--ink)" data-i18n>Upload for this renewal</div>
        <?php
          $proofKey = RenewalService::DISABILITY_PROOF_KEY;
          $proofListed = in_array($proofKey, array_column($docDefs, 'doc_key'), true);
          $defs = $docDefs;
          if (!$proofListed) { // shown by the page as soon as a child over 22 is ticked "with a disability"
              $defs[] = ['doc_key' => $proofKey, 'label' => 'PWD ID or medical certificate of the child with a disability', 'reason' => 'Needed because a child over 22 is marked as having a disability (RA 11861).', 'hidden' => true];
          }
        ?>
        <?php foreach ($defs as $item):
          $uploaded = $draft['documents'][$item['doc_key']] ?? null;
          $inputId = 'renewal-file-' . e($item['doc_key']);
          $isProof = $item['doc_key'] === $proofKey;
        ?>
          <div class="user-label<?= !empty($item['hidden']) ? ' d-none' : '' ?>"<?= $isProof ? ' id="renewal-proof"' : '' ?> style="border-left:3px solid <?= $isProof ? '#b45309' : 'var(--border, #d9e1ec)' ?>;padding-left:10px">
            <label class="form-label mb-1" for="<?= $inputId ?>"><strong><?= e($item['label']) ?></strong> <span class="opt"><?= e($item['reason']) ?></span></label>
            <?php if ($uploaded): ?><p class="small mb-1"><span data-i18n>Selected file:</span> <?= e($uploaded['original_name']) ?></p><?php endif; ?>
            <input class="form-control" type="file" id="<?= $inputId ?>" name="renewal_file[<?= e($item['doc_key']) ?>]" accept=".png,.jpg,.jpeg,image/png,image/jpeg">
            <span class="opt" data-i18n><?= $uploaded ? 'Choose another file only to replace it.' : 'Choose a file to upload.' ?> PNG, JPG or JPEG, up to <?= e((string) document_rules()['max_mb']) ?> MB.</span>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="review-card d-flex flex-column gap-2">
        <h2 class="font-heading" style="font-size:19px;margin:0" data-i18n>5. What happens next</h2>
        <ol class="small mb-0" style="color:var(--ink);padding-left:18px">
          <li data-i18n>MSWDO staff check your documents.</li>
          <li data-i18n><b>You have an interview</b> at the MSWDO office, so MSWDO can confirm you still qualify as a Solo Parent. MSWDO sets the date and time; you can choose another day in your account.</li>
          <li class="renewal-bring-id-item" data-i18n><b>Bring your current Solo Parent ID and a valid ID</b> to the interview. MSWDO checks your ID; if you lost it, tell the staff and bring an affidavit of loss if you have one.</li>
          <li data-i18n>After a successful interview your renewal is approved and MSWDO schedules the release of your new ID. Only you can receive it.</li>
        </ol>
        <label class="d-flex align-items-start gap-2 mt-2" style="font-size:14px;color:var(--ink);cursor:pointer" data-i18n><input type="checkbox" name="consent" value="1" <?= !empty($draft['consent']) ? 'checked' : '' ?> required style="margin-top:3px;width:18px;height:18px;flex:0 0 auto"> <span>The details above are true, and I agree that MSWDO checks that I still qualify as a Solo Parent at an interview.</span></label>
      </section>

      <div class="d-flex flex-wrap gap-2 align-items-center">
        <button type="submit" name="action" value="save_draft" formnovalidate class="btn-outline" data-i18n>Save draft</button>
        <button type="submit" name="action" value="submit" class="btn-navy js-child-submit" <?= $w['submitOpen'] ? '' : 'disabled aria-disabled="true" title="You can submit from ' . e($fmt($w['submitFrom'])) . '"' ?> data-i18n>Submit Renewal</button>
        <?php if (!$w['submitOpen']): ?><span class="small" style="color:var(--muted)" data-i18n>Submit opens on <?= e($fmt($w['submitFrom'])) ?>.</span><?php endif; ?>
      </div>
      <p class="cr-why js-child-why" hidden><?= ui_icon('lock', 14) ?><span data-i18n>Submit unlocks when at least one child qualifies.</span></p>
    </form>

    <template id="renewal-child-template">
      <div class="cr-row js-child renewal-child">
        <div class="row g-2 align-items-end">
          <label class="col-12 col-md-6 user-label mb-0">Child's full name<input class="form-control" data-field="name" maxlength="150"></label>
          <label class="col-7 col-md-4 user-label mb-0">Birthdate<input class="form-control js-child-birth" type="date" max="<?= e($today) ?>" data-field="birthdate"></label>
          <div class="col-5 col-md-2 text-md-end"><button type="button" class="btn-outline js-child-remove" style="padding:4px 10px;font-size:12px">Remove</button></div>
        </div>
        <label class="cr-tick"><input type="checkbox" class="js-child-pwd" data-field="disability" value="1"><span><?= ui_icon('shield-check', 16) ?><span>This child has a disability (PWD)</span></span></label>
        <div class="cr-live js-child-live" role="status"></div>
      </div>
    </template>
    <script src="<?= public_asset('js/child-rules.js') ?>" defer></script>
    <script>
      (() => {
        const list = document.getElementById('renewal-children'), tpl = document.getElementById('renewal-child-template');
        const box = document.querySelector('[data-child-rules]');
        const refresh = () => { if (window.ChildRules) window.ChildRules.refresh(box); };
        const renumber = () => list.querySelectorAll('.renewal-child').forEach((row, i) => {
          row.querySelectorAll('[data-field], [name^="children["]').forEach(el => {
            const field = el.dataset.field || el.name.replace(/^children\[\d+\]\[(\w+)\]$/, '$1');
            el.dataset.field = field; el.name = 'children[' + i + '][' + field + ']';
          });
        });
        document.getElementById('renewal-add-child').addEventListener('click', () => {
          list.appendChild(tpl.content.cloneNode(true)); renumber(); refresh();
          if (window.ViracI18n) window.ViracI18n.refresh();
          list.lastElementChild.querySelector('[data-field="name"]').focus();
        });
        list.addEventListener('click', e => {
          if (!e.target.classList.contains('js-child-remove')) return;
          if (list.querySelectorAll('.renewal-child').length === 1) { e.target.closest('.renewal-child').querySelectorAll('input').forEach(i => { if (i.type === 'checkbox') i.checked = false; else i.value = ''; }); }
          else { e.target.closest('.renewal-child').remove(); renumber(); }
          refresh();
        });
        renumber();
        const civil = document.getElementById('renewal-civil'), civilNote = document.getElementById('renewal-civil-note');
        const civilCheck = () => civilNote.classList.toggle('d-none', !(civil.value === 'Married' && civil.dataset.previous !== 'Married'));
        civil.addEventListener('change', civilCheck); civilCheck();
      })();
    </script>
  <?php endif; ?>
<?php endif; ?>
