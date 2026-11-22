<?php
/**
 * @var array  $app
 * @var string $statusError
 * @var string $assessmentError
 * @var string $releaseError
 * @var string $interviewError
 * @var string $invitationError
 * @var string $invitationState  'off' (flag off: card hidden), 'incomplete' (flag on, schema not ready) or 'ready'
 * @var ?array $invitationPanel  InterviewInvitationService::panel(); null unless ready
 * @var ?string $flashMessage
 * @var array  $allowedTransitions  statuses the application can move to now (Application::TRANSITIONS)
 * @var array  $approvalChecklist   ApplicationReviewService::approvalChecklist()
 * @var array  $decisions, $notes  see views/partials/case-record.php
 * @var ?array $interview
 * @var array  $children
 * @var array  $history
 */
$st = status_style($app['status']);
$interviewOutcome = Appointment::interviewOutcome($interview);
$canDecide = !empty($app['renewal_simplified']) || Appointment::interviewSuccessful($interview);
// After an UNSUCCESSFUL interview staff may still request corrections (approval stays locked), schedule another interview, or reject.
$canCorrect = $canDecide || Appointment::interviewOutcome($interview) === 'Unsuccessful';
// A renewal with an interview: staff re-check that the Solo Parent still qualifies (RA 11861).
$isRenewalInterview = ($app['type'] ?? '') === 'renewal' && empty($app['renewal_simplified']);
// An interview the system released because nobody confirmed it: staff can still record that the Solo Parent attended.
$lateAttended = ($interview['status'] ?? '') === 'MISSED' && ($interview['notes'] ?? '') === 'Released automatically: no outcome was recorded';
// Rejecting needs no successful interview (only a reason); corrections and approval still do ($canDecide).
$canReject = in_array('Rejected', $allowedTransitions ?? [], true);
$interviewNotes = (string) ($interview['result'] ?? '');
$requiredChecklist = array_filter($submittedChecklist ?? [], fn($item) => !empty($item['is_required']));
$outstandingRequirements = array_filter($requiredChecklist, fn($item) => $item['verification_status'] !== 'Verified' || (!empty($item['expires_at']) && $item['expires_at'] < date('Y-m-d')));
$undoButton = function (string $section, string $label) use ($reviewUndoToken): void { ?>
  <form method="post" class="review-undo-form" onsubmit="return confirm('Undo this review and reopen its controls?');">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="undo_review">
    <input type="hidden" name="review_section" value="<?= e($section) ?>">
    <input type="hidden" name="expected_review" value="<?= e($reviewUndoToken) ?>">
    <button type="submit" class="btn-outline review-button-undo"><?= e($label) ?></button>
  </form>
<?php };
if ($interviewOutcome !== null) {
    $interviewNotes = $interviewNotes === $interviewOutcome ? '' : substr($interviewNotes, strlen($interviewOutcome) + 2);
}
?>
<div class="portal-container staff-application-review">
  <a href="<?= base_url('staff/index.php') ?>" class="staff-back-link"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5m7-7-7 7 7 7"/></svg><span data-i18n>Back</span></a>
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mt-2 mb-4">
    <div>
      <h1 class="font-heading m-0" style="font-size:26px"><?= e($app['reference_no']) ?> <?php if ($app['type'] === 'renewal'): ?><span class="doc-tag" style="vertical-align:middle" data-i18n>RENEWAL</span><?php endif; ?></h1>
      <div style="font-size:13px;color:var(--muted)" data-i18n>Submitted <?= e(date('F j, Y g:i A', strtotime($app['submitted_at']))) ?><?php if ($app['reviewer_name']): ?> · Reviewed by <?= e($app['reviewer_name']) ?><?php endif; ?></div>
    </div>
    <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>;font-size:14px"><?= e($app['status']) ?></div>
  </div>

  <div class="row g-4">
    <div class="col-12 col-lg-7">
      <?php if (isset($renewalOf)) { require APP_ROOT . '/views/partials/renewal-of.php'; } ?>
      <div class="review-card">
        <div class="review-card-head"><div class="title" data-i18n>Personal Info</div></div>
        <div class="staff-applicant-list">
          <div class="review-row"><div class="k" data-i18n>Full name</div><div class="v"><?= e($app['full_name']) ?></div></div>
          <div class="review-row"><div class="k" data-i18n>Birthdate</div><div class="v"><?= e(format_date_long($app['birthdate'])) ?></div></div>
          <div class="review-row"><div class="k" data-i18n>Address</div><div class="v"><?= e($app['address']) ?>, Brgy. <?= e($app['barangay']) ?></div></div>
          <?php require APP_ROOT . '/views/partials/mobile-numbers.php'; ?>
          <div class="review-row"><div class="k" data-i18n>Email</div><div class="v"><a href="mailto:<?= e($app['email']) ?>"><?= e($app['email']) ?></a></div></div>
          <div class="review-row"><div class="k" data-i18n>Sex</div><div class="v"><?= e($app['sex']) ?></div></div>
          <div class="review-row"><div class="k" data-i18n>Civil status</div><div class="v"><?= e($app['civil_status']) ?></div></div>
          <div class="review-row"><div class="k" data-i18n>Category</div><div class="v"><?= e($app['category_name']) ?></div></div>
        </div>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title" data-i18n>Children</div></div>
        <div class="staff-applicant-list">
          <?php if (!$children): ?>
            <div class="text-muted small">No children recorded for this application.</div>
          <?php else: foreach ($children as $i => $c): ?>
            <div class="review-row"><div class="k" data-i18n>Child <?= $i + 1 ?></div><div class="v"><?= e($c['full_name']) ?> · <?= e(format_date_long($c['birthdate'])) ?></div></div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <?php if (!empty($autoCheck)): require APP_ROOT . '/views/staff/applications/auto-check.php'; endif; ?>

      <div class="review-card" id="checklist">
        <div class="review-card-head"><div class="title" data-i18n>Supporting Documents</div></div>
        <p class="small text-muted" data-i18n>Review each applicant-uploaded file. Record a status and explain any rejection or correction request.</p>
        <?php if (!empty($verificationNotice)): ?><div class="<?= !empty($verificationNotice['warning']) ? 'auth-error' : 'info-box' ?>" style="max-width:none" role="status"><?= e($verificationNotice['text']) ?></div><?php endif; ?>
        <?php if (!empty($documentErrors['checklist'])): ?><div class="auth-error" role="alert"><?= e($documentErrors['checklist']) ?></div><?php endif; ?>
        <?php $canVerifyDocs = in_array($app['status'], Application::IN_PROGRESS_STATUSES, true); ?>
        <?php if ($canVerifyDocs && !empty($submittedChecklist)): ?><form method="post" id="verification-form"><?= csrf_field() ?><input type="hidden" name="form_action" value="save_verification"><?php endif; ?>
        <div class="staff-applicant-list">
          <?php if (empty($submittedChecklist)): ?>
            <div class="text-muted small" data-i18n>No document requirements are configured for this application.</div>
          <?php else: foreach ($submittedChecklist as $item): $file = $item['uploaded_document'] ?? null; ?>
            <div class="review-row doc-item" id="<?= $file ? 'doc-' . (int) $file['id'] : 'checklist-' . e($item['doc_key']) ?>">
              <div class="k doc-name"><?= e($item['label']) ?><?= !empty($item['is_required']) ? ' *' : ' (optional)' ?></div>
              <?php if ($file): ?>
                <?php $docStyle = document_status_style((string) $file['verification_status']); ?>
                <div class="doc-file">
                  <div class="doc-file-info">
                    <div class="doc-filename" title="<?= e($file['original_name']) ?>"><?= e($file['original_name']) ?></div>
                    <div class="doc-meta">Uploaded <?= e(date('M j, Y g:i A', strtotime($file['uploaded_at']))) ?></div>
                  </div>
                  <button type="button" class="btn btn-sm btn-outline-primary js-view-document" data-doc-src="<?= e(base_url('download.php?doc_id=' . (int) $file['id'] . '&app_id=' . (int) $app['id'] . '&preview=1')) ?>" data-doc-name="<?= e($file['original_name']) ?>" data-i18n>View Document</button>
                </div>
                <div class="doc-status">
                  <span class="doc-status-pill" style="background:<?= e($docStyle['bg']) ?>;color:<?= e($docStyle['color']) ?>"><?= e(document_status_label($file['verification_status'])) ?></span>
                  <?php if (!empty($file['verifier_name']) || !empty($file['verified_at'])): ?><span class="doc-meta"><?php if (!empty($file['verifier_name'])): ?>by <?= e($file['verifier_name']) ?><?php endif; ?><?php if (!empty($file['verified_at'])): ?> on <?= e(date('M j, Y g:i A', strtotime($file['verified_at']))) ?><?php endif; ?></span><?php endif; ?>
                </div>
                <?php if (!empty($documentErrors[$file['id']])): ?><div class="field-error" role="alert"><?= e($documentErrors[$file['id']]) ?></div><?php endif; ?>
                <?php if (!empty($file['staff_remarks'])): ?><div class="doc-remark"><b>MSWDO Staff remarks:</b> <?= e($file['staff_remarks']) ?></div><?php endif; ?>
                <?php if ($canVerifyDocs):
                  $stored = (string) $file['verification_status'];
                  $storedRemarks = (string) ($file['staff_remarks'] ?? '');
                  $posted = $_POST['docs'][$file['id']] ?? null;
                  // Keep the submitted values only for rows the staff member actually edited AND whose submitted file
                  // version still matches the file shown now. After a stale-file rejection the posted verdict is
                  // discarded, so a fresh decision must be made on the current file.
                  $wasEdited = is_array($posted) && is_string($posted['status'] ?? null) && is_string($posted['remarks'] ?? null)
                      && is_string($posted['expected'] ?? null) && is_string($posted['expected_remarks'] ?? null)
                      && is_string($posted['expected_version'] ?? null) && hash_equals(Document::versionToken($file), $posted['expected_version'])
                      && ($posted['status'] !== $posted['expected'] || trim($posted['remarks']) !== trim($posted['expected_remarks']));
                  $curStatus = $wasEdited ? $posted['status'] : $stored;
                  $curRemarks = $wasEdited ? $posted['remarks'] : $storedRemarks;
                  $legacy = !in_array($stored, Document::VERIFICATION_STATUSES, true); ?>
                  <div class="doc-verify-form doc-controls">
                    <div class="doc-controls-title">Verification decision</div>
                    <input type="hidden" name="docs[<?= (int) $file['id'] ?>][expected]" value="<?= e($stored) ?>">
                    <input type="hidden" name="docs[<?= (int) $file['id'] ?>][expected_remarks]" value="<?= e($storedRemarks) ?>">
                    <input type="hidden" name="docs[<?= (int) $file['id'] ?>][expected_version]" value="<?= e(Document::versionToken($file)) ?>">
                    <select name="docs[<?= (int) $file['id'] ?>][status]" class="form-select" aria-label="Verification status for <?= e($item['label']) ?>">
                      <?php if ($legacy): ?><option value="<?= e($stored) ?>" <?= $curStatus === $stored ? 'selected' : '' ?>><?= e(document_status_label($stored)) ?> (current status - choose a new status to change it)</option><?php endif; ?>
                      <?php foreach (Document::VERIFICATION_STATUSES as $status): ?><option value="<?= e($status) ?>" <?= $curStatus === $status ? 'selected' : '' ?>><?= e(document_status_label($status)) ?></option><?php endforeach; ?>
                    </select>
                    <input type="text" name="docs[<?= (int) $file['id'] ?>][remarks]" maxlength="500" value="<?= e($curRemarks) ?>" placeholder="Remarks for rejection or correction" class="form-control" aria-label="Remarks for <?= e($item['label']) ?>">
                  </div>
                <?php endif; ?>
                <?php if (!empty($documentHistory[$file['id']])): ?><div class="doc-history-title">Verification history</div><?php endif; ?>
                <?php foreach (($documentHistory[$file['id']] ?? []) as $event): ?><div class="doc-history-item"><?= !empty($event['from_status']) ? e(document_status_label((string) $event['from_status'])) . ' to ' : '' ?><?= e(document_status_label((string) ($event['to_status'] ?? ''))) ?> - <?= e($event['actor_name']) ?> - <?= e(date('M j, Y g:i A', strtotime($event['created_at']))) ?><?= !empty($event['remarks']) ? ' - ' . e($event['remarks']) : '' ?></div><?php endforeach; ?>
              <?php else: ?>
                <div class="small text-muted" data-i18n><?= $item['verification_status'] === 'Verified' ? 'Previously accepted evidence is retained in the application record.' : 'No file uploaded yet.' ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
        </div>
        <?php if ($canVerifyDocs && !empty($submittedChecklist)): ?>
          <div class="mt-3"><?php if (!empty($documentErrors['checklist'])): ?><div class="field-error mb-2" role="alert"><?= e($documentErrors['checklist']) ?></div><?php endif; ?><button type="submit" class="btn-navy" data-i18n>Save Verification</button></div>
        </form><?php endif; ?>
        <?php if ($canVerifyDocs && !empty($correctionSms)): $cs = $correctionSms; $csLog = $cs['last_log']; ?>
        <div id="correction-sms" class="mt-4 pt-3" style="border-top:1px solid #dee2e6;scroll-margin-top:80px">
          <div class="fw-semibold" data-i18n>Correction SMS</div>
          <?php if (!empty($correctionNotice)):
            $cn = $correctionNotice;
            $cnLabel = $cn['outcome'] === 'SENT' ? 'SENT' : ($cn['outcome'] === 'PENDING' ? 'PENDING - NOT CONFIRMED' : ($cn['outcome'] === 'FAILED' ? 'FAILED' : ($cn['outcome'] === 'DISABLED' ? 'NOT SENT - SMS DISABLED' : 'NOT SENT'))); ?>
            <div class="<?= $cn['outcome'] === 'SENT' ? 'info-box' : 'auth-error' ?> my-2" style="max-width:none" role="status" tabindex="-1"><strong><?= e($cnLabel) ?>:</strong> <?= e($cn['text']) ?></div>
          <?php endif; ?>
          <p class="small text-muted mb-2" data-i18n>Save Verification does not send an SMS. After you save documents as Needs Correction, review the message below and confirm to send one SMS for the whole batch. Your remarks are never included in the SMS.</p>
          <?php if ($cs['has_batch']): ?>
            <?php if ($csLog): ?>
              <div class="small mb-2">Latest SMS for this batch: <strong><?= e($csLog['status']) ?></strong> (<?= e(date('M j, Y g:i A', strtotime($csLog['sent_at']))) ?>)<?= $csLog['status'] === 'FAILED' && !empty($csLog['error']) ? ' - ' . e($csLog['error']) : '' ?><?= $csLog['status'] === 'PENDING' ? ' - outcome not recorded; this batch stays blocked and needs separate manual investigation' : '' ?></div>
            <?php else: ?>
              <div class="small mb-2 text-muted">No correction SMS has been sent for this batch yet.</div>
            <?php endif; ?>
          <?php else: ?>
            <div class="small text-muted">No document is currently marked Needs Correction.</div>
          <?php endif; ?>
          <?php if ($cs['has_batch']): ?>
            <button type="button" class="btn-navy mt-2" data-next-step-open="correction" data-i18n>Review and send correction SMS</button>
            <div id="correction-review-store" hidden>
            <div class="review-card" id="correction-review" style="background:#f8f9fa">
              <div class="small text-muted">Review the message below - nothing has been sent yet</div>
              <div class="fw-semibold"><?= e($cs['applicant']) ?> <span class="text-muted fw-normal">- <?= e($cs['reference']) ?></span></div>
              <div class="small">To: <strong><?= e($cs['recipient']) ?></strong></div>
              <?php if ($cs['has_batch']): ?>
                <div class="small mt-2">Documents (<?= count($cs['documents']) ?>): <?= e(implode(', ', $cs['documents'])) ?></div>
                <div class="mt-2 p-2 bg-white border rounded" style="white-space:pre-wrap"><?= e($cs['message']) ?></div>
                <div class="small text-muted mt-1"><?= (int) $cs['length'] ?> / <?= (int) SMSService::CORRECTION_MAX_LENGTH ?> characters</div>
              <?php endif; ?>
              <?php if ($cs['block']): ?>
                <div class="field-error mt-2" role="alert"><?= e($cs['block']) ?></div>
              <?php endif; ?>
              <form method="post" class="mt-3" onsubmit="var b=this.querySelector('button[type=submit]');if(b){b.disabled=true;}">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="confirm_correction_sms">
                <input type="hidden" name="batch_token" value="<?= e($cs['token']) ?>">
                <button type="submit" class="btn-navy js-correction-confirm" data-blocked="<?= $cs['block'] ? '1' : '0' ?>" <?= $cs['block'] ? 'disabled' : '' ?> data-i18n>Confirm and Send Correction SMS</button>
              </form>
              <script>
                // Back/forward cache can restore this page with the button still disabled from the earlier submit.
                // Usability only: the server (CSRF, token, lock, reservation) decides whether anything is sent.
                window.addEventListener('pageshow', function () {
                  document.querySelectorAll('.js-correction-confirm').forEach(function (b) { b.disabled = b.getAttribute('data-blocked') === '1'; });
                });
              </script>
            </div>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="modal fade" id="document-viewer" tabindex="-1" aria-labelledby="document-viewer-title" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-lg-down">
          <div class="modal-content" style="height:90vh">
            <div class="modal-header py-2">
              <h2 class="modal-title fs-6 text-truncate" id="document-viewer-title">Document</h2>
              <div class="btn-group btn-group-sm ms-auto me-2 d-none" id="document-viewer-zoom" role="group" aria-label="Zoom">
                <button type="button" class="btn btn-outline-secondary" data-zoom="out" aria-label="Zoom out">&minus;</button>
                <button type="button" class="btn btn-outline-secondary" data-zoom="reset">100%</button>
                <button type="button" class="btn btn-outline-secondary" data-zoom="in" aria-label="Zoom in">+</button>
              </div>
              <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
            <div class="modal-body p-0 position-relative bg-light" id="document-viewer-body" style="overflow:auto"></div>
          </div>
        </div>
      </div>
      <script src="<?= public_asset('js/document-viewer.js') ?>" defer></script>
      <script src="<?= public_asset('js/staff-next-step.js') ?>" defer data-auto="<?= ($_GET['next'] ?? '') === '1' ? '1' : '0' ?>"></script>
      <div class="review-card">
        <div class="review-card-head"><div class="title">Solo Parent Declaration</div></div>
        <div class="staff-applicant-list">
          <div class="review-row">
            <div class="k">Truth and data processing confirmation</div>
            <div class="v"><?php if (!empty($app['consent_at'])): ?>Confirmed at submission (<?= e(date('F j, Y g:i A', strtotime($app['consent_at']))) ?>)<?php else: ?>No confirmation recorded<?php endif; ?></div>
          </div>
        </div>
      </div>

    </div>

    <div class="col-12 col-lg-5">
      <?php if ($flashMessage): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>
      <?php if (!empty($smsNotice)) { require APP_ROOT . '/views/staff/applications/sms-notice.php'; } ?>
      <?php if (!empty($smsLogs)) { require APP_ROOT . '/views/staff/applications/sms-log.php'; } ?>

      <div class="review-card" id="decision">
        <?php if ($statusError): ?><div class="auth-error" role="alert"><?= e($statusError) ?></div><?php endif; ?>
        <?php if ($assessmentError): ?><div class="auth-error" role="alert"><?= e($assessmentError) ?></div><?php endif; ?>

        <?php if (!$allowedTransitions): ?>
          <div style="font-size:13px;color:var(--muted)">This application has a final status (<?= e($app['status']) ?>). No further decisions can be made.</div>
        <?php else: ?>
          <?php if ($interview && $interview['status'] === 'BOOKED'): ?><div class="mb-2"><b data-i18n>Interview Scheduled</b> · <?= e(interview_schedule_text($interview['scheduled_at'], interview_is_date_only($interview))) ?> at <?= e($interview['location']) ?></div><?php endif; ?>
          <?php if ($approvalChecklist): ?>
            <div class="approval-checklist">
              <div style="font-size:12px;font-weight:700;color:var(--ink);margin-bottom:6px">Before approving</div>
              <?php foreach ($approvalChecklist as $item): ?>
                <div class="<?= $item['ok'] ? 'ok' : 'no' ?>"><?= $item['ok'] ? '✓' : '✗' ?> <?= e($item['label']) ?><?php if ($item['detail']): ?> <span class="detail" style="white-space:pre-line">— <?= e($item['detail']) ?></span><?php endif; ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if (empty($app['renewal_simplified']) && (in_array($interview['status'] ?? '', ['BOOKED', 'COMPLETED'], true) || $lateAttended)): ?>
            <?php if ($lateAttended): ?><div class="info-box mb-3" role="status" style="max-width:none">The system released this interview because nobody recorded the outcome. <b>If the Solo Parent really attended, record the result here.</b> This is possible until they choose a new date.</div><?php endif; ?>
            <?php if ($interviewOutcome): ?>
              <div class="review-saved-action mb-3"><span>Interview: <b><?= e($interviewOutcome) ?></b></span><?php $undoButton('interview', 'Undo Interview Result'); ?></div>
              <?php if ($interviewNotes !== ''): ?><div class="small mb-3"><?= e($interviewNotes) ?></div><?php endif; ?>
              <?php if ($outstandingRequirements): ?><div class="small text-muted mb-3" id="interview-result-locked">The interview result cannot be changed until all required documents are verified again (<?= e(implode('; ', array_map(fn($item) => $item['label'], $outstandingRequirements))) ?>).</div><?php endif; ?>
            <?php elseif ($outstandingRequirements): ?>
            <div class="info-box mb-3" id="interview-result-locked" style="max-width:none" role="status">
              <b>Interview result is locked until all required documents are verified</b>
              <ul><?php foreach ($outstandingRequirements as $item): $expiredItem = !empty($item['expires_at']) && $item['expires_at'] < date('Y-m-d'); ?><li><?= e($item['label']) ?><?= $expiredItem ? ' - expired' : (!empty($item['uploaded_document']) ? ' - ' . e(document_status_label($item['uploaded_document']['verification_status'])) : ' - no file uploaded') ?></li><?php endforeach; ?></ul>
              MSWDO staff must verify every required document before recording the interview result. The appointment and its reminders are not affected; verify the documents above to unlock the result.
              <?php if ($interview['status'] === 'BOOKED' && strtotime((string) $interview['scheduled_at']) > time()): ?>
                <div class="small text-muted mt-2">The interview result can be recorded on or after <?= e(interview_schedule_text($interview['scheduled_at'], interview_is_date_only($interview))) ?>.</div>
              <?php endif; ?>
            </div>
            <?php else: ?>
            <form method="post" id="interview-result-form" class="d-flex flex-column gap-2 mb-3" onsubmit="return confirm('Confirm this interview result?');">
              <?= csrf_field() ?>
              <input type="hidden" name="form_action" value="record_result">
              <?php if ($isRenewalInterview): require_once APP_ROOT . '/services/SoloParentChildRules.php'; $rcToday = date('Y-m-d');
                $rcPrevCivil = (string) ($renewalOf['civil_status'] ?? ''); $rcCivilChanged = ($app['civil_status'] ?? '') === 'Married' && $rcPrevCivil !== '' && $rcPrevCivil !== 'Married';
                $rcValidFrom = $renewalOf['id_valid_from'] ?? null; $rcExpires = $renewalOf['id_expires_at'] ?? null; ?>
                <div class="renewal-recheck" style="border:1px solid var(--border, #d9e1ec);border-left:5px solid #1b3a6b;border-radius:12px;padding:12px 14px">
                  <div class="d-flex align-items-center gap-2 mb-1">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="color:var(--ink)"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    <div class="fw-semibold" style="color:var(--ink)">Renewal re-check (RA 11861)</div>
                  </div>
                  <div class="small mb-2" style="color:var(--muted)">Confirm at the interview that the Solo Parent still qualifies. A <b>Successful</b> result needs every item; if one is not true, record <b>Unsuccessful</b> and reject with the reason.</div>

                  <div class="small fw-semibold" style="color:var(--ink)">Current Solo Parent ID to check</div>
                  <div class="recheck-id-card">
                    <div><div class="k">Reference</div><div class="v"><?= e((string) ($renewalOf['reference_no'] ?? '—')) ?></div></div>
                    <div><div class="k">Name on the ID</div><div class="v"><?= e((string) ($renewalOf['full_name'] ?? $app['full_name'])) ?></div></div>
                    <div><div class="k">Valid from</div><div class="v"><?= $rcValidFrom ? e(date('M j, Y', strtotime((string) $rcValidFrom))) : '—' ?></div></div>
                    <div><div class="k">Valid until</div><div class="v"><?= $rcExpires ? e(date('M j, Y', strtotime((string) $rcExpires))) : '—' ?></div></div>
                  </div>

                  <ul class="small mb-2" style="padding-left:18px">
                    <?php foreach (($children ?? []) as $rc): $rcStatus = SoloParentChildRules::status($rc, $rcToday); ?>
                      <li class="<?= in_array($rcStatus, [SoloParentChildRules::QUALIFIES, SoloParentChildRules::QUALIFIES_DISABILITY], true) ? 'child-ok' : 'child-bad' ?>"><b><?= e($rc['full_name']) ?></b> — <?= e(SoloParentChildRules::label($rc, $rcToday)) ?></li>
                    <?php endforeach; ?>
                    <?php if (!SoloParentChildRules::anyQualifies($children ?? [], $rcToday)): ?><li class="child-bad"><b>No qualifying child</b> — this renewal may not be eligible.</li><?php endif; ?>
                    <?php if ($rcCivilChanged): ?><li class="child-bad"><b>Civil status changed to Married</b> (was <?= e($rcPrevCivil) ?>) — may no longer qualify.</li><?php endif; ?>
                  </ul>

                  <div class="d-flex flex-column gap-1">
                    <?php foreach (ApplicationReviewService::RENEWAL_RECHECK as $rcKey => $rcLabel): ?>
                      <label class="d-flex align-items-start gap-2" style="font-size:13px;color:var(--ink);cursor:pointer"><input type="checkbox" name="recheck[]" value="<?= e($rcKey) ?>" class="js-recheck<?= $rcKey === 'current_id' ? ' js-recheck-id' : '' ?>" style="margin-top:2px;width:17px;height:17px;flex:0 0 auto"> <span><?= $rcKey === 'current_id' ? '<b>' . e($rcLabel) . '</b> (compare it with the details above)' : e($rcLabel) ?></span></label>
                    <?php endforeach; ?>
                  </div>
                  <div class="recheck-noid">
                    <label class="d-flex align-items-start gap-2 mb-0" style="font-size:13px;color:#5b3a07;cursor:pointer"><input type="checkbox" name="id_not_shown" value="1" class="js-noid" style="margin-top:2px;width:17px;height:17px;flex:0 0 auto"> <span><b>ID not shown</b> — the Solo Parent lost or forgot their current Solo Parent ID</span></label>
                    <label class="d-none js-noid-wrap mt-2" style="font-size:13px;font-weight:600;color:#5b3a07;width:100%">Reason (saved in the history and audit log)
                      <input type="text" name="id_not_shown_reason" maxlength="200" class="form-control mt-1 js-noid-reason" placeholder="For example: lost, showed an affidavit of loss">
                    </label>
                  </div>
                </div>
                <script>
                  (() => {
                    const form = document.getElementById('interview-result-form');
                    const noId = form.querySelector('.js-noid'), noIdWrap = form.querySelector('.js-noid-wrap'), noIdReason = form.querySelector('.js-noid-reason');
                    const idBox = form.querySelector('.js-recheck-id');
                    const sync = () => {
                      noIdWrap.classList.toggle('d-none', !noId.checked);
                      if (noId.checked) { idBox.checked = false; idBox.disabled = true; } else { idBox.disabled = false; }
                    };
                    noId.addEventListener('change', sync); sync();
                    form.querySelectorAll('button[name="interview_outcome"][value="Successful"]').forEach(btn => btn.addEventListener('click', e => {
                      const others = [...form.querySelectorAll('.js-recheck:not(.js-recheck-id)')];
                      if (others.some(b => !b.checked)) { e.preventDefault(); alert('Tick every renewal re-check item before recording a successful interview. If one is not true, record Unsuccessful.'); return; }
                      if (!idBox.checked && !(noId.checked && noIdReason.value.trim())) {
                        e.preventDefault();
                        alert(noId.checked ? 'Give the reason the Solo Parent ID was not shown.' : 'Tick that the Solo Parent showed their current Solo Parent ID, or choose "ID not shown" and give the reason.');
                        (noId.checked ? noIdReason : idBox).focus();
                      }
                    }));
                  })();
                </script>
              <?php endif; ?>
              <?php if ($interviewOutcome): ?><div class="small">Current result: <b><?= e($interviewOutcome) ?></b></div><?php endif; ?>
              <?php if ($interview['result_recorded_at']): ?>
                <div style="font-size:12px;color:var(--faint)">Recorded <?= e(date('M j, Y g:i A', strtotime($interview['result_recorded_at']))) ?></div>
              <?php endif; ?>
              <?php if ($interview['status'] === 'BOOKED' && strtotime((string) $interview['scheduled_at']) > time()): ?>
                <div class="small text-muted">The interview result can be recorded on or after <?= e(interview_schedule_text($interview['scheduled_at'], interview_is_date_only($interview))) ?>.</div>
              <?php else: ?>
                <div class="review-action-grid" role="group" aria-label="Interview result">
                  <button type="submit" name="interview_outcome" value="Successful" class="btn-outline review-button-success<?= $interviewOutcome === 'Successful' ? ' is-selected' : '' ?>" aria-pressed="<?= $interviewOutcome === 'Successful' ? 'true' : 'false' ?>" style="flex:1" data-i18n>Successful</button>
                  <button type="submit" name="interview_outcome" value="Unsuccessful" class="btn-outline review-button-danger<?= $interviewOutcome === 'Unsuccessful' ? ' is-selected' : '' ?>" aria-pressed="<?= $interviewOutcome === 'Unsuccessful' ? 'true' : 'false' ?>" style="flex:1" data-i18n>Unsuccessful</button>
                </div>
              <?php endif; ?>
            </form>
            <?php endif; ?>
          <?php endif; ?>
          <?php if (!$canDecide && $canCorrect): ?>
            <div class="info-box" role="status" style="max-width:none">The last interview was <b>unsuccessful</b>. You can request corrections, schedule another interview (in the interview section below), or reject the application with a reason. Approval stays locked until an interview is successful.</div>
          <?php endif; ?>
          <?php if ($canDecide || $canCorrect || $canReject): ?>
          <form method="post" id="application-decision-form" class="d-flex flex-column gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update_status">
            <input type="hidden" name="expected_status" value="<?= e($app['status']) ?>">
            <div class="review-action-grid">
              <?php foreach ($allowedTransitions as $to):
                  if ($to === 'Under Verification' || ($to === 'Approved' && !$canDecide) || ($to === 'For Revision' && !$canCorrect)) {
                      continue;
                  }
                  $class = $to === 'Rejected' ? 'btn-outline review-button-danger' : ($to === 'Approved' || $to === 'ID Released' ? 'btn-navy review-button-success is-selected' : 'btn-outline review-button-correction');
                  $confirm = $to === 'Rejected' ? 'Reject this application? This is final.' : ($to === 'Approved' ? 'Approve this application?' : '');
                  $needsReason = in_array($to, Application::REASON_REQUIRED, true);
              ?>
                <button type="submit" name="status" value="<?= e($to) ?>" class="<?= $class ?>" style="flex:1 1 150px"<?= $to === 'Approved' && $outstandingRequirements ? ' disabled title="Complete all required documents before approval"' : '' ?><?= $needsReason ? ' data-needs-reason="1" data-confirm="' . e($confirm) . '"' : ($confirm ? ' onclick="return confirm(\'' . e($confirm) . '\')"' : '') ?>><?= e(Application::ACTION_LABELS[$to] ?? $to) ?></button>
              <?php endforeach; ?>
            </div>
            <?php /* The reason is asked for only when the chosen action needs one (reject / request corrections). */ ?>
            <label id="decision-reason-wrap" class="d-none mt-2" style="font-size:13px;font-weight:600;color:var(--ink)">
              <span id="decision-reason-label">Reason for the Solo Parent</span>
              <textarea name="remarks" id="decision-reason" aria-label="Reason for the Solo Parent" rows="3" maxlength="500" placeholder="Required. This reason is sent to the Solo Parent by SMS." class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px"></textarea>
              <span class="small text-muted d-block mt-1">Fill this in, then press the same button again to confirm.</span>
            </label>
            <div id="decision-reason-chips" class="d-none d-flex flex-wrap gap-2" aria-label="Common reasons">
              <span class="small text-muted w-100">Common reasons (RA 11861) — click one, then edit it if needed:</span>
              <?php foreach ([
                  'No qualifying child: the child is over 22 and has no disability (RA 11861).',
                  'Remarried or living with a partner, so no longer a solo parent (RA 11861).',
                  'The solo parent category no longer applies.',
                  'No longer living in Virac.',
              ] as $chip): ?>
                <button type="button" class="btn-outline js-reason-chip" style="padding:3px 10px;font-size:12px" data-reason="<?= e($chip) ?>"><?= e($chip) ?></button>
              <?php endforeach; ?>
            </div>
          </form>
          <script>
            (() => {
              const form = document.getElementById('application-decision-form'), wrap = document.getElementById('decision-reason-wrap');
              const box = document.getElementById('decision-reason'), label = document.getElementById('decision-reason-label');
              const chips = document.getElementById('decision-reason-chips');
              chips.querySelectorAll('.js-reason-chip').forEach(chip => chip.addEventListener('click', () => { box.value = chip.dataset.reason; box.focus(); }));
              form.querySelectorAll('button[name="status"]').forEach(btn => btn.addEventListener('click', event => {
                if (!btn.dataset.needsReason) { box.value = ''; box.required = false; return; }   // approving needs no remarks
                const rejecting = btn.value === 'Rejected';
                if (wrap.classList.contains('d-none') || !box.value.trim() || wrap.dataset.mode !== btn.value) {
                  event.preventDefault();
                  if (wrap.dataset.mode && wrap.dataset.mode !== btn.value) box.value = '';
                  wrap.dataset.mode = btn.value;
                  wrap.classList.remove('d-none'); box.required = true;
                  chips.classList.toggle('d-none', !rejecting);
                  label.textContent = rejecting ? 'Why is the application rejected? (sent to the Solo Parent)' : 'What does the Solo Parent need to correct?';
                  box.focus();
                  return;
                }
                if (btn.dataset.confirm && !confirm(btn.dataset.confirm)) event.preventDefault();
              }));
            })();
          </script>
          <?php endif; ?>
          <?php if (!$canCorrect && $interview && in_array($interview['status'], ['BOOKED', 'COMPLETED', 'MISSED'], true)): ?>
            <div class="info-box" role="status"><?= 'Request Corrections and Approve become available after a successful interview is recorded.' ?></div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php if (empty($app['renewal_simplified'])): ?>
      <div class="review-card" id="interview">
        <?php
        $ivStatus = $interview['status'] ?? null;
        $ivStyles = ['BOOKED' => ['#dce8f5', '#1b3a6b', 'Booked'], 'COMPLETED' => ['#dcf3e2', '#1c7a3f', 'Completed'], 'MISSED' => ['#fde8e8', '#a51c1c', 'Missed'], 'CANCELLED' => ['#f1f1f1', '#555', 'Cancelled']];
        $inProgress = in_array($app['status'], Application::IN_PROGRESS_STATUSES, true);
        ?>
        <div class="review-card-head">
          <div class="title" data-i18n>Interview</div>
          <?php if ($ivStatus): [$ibg, $ifg, $ilabel] = $ivStyles[$ivStatus]; ?><span class="status-pill" style="background:<?= $ibg ?>;color:<?= $ifg ?>"><?= e($ilabel) ?></span><?php endif; ?>
        </div>
        <?php if ($interviewError): ?><div class="auth-error" role="alert"><?= e($interviewError) ?></div><?php endif; ?>
        <?php
        // Rescheduling: the Solo Parent booked again after a missed, unrecorded or cancelled interview.
        $evLabels = ['STAFF_SCHEDULED' => 'Scheduled by MSWDO staff', 'BOOKED' => 'Date booked', 'RESCHEDULED' => 'Rescheduled by the Solo Parent', 'MISSED' => 'Marked as not attended',
            'NO_OUTCOME' => 'No outcome recorded (released by the system)', 'CANCELLED' => 'Cancelled', 'INVITATION_REOPENED' => 'Booking reopened', 'INVITATION_CLOSED' => 'Booking closed'];
        $priorEvents = array_values(array_filter($interviewEvents ?? [], fn($ev) => in_array($ev['event_type'], ['MISSED', 'NO_OUTCOME', 'CANCELLED'], true)));
        $isResched = $interview && $ivStatus === 'BOOKED' && ($interview['booking_source'] ?? '') === 'APPLICANT' && $priorEvents;
        if ($isResched): $lastPrior = $priorEvents[count($priorEvents) - 1]; ?>
          <div class="info-box" style="max-width:none;border-left:5px solid #b45309" role="status"><b>Rescheduled by the Solo Parent.</b> They chose
            <b><?= e(interview_schedule_text($interview['scheduled_at'], interview_is_date_only($interview))) ?></b> after the interview on
            <?= e($lastPrior['interview_date'] ? date('M j, Y', strtotime((string) $lastPrior['interview_date'])) : 'an earlier date') ?>
            (<?= e(strtolower($evLabels[$lastPrior['event_type']] ?? $lastPrior['event_type'])) ?>).</div>
        <?php endif; ?>
        <?php if (!empty($interviewEvents)): ?>
          <details class="mb-2"><summary class="small fw-semibold">Interview history (<?= count($interviewEvents) ?>)</summary>
            <ul class="small mt-2 mb-0">
              <?php foreach ($interviewEvents as $ev): ?>
                <li><?= e(date('M j, Y g:i A', strtotime((string) $ev['created_at']))) ?> · <b><?= e($evLabels[$ev['event_type']] ?? ucfirst(strtolower(str_replace('_', ' ', (string) $ev['event_type'])))) ?></b><?= $ev['interview_date'] ? ' · ' . e(date('M j, Y', strtotime((string) $ev['interview_date']))) : '' ?> · <?= e(ucfirst(strtolower((string) $ev['source']))) ?><?= !empty($ev['details']) ? ' — ' . e($ev['details']) : '' ?></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
        <?php if ($interview): ?>
          <div class="review-grid mb-3">
            <div class="review-row"><div class="k">Scheduled</div><div class="v"><?= e(interview_schedule_text($interview['scheduled_at'], interview_is_date_only($interview))) ?></div></div>
            <div class="review-row"><div class="k" data-i18n>Location</div><div class="v"><?= e($interview['location']) ?></div></div>
            <div class="review-row"><div class="k">Booked by</div><div class="v"><?= $interview['scheduled_by'] ? 'MSWDO staff' : (($interview['notes'] ?? '') === 'Interview automatically scheduled on application submission.' ? 'System (automatically scheduled)' : 'Solo Parent (self-booked)') ?></div></div>
            <?php if ($interview['notes']): ?><div class="review-row"><div class="k">Notes</div><div class="v"><?= e($interview['notes']) ?></div></div><?php endif; ?>
            <?php if ($ivStatus === 'CANCELLED'): ?><div class="review-row"><div class="k">Cancelled</div><div class="v"><?= e(date('M j, Y g:i A', strtotime($interview['cancelled_at']))) ?><?= $interview['cancel_reason'] ? ' — ' . e($interview['cancel_reason']) : '' ?></div></div><?php endif; ?>
          </div>
          <?php if ($ivStatus === 'BOOKED'): ?>
            <div style="font-size:12px;color:var(--muted);margin-bottom:12px">To cancel this appointment or mark a no-show, use <a href="<?= base_url('staff/appointments.php') ?>" data-i18n>Appointment Management</a>.</div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($interviewSms)): ?>
          <div class="review-row mb-3">
            <div class="k">Interview SMS · <?= $interviewSms['template_key'] === 'interview_custom' ? 'Custom' : 'Automatic' ?> message</div>
            <div class="v"><b><?= e($interviewSms['status']) ?></b>
              · <?= e($interviewSms['provider']) ?><?= $interviewSms['status'] === 'SENT' ? ' accepted the message' : '' ?>
              <br><span class="small text-muted">To <?= e($interviewSms['mobile']) ?> · Attempted <?= e(date('M j, Y g:i A', strtotime($interviewSms['sent_at']))) ?></span>
              <?php if ($interviewSms['error']): ?><br><span class="field-error"><?= e($interviewSms['error']) ?></span><?php endif; ?>
              <details class="mt-2"><summary style="cursor:pointer;font-size:12px">Review final message</summary><div class="small mt-1" style="white-space:pre-wrap"><?= e($interviewSms['message']) ?></div></details>
            </div>
          </div>
          <?php if ($interviewSms['status'] === 'FAILED' && $ivStatus === 'BOOKED' && (int) $interviewSms['attempts'] < SMS_MAX_ATTEMPTS): ?>
            <form method="post" class="mb-3">
              <?= csrf_field() ?>
              <input type="hidden" name="form_action" value="retry_interview_sms">
              <input type="hidden" name="sms_log_id" value="<?= (int) $interviewSms['id'] ?>">
              <button type="submit" class="btn-outline">Retry SMS</button>
            </form>
          <?php elseif ($interviewSms['status'] === 'FAILED'): ?>
            <div class="small text-muted mb-3">No more retries are available for this SMS attempt.</div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($inProgress && $ivStatus !== 'COMPLETED'): ?>
          <?php if ($outstandingRequirements): ?>
            <div class="info-box" style="max-width:none" role="status">
              <b>Scheduling the interview is locked until all required documents are verified</b>
              <ul><?php foreach ($outstandingRequirements as $item): $expiredItem = !empty($item['expires_at']) && $item['expires_at'] < date('Y-m-d'); ?><li><?= e($item['label']) ?><?= $expiredItem ? ' - expired' : (!empty($item['uploaded_document']) ? ' - ' . e(document_status_label($item['uploaded_document']['verification_status'])) : ' - no file uploaded') ?></li><?php endforeach; ?></ul>
              MSWDO staff must verify every required document before scheduling the interview. When the Solo Parent uploads a replacement, verify it above to unlock it.
            </div>
          <?php else: ?>
            <div class="info-box" style="max-width:none"><b>All required documents are verified</b> <?php if (($invitationState ?? 'off') !== 'ready'): ?>Interview scheduling is not available until interview date booking is enabled.<?php else: ?>Pick the interview date in <b>Schedule the interview date</b> below. The Solo Parent is texted the date and can keep it or log in to pick another day; the booking then appears here and in the MSWDO staff dashboard's Expected Today list.<?php endif; ?></div>
          <?php endif; ?>
        <?php endif; ?>

      </div>

      <?php
      $invState = $invitationState ?? 'off';
      $invPanel = $invitationPanel ?? null;
      $inv = $invPanel['invitation'] ?? null;
      $invProblems = $invPanel['problems'] ?? [];
      // SMS choice for the schedule form: the automatic text, or a custom text staff write and review first.
      // $p makes the element ids unique when two forms share the page. $auto may hold a {when} token, filled live from the
      // date field with id "$p-date" (the schedule form).
      $invSmsFields = function (string $p, string $auto) { ?>
        <label style="font-size:13px;font-weight:600;color:var(--ink)">SMS Message Option
          <select name="sms_message_type" id="<?= $p ?>-sms-type" class="form-select mt-1">
            <option value="automatic" selected>Automatic Message</option>
            <option value="custom">Custom Message</option>
          </select>
        </label>
        <div id="<?= $p ?>-sms-preview" class="info-box small" style="max-width:none;margin:0;white-space:pre-wrap" role="status"><?= e($auto) ?></div>
        <label id="<?= $p ?>-custom-wrap" class="d-none" style="font-size:13px;font-weight:600;color:var(--ink)">Review and edit the final SMS
          <textarea name="invitation_custom_message" id="<?= $p ?>-custom-message" rows="4" maxlength="500" class="form-control mt-1" placeholder="Write the message to send"></textarea>
        </label>
        <script>
          (() => {
            const type = document.getElementById('<?= $p ?>-sms-type'), preview = document.getElementById('<?= $p ?>-sms-preview');
            const wrap = document.getElementById('<?= $p ?>-custom-wrap'), custom = document.getElementById('<?= $p ?>-custom-message');
            const dateField = document.getElementById('<?= $p ?>-date');
            const template = <?= json_encode($auto, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const fill = () => {
              if (!dateField) return template;
              const d = dateField.value ? new Date(dateField.value + 'T00:00:00') : null;
              return template.split('{when}').join(d && !isNaN(d) ? d.toLocaleDateString('en-US', { weekday: '<?= ($scheduleDateStyle ?? 'long') === 'short' ? 'short' : 'long' ?>', year: 'numeric', month: '<?= ($scheduleDateStyle ?? 'long') === 'short' ? 'short' : 'long' ?>', day: 'numeric' }) : '[the date you pick]');
            };
            let lastAuto = fill();
            const update = () => {
              const customMode = type.value === 'custom', automatic = fill();
              wrap.classList.toggle('d-none', !customMode); custom.required = customMode;
              if (customMode && (!custom.value || custom.value === lastAuto)) custom.value = automatic;
              lastAuto = automatic;
              preview.textContent = customMode ? (custom.value || 'Enter the custom SMS message to review it here.') : automatic;
            };
            type.addEventListener('change', update); custom.addEventListener('input', update);
            if (dateField) dateField.addEventListener('input', update);
            update();
          })();
        </script>
      <?php };
      ?>
      <?php if ($invState !== 'off' || !empty($invitationError)): ?>
      <div class="review-card" id="schedule-interview">
        <div class="review-card-head">
          <div class="title">Schedule the interview date</div>
          <?php $ivNow = $interview['status'] ?? null; $ivRetake = $ivNow === 'COMPLETED' && Appointment::interviewOutcome($interview) === 'Unsuccessful'; ?>
          <?php if (in_array($ivNow, ['BOOKED', 'COMPLETED'], true)): ?>
            <span class="status-pill" style="background:#dce8f5;color:#1b3a6b"><?= $ivNow === 'COMPLETED' ? 'Interview done' : 'Date set' ?></span>
          <?php else: ?>
            <span class="status-pill" style="background:#f1f1f1;color:#555">No date yet</span>
          <?php endif; ?>
        </div>
        <?php if (!empty($invitationError)): ?><div class="auth-error" role="alert"><?= e($invitationError) ?></div><?php endif; ?>

        <?php if ($invState === 'incomplete'): ?>
          <div class="info-box" style="max-width:none" role="status">Interview scheduling is not available because its setup is incomplete. Please contact the MSWDO admin.</div>
        <?php elseif ($invState === 'ready' && $invPanel !== null): ?>
          <?php if ($ivNow === 'BOOKED'): ?>
            <div class="small text-muted">An interview date is already set (shown above). The Solo Parent can keep it or pick another day from their own account. To change it yourself, the Solo Parent must cancel it first.</div>
          <?php elseif ($ivNow === 'COMPLETED' && !$ivRetake): ?>
            <div class="small text-muted">The interview has been held. Record the result and decide on the application below.</div>
          <?php elseif (($invProblems['open'] ?? null) !== null && !$inv): ?>
            <div class="small text-muted"><?= e($invProblems['open']) ?></div>
          <?php else: ?>
            <?php if ($inv): ?>
              <div class="review-grid mb-2">
                <div class="review-row"><div class="k">Reschedules used</div><div class="v"><?= (int) $inv['reschedules_used'] ?> of <?= (int) $inv['reschedules_allowed'] ?> by the Solo Parent</div></div>
                <?php if ($inv['status'] === 'CLOSED'): ?>
                  <div class="review-row"><div class="k">Solo Parent booking</div><div class="v">Closed<?= !empty($inv['close_reason']) ? ' — ' . e($inv['close_reason']) : '' ?>. Scheduling a date here reopens it for this booking.</div></div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            <button type="button" class="btn-navy mb-2" data-next-step-open="schedule">Schedule the interview</button>
            <div id="schedule-store" hidden>
            <div id="schedule-modal-source" data-open="<?= !empty($invitationError) ? '1' : '0' ?>">
            <?php if (!empty($ivRetake)): ?><div class="info-box mb-2" style="max-width:none" role="status">The last interview was <b>unsuccessful</b>. Scheduling another interview replaces it; the result stays in the history.</div><?php endif; ?>
            <div class="small text-muted mb-2">Choose any date from today and any time, including right now. The interview is booked right away and the Solo Parent is texted: they can come at that time, or log in and pick a different day.</div>
            <form method="post" class="d-flex flex-column gap-2 mb-2" data-warn="<?= e(json_encode($scheduleWarn ?? [])) ?>" onsubmit="return virScheduleConfirm(this);">
              <?= csrf_field() ?><input type="hidden" name="form_action" value="schedule_interview_date">
              <label style="font-size:13px;font-weight:600;color:var(--ink)">Interview date
                <input type="date" name="interview_date" id="schedule-date" class="form-control mt-1" value="<?= e($scheduleMinDate ?? '') ?>" min="<?= e($scheduleMinDate ?? '') ?>" max="<?= e($scheduleMaxDate ?? '') ?>" required>
              </label>
              <label style="font-size:13px;font-weight:600;color:var(--ink)">Interview time
                <input type="time" name="interview_time" id="schedule-time" class="form-control mt-1" step="60" value="<?= e(date('H:i')) ?>" required>
              </label>
              <?php $invSmsFields('schedule', $scheduleAutoMessage ?? ''); ?>
              <button type="submit" class="btn-navy">Schedule Date and Send SMS</button>
            </form>
            </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="small text-muted mb-3" id="schedule-interview">Interview date booking is currently not enabled.</div>
      <?php endif; ?>

      <?php else: ?>
        <div class="info-box">This renewal needs document review and approval only. A new interview is not required.</div>

      <?php endif; ?>
      <?php if (!empty($app['renewal_simplified']) || in_array($interview['status'] ?? '', ['BOOKED', 'COMPLETED', 'MISSED'], true) || in_array($app['status'], ['Approved', 'ID Released'], true)): ?>
      <div class="review-card" id="id-release">
        <div class="review-card-head"><div class="title">ID Release Scheduling</div>
          <?php if ($app['status'] === 'ID Released'): ?><span class="status-pill" style="background:#dcf3e2;color:#1c7a3f" data-i18n>ID Released</span>
          <?php elseif ($idReleaseSchedule): ?><span class="status-pill" style="background:#dce8f5;color:#1b3a6b" data-i18n>ID Release Scheduled</span>
          <?php else: ?><span class="status-pill" style="background:#f1f1f1;color:#555"><?= e($app['status']) ?></span><?php endif; ?>
        </div>
        <?php if ($releaseError): ?><div class="auth-error" role="alert"><?= e($releaseError) ?></div><?php endif; ?>
        <?php if ($app['status'] === 'ID Released'): ?>
          <div class="review-row"><div class="k">Handed over</div><div class="v"><?= $idReleaseSchedule && $idReleaseSchedule['released_at'] ? e(date('F j, Y g:i A', strtotime($idReleaseSchedule['released_at']))) : 'Recorded in application status history.' ?></div></div>
          <?php if ($idReleaseSchedule && $idReleaseSchedule['released_by_name']): ?><div class="small text-muted">Confirmed by <?= e($idReleaseSchedule['released_by_name']) ?></div><?php endif; ?>
          <?php $validFromShown = $app['id_valid_from'] ?? (($idReleaseSchedule['released_at'] ?? null) ? substr((string) $idReleaseSchedule['released_at'], 0, 10) : null); ?>
          <div class="review-grid mt-2 mb-2">
            <div class="review-row"><div class="k">Valid from</div><div class="v"><?= $validFromShown ? e(date('F j, Y', strtotime((string) $validFromShown))) : '—' ?><?= empty($app['id_valid_from']) && $validFromShown ? ' <span class="small text-muted">(handover date)</span>' : '' ?></div></div>
            <div class="review-row"><div class="k">Valid until</div><div class="v"><b><?= $app['id_expires_at'] ? e(date('F j, Y', strtotime((string) $app['id_expires_at']))) : '—' ?></b><?= $app['id_expires_at'] && $app['id_expires_at'] < date('Y-m-d') ? ' <span class="status-pill" style="background:#fde8e8;color:#a51c1c">Expired</span>' : '' ?></div></div>
          </div>
          <details class="mt-1"><summary class="small fw-semibold" style="cursor:pointer;color:var(--ink)">Correct the ID dates</summary>
            <form method="post" class="d-flex flex-column gap-2 mt-2" onsubmit="return confirm('Save the corrected ID dates? The Solo Parent is told in their account.');">
              <?= csrf_field() ?><input type="hidden" name="form_action" value="correct_id_dates">
              <div class="small text-muted">Only to fix a typing mistake: use the dates printed on the card. The expiry SMS follows the corrected date if it has not been sent yet.</div>
              <div class="row g-2">
                <label class="col-12 col-sm-6" style="font-size:13px;font-weight:600;color:var(--ink)">ID start date<input type="date" name="id_valid_from" class="form-control mt-1" value="<?= e((string) ($validFromShown ?? '')) ?>" required></label>
                <label class="col-12 col-sm-6" style="font-size:13px;font-weight:600;color:var(--ink)">Expiration date<input type="date" name="id_expires_at" class="form-control mt-1" value="<?= e((string) ($app['id_expires_at'] ?? '')) ?>" required></label>
              </div>
              <label style="font-size:13px;font-weight:600;color:var(--ink)">Reason for the correction<textarea name="correction_reason" rows="2" maxlength="500" class="form-control mt-1" required placeholder="For example: the expiration date was typed wrong."></textarea></label>
              <button type="submit" class="btn-outline align-self-start">Save corrected dates</button>
            </form>
          </details>
        <?php elseif ($outstandingRequirements && !($app['status'] === 'Approved' && $idReleaseSchedule)): ?>
          <div class="checklist-attention-summary" role="status">Remind the Solo Parent to submit the highlighted required documents before ID release can be scheduled.</div>
        <?php elseif ($app['status'] !== 'Approved'): ?>
          <div class="small text-muted">An ID release appointment can be scheduled after the application is approved.</div>
        <?php else: ?>
          <?php if ($idReleaseSchedule): ?>
            <div class="review-grid mb-3">
              <div class="review-row"><div class="k">Release date and time</div><div class="v"><?= e(date('F j, Y g:i A', strtotime($idReleaseSchedule['scheduled_at']))) ?></div></div>
              <div class="review-row"><div class="k" data-i18n>Location</div><div class="v"><?= e($idReleaseSchedule['location']) ?></div></div>
              <div class="review-row"><div class="k">Scheduled by</div><div class="v"><?= e($idReleaseSchedule['scheduled_by_name'] ?? 'Former MSWDO staff') ?></div></div>
            </div>
            <div class="review-row mb-3"><div class="k">SMS submission</div><div class="v">
              <?php if ($idReleaseSchedule['sms_status']): ?><b><?= e($idReleaseSchedule['sms_status']) ?></b><?php if ($idReleaseSchedule['sms_status'] === 'SENT'): ?> · Provider accepted; delivery is not confirmed<?php endif; ?>
                <?php if ($idReleaseSchedule['sms_mobile']): ?><br><span class="small text-muted">To ending <?= e(substr((string) $idReleaseSchedule['sms_mobile'], -4)) ?></span><?php endif; ?>
                <?php if ($idReleaseSchedule['sms_error']): ?><br><span class="field-error"><?= e($idReleaseSchedule['sms_error']) ?></span><?php endif; ?>
              <?php else: ?>No SMS log is linked to this schedule.<?php endif; ?>
            </div></div>
            <?php if ($idReleaseSchedule['sms_status'] === 'FAILED' && (int) $idReleaseSchedule['sms_attempts'] < SMS_MAX_ATTEMPTS): ?>
              <form method="post" class="mb-3"><?= csrf_field() ?><input type="hidden" name="form_action" value="retry_id_release_sms"><input type="hidden" name="sms_log_id" value="<?= (int) $idReleaseSchedule['sms_log_id'] ?>"><button class="btn-outline" type="submit">Retry SMS</button></form>
            <?php endif; ?>
          <?php endif; ?>

          <?php if ($idReleaseSchedule) { require APP_ROOT . '/views/staff/applications/id-handover.php'; } ?>
          <?php if (!$outstandingRequirements): ?>
          <?php if ($idReleaseSchedule): ?><details class="mb-3"><summary class="small fw-semibold" style="cursor:pointer;color:var(--ink)">Change the release date, time or place</summary><div class="mt-3"><?php endif; ?>
          <form method="post" class="d-flex flex-column gap-3 mb-3">
            <?= csrf_field() ?><input type="hidden" name="form_action" value="schedule_id_release">
            <div class="row g-2">
              <label class="col-12 col-sm-6" style="font-size:13px;font-weight:600;color:var(--ink)">ID Release Date<input type="date" name="release_date" min="<?= e(date('Y-m-d')) ?>" value="<?= $idReleaseSchedule ? e(date('Y-m-d', strtotime($idReleaseSchedule['scheduled_at']))) : '' ?>" class="form-control mt-1" required></label>
              <label class="col-12 col-sm-6" style="font-size:13px;font-weight:600;color:var(--ink)">ID Release Time<input type="time" name="release_time" value="<?= $idReleaseSchedule ? e(date('H:i', strtotime($idReleaseSchedule['scheduled_at']))) : '' ?>" class="form-control mt-1" required></label>
            </div>
            <input type="text" name="release_location" maxlength="255" value="<?= e($idReleaseSchedule['location'] ?? $defaultReleaseLocation) ?>" placeholder="Release location" class="form-control" required>
            <label style="font-size:13px;font-weight:600;color:var(--ink)">SMS Message Option<select name="sms_message_type" id="release-sms-type" class="form-select mt-1"><option value="automatic" <?= !$idReleaseSchedule || $idReleaseSchedule['sms_template_key'] !== 'id_release_custom' ? 'selected' : '' ?>>Automatic Message</option><option value="custom" <?= $idReleaseSchedule && $idReleaseSchedule['sms_template_key'] === 'id_release_custom' ? 'selected' : '' ?>>Custom Message</option></select></label>
            <div id="release-sms-preview" class="info-box small" style="max-width:none;margin:0;white-space:pre-wrap" role="status"></div>
            <label id="release-custom-wrap" class="d-none" style="font-size:13px;font-weight:600;color:var(--ink)">Review and edit the final SMS<textarea name="release_custom_message" id="release-custom-message" rows="4" maxlength="500" class="form-control mt-1" placeholder="Write the message to send"><?= $idReleaseSchedule && $idReleaseSchedule['sms_template_key'] === 'id_release_custom' ? e($idReleaseSchedule['sms_message']) : '' ?></textarea></label>
            <button type="submit" class="btn-navy review-button-schedule review-release-action">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M8 15h2M14 15h2"/></svg>
              <span>Schedule ID Release and Send SMS</span>
            </button>
          </form>
          <script>
            (() => {
              const date = document.querySelector('[name="release_date"]'), time = document.querySelector('[name="release_time"]');
              const location = document.querySelector('[name="release_location"]'), type = document.getElementById('release-sms-type');
              const preview = document.getElementById('release-sms-preview'), wrap = document.getElementById('release-custom-wrap');
              const custom = document.getElementById('release-custom-message');
              const name = <?= json_encode($app['full_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
              const idTemplate = <?= json_encode($idReleaseTemplate ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
              const automaticMessage = () => {
                if (!date.value || !time.value || !location.value.trim()) return '';
                const [y,m,d] = date.value.split('-').map(Number), [h,min] = time.value.split(':').map(Number);
                const clock = (h % 12 || 12) + ':' + String(min).padStart(2,'0') + (h >= 12 ? ' PM' : ' AM');
                const when = <?= !empty($idReleaseCompact) ? 'true' : 'false' ?>
                  ? new Date(y, m-1, d).toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' }) + ' ' + clock
                  : ['January','February','March','April','May','June','July','August','September','October','November','December'][m-1] + ' ' + d + ', ' + y + ' at ' + clock;
                return idTemplate.split('{name}').join(name).split('{when}').join(when).split('{location}').join(location.value.trim());
              };
              const update = () => {
                const customMode = type.value === 'custom';
                wrap.classList.toggle('d-none', !customMode); custom.required = customMode;
                const automatic = automaticMessage();
                if (customMode) {
                  if (!custom.value && automatic) custom.value = automatic;
                  preview.textContent = custom.value || 'Enter the custom SMS message to review it here.';
                  return;
                }
                preview.textContent = automatic || 'Enter the release date, time, and location to preview the automatic message.';
              };
              [date,time,location,type,custom].forEach(el => el.addEventListener('input', update)); type.addEventListener('change', update); update();
            })();
          </script>
          <?php if ($idReleaseSchedule): ?></div></details><?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php endif; ?>
<?php
$showAssessmentHistory = false;
$showCaseNotes = false;   // staff no longer see or add case notes here; the admin monitoring view still lists them
$showDecisionHistory = !empty($app['renewal_simplified']) || in_array($interview['status'] ?? '', ['BOOKED', 'COMPLETED', 'MISSED'], true) || !empty($decisions);
require APP_ROOT . '/views/partials/case-record.php';
require APP_ROOT . '/views/partials/status-history.php'; ?>
    </div>
  </div>
</div>
