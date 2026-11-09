<?php
/**
 * @var array  $app
 * @var string $statusError
 * @var string $assessmentError
 * @var string $releaseError
 * @var string $interviewError
 * @var string $noteError
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
$canReceiveDocuments = !empty($app['renewal_simplified']) || (($interview['status'] ?? '') === 'COMPLETED' && $interviewOutcome !== null);
$canDecide = !empty($app['renewal_simplified']) || Appointment::interviewSuccessful($interview);
$interviewNotes = (string) ($interview['result'] ?? '');
$requiredChecklist = array_filter($submittedChecklist ?? [], fn($item) => !empty($item['is_required']));
$uncheckedRequirements = array_filter($requiredChecklist, [Document::class, 'needsPreparation']);
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

      <div class="review-card" id="checklist">
        <div class="review-card-head"><div class="title" data-i18n>Required Documents Checklist</div></div>
        <?php if (!empty($documentErrors['checklist'])): ?><div class="auth-error" role="alert"><?= e($documentErrors['checklist']) ?></div><?php endif; ?>
        <div class="staff-applicant-list">
          <?php if (empty($submittedChecklist)): ?>
            <div class="text-muted small">No document checklist information is recorded for this application.</div>
          <?php else: foreach ($submittedChecklist as $item): ?>
            <?php $needsAttention = (!empty($item['is_required']) || empty($item['applicant_has'])) && ($item['verification_status'] !== 'Verified' || (!empty($item['expires_at']) && $item['expires_at'] < date('Y-m-d'))); ?>
            <div class="review-row<?= $needsAttention ? ' checklist-needs-attention' : '' ?>" id="checklist-<?= e($item['doc_key']) ?>">
              <?php if ($needsAttention && empty($item['applicant_has'])): ?>
                <div class="checklist-missing-badge"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.3 3.9 1.8 18.6A2 2 0 0 0 3.5 21h17a2 2 0 0 0 1.7-2.4L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4m0 4h.01"/></svg>Not submitted</div>
              <?php endif; ?>
              <div class="k"><input type="checkbox" disabled <?= !empty($item['applicant_has']) || ($item['verification_status'] === 'Verified' && !$needsAttention) ? 'checked' : '' ?> aria-label="Document checklist: <?= e($item['label']) ?>"> <?= e($item['label']) ?><?= !empty($item['is_required']) ? ' *' : ' (optional)' ?></div>
              <?php if ($canReceiveDocuments && $needsAttention && !empty($item['is_required']) && !empty($item['id']) && in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)): ?>
                <form method="post" class="mt-2" onsubmit="return confirm('Confirm that staff physically received this document?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="form_action" value="receive_checklist_document">
                  <input type="hidden" name="doc_key" value="<?= e($item['doc_key']) ?>">
                  <input type="hidden" name="confirm_received" value="1">
                  <button type="submit" class="btn-outline">Document received</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
        </div>
        <?php if ($canReceiveDocuments && $outstandingRequirements && in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)): ?>
          <form method="post" class="mt-3" onsubmit="return confirm('Confirm that staff physically received ALL remaining required documents? Do not confirm while any required document is still missing.');">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="confirm_all_required_documents">
            <input type="hidden" name="confirm_received" value="1">
            <button type="submit" class="btn-navy">Confirm all required documents received</button>
          </form>
        <?php endif; ?>
      </div>
      <div class="review-card">
        <div class="review-card-head"><div class="title">Applicant Declaration</div></div>
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

      <div class="review-card" id="decision">
        <?php if ($statusError): ?><div class="auth-error" role="alert"><?= e($statusError) ?></div><?php endif; ?>
        <?php if ($assessmentError): ?><div class="auth-error" role="alert"><?= e($assessmentError) ?></div><?php endif; ?>

        <?php if (!$allowedTransitions): ?>
          <div style="font-size:13px;color:var(--muted)">This application has a final status (<?= e($app['status']) ?>). No further decisions can be made.</div>
        <?php else: ?>
          <?php if ($interview && $interview['status'] === 'BOOKED'): ?><div class="mb-2"><b data-i18n>Interview Scheduled</b> · <?= e(date('F j, Y g:i A', strtotime($interview['scheduled_at']))) ?> at <?= e($interview['location']) ?></div><?php endif; ?>
          <?php if ($approvalChecklist): ?>
            <div class="approval-checklist">
              <div style="font-size:12px;font-weight:700;color:var(--ink);margin-bottom:6px">Before approving</div>
              <?php foreach ($approvalChecklist as $item): ?>
                <div class="<?= $item['ok'] ? 'ok' : 'no' ?>"><?= $item['ok'] ? '✓' : '✗' ?> <?= e($item['label']) ?><?php if ($item['detail']): ?> <span class="detail" style="white-space:pre-line">— <?= e($item['detail']) ?></span><?php endif; ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if (empty($app['renewal_simplified']) && in_array($interview['status'] ?? '', ['BOOKED', 'COMPLETED'], true)): ?>
            <?php if ($interviewOutcome): ?>
              <div class="review-saved-action mb-3"><span>Interview: <b><?= e($interviewOutcome) ?></b></span><?php $undoButton('interview', 'Undo Interview Result'); ?></div>
              <?php if ($interviewNotes !== ''): ?><div class="small mb-3"><?= e($interviewNotes) ?></div><?php endif; ?>
            <?php else: ?>
            <form method="post" id="interview-result-form" class="d-flex flex-column gap-2 mb-3" onsubmit="return confirm('Confirm this interview result?');">
              <?= csrf_field() ?>
              <input type="hidden" name="form_action" value="record_result">
              <?php if ($interviewOutcome): ?><div class="small">Current result: <b><?= e($interviewOutcome) ?></b></div><?php endif; ?>
              <?php if ($interview['result_recorded_at']): ?>
                <div style="font-size:12px;color:var(--faint)">Recorded <?= e(date('M j, Y g:i A', strtotime($interview['result_recorded_at']))) ?></div>
              <?php endif; ?>
              <?php if ($interview['status'] === 'BOOKED' && strtotime((string) $interview['scheduled_at']) > time()): ?>
                <div class="small text-muted">The interview result can be recorded on or after <?= e(date('F j, Y g:i A', strtotime($interview['scheduled_at']))) ?>.</div>
              <?php else: ?>
                <div class="review-action-grid" role="group" aria-label="Interview result">
                  <button type="submit" name="interview_outcome" value="Successful" class="btn-outline review-button-success<?= $interviewOutcome === 'Successful' ? ' is-selected' : '' ?>" aria-pressed="<?= $interviewOutcome === 'Successful' ? 'true' : 'false' ?>" style="flex:1" data-i18n>Successful</button>
                  <button type="submit" name="interview_outcome" value="Unsuccessful" class="btn-outline review-button-danger<?= $interviewOutcome === 'Unsuccessful' ? ' is-selected' : '' ?>" aria-pressed="<?= $interviewOutcome === 'Unsuccessful' ? 'true' : 'false' ?>" style="flex:1" data-i18n>Unsuccessful</button>
                </div>
              <?php endif; ?>
            </form>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($canDecide): ?>
          <form method="post" id="application-decision-form" class="d-flex flex-column gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update_status">
            <input type="hidden" name="expected_status" value="<?= e($app['status']) ?>">
            <div class="review-action-grid">
              <?php foreach ($allowedTransitions as $to):
                  if ($to === 'Under Verification') {
                      continue;
                  }
                  $class = $to === 'Rejected' ? 'btn-outline review-button-danger' : ($to === 'Approved' || $to === 'ID Released' ? 'btn-navy review-button-success is-selected' : 'btn-outline review-button-correction');
                  $confirm = $to === 'Rejected' ? 'Reject this application? This is final.' : ($to === 'Approved' ? 'Approve this application?' : '');
              ?>
                <button type="submit" name="status" value="<?= e($to) ?>" class="<?= $class ?>" style="flex:1 1 150px"<?= $to === 'Approved' && $outstandingRequirements ? ' disabled title="Complete all required documents before approval"' : '' ?><?= $confirm ? ' onclick="return confirm(\'' . e($confirm) . '\')"' : '' ?>><?= e(Application::ACTION_LABELS[$to] ?? $to) ?></button>
              <?php endforeach; ?>
            </div>
          </form>
          <?php elseif ($interview && in_array($interview['status'], ['BOOKED', 'COMPLETED', 'MISSED'], true)): ?>
            <div class="info-box" role="status">Request Corrections, Approve, and Reject become available after a successful interview is recorded.</div>
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

        <?php if ($interview): ?>
          <div class="review-grid mb-3">
            <div class="review-row"><div class="k">Scheduled</div><div class="v"><?= e(date('F j, Y g:i A', strtotime($interview['scheduled_at']))) ?></div></div>
            <div class="review-row"><div class="k" data-i18n>Location</div><div class="v"><?= e($interview['location']) ?></div></div>
            <div class="review-row"><div class="k">Booked by</div><div class="v"><?= $interview['scheduled_by'] ? 'MSWDO staff' : (($interview['notes'] ?? '') === 'Interview automatically scheduled on application submission.' ? 'System (automatically scheduled)' : 'Applicant (self-booked)') ?></div></div>
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
              <?php if (!empty($interviewSms['test_mode'])): ?> · Test mode (not sent)<?php else: ?> · <?= e($interviewSms['provider']) ?><?= $interviewSms['status'] === 'SENT' ? ' accepted the message' : '' ?><?php endif; ?>
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
          <div class="info-box" style="max-width:none">
            <b><?= $uncheckedRequirements ? 'Documents to complete before ID release' : 'All required documents checked' ?></b>
            <?php if ($uncheckedRequirements): ?><ul><?php foreach ($uncheckedRequirements as $item): ?><li><?= e($item['label']) ?></li><?php endforeach; ?></ul><?php endif; ?>
            Checked documents are automatically verified. You can schedule the interview even if some documents are missing. The applicant must submit the missing documents before ID release can be scheduled.
          </div>
          <?php require APP_ROOT . '/views/staff/appointments/schedule-form.php'; ?>
        <?php endif; ?>

        <?php if ($allowedTransitions && $canDecide): ?>
          <label class="d-block mt-3" style="font-size:13px;font-weight:600;color:var(--ink)">
            Remarks for the applicant
            <textarea form="application-decision-form" name="remarks" aria-label="Remarks for the applicant" rows="3" maxlength="500" placeholder="Remarks for the applicant (required to reject; sent by SMS)" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px"></textarea>
          </label>
        <?php endif; ?>
      </div>

      <?php else: ?>
        <div class="info-box">This renewal needs document review and approval only. A new interview is not required.</div>
        <?php if ($allowedTransitions): ?><label class="d-block mb-3">Remarks for the applicant<textarea form="application-decision-form" name="remarks" rows="3" maxlength="500" class="form-control" aria-label="Renewal decision remarks"></textarea></label><?php endif; ?>
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
        <?php elseif ($outstandingRequirements): ?>
          <div class="checklist-attention-summary" role="status">Remind the applicant to submit the highlighted required documents before ID release can be scheduled.</div>
        <?php elseif ($app['status'] !== 'Approved'): ?>
          <div class="small text-muted">An ID release appointment can be scheduled after the application is approved.</div>
        <?php else: ?>
          <?php if ($idReleaseSchedule): ?>
            <div class="review-grid mb-3">
              <div class="review-row"><div class="k">Release date and time</div><div class="v"><?= e(date('F j, Y g:i A', strtotime($idReleaseSchedule['scheduled_at']))) ?></div></div>
              <div class="review-row"><div class="k" data-i18n>Location</div><div class="v"><?= e($idReleaseSchedule['location']) ?></div></div>
              <div class="review-row"><div class="k">Scheduled by</div><div class="v"><?= e($idReleaseSchedule['scheduled_by_name'] ?? 'Former staff') ?></div></div>
            </div>
            <div class="review-row mb-3"><div class="k">SMS submission</div><div class="v">
              <?php if ($idReleaseSchedule['sms_status']): ?><b><?= e($idReleaseSchedule['sms_status']) ?></b><?php if ($idReleaseSchedule['sms_test_mode']): ?> · Test mode (not sent)<?php elseif ($idReleaseSchedule['sms_status'] === 'SENT'): ?> · Provider accepted; delivery is not confirmed<?php endif; ?>
                <?php if ($idReleaseSchedule['sms_mobile']): ?><br><span class="small text-muted">To ending <?= e(substr((string) $idReleaseSchedule['sms_mobile'], -4)) ?></span><?php endif; ?>
                <?php if ($idReleaseSchedule['sms_error']): ?><br><span class="field-error"><?= e($idReleaseSchedule['sms_error']) ?></span><?php endif; ?>
              <?php else: ?>No SMS log is linked to this schedule.<?php endif; ?>
            </div></div>
            <?php if ($idReleaseSchedule['sms_status'] === 'FAILED' && (int) $idReleaseSchedule['sms_attempts'] < SMS_MAX_ATTEMPTS): ?>
              <form method="post" class="mb-3"><?= csrf_field() ?><input type="hidden" name="form_action" value="retry_id_release_sms"><input type="hidden" name="sms_log_id" value="<?= (int) $idReleaseSchedule['sms_log_id'] ?>"><button class="btn-outline" type="submit">Retry SMS</button></form>
            <?php endif; ?>
          <?php endif; ?>

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
              const automaticMessage = () => {
                if (!date.value || !time.value || !location.value.trim()) return '';
                const [y,m,d] = date.value.split('-').map(Number), [h,min] = time.value.split(':').map(Number);
                const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                const when = months[m-1] + ' ' + d + ', ' + y + ' at ' + (h % 12 || 12) + ':' + String(min).padStart(2,'0') + (h >= 12 ? ' PM' : ' AM');
                return 'Hello ' + name + ', your Solo Parent ID is scheduled for release on ' + when + '. Location: ' + location.value.trim() + '. Please visit the MSWDO Virac office at your scheduled time. For inquiries, contact the MSWDO office.';
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
          <?php if ($idReleaseSchedule): ?>
            <div class="info-box" style="max-width:none">Scheduling the appointment does not mark the ID as released.</div>
            <form method="post" onsubmit="return confirm('Confirm that the Solo Parent ID was physically handed to this applicant? This will record ID Released.');">
              <?= csrf_field() ?><input type="hidden" name="form_action" value="confirm_id_release"><input type="hidden" name="confirm_handover" value="1">
              <button type="submit" class="btn-navy review-button-success is-selected review-release-action">Confirm ID Released</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <?php endif; ?>
<?php
$noteForm = function () use ($noteError): void { ?>
  <?php if ($noteError): ?><div class="auth-error" role="alert"><?= e($noteError) ?></div><?php endif; ?>
  <form method="post" class="d-flex flex-column gap-2 mb-3">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="add_note">
    <textarea name="note" aria-label="Case note" rows="2" maxlength="1000" required placeholder="Add a case note (home visit findings, phone call, etc.)" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px"></textarea>
    <button type="submit" class="btn-outline">Add Note</button>
  </form>
<?php };
$showAssessmentHistory = false;
$showDecisionHistory = !empty($app['renewal_simplified']) || in_array($interview['status'] ?? '', ['BOOKED', 'COMPLETED', 'MISSED'], true) || !empty($decisions);
require APP_ROOT . '/views/partials/case-record.php';
require APP_ROOT . '/views/partials/status-history.php'; ?>
    </div>
  </div>
</div>
