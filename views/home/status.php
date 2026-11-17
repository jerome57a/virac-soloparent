<?php
/**
 * Public status lookup (no login): reference number only. The applicant's name is masked.
 *
 * @var string $query
 * @var ?array $result
 * @var string $error
 */
?>
<div class="public-track" data-i18n>
  <div style="font-size:14px;color:var(--muted);margin-bottom:14px" data-i18n>Enter your application reference number to check your status. No login required.</div>
  <p data-i18n>Your reference number is available under My Application after you sign in. <a href="<?= base_url('login.php?next=applications') ?>" data-i18n>Log In to My Applications</a> to find it, or <a href="<?= base_url('portal.php?tab=help') ?>" data-i18n>contact MSWDO for help</a>.</p>
  <?php if (!empty($user)): ?><p><a class="btn-outline text-decoration-none" href="<?= base_url('applicant/dashboard.php?section=application') ?>" data-i18n>View My Applications</a></p><?php endif; ?>
  <form method="get" class="d-flex flex-wrap gap-2 mb-4">
    <input type="hidden" name="tab" value="status">
    <label class="w-100" for="status-ref" data-i18n>Reference number</label>
    <input type="text" id="status-ref" name="ref" placeholder="e.g. VIRAC-SP-2026-0001" value="<?= e($query) ?>" maxlength="40" required class="form-control" style="flex:2 1 220px;padding:11px;border:1px solid var(--input-border);border-radius:6px">
    <button type="submit" class="btn-navy" style="padding:11px 20px" data-i18n>Track Application</button>
  </form>

  <?php if ($result): $st = status_style($result['status']); $appointment = $result['appointment']; $idSummary = $result['id_summary']; ?>
    <article class="public-track-result" aria-labelledby="track-result-title" data-i18n>
      <header class="public-track-header">
        <div><span class="public-track-eyebrow" data-i18n>Application reference</span><h2 id="track-result-title"><?= e($result['reference_no']) ?></h2></div>

      </header>
      <section class="public-track-id public-track-id-<?= e($idSummary['tone']) ?>" aria-labelledby="track-id-status-title">
        <span class="public-track-eyebrow" data-i18n>Your ID status</span>
        <h3 id="track-id-status-title" data-i18n><?= e($idSummary['headline']) ?></h3>
        <dl class="public-track-details">
          <div><dt data-i18n>Have I received my ID?</dt><dd data-i18n><?= e($idSummary['received']) ?><?php if ($result['released_at']): ?><small>Released on <?= e(date('F j, Y g:i A', strtotime($result['released_at']))) ?></small><?php endif; ?></dd></div>
          <div><dt data-i18n>Is my ID still valid?</dt><dd data-i18n><?= e($idSummary['validity']) ?></dd></div>
          <div class="public-track-wide"><dt data-i18n>Do I need to renew?</dt><dd data-i18n><?= e($idSummary['renewal']) ?></dd></div>
        </dl>
        <?php if ($idSummary['renewal_ref']): ?><a class="btn-outline text-decoration-none" href="<?= e(base_url('portal.php?tab=status&ref=' . rawurlencode($idSummary['renewal_ref']))) ?>">Track renewal <?= e($idSummary['renewal_ref']) ?></a><?php endif; ?>
      </section>
      <section class="public-track-next" aria-labelledby="track-next-title">
        <span class="public-track-eyebrow" data-i18n>Your next step</span>
        <h3 id="track-next-title" data-i18n><?= e($result['title']) ?></h3>
        <p data-i18n><?= e($result['instruction']) ?></p>
      </section>
      <?php if ($appointment): ?>
        <section class="public-track-section" aria-labelledby="track-appointment-title">
          <h3 id="track-appointment-title" data-i18n><?= e($appointment['label']) ?></h3>
          <dl class="public-track-details">
            <div><dt data-i18n>Date</dt><dd><?= e(date('l, F j, Y', strtotime($appointment['scheduled_at']))) ?></dd></div>
            <?php if (empty($appointment['date_only'])): ?><div><dt data-i18n>Time</dt><dd><?= e(date('g:i A', strtotime($appointment['scheduled_at']))) ?></dd></div><?php endif; ?>
            <div class="public-track-wide"><dt data-i18n>Location</dt><dd><?= e($appointment['location'] ?: 'Contact MSWDO to confirm the location.') ?></dd></div>
          </dl>
          <?php if ($appointment['past']): ?><p class="public-track-note" data-i18n>The scheduled time has passed. If you have not attended or collected your ID, contact MSWDO to confirm the next available schedule.</p><?php endif; ?>
        </section>
      <?php elseif ($result['status'] === 'Interview Scheduled'): ?>
        <p class="public-track-note" data-i18n>Appointment details have not been recorded yet. Log in or contact MSWDO to confirm your schedule before visiting.</p>
      <?php endif; ?>
      <details class="public-track-section public-track-history">
        <summary data-i18n>Application details</summary>
        <div style="margin-top:16px">
      <dl class="public-track-details">
        <div><dt data-i18n>Applicant</dt><dd><?= e($result['masked_name']) ?></dd></div>
        <div><dt data-i18n>Application type</dt><dd data-i18n><?= $result['type'] === 'renewal' ? 'ID renewal' : 'New Solo Parent ID' ?></dd></div>
        <div><dt data-i18n>Submitted</dt><dd><?= e(date('F j, Y', strtotime($result['submitted_at']))) ?></dd></div>
        <div><dt data-i18n>Latest recorded update</dt><dd><?= e(date('F j, Y g:i A', strtotime($result['updated_at']))) ?></dd></div>
        <div><dt data-i18n>Application status</dt><dd data-i18n><?= e($result['status']) ?></dd></div>
      </dl>
        </div>
      </details>
      <?php if ($result['timeline']): ?>
        <details class="public-track-section public-track-history">
          <summary data-i18n>Application status history</summary>
          <ol>
            <?php foreach (array_reverse($result['timeline']) as $event): ?>
              <li><strong data-i18n><?= e($event['status']) ?></strong><time datetime="<?= e(date('c', strtotime($event['date']))) ?>"><?= e(date('F j, Y g:i A', strtotime($event['date']))) ?></time></li>
            <?php endforeach; ?>
          </ol>
        </details>
      <?php endif; ?>
      <div class="public-track-actions">
        <a href="<?= base_url(!empty($user) ? 'applicant/dashboard.php?section=application' : 'login.php?next=applications') ?>" class="btn-navy text-decoration-none" data-i18n><?= !empty($user) ? 'View My Applications' : 'Log In for Full Details' ?></a>
        <a href="<?= base_url('portal.php?tab=help') ?>" class="btn-outline text-decoration-none" data-i18n>Get Help</a>
      </div>
      <section class="public-track-contact" aria-labelledby="track-contact-title">
        <h3 id="track-contact-title" data-i18n>Need help with your ID?</h3>
        <p><strong><?= e(setting('office_name')) ?></strong><br><?= e(setting('office_address')) ?><br><?= e(setting('office_hours')) ?></p>
        <p data-i18n>Phone: <?= e(setting('office_phone')) ?><br>Email: <?= e(setting('office_email')) ?></p>
        <p class="public-track-note" data-i18n>Give your application reference number when contacting the office. Log in to read correction requests, decision details, and document instructions.</p>
      </section>
    </article>
  <?php elseif ($error !== ''): ?>
    <div class="public-track-note" role="alert"><?= e($error) ?></div>
  <?php endif; ?>
</div>
