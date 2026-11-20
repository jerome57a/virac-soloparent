<?php
/**
 * @var string $appointmentError
 * @var ?array $bookableApp     the in-progress application an appointment applies to
 * @var ?array $appointment     its appointment row (any status), if one was ever made
 * @var bool   $canChange       the booked appointment can still be cancelled online
 * @var ?array  $cancelWindow    AppointmentService::cancellationWindow() for a BOOKED appointment (allowed, dateOnly, deadline)
 * @var int    $minNoticeHours
 * @var ?array $booking         InterviewBookingService::options(); null unless interview date booking is enabled (the page is then unchanged)
 */
$status = $appointment['status'] ?? null;
$booking = $booking ?? null;
// A booked date-only appointment with date booking enabled: remaining reschedules come from InterviewBookingService (reschedulesLeft).
$dateBooked = $status === 'BOOKED' && interview_is_date_only($appointment) && ($booking['invitation'] ?? null) !== null;
$reschedulesLeft = $booking['reschedulesLeft'] ?? null;
$cancelConfirm = $dateBooked
    ? 'Cancel this appointment? Your date will be released and may be taken by another Solo Parent. '
        . ((int) $reschedulesLeft > 0 ? 'If one is still available, you can then choose a new date.' : 'You have no reschedules left, so you would need to contact MSWDO to book again.')
    : (($booking['invitation'] ?? null) !== null
        ? 'Cancel this appointment? If you still have a reschedule left, you can then choose a new date.'
        : 'Cancel this appointment? MSWDO will need to set a new schedule for you.');
