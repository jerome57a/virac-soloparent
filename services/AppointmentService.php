<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/SMSService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/InterviewCalendarService.php';
require_once __DIR__ . '/InterviewInvitationService.php';

/**
 * Interview appointments: an applicant chooses an interview day after MSWD staff send an invitation (InterviewBookingService);
 * this service handles what happens to a booked appointment afterwards (cancel, no-show, reminders).
 *
 * Rules: an application holds at most one appointment (UNIQUE
 * application_id). Applicants may cancel only up to the admin-configured
 * minimum notice before the appointment; staff can cancel (with a reason)
 * or mark a no-show at any time. Cancelling or missing an appointment puts
 * the application back to the status it had before it was scheduled.
 */
class AppointmentService
{
    public const DEFAULT_LOCATION = 'MSWDO Office, Municipal Building, Salvacion, Virac, Catanduanes';
    private const BOOKABLE_STATUSES = ['Pending Review', 'Under Verification', 'For Revision', 'Interview Scheduled'];
    private PDO $pdo;
    private Appointment $appointments;
    private Application $applications;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->appointments = new Appointment($pdo);
        $this->applications = new Application($pdo);
    }

    /** Applicants can't book, reschedule or cancel within this many hours of the appointment. */
    public function minNoticeHours(): int
    {
        return max(0, min(168, (int) get_setting($this->pdo, 'appointment_min_notice_hours', '2')));
    }

    /** The first still-in-progress application an appointment applies to, if any. */
    public function findBookableApplication(array $applications): ?array
    {
        foreach ($applications as $app) {
            if (!empty($app['renewal_simplified'])) {
                continue;
            }
            if (in_array($app['status'], self::BOOKABLE_STATUSES, true)) {
                return $app;
            }
        }
        return null;
    }

    /** An in-progress application waiting for staff to assign an initial/replacement interview. */
    public static function needsBooking(?array $application): bool
    {
        return $application !== null && in_array($application['interview_status'] ?? null, [null, 'MISSED', 'CANCELLED'], true);
    }

    public function location(): string
    {
        return get_setting($this->pdo, 'appointment_location', self::DEFAULT_LOCATION);
    }

    /** True if an appointment at $scheduledAt is still outside the applicant's notice window (can be changed/cancelled online). */
    public function changeableByApplicant(string $scheduledAt, ?int $now = null): bool
    {
        return strtotime($scheduledAt) >= ($now ?? time()) + $this->minNoticeHours() * 3600;
    }

    /**
     * Whether the applicant may cancel this appointment online, and why not. A timed appointment keeps the
     * minimum-notice-hours rule exactly (changeableByApplicant). A date-only appointment can be cancelled strictly
     * before the office closing time on the previous office day (Manila time); at or after that instant, or when the
     * calendar cannot determine it, online cancellation is refused (fail closed).
     * @return array{allowed: bool, dateOnly: bool, deadline: ?DateTimeImmutable}
     */
    public function cancellationWindow(array $appointment, ?int $now = null): array
    {
        $scheduledAt = (string) $appointment['scheduled_at'];
        if (!interview_is_date_only($appointment)) {
            return ['allowed' => $this->changeableByApplicant($scheduledAt, $now), 'dateOnly' => false, 'deadline' => null];
        }
        $deadline = (new InterviewCalendarService($this->pdo))->cancellationCutoff(substr($scheduledAt, 0, 10));
        return ['allowed' => $deadline !== null && ($now ?? time()) < $deadline->getTimestamp(), 'dateOnly' => true, 'deadline' => $deadline];
    }

    /** The deadline as shown to the applicant, e.g. "5:00 PM on Friday, December 4, 2026". */
    public static function formatCancellationDeadline(DateTimeImmutable $deadline): string
    {
        return $deadline->format('g:i A \o\n l, F j, Y');
    }

    /** What to tell the applicant about a date-only appointment's online cancellation window (allowed, passed or unknown). */
    public static function dateOnlyCancellationNotice(array $window): string
    {
        $deadline = $window['deadline'] ?? null;
        if ($deadline === null) {
            return 'Online cancellation is not available for this appointment right now. Please contact the MSWDO office.';
        }
        $when = self::formatCancellationDeadline($deadline);
        return $window['allowed']
            ? 'You can cancel this appointment online until ' . $when . '. After that, please contact MSWDO.'
            : 'The online cancellation deadline for this appointment (' . $when . ") has passed. Please contact the MSWDO office if you can't make it.";
    }

    // ---- Applicant ----

    /** The applicant cancels their own BOOKED appointment (outside the notice window). Returns an error message, or null. */
    public function cancelByApplicant(array $user, array $application): ?string
    {
        $current = $this->appointments->interviewFor((int) $application['id']);
        if (!$current || $current['status'] !== 'BOOKED') {
            return 'You have no booked appointment to cancel.';
        }
        $window = $this->cancellationWindow($current);
        if (!$window['allowed']) {
            if ($window['dateOnly']) {
                return self::dateOnlyCancellationNotice($window);
            }
            return 'Your appointment is less than ' . $this->minNoticeHours() . ' hour(s) away and can no longer be cancelled online. Please contact the MSWDO office.';
        }
        $current += ['reference_no' => $application['reference_no'], 'user_id' => $user['id'], 'mobile' => $application['mobile'], 'application_status' => $application['status']];
        if (!$this->closeAppointment($user, $current, 'CANCELLED', 'Cancelled by the Solo Parent', false)) {
            return 'This appointment changed. Refresh the page before cancelling.';
        }
        (new NotificationService($this->pdo))->notifyStaff('appointment_update', $application['reference_no'] . ' (' . $user['full_name'] . ') cancelled their appointment on ' . interview_schedule_text($current['scheduled_at'], interview_is_date_only($current), 'M j, Y g:i A') . '.', (int) $application['id']);
        return null;
    }

    // ---- Staff ----

    /**
     * Staff mark a BOOKED appointment CANCELLED (reason required; the
     * applicant is told by SMS and in-app) or MISSED (only once its time has
     * passed; also told by SMS and in-app). Returns an error message, or null on success.
     */
    public function staffSetOutcome(array $staff, int $appointmentId, string $outcome, string $reason, ?array &$smsResult = null): ?string
    {
        $appt = $this->appointments->findWithApplication($appointmentId);
        if (!$appt) {
            return 'Appointment not found.';
        }
        if ($appt['status'] !== 'BOOKED') {
            return 'Only a booked appointment can be changed (this one is ' . strtolower($appt['status']) . ').';
        }
        $reason = trim($reason);
        if (mb_strlen($reason) > 255) {
            return 'Reason is too long (up to 255 characters).';
        }
        if ($outcome === 'CANCELLED') {
            if ($reason === '') {
                return 'Give a reason for cancelling -- it is sent to the Solo Parent.';
            }
        } elseif ($outcome === 'MISSED') {
            if (interview_is_date_only($appt)) {
                // Read from the row itself, so this holds whatever the schema checks say; Manila calendar date.
                if (!Appointment::canMarkMissed($appt)) {
                    return 'A date-only appointment can only be marked missed after its scheduled date has passed.';
                }
            } elseif (strtotime($appt['scheduled_at']) > time()) {
                return 'An appointment can only be marked missed after its scheduled time.';
            }
        } else {
            return 'Choose a valid action.';
        }
        if (!$this->closeAppointment($staff, $appt, $outcome, $reason !== '' ? $reason : null, true, $smsResult)) {
            return 'This appointment changed. Refresh the page before recording its outcome.';
        }
        return null;
    }

    /**
     * Sends a reminder SMS + in-app notification for every BOOKED appointment
     * inside the configured window (default 24h) not yet reminded.
     * Rescheduling resets reminder_sent_at, so a moved appointment gets a
     * fresh reminder.
     */
    public function sendDueReminders(): array
    {
        $hours = (int) get_setting($this->pdo, 'appointment_reminder_hours', '24');
        $hours = $hours > 0 ? $hours : 24;
        $due = $this->appointments->dueForReminder($hours);

        $sms = new SMSService($this->pdo);
        $sentRefs = [];
        foreach ($due as $iv) {
            $sms->notifyAppointmentReminder((int) $iv['application_id'], $iv['mobile'], $iv['reference_no'], $iv['scheduled_at'], $iv['location']);
            self::notifyUser($this->pdo, (int) $iv['user_id'], 'appointment_reminder',
                'Reminder: your appointment for ' . $iv['reference_no'] . ' is on ' . date('M j, Y g:i A', strtotime($iv['scheduled_at'])) . ' at ' . $iv['location'] . '.',
                (int) $iv['application_id']
            );
            $this->appointments->markReminded((int) $iv['interview_id']);
            $sentRefs[] = $iv['reference_no'];
        }

        // Date-only appointments: one reminder at the office closing time on the previous valid office day (the same
        // instant online cancellation closes, so the text never offers cancelling), with a catch-up until noon on the
        // appointment date. SMS failure bookkeeping is exactly the timed behaviour above.
        $calendar = new InterviewCalendarService($this->pdo);
        $dueDateOnly = array_values(array_filter($this->appointments->dueDateOnlyForReminder(), fn($iv) => self::dateOnlyReminderDue($calendar, (string) $iv['scheduled_at'])));
        foreach ($dueDateOnly as $iv) {
            $sms->notifyAppointmentReminder((int) $iv['application_id'], $iv['mobile'], $iv['reference_no'], $iv['scheduled_at'], $iv['location'], true);
            self::notifyUser($this->pdo, (int) $iv['user_id'], 'appointment_reminder',
                'Reminder: your appointment for ' . $iv['reference_no'] . ' is on ' . interview_schedule_text($iv['scheduled_at'], true, 'M j, Y g:i A') . ' at ' . $iv['location'] . '.',
                (int) $iv['application_id']
            );
            $this->appointments->markReminded((int) $iv['interview_id']);
            $sentRefs[] = $iv['reference_no'];
        }

        return ['checked' => count($due) + count($dueDateOnly), 'sent' => $sentRefs];
    }

    /**
     * Whether a date-only appointment on $scheduledAt's date is due its reminder now (Manila time): at or after the
     * cancellation cutoff instant (previous valid office day at the office closing time), and no later than 12:00 noon on
     * the appointment date. False, so it is retried by a later run, when the calendar cannot determine the instant.
     */
    private static function dateOnlyReminderDue(InterviewCalendarService $calendar, string $scheduledAt, ?int $now = null): bool
    {
        $date = substr($scheduledAt, 0, 10);
        $due = $calendar->cancellationCutoff($date);
        $noon = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' 12:00', new DateTimeZone('Asia/Manila'));
        $now ??= time();
        return $due !== null && $noon !== false && $now >= $due->getTimestamp() && $now <= $noon->getTimestamp();
    }

    /**
     * An in-app notice to a Solo Parent. It can never break the action it reports: a record whose account no longer exists (or any
     * other notification error) is logged and skipped, so staff do not see "Something went wrong" after the change was saved.
     */
    private static function notifyUser(PDO $pdo, int $userId, string $type, string $message, ?int $applicationId = null): void
    {
        try {
            if ($userId < 1) {
                return;
            }
            $exists = $pdo->prepare('SELECT 1 FROM users WHERE id = ?');
            $exists->execute([$userId]);
            if ($exists->fetchColumn()) {
                create_notification($pdo, $userId, $type, $message, $applicationId);
            }
        } catch (Throwable $e) {
            error_log('In-app notice not saved: ' . $e->getMessage());
        }
    }

    /** Weekdays after the interview date that staff have to confirm the outcome before the system releases the booking. */
    public const RELEASE_GRACE_WEEKDAYS = 2;
    public const RELEASE_NOTE = 'Released automatically: no outcome was recorded';

    /**
     * Interviews nobody confirmed: a BOOKED interview still without an outcome RELEASE_GRACE_WEEKDAYS weekdays after its date is
     * released so the Solo Parent can choose a new day (the invitation reopens). It is NOT counted as a missed interview, no SMS
     * is sent (the Solo Parent sees the in-app notice and the Appointment page; staff get an in-app alert). Returns how many.
     */
    public function releaseUnconfirmed(?int $now = null): int
    {
        if (!InterviewDateFeature::enabled($this->pdo)) {
            return 0;
        }
        $today = Appointment::manilaDate($now);
        $released = 0;
        foreach ($this->appointments->awaitingOutcome() as $row) {
            if ($today <= self::releaseAfter(substr((string) $row['scheduled_at'], 0, 10))) {
                continue;
            }
            try {
                if ($this->releaseOne($row)) {
                    $released++;
                }
            } catch (Throwable $e) {
                error_log('Releasing an unconfirmed interview failed: ' . $e->getMessage());
            }
        }
        return $released;
    }

    /** The last day of the grace period: the RELEASE_GRACE_WEEKDAYS-th weekday (Monday to Friday) after $date. */
    public static function releaseAfter(string $date): string
    {
        $day = new DateTimeImmutable($date);
        for ($counted = 0; $counted < self::RELEASE_GRACE_WEEKDAYS;) {
            $day = $day->modify('+1 day');
            if ((int) $day->format('N') < 6) {
                $counted++;
            }
        }
        return $day->format('Y-m-d');
    }

    private function releaseOne(array $row): bool
    {
        $applicationId = (int) $row['application_id'];
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM applications WHERE id = ? FOR UPDATE');
            $lock->execute([$applicationId]);
            $current = $this->appointments->findWithApplication((int) $row['id']);
            $invitations = new InterviewInvitation($this->pdo);
            $invitation = $invitations->find($applicationId, true);
            // Only an interview whose booking the Solo Parent can reopen: otherwise staff decide.
            if (!$current || $current['status'] !== 'BOOKED' || $current['scheduled_at'] !== $row['scheduled_at'] || $invitation === null || $invitation['status'] !== 'BOOKED') {
                $this->pdo->rollBack();
                return false;
            }
            $when = interview_schedule_text($current['scheduled_at'], interview_is_date_only($current), 'M j, Y g:i A');
            $this->appointments->markAutoReleased((int) $current['id'], self::RELEASE_NOTE);
            if ($current['application_status'] === 'Interview Scheduled') {
                $back = $this->applications->statusBeforeInterview($applicationId);
                $this->applications->setStatus($applicationId, $back);
                $this->applications->addHistory($applicationId, $back, 'Interview on ' . $when . ' had no recorded outcome; booking reopened by the system.', 'System', null);
            }
            if (!$invitations->releaseBooking($applicationId)) {
                throw new RuntimeException('The interview invitation changed while it was being released.');
            }
            $events = new AppointmentEvent($this->pdo);
            $day = substr((string) $current['scheduled_at'], 0, 10);
            $events->record($applicationId, 'NO_OUTCOME', 'SYSTEM', [], null, 'No outcome was recorded within ' . self::RELEASE_GRACE_WEEKDAYS . ' weekdays; not counted as a missed interview.', (int) $current['id'], $day);
            $events->record($applicationId, 'INVITATION_REOPENED', 'SYSTEM', [], null, 'Booking reopened so the Solo Parent can choose a new date.', (int) $current['id'], $day);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        try {
            self::notifyUser($this->pdo, (int) $current['user_id'], 'appointment_confirmation',
                'Your interview for ' . $current['reference_no'] . ' on ' . $when . ' has no recorded attendance. Please log in, open Appointment and choose a new interview date.', $applicationId);
            (new NotificationService($this->pdo))->notifyStaff('appointment_update', $current['reference_no'] . ' had no recorded interview outcome; its booking was reopened for the Solo Parent.', $applicationId);
            audit_log($this->pdo, null, 'System', 'system', 'Interview Released (No Outcome)', 'application', $applicationId, $current['reference_no'] . ' · ' . $when);
        } catch (Throwable $e) {
            error_log('Release notice failed: ' . $e->getMessage());
        }
        return true;
    }

    /**
     * Ends a BOOKED appointment as CANCELLED or MISSED: records it, puts an
     * application that was "Interview Scheduled" back to its earlier status
     * (so it can be booked again), and tells the applicant. $appt carries the
     * appointment row plus reference_no, user_id, mobile, application_status.
     */
    private function closeAppointment(array $actor, array $appt, string $outcome, ?string $reason, bool $byStaff, ?array &$smsResult = null): bool
    {
        $applicationId = (int) $appt['application_id'];
        // Display text only (history note, in-app notification, audit log); SMS reads scheduled_at itself.
        $when = interview_schedule_text($appt['scheduled_at'], interview_is_date_only($appt), 'M j, Y g:i A');

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT id FROM applications WHERE id = ? FOR UPDATE');
            $lock->execute([$applicationId]);
            $current = $this->appointments->findWithApplication((int) $appt['id']);
            if (!$current || $current['status'] !== 'BOOKED' || $current['scheduled_at'] !== $appt['scheduled_at'] || (int) $current['slot_id'] !== (int) $appt['slot_id']) {
                $this->pdo->rollBack();
                return false;
            }
            $appt = $current;
            if ($outcome === 'CANCELLED') {
                $this->appointments->cancel((int) $appt['id'], (int) $actor['id'], (string) $reason);
            } else {
                $this->appointments->markMissed((int) $appt['id']);
            }
            if ($appt['application_status'] === 'Interview Scheduled') {
                $back = $this->applications->statusBeforeInterview($applicationId);
                $this->applications->setStatus($applicationId, $back);
                $note = ($outcome === 'CANCELLED' ? 'Appointment on ' . $when . ' cancelled' : 'Appointment on ' . $when . ' missed') . ($reason ? ': ' . $reason : '.');
                $this->applications->addHistory($applicationId, $back, $note, $actor['full_name'], (int) $actor['id']);
            }
            // Interview date booking: event + invitation transition in this same transaction (a no-op when the feature is off).
            // True when the applicant can now pick a new date themselves.
            $canRebook = (new InterviewInvitationService($this->pdo))->afterAppointmentClosed($actor, $appt, $outcome, $byStaff, $reason);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        if ($byStaff) {
            $message = $outcome === 'CANCELLED'
                ? 'Your appointment for ' . $appt['reference_no'] . ' on ' . $when . ' was cancelled by MSWDO: ' . $reason . '. Please contact MSWDO for a new schedule.'
                : 'You missed your appointment for ' . $appt['reference_no'] . ' on ' . $when . '. '
                    . ($canRebook ? 'You can choose a new interview date: log in and open Appointment.' : 'Please contact MSWDO for a new schedule.');
            self::notifyUser($this->pdo, (int) $appt['user_id'], 'appointment_confirmation', $message, $applicationId);
            if ($outcome === 'CANCELLED') {
                $smsResult = (new SMSService($this->pdo))->notifyAppointmentCancelled($applicationId, $appt['mobile'], $appt['reference_no'], $appt['scheduled_at'], (string) $reason, interview_is_date_only($appt));
            }
            // A missed interview is never texted: the Solo Parent sees the in-app notice, the dashboard banner and the Appointment
            // page when they log in (and chooses a new date there, or contacts MSWDO after too many misses).
        }
        audit_log($this->pdo, (int) $actor['id'], $actor['full_name'], $actor['role'],
            $outcome === 'CANCELLED' ? 'Appointment Cancelled' : 'Appointment Missed', 'application', $applicationId,
            $appt['reference_no'] . ' · ' . $when . ($reason ? ' — ' . $reason : ''));
        return true;
    }
}
