<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/SMSService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/InterviewCalendarService.php';

/**
 * Interview appointments, scheduled by MSWD staff (ApplicationReviewService::scheduleInterview).
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
    public function changeableByApplicant(string $scheduledAt): bool
    {
        return strtotime($scheduledAt) >= time() + $this->minNoticeHours() * 3600;
    }

    // ---- Applicant ----

    /** The applicant cancels their own BOOKED appointment (outside the notice window). Returns an error message, or null. */
    public function cancelByApplicant(array $user, array $application): ?string
    {
        $current = $this->appointments->interviewFor((int) $application['id']);
        if (!$current || $current['status'] !== 'BOOKED') {
            return 'You have no booked appointment to cancel.';
        }
        if (!$this->changeableByApplicant($current['scheduled_at'])) {
            return 'Your appointment is less than ' . $this->minNoticeHours() . ' hour(s) away and can no longer be cancelled online. Please contact the MSWDO office.';
        }
        $current += ['reference_no' => $application['reference_no'], 'user_id' => $user['id'], 'mobile' => $application['mobile'], 'application_status' => $application['status']];
        if (!$this->closeAppointment($user, $current, 'CANCELLED', 'Cancelled by the applicant', false)) {
            return 'This appointment changed. Refresh the page before cancelling.';
        }
        (new NotificationService($this->pdo))->notifyStaff('appointment_update', $application['reference_no'] . ' (' . $user['full_name'] . ') cancelled their appointment on ' . date('M j, Y g:i A', strtotime($current['scheduled_at'])) . '.', (int) $application['id']);
        return null;
    }

    // ---- Staff ----

    /**
     * Staff mark a BOOKED appointment CANCELLED (reason required; the
     * applicant is told by SMS and in-app) or MISSED (only once its time has
     * passed; also told by SMS and in-app). Returns an error message, or null on success.
     */
    public function staffSetOutcome(array $staff, int $appointmentId, string $outcome, string $reason): ?string
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
                return 'Give a reason for cancelling -- it is sent to the applicant.';
            }
        } elseif ($outcome === 'MISSED') {
            if (strtotime($appt['scheduled_at']) > time()) {
                return 'An appointment can only be marked missed after its scheduled time.';
            }
        } else {
            return 'Choose a valid action.';
        }
        if (!$this->closeAppointment($staff, $appt, $outcome, $reason !== '' ? $reason : null, true)) {
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
            create_notification(
                $this->pdo, (int) $iv['user_id'], 'appointment_reminder',
                'Reminder: your appointment for ' . $iv['reference_no'] . ' is on ' . date('M j, Y g:i A', strtotime($iv['scheduled_at'])) . ' at ' . $iv['location'] . '.',
                (int) $iv['application_id']
            );
            $this->appointments->markReminded((int) $iv['interview_id']);
            $sentRefs[] = $iv['reference_no'];
        }

        return ['checked' => count($due), 'sent' => $sentRefs];
    }

    /**
     * Ends a BOOKED appointment as CANCELLED or MISSED: records it, puts an
     * application that was "Interview Scheduled" back to its earlier status
     * (so it can be booked again), and tells the applicant. $appt carries the
     * appointment row plus reference_no, user_id, mobile, application_status.
     */
    private function closeAppointment(array $actor, array $appt, string $outcome, ?string $reason, bool $byStaff): bool
    {
        $applicationId = (int) $appt['application_id'];
        $when = date('M j, Y g:i A', strtotime($appt['scheduled_at']));

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
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        if ($byStaff) {
            $message = $outcome === 'CANCELLED'
                ? 'Your appointment for ' . $appt['reference_no'] . ' on ' . $when . ' was cancelled by MSWDO: ' . $reason . '. Please contact MSWDO for a new schedule.'
                : 'You missed your appointment for ' . $appt['reference_no'] . ' on ' . $when . '. Please contact MSWDO for a new schedule.';
            create_notification($this->pdo, (int) $appt['user_id'], 'appointment_confirmation', $message, $applicationId);
            if ($outcome === 'CANCELLED') {
                (new SMSService($this->pdo))->notifyAppointmentCancelled($applicationId, $appt['mobile'], $appt['reference_no'], $appt['scheduled_at'], (string) $reason);
            } else {
                (new SMSService($this->pdo))->notifyAppointmentMissed($applicationId, $appt['mobile'], $appt['reference_no'], $appt['scheduled_at']);
            }
        }
        audit_log($this->pdo, (int) $actor['id'], $actor['full_name'], $actor['role'],
            $outcome === 'CANCELLED' ? 'Appointment Cancelled' : 'Appointment Missed', 'application', $applicationId,
            $appt['reference_no'] . ' · ' . $when . ($reason ? ' — ' . $reason : ''));
        return true;
    }
}
