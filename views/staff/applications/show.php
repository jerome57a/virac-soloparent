<?php
/**
 * @var array  $app
 * @var string $statusError
 * @var string $interviewError
 * @var string $eligibilityError
 * @var string $noteError
 * @var ?string $flashMessage
 * @var array  $allowedTransitions  statuses the application can move to now (Application::TRANSITIONS)
 * @var array  $approvalChecklist   ApplicationReviewService::approvalChecklist()
 * @var array  $decisions, $notes, $mswdAssessments  see views/partials/case-record.php
 * @var ?array $selfCheck           the applicant's latest preliminary self-check
 * @var array  $verificationStatuses
 * @var ?array $interview
 * @var array  $children
 * @var array  $docs                 Document::forReview() rows
 * @var array  $docHistory           document_id => status trail
 * @var array  $documentErrors       doc id => error from the last verification attempt
 * @var bool   $documentsEditable
 * @var array  $history
 */
$st = status_style($app['status']);
?>
<div class="portal-container">
  <a href="<?= base_url('staff/index.php') ?>" style="font-size:13px">&larr; Back to applications</a>
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mt-2 mb-4">
    <div>
      <h1 class="font-heading m-0" style="font-size:26px"><?= e($app['reference_no']) ?> <?php if ($app['type'] === 'renewal'): ?><span class="doc-tag" style="vertical-align:middle">RENEWAL</span><?php endif; ?></h1>
      <div style="font-size:13px;color:var(--muted)">Submitted <?= e(date('F j, Y g:i A', strtotime($app['submitted_at']))) ?><?php if ($app['reviewer_name']): ?> · Reviewed by <?= e($app['reviewer_name']) ?><?php endif; ?></div>
    </div>
    <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>;font-size:14px"><?= e($app['status']) ?></div>
  </div>

  <div class="row g-4">
    <div class="col-12 col-lg-7">
      <?php if (isset($renewalOf)) { require APP_ROOT . '/views/partials/renewal-of.php'; } ?>
      <div class="review-card">
        <div class="review-card-head"><div class="title">Personal Info</div></div>
        <div class="review-grid">
          <div class="review-row"><div class="k">Full name</div><div class="v"><?= e($app['full_name']) ?></div></div>
          <div class="review-row"><div class="k">Birthdate</div><div class="v"><?= e(format_date_long($app['birthdate'])) ?></div></div>
          <div class="review-row"><div class="k">Address</div><div class="v"><?= e($app['address']) ?>, Brgy. <?= e($app['barangay']) ?></div></div>
          <?php require APP_ROOT . '/views/partials/mobile-numbers.php'; ?>
          <div class="review-row"><div class="k">Email</div><div class="v"><?= e($app['email']) ?></div></div>
          <div class="review-row"><div class="k">Sex</div><div class="v"><?= e($app['sex']) ?></div></div>
          <div class="review-row"><div class="k">Civil status</div><div class="v"><?= e($app['civil_status']) ?></div></div>
          <div class="review-row"><div class="k">Category</div><div class="v"><?= e($app['category_name']) ?></div></div>
        </div>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Children</div></div>
        <div class="review-grid">
          <?php foreach ($children as $i => $c): ?>
            <div class="review-row"><div class="k">Child <?= $i + 1 ?></div><div class="v"><?= e($c['full_name']) ?> · <?= e(format_date_long($c['birthdate'])) ?></div></div>
          <?php endforeach; ?>
        </div>
      </div>

      <?php $canVerify = true; require APP_ROOT . '/views/partials/document-review.php'; ?>
    </div>

    <div class="col-12 col-lg-5">
      <?php if ($flashMessage): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>

      <div class="review-card" id="decision">
        <div class="review-card-head">
          <div class="title">Decision</div>
          <span class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></span>
        </div>
        <?php if ($statusError): ?><div class="auth-error" role="alert"><?= e($statusError) ?></div><?php endif; ?>

        <?php if (!$allowedTransitions): ?>
          <div style="font-size:13px;color:var(--muted)">This application has a final status (<?= e($app['status']) ?>). No further decisions can be made.</div>
        <?php else: ?>
          <?php if (in_array('Approved', $allowedTransitions, true)): ?>
            <div class="approval-checklist">
              <div style="font-size:12px;font-weight:700;color:var(--ink);margin-bottom:6px">Before approving</div>
              <?php foreach ($approvalChecklist as $item): ?>
                <div class="<?= $item['ok'] ? 'ok' : 'no' ?>"><?= $item['ok'] ? '✓' : '✗' ?> <?= e($item['label']) ?><?php if (!$item['ok'] && $item['detail']): ?> <span class="detail">— <?= e($item['detail']) ?></span><?php endif; ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form method="post" class="d-flex flex-column gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update_status">
            <input type="hidden" name="expected_status" value="<?= e($app['status']) ?>">
            <textarea name="remarks" aria-label="Remarks for the applicant" rows="3" maxlength="500" placeholder="Reason / remarks for the applicant (required to request corrections or reject; sent by SMS)" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px"></textarea>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ($allowedTransitions as $to):
                  $class = $to === 'Rejected' ? 'btn-red' : ($to === 'Approved' || $to === 'ID Released' ? 'btn-navy' : 'btn-outline');
                  $confirm = $to === 'Rejected' ? 'Reject this application? This is final.' : ($to === 'Approved' ? 'Approve this application?' : '');
              ?>
                <button type="submit" name="status" value="<?= e($to) ?>" class="<?= $class ?>" style="flex:1 1 150px"<?= $confirm ? ' onclick="return confirm(\'' . e($confirm) . '\')"' : '' ?>><?= e(Application::ACTION_LABELS[$to] ?? $to) ?></button>
              <?php endforeach; ?>
            </div>
          </form>
        <?php endif; ?>
      </div>

      <div class="review-card" id="assessment">
        <div class="review-card-head">
          <div class="title">Eligibility Assessment</div>
          <?php $es = $app['eligibility_status']; ?>
          <span class="status-pill" style="background:<?= $es === 'Eligible' ? '#dcf3e2' : ($es === 'Not Eligible' ? '#fde8e8' : '#f1f1f1') ?>;color:<?= $es === 'Eligible' ? '#1c7a3f' : ($es === 'Not Eligible' ? '#a51c1c' : '#555') ?>"><?= e($es) ?></span>
        </div>
        <div style="font-size:12px;color:var(--faint);margin-bottom:10px">MSWD's official assessment against RA 8972/11861. The applicant's own self-check below is for reference only.</div>
        <div class="self-check">
          <b>Applicant's preliminary self-check:</b>
          <?php if ($selfCheck): ?>
            <?= $selfCheck['result']['eligible'] ? 'may be eligible' : 'may not meet the criteria' ?> (<?= e(date('M j, Y', strtotime($selfCheck['checkedAt']))) ?>)<?php if ($selfCheck['result']['reason']): ?> — <?= e($selfCheck['result']['reason']) ?><?php endif; ?>
          <?php else: ?>
            none on record.
          <?php endif; ?>
        </div>
        <?php if ($eligibilityError): ?><div class="auth-error" role="alert"><?= e($eligibilityError) ?></div><?php endif; ?>
        <?php if (in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)): ?>
          <form method="post" class="d-flex flex-column gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="update_eligibility">
            <div class="d-flex gap-3" style="font-size:14px">
              <label class="d-flex align-items-center gap-2"><input type="radio" name="eligibility_status" value="Eligible" <?= $es === 'Eligible' ? 'checked' : '' ?> required> Eligible</label>
              <label class="d-flex align-items-center gap-2"><input type="radio" name="eligibility_status" value="Not Eligible" <?= $es === 'Not Eligible' ? 'checked' : '' ?>> Not Eligible</label>
            </div>
            <textarea name="eligibility_remarks" aria-label="Eligibility assessment notes" rows="2" maxlength="500" placeholder="Assessment notes (required if not eligible)" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px"></textarea>
            <button type="submit" class="btn-outline">Record Assessment</button>
          </form>
        <?php endif; ?>
      </div>
      <div class="review-card" id="interview">
        <?php
        $ivStatus = $interview['status'] ?? null;
        $ivStyles = ['BOOKED' => ['#dce8f5', '#1b3a6b', 'Booked'], 'COMPLETED' => ['#dcf3e2', '#1c7a3f', 'Completed'], 'MISSED' => ['#fde8e8', '#a51c1c', 'Missed'], 'CANCELLED' => ['#f1f1f1', '#555', 'Cancelled']];
        $inProgress = in_array($app['status'], Application::IN_PROGRESS_STATUSES, true);
        ?>
        <div class="review-card-head">
          <div class="title">Interview</div>
          <?php if ($ivStatus): [$ibg, $ifg, $ilabel] = $ivStyles[$ivStatus]; ?><span class="status-pill" style="background:<?= $ibg ?>;color:<?= $ifg ?>"><?= e($ilabel) ?></span><?php endif; ?>
        </div>
        <?php if ($interviewError): ?><div class="auth-error" role="alert"><?= e($interviewError) ?></div><?php endif; ?>

        <?php if ($interview): ?>
          <div class="review-grid mb-3">
            <div class="review-row"><div class="k">Scheduled</div><div class="v"><?= e(date('F j, Y g:i A', strtotime($interview['scheduled_at']))) ?></div></div>
            <div class="review-row"><div class="k">Location</div><div class="v"><?= e($interview['location']) ?></div></div>
            <div class="review-row"><div class="k">Booked by</div><div class="v"><?= $interview['scheduled_by'] ? 'MSWDO staff' : 'Applicant (self-booked)' ?></div></div>
            <?php if ($interview['notes']): ?><div class="review-row"><div class="k">Notes</div><div class="v"><?= e($interview['notes']) ?></div></div><?php endif; ?>
            <?php if ($ivStatus === 'CANCELLED'): ?><div class="review-row"><div class="k">Cancelled</div><div class="v"><?= e(date('M j, Y g:i A', strtotime($interview['cancelled_at']))) ?><?= $interview['cancel_reason'] ? ' — ' . e($interview['cancel_reason']) : '' ?></div></div><?php endif; ?>
          </div>
          <?php if ($ivStatus === 'BOOKED'): ?>
            <div style="font-size:12px;color:var(--muted);margin-bottom:12px">To cancel this appointment or mark a no-show, use <a href="<?= base_url('staff/appointments.php') ?>">Appointment Management</a>.</div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($inProgress && $ivStatus !== 'COMPLETED'): ?>
          <form method="post" class="d-flex flex-column gap-3 mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="schedule_interview">
            <label style="font-size:13px;font-weight:600;color:var(--ink)">
              <?= $ivStatus === 'BOOKED' ? 'Reschedule interview' : 'Schedule interview' ?>
              <input type="datetime-local" name="scheduled_at" min="<?= e(date('Y-m-d\TH:i')) ?>" value="<?= $ivStatus === 'BOOKED' ? e(date('Y-m-d\TH:i', strtotime($interview['scheduled_at']))) : '' ?>" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px" required>
            </label>
            <input type="text" name="location" aria-label="Interview location" maxlength="255" value="<?= $interview ? e($interview['location']) : '' ?>" placeholder="Location (e.g. MSWDO Office, Municipal Building)" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px" required>
            <textarea name="notes" aria-label="Notes for the applicant" rows="2" maxlength="500" placeholder="Notes for the applicant (optional)" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px"><?= $interview ? e($interview['notes']) : '' ?></textarea>
            <button type="submit" class="btn-outline"><?= $ivStatus === 'BOOKED' ? 'Update Interview Schedule' : 'Schedule Interview' ?></button>
          </form>
        <?php endif; ?>

        <?php if ($ivStatus === 'BOOKED' && substr((string) $interview['scheduled_at'], 0, 10) > date('Y-m-d')): ?>
          <div style="font-size:13px;color:var(--muted)">The interview result can be recorded from <?= e(format_date_long(substr((string) $interview['scheduled_at'], 0, 10))) ?>, the day of the interview.</div>
        <?php elseif (in_array($ivStatus, ['BOOKED', 'COMPLETED'], true)): ?>
          <form method="post" class="d-flex flex-column gap-3">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="record_result">
            <label style="font-size:13px;font-weight:600;color:var(--ink)">
              Interview result
              <textarea name="result" aria-label="Interview result" rows="3" maxlength="500" placeholder="What happened at the interview?" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px"><?= e($interview['result'] ?? '') ?></textarea>
            </label>
            <?php if ($interview['result_recorded_at']): ?>
              <div style="font-size:12px;color:var(--faint)">Recorded <?= e(date('M j, Y g:i A', strtotime($interview['result_recorded_at']))) ?></div>
            <?php endif; ?>
            <button type="submit" class="btn-navy">Save Result</button>
          </form>
        <?php endif; ?>
      </div>

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
require APP_ROOT . '/views/partials/case-record.php';
require APP_ROOT . '/views/partials/status-history.php'; ?>
    </div>
  </div>
</div>
