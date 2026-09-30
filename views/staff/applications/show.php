<?php
/**
 * @var array  $app
 * @var string $statusError
 * @var string $interviewError
 * @var array  $statuses
 * @var array  $eligibilityStatuses
 * @var array  $verificationStatuses
 * @var ?array $interview
 * @var array  $children
 * @var array  $docs
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
      <div class="review-card">
        <div class="review-card-head"><div class="title">Personal Info</div></div>
        <div class="review-grid">
          <div class="review-row"><div class="k">Full name</div><div class="v"><?= e($app['full_name']) ?></div></div>
          <div class="review-row"><div class="k">Birthdate</div><div class="v"><?= e(format_date_long($app['birthdate'])) ?></div></div>
          <div class="review-row"><div class="k">Address</div><div class="v"><?= e($app['address']) ?>, Brgy. <?= e($app['barangay']) ?></div></div>
          <div class="review-row"><div class="k">Mobile</div><div class="v"><?= e($app['mobile']) ?></div></div>
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

      <div class="review-card">
        <div class="review-card-head"><div class="title">Documents</div></div>
        <?php foreach ($docs as $d): $vst = doc_verification_style($d['verification_status']); ?>
          <div class="mb-3 pb-3" style="border-bottom:1px solid var(--border)">
            <div class="d-flex justify-content-between align-items-center gap-2 mb-2" style="font-size:14px">
              <span><?= e($d['doc_key']) ?> — <?= e($d['original_name']) ?></span>
              <div class="d-flex align-items-center gap-2">
                <span class="status-pill" style="background:<?= e($vst['bg']) ?>;color:<?= e($vst['color']) ?>"><?= e($d['verification_status']) ?></span>
                <a href="<?= base_url('download.php?doc_id=' . (int) $d['id']) ?>" target="_blank" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px">View</a>
              </div>
            </div>
            <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
              <?= csrf_field() ?>
              <input type="hidden" name="form_action" value="update_document_status">
              <input type="hidden" name="doc_id" value="<?= (int) $d['id'] ?>">
              <select name="verification_status" class="form-select" style="max-width:170px;padding:6px 10px;font-size:12px">
                <?php foreach ($verificationStatuses as $vs): ?>
                  <option value="<?= e($vs) ?>" <?= $d['verification_status'] === $vs ? 'selected' : '' ?>><?= e($vs) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="text" name="staff_remarks" value="<?= e($d['staff_remarks'] ?? '') ?>" placeholder="Remarks for the applicant (optional)" class="form-control" style="flex:1 1 200px;padding:6px 10px;font-size:12px">
              <button type="submit" class="btn-outline" style="padding:6px 12px;font-size:12px">Save</button>
            </form>
          </div>
        <?php endforeach; ?>
        <?php if (!$docs): ?><div style="font-size:13px;color:var(--muted)">No documents on file.</div><?php endif; ?>
      </div>
    </div>

    <div class="col-12 col-lg-5">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Eligibility Assessment</div></div>
        <div style="font-size:12px;color:var(--faint);margin-bottom:10px">This is MSWD's official assessment against RA 8972/11861, separate from the applicant's own self-check.</div>
        <form method="post" class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <input type="hidden" name="form_action" value="update_eligibility">
          <select name="eligibility_status" class="form-select" style="padding:10px;border:1px solid var(--input-border);border-radius:6px">
            <?php foreach ($eligibilityStatuses as $es): ?>
              <option value="<?= e($es) ?>" <?= $app['eligibility_status'] === $es ? 'selected' : '' ?>><?= e($es) ?></option>
            <?php endforeach; ?>
          </select>
          <textarea name="eligibility_remarks" rows="2" placeholder="Evaluation remarks (optional)" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px"><?= e($app['eligibility_remarks'] ?? '') ?></textarea>
          <button type="submit" class="btn-outline">Save Assessment</button>
        </form>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Update Status</div></div>
        <?php if ($statusError): ?><div class="auth-error"><?= e($statusError) ?></div><?php endif; ?>
        <form method="post" class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <select name="status" class="form-select" style="padding:10px;border:1px solid var(--input-border);border-radius:6px">
            <?php foreach ($statuses as $s): ?>
              <option value="<?= e($s) ?>" <?= $app['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
          <textarea name="remarks" rows="3" placeholder="Remarks sent to the applicant via SMS (optional)" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px"></textarea>
          <input type="hidden" name="form_action" value="update_status">
          <button type="submit" class="btn-navy">Save Status</button>
        </form>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Interview</div></div>
        <?php if ($interviewError): ?><div class="auth-error"><?= e($interviewError) ?></div><?php endif; ?>

        <?php if ($interview): ?>
          <div class="review-grid mb-3">
            <div class="review-row"><div class="k">Scheduled</div><div class="v"><?= e(date('F j, Y g:i A', strtotime($interview['scheduled_at']))) ?></div></div>
            <div class="review-row"><div class="k">Location</div><div class="v"><?= e($interview['location']) ?></div></div>
            <div class="review-row"><div class="k">Booked by</div><div class="v"><?= $interview['scheduled_by'] ? 'MSWDO staff' : 'Applicant (self-booked)' ?></div></div>
            <?php if ($interview['notes']): ?><div class="review-row"><div class="k">Notes</div><div class="v"><?= e($interview['notes']) ?></div></div><?php endif; ?>
          </div>
        <?php endif; ?>

        <form method="post" class="d-flex flex-column gap-3 mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="form_action" value="schedule_interview">
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            <?= $interview ? 'Reschedule interview' : 'Schedule interview' ?>
            <input type="datetime-local" name="scheduled_at" value="<?= $interview ? e(date('Y-m-d\TH:i', strtotime($interview['scheduled_at']))) : '' ?>" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px" required>
          </label>
          <input type="text" name="location" value="<?= $interview ? e($interview['location']) : '' ?>" placeholder="Location (e.g. MSWDO Office, Municipal Building)" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px" required>
          <textarea name="notes" rows="2" placeholder="Notes for the applicant (optional)" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px"><?= $interview ? e($interview['notes']) : '' ?></textarea>
          <button type="submit" class="btn-outline"><?= $interview ? 'Update Interview Schedule' : 'Schedule Interview' ?></button>
        </form>

        <?php if ($interview): ?>
          <form method="post" class="d-flex flex-column gap-3">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="record_result">
            <label style="font-size:13px;font-weight:600;color:var(--ink)">
              Interview result
              <textarea name="result" rows="3" placeholder="What happened at the interview?" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px"><?= e($interview['result'] ?? '') ?></textarea>
            </label>
            <?php if ($interview['result_recorded_at']): ?>
              <div style="font-size:12px;color:var(--faint)">Recorded <?= e(date('M j, Y g:i A', strtotime($interview['result_recorded_at']))) ?></div>
            <?php endif; ?>
            <button type="submit" class="btn-navy">Save Result</button>
          </form>
        <?php endif; ?>
      </div>

<?php require APP_ROOT . '/views/partials/status-history.php'; ?>
    </div>
  </div>
</div>
