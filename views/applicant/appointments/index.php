<?php
/**
 * @var string $appointmentError
 * @var ?array $bookableApp         the in-progress application an appointment applies to
 * @var ?array $currentAppointment  same application, when it already has an interview booked
 * @var array  $openSlots
 */
?>
      <?php if ($appointmentError): ?><div class="step-alert"><?= e($appointmentError) ?></div><?php endif; ?>

      <?php if (!$bookableApp): ?>
        <div class="dash-card">
          <div class="dash-card-label">No Appointment Needed Right Now</div>
          <div style="font-size:14px;color:var(--muted);margin:8px 0">You don't have an application that currently needs an appointment. This page becomes available once your application is submitted and awaiting review or interview.</div>
        </div>
      <?php else: ?>
        <?php if ($currentAppointment): ?>
          <div class="dash-card">
            <div class="dash-card-label">Your Appointment</div>
            <div style="font-size:16px;color:var(--ink);font-weight:700;margin:8px 0 2px"><?= e(date('F j, Y g:i A', strtotime($currentAppointment['interview_at']))) ?></div>
            <div style="font-size:13px;color:var(--muted)"><?= e($currentAppointment['interview_location']) ?></div>
          </div>
        <?php endif; ?>

        <div style="font-size:13px;color:var(--muted);margin:14px 0">For application <b><?= e($bookableApp['reference_no']) ?></b>. Pick an open slot below<?= $currentAppointment ? ' to reschedule' : '' ?>.</div>

        <?php if (!$openSlots): ?>
          <div class="dash-card"><div style="font-size:14px;color:var(--muted)">No open appointment slots right now. Please check back later.</div></div>
        <?php else: ?>
          <?php foreach ($openSlots as $slot): ?>
            <div class="app-card">
              <div>
                <div class="app-ref"><?= e(date('F j, Y', strtotime($slot['slot_date']))) ?></div>
                <div class="app-date"><?= e(date('g:i A', strtotime($slot['start_time']))) ?> &ndash; <?= e(date('g:i A', strtotime($slot['end_time']))) ?> · <?= (int) $slot['remaining'] ?> slot<?= (int) $slot['remaining'] === 1 ? '' : 's' ?> left</div>
              </div>
              <form method="post" class="m-0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="book_slot">
                <input type="hidden" name="slot_id" value="<?= (int) $slot['id'] ?>">
                <button type="submit" class="btn-navy" style="padding:8px 16px;font-size:13px"><?= $currentAppointment ? 'Reschedule Here' : 'Book This Slot' ?></button>
              </form>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>
