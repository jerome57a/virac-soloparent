<?php
/**
 * @var string $appointmentError
 * @var ?array $bookableApp     the in-progress application an appointment applies to
 * @var ?array $appointment     its appointment row (any status), if one was ever made
 * @var bool   $canChange       the booked appointment can still be cancelled online
 * @var int    $minNoticeHours
 */
$status = $appointment['status'] ?? null;
$apptStyles = [
    'BOOKED' => ['#dce8f5', '#1b3a6b', 'Booked'], 'COMPLETED' => ['#dcf3e2', '#1c7a3f', 'Completed'],
    'MISSED' => ['#fde8e8', '#a51c1c', 'Missed'], 'CANCELLED' => ['#f1f1f1', '#555', 'Cancelled'],
];
?>
      <?php if ($appointmentError): ?><div class="step-alert" role="alert"><?= e($appointmentError) ?></div><?php endif; ?>

      <?php if (!$bookableApp): ?>
        <div class="dash-card">
          <div class="dash-card-label" data-i18n>No Appointment Needed Right Now</div>
          <div style="font-size:14px;color:var(--muted);margin:8px 0" data-i18n>You don't have an application that currently needs an appointment. This page becomes available once your application is submitted and awaiting review or interview.</div>
        </div>
      <?php else: ?>
        <div class="info-box" style="max-width:none" data-i18n>MSWDO staff will assign your interview date and time after you submit your application. Your schedule will appear here and be sent by SMS. Missing documents must be uploaded before ID release scheduling. <a href="?section=documents" data-i18n>Go to Documents</a>.</div>
        <?php if ($appointment): [$bg, $fg, $label] = $apptStyles[$status]; ?>
          <div class="dash-card">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div class="dash-card-label" data-i18n><?= $status === 'BOOKED' ? 'Your Appointment' : 'Your Last Appointment' ?></div>
              <span class="status-pill" style="background:<?= e($bg) ?>;color:<?= e($fg) ?>" data-i18n><?= e($label) ?></span>
            </div>
            <div style="font-size:16px;color:var(--ink);font-weight:700;margin:8px 0 2px"><?= e(date('l, F j, Y · g:i A', strtotime($appointment['scheduled_at']))) ?></div>
            <div style="font-size:13px;color:var(--muted)"><?= e($appointment['location']) ?></div>
            <?php if ($appointment['notes']): ?><div style="font-size:13px;color:var(--body-text);margin-top:6px">📝 <?= e($appointment['notes']) ?></div><?php endif; ?>

            <?php if ($status === 'BOOKED'): ?>
              <?php $preparationItems = $interviewRequirements ?? []; require APP_ROOT . '/views/partials/requirements-preparation.php'; ?>
              <?php if ($canChange): ?>
                <form method="post" class="mt-3" onsubmit="return confirm('Cancel this appointment? MSWDO will need to set a new schedule for you.')">
                  <?= csrf_field() ?>
                  <button type="submit" name="action" value="cancel_appointment" class="btn-outline" style="padding:8px 16px;font-size:13px" data-i18n>Cancel Appointment</button>
                </form>
              <?php else: ?>
                <div style="font-size:13px;color:var(--muted);margin-top:10px">Your appointment is less than <?= (int) $minNoticeHours ?> hour(s) away, so it can no longer be changed online. Please contact the MSWDO office if you can't make it.</div>
              <?php endif; ?>
            <?php elseif ($status === 'COMPLETED'): ?>
              <div style="font-size:13px;color:var(--muted);margin-top:10px" data-i18n>Your interview is done. MSWDO will update your application status.</div>
            <?php elseif ($status === 'MISSED'): ?>
              <div style="font-size:13px;color:var(--error-text);margin-top:10px" data-i18n>This appointment was marked as missed. Please contact MSWDO for a new schedule.</div>
            <?php elseif ($status === 'CANCELLED'): ?>
              <div style="font-size:13px;color:var(--muted);margin-top:10px">Cancelled<?= $appointment['cancel_reason'] ? ': ' . e($appointment['cancel_reason']) : '' ?>. Please contact MSWDO for a new schedule.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      <?php endif; ?>