$apptStyles = [
    'BOOKED' => ['#dce8f5', '#1b3a6b', 'Booked'], 'COMPLETED' => ['#dcf3e2', '#1c7a3f', 'Completed'],
    'MISSED' => ['#fde8e8', '#a51c1c', 'Missed'], 'CANCELLED' => ['#f1f1f1', '#555', 'Cancelled'],
];
?>
      <?php if ($appointmentError): ?><div class="step-alert" role="alert"><?= e($appointmentError) ?></div><?php endif; ?>

      <?php if (!$bookableApp): ?>
        <div class="appt-empty">
          <svg class="appt-empty-icon" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
          <h2 data-i18n>No Appointment Needed Right Now</h2>
          <p data-i18n>You don't have an application that currently needs an appointment. This page becomes available once your application is submitted and awaiting review or interview.</p>
        </div>
      <?php else: ?>
        <?php if ($booking === null): ?>
        <div class="info-box" style="max-width:none" data-i18n>MSWDO staff will arrange your interview schedule after all required documents have been uploaded and verified. Your schedule will appear here and be sent by SMS. If MSWDO asks for a correction, upload a replacement; staff must verify it before your interview can be scheduled. <a href="?section=documents" data-i18n>Go to Documents</a>.</div>
        <?php else:
          $apptStep = $status === 'BOOKED' ? 3 : (($booking['state'] ?? '') === 'open' ? 2 : 1);
          $apptSteps = [1 => ['Documents verified', 'MSWDO verifies your required documents'], 2 => ['Choose your date', 'Pick the day you want to arrive'], 3 => ['Come to the office', 'Bring a valid ID on your date']];
        ?>
        <ol class="appt-steps" aria-label="Interview steps">
          <?php foreach ($apptSteps as $n => [$stepTitle, $stepHint]): ?>
            <li class="appt-step<?= $n < $apptStep ? ' is-done' : ($n === $apptStep ? ' is-current' : '') ?>"<?= $n === $apptStep ? ' aria-current="step"' : '' ?>>
              <span class="appt-step-num" aria-hidden="true"><?= $n < $apptStep ? '&#10003;' : $n ?></span>
              <span class="appt-step-text"><b><?= e($stepTitle) ?></b><small><?= e($stepHint) ?></small></span>
            </li>
          <?php endforeach; ?>
        </ol>
        <?php endif; ?>

        <?php if ($appointment): [$bg, $fg, $label] = $apptStyles[$status]; $apptTs = strtotime((string) $appointment['scheduled_at']); ?>
          <section class="appt-card" aria-labelledby="appt-card-title">
            <div class="appt-card-main">
              <div class="appt-date-tile" aria-hidden="true">
                <span class="appt-date-month"><?= e(date('M', $apptTs)) ?></span>
                <span class="appt-date-day"><?= e(date('j', $apptTs)) ?></span>
                <span class="appt-date-year"><?= e(date('Y', $apptTs)) ?></span>
              </div>
              <div class="appt-card-info">
                <div class="appt-card-head">
                  <h2 id="appt-card-title" class="appt-card-title" data-i18n><?= $status === 'BOOKED' ? 'Your Appointment' : 'Your Last Appointment' ?></h2>
                  <span class="status-pill" style="background:<?= e($bg) ?>;color:<?= e($fg) ?>" data-i18n><?= e($label) ?></span>
                </div>
                <div class="appt-when"><?= e(interview_schedule_text($appointment['scheduled_at'], interview_is_date_only($appointment), 'l, F j, Y · g:i A')) ?></div>
                <div class="appt-where">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                  <span><?= e($appointment['location']) ?></span>
                </div>
                <?php if ($appointment['notes']): ?><div class="appt-note"><?= e($appointment['notes']) ?></div><?php endif; ?>
              </div>
            </div>

            <?php if ($status === 'BOOKED'): ?>
              <?php $preparationItems = $interviewRequirements ?? []; require APP_ROOT . '/views/partials/requirements-preparation.php'; ?>
              <?php if ($canChange): ?>
                <form method="post" class="appt-card-actions" onsubmit="return confirm('<?= e($cancelConfirm) ?>')">
                  <?= csrf_field() ?>
                  <button type="submit" name="action" value="cancel_appointment" class="btn-outline" style="padding:8px 16px;font-size:13px" data-i18n>Cancel Appointment</button>
                </form>
                <?php if (!empty($cancelWindow['dateOnly'])): ?>
                  <div class="appt-help"><?= e(AppointmentService::dateOnlyCancellationNotice($cancelWindow)) ?></div>
                <?php endif; ?>
                <?php if (!empty($booking['staffAssigned'])): ?>
                  <div class="appt-help">MSWDO scheduled this date for you. You can come on this date, or choose a different day below. Changing it this way does not use up a reschedule.</div>
                <?php elseif ($dateBooked): ?>
                  <div class="appt-help">To change your date, cancel this booking first, then choose another available date.
                    <?= (int) $reschedulesLeft > 0 ? 'You can reschedule ' . (int) $reschedulesLeft . ' more time' . ((int) $reschedulesLeft === 1 ? '' : 's') . '.' : 'You have no reschedules left.' ?>
                    The date you cancel is released and may be taken by another Solo Parent.</div>
                <?php endif; ?>
              <?php elseif (!empty($cancelWindow['dateOnly'])): ?>
                <div class="appt-help"><?= e(AppointmentService::dateOnlyCancellationNotice($cancelWindow)) ?></div>
              <?php else: ?>
                <div class="appt-help">Your appointment is less than <?= (int) $minNoticeHours ?> hour(s) away, so it can no longer be changed online. Please contact the MSWDO office if you can't make it.</div>
              <?php endif; ?>
            <?php elseif ($status === 'COMPLETED'): ?>
              <div class="appt-help" data-i18n>Your interview is done. MSWDO will update your application status.</div>
            <?php elseif ($status === 'MISSED'): ?>
              <div class="appt-help appt-help-error" data-i18n><?= ($booking['state'] ?? '') === 'open' ? 'This appointment was marked as missed. You can choose a new interview date below.' : 'This appointment was marked as missed. Please contact MSWDO for a new schedule.' ?></div>
            <?php elseif ($status === 'CANCELLED'): ?>
              <div class="appt-help">Cancelled<?= $appointment['cancel_reason'] ? ': ' . e($appointment['cancel_reason']) : '' ?>. <?= ($booking['state'] ?? '') === 'open' ? 'You can choose a new date below.' : 'Please contact MSWDO for a new schedule.' ?></div>
            <?php endif; ?>
          </section>
        <?php endif; ?>

        <?php $changing = !empty($booking['staffAssigned']) && $status === 'BOOKED'; ?>
        <?php if ($booking !== null && ($booking['state'] !== 'booked' || $changing)): ?>
          <section class="appt-card" id="book-interview" aria-labelledby="book-title">
            <h2 id="book-title" class="appt-card-title"><?= $changing ? 'Prefer a Different Day?' : 'Book Your Interview Date' ?></h2>
            <?php if ($changing) { $booking['dates'] = array_values(array_filter($booking['dates'], fn ($d) => $d['date'] !== substr((string) $appointment['scheduled_at'], 0, 10))); } ?>
            <?php if ($booking['state'] === 'open' || $changing): ?>
              <?php if (!$booking['dates']): ?>
                <div class="appt-empty appt-empty-inline">
                  <svg class="appt-empty-icon" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                  <p>No interview days are available right now (every upcoming office day is full or past its booking deadline). Please check again later or contact the MSWDO office.</p>
                </div>
              <?php else:
                $byMonth = [];
                foreach ($booking['dates'] as $option) {
                    $byMonth[date('F Y', strtotime($option['date']))][] = $option;
                }
                // Only the month that is needed: the earliest month that still has days. The next month appears once this one has none left.
                $byMonth = array_slice($byMonth, 0, 1, true);
              ?>
                <p class="appt-lead"><?= $changing ? 'You can keep the date MSWDO scheduled, or choose another day to arrive instead.' : 'Choose the day you want to arrive for your interview.' ?> The office is open Monday to Friday, 7:00 AM to 5:00 PM; holidays and closures are not shown.</p>
                <form method="post" id="book-form" onsubmit="return confirm('<?= $changing ? 'Change your interview to this date?' : 'Book this interview date?' ?>')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="book_appointment">
                  <?php foreach ($byMonth as $monthLabel => $options): ?>
                    <fieldset class="appt-month">
                      <legend><?= e($monthLabel) ?></legend>
                      <div class="appt-day-grid">
                        <?php foreach ($options as $option): $ts = strtotime($option['date']); $left = (int) $option['left']; ?>
                          <label class="appt-day">
                            <input type="radio" name="interview_date" value="<?= e($option['date']) ?>" required>
                            <span class="appt-day-box">
                              <span class="appt-day-name"><?= e(date('D', $ts)) ?></span>
                              <span class="appt-day-num"><?= e(date('j', $ts)) ?></span>
                              <span class="appt-day-left<?= $left <= 3 ? ' is-low' : '' ?>"><?= $left ?> <?= $left === 1 ? 'seat' : 'seats' ?> left</span>
                            </span>
                          </label>
                        <?php endforeach; ?>
                      </div>
                    </fieldset>
                  <?php endforeach; ?>
                  <?php if (!empty($booking['replacement'])): ?>
                    <div class="appt-help">This is a replacement date. It uses one of your reschedules (<?= (int) $booking['reschedulesLeft'] ?> left, including this one).</div>
                  <?php endif; ?>
                  <div class="appt-book-bar">
                    <span class="appt-help m-0">First come, first served. You can cancel online until the office closes on the previous office day; after that please contact MSWDO.</span>
                    <button type="submit" class="btn-navy" id="book-submit" disabled><?= $changing ? 'Change to this date' : 'Book this date' ?></button>
                  </div>
                </form>
                <script>
                  (() => {
                    const form = document.getElementById('book-form'), submit = document.getElementById('book-submit');
                    const sync = () => { submit.disabled = !form.querySelector('input[name="interview_date"]:checked'); };
                    form.addEventListener('change', sync); window.addEventListener('pageshow', sync);
                  })();
                </script>
              <?php endif; ?>
            <?php else: ?>
              <p class="appt-lead"><?= e((string) $booking['message']) ?></p>
              <?php if (in_array($booking['state'], ['not_open', 'blocked'], true)): ?><a class="btn-outline d-inline-block text-decoration-none" href="?section=documents" style="padding:8px 16px;font-size:13px">Go to Documents</a><?php endif; ?>
            <?php endif; ?>
          </section>
        <?php endif; ?>

      <?php endif; ?>
