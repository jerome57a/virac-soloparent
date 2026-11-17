<?php
require_once __DIR__ . '/InterviewDateFeature.php';
require_once __DIR__ . '/InterviewCalendarService.php';
require_once __DIR__ . '/InterviewDateService.php';
require_once __DIR__ . '/AppointmentService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/SMSService.php';
require_once __DIR__ . '/../models/InterviewDate.php';
require_once __DIR__ . '/../models/InterviewInvitation.php';
require_once __DIR__ . '/../models/AppointmentEvent.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/Document.php';

/**
 * Applicant booking of an interview DATE (and, after cancelling, a replacement date). First come, first served.
 *
 * Everything is refused unless InterviewDateFeature::enabled() (flag on and the whole schema ready); options() then
 * returns null, so with the flag off the applicant page is exactly what it was and no new table is read or written.
 *
 * book() rules: the application belongs to the applicant, is still being processed (Application::IN_PROGRESS_STATUSES)
 * and is not a simplified renewal; its invitation is OPEN and no BOOKED or COMPLETED interview exists; every required
 * document is verified and unexpired; the date is a valid office day whose online-cancellation cutoff
 * (InterviewCalendarService::cancellationCutoff) is still ahead (fail closed when it cannot be determined), so a date
 * the applicant could not cancel is never offered; the date is not closed by staff (its row is created on the first booking, so staff do not open dates), has a capacity of at
 * least 1 and fewer BOOKED + COMPLETED seats than that capacity (never overbooked).
 *
 * One transaction, lock order: application row, its documents in ascending id, the invitation, then the date row and
 * a locking seat count (the capacity lock), before anything is written. It saves the date-only appointment (source
 * APPLICANT), moves the invitation OPEN -> BOOKED, sets the application to 'Interview Scheduled' with a history line,
 * and writes the appointment_events row and the audit_logs entry. A booking is a COUNTED replacement - event
 * RESCHEDULED and reschedules_used + 1, in the same statement and transaction that books the invitation - only when the
 * previous appointment row is CANCELLED, was cancelled by this applicant (cancelled_by = the application's user) and was
 * a date booking (date_only = 1). Every other booking is event BOOKED and never touches the allowance.
 */
class InterviewBookingService
{
    /** How many days ahead (from today) an applicant can book. */
    public const BOOKING_WINDOW_DAYS = 45;

    private PDO $pdo;
    private InterviewInvitation $invitations;
    private InterviewDate $dates;
    private AppointmentEvent $events;
    private Appointment $appointments;
    private Application $applications;
    private Document $documents;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->invitations = new InterviewInvitation($pdo);
        $this->dates = new InterviewDate($pdo);
        $this->events = new AppointmentEvent($pdo);
        $this->appointments = new Appointment($pdo);
        $this->applications = new Application($pdo);
        $this->documents = new Document($pdo);
    }

    // ---- Display ----

    /**
     * What the applicant page shows for booking (plain reads, nothing locked), or null when the feature is not enabled or
     * there is nothing to show (not the applicant's application, or a simplified renewal).
     *
     * state: 'not_open' (no invitation yet), 'open' (dates can be booked), 'booked' (a date is held), 'closed' (staff
     * closed it or the allowance is used up) or 'blocked' (status or documents stop booking; see message).
     *
     * @return array{state: string, message: ?string, dates: array, replacement: bool, reschedulesLeft: ?int, invitation: ?array}|null
     */
    public function options(array $user, array $application): ?array
    {
        if (!InterviewDateFeature::enabled($this->pdo)) {
            return null;
        }
        $app = $this->application((int) $application['id'], (int) $user['id'], false);
        if ($app === null || !empty($app['renewal_simplified'])) {
            return null;
        }
        $result = ['state' => 'blocked', 'message' => null, 'dates' => [], 'replacement' => false, 'reschedulesLeft' => null, 'invitation' => null];
        if (!in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
            return ['message' => 'Interview booking is only available while your application is being processed.'] + $result;
        }
        $invitation = $this->invitations->find((int) $app['id']);
        if ($invitation === null) {
            return ['state' => 'not_open', 'message' => 'MSWDO has not opened interview booking for your application yet. It opens after every required document is verified.'] + $result;
        }
        $result['invitation'] = $invitation;
        $result['reschedulesLeft'] = max(0, (int) $invitation['reschedules_allowed'] - (int) $invitation['reschedules_used']);
        if ($invitation['status'] === 'BOOKED') {
            return ['state' => 'booked'] + $result;
        }
        if ($invitation['status'] === 'CLOSED') {
            return ['state' => 'closed', 'message' => 'Interview booking is closed for your application. Please contact the MSWDO office.'] + $result;
        }
        $appointment = $this->appointments->interviewFor((int) $app['id']);
        if ($appointment !== null && in_array($appointment['status'], ['BOOKED', 'COMPLETED'], true)) {
            return ['state' => 'booked'] + $result;
        }
        if ($outstanding = $this->documents->outstandingRequired($app)) {
            return ['message' => 'Interview booking is paused until your required documents are verified: ' . $this->outstandingText($outstanding) . '.'] + $result;
        }
        $result['replacement'] = $this->isReplacement($appointment, $app);
        if ($result['replacement'] && (int) $invitation['reschedules_used'] >= (int) $invitation['reschedules_allowed']) {
            return ['state' => 'closed', 'message' => 'You have used every reschedule allowed. Please contact the MSWDO office.'] + $result;
        }
        return ['state' => 'open', 'dates' => $this->bookableDates()] + $result;
    }

    /**
     * @return array<int, array{date: string, left: int}> every upcoming office day with seats left, for the next
     * BOOKING_WINDOW_DAYS days. Staff do not open dates: weekends, holidays, announced closures and days the office does
     * not hold interviews are skipped by InterviewCalendarService, and so is a date whose online-cancellation cutoff has
     * passed. A date row only exists once someone books it (or staff closed it, or gave it its own capacity); a row that
     * is CLOSED removes the date, its capacity_override replaces the default, and a date with no row follows the default.
     */
    private function bookableDates(): array
    {
        $calendar = new InterviewCalendarService($this->pdo);
        $dateService = new InterviewDateService($this->pdo);
        $default = $dateService->defaultCapacity();
        $today = Appointment::manilaDate();
        $last = (new DateTimeImmutable($today))->modify('+' . self::BOOKING_WINDOW_DAYS . ' days')->format('Y-m-d');
        $rows = [];
        foreach ($this->dates->upcoming($today, 500) as $row) {
            $rows[(string) $row['interview_date']] = $row;
        }
        $seats = $this->dates->seatsByDate($today, $last);
        $now = time();
        $out = [];
        for ($day = new DateTimeImmutable($today); $day->format('Y-m-d') <= $last; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $row = $rows[$date] ?? null;
            if (($row !== null && $row['status'] !== 'OPEN') || $calendar->dateError($date) !== null) {
                continue;
            }
            $cutoff = $calendar->cancellationCutoff($date);
            if ($cutoff === null || $now >= $cutoff->getTimestamp()) {
                continue;
            }
            $left = $dateService->effectiveCapacity($row, $default) - ($seats[$date] ?? 0);
            if ($left > 0) {
                $out[] = ['date' => $date, 'left' => $left];
            }
        }
        return $out;
    }

    // ---- Booking ----

    /** Books $date (Y-m-d) for the applicant's application. Returns an error message, or null on success. */
    public function book(array $user, array $application, string $date): ?string
    {
        if (!InterviewDateFeature::enabled($this->pdo)) {
            return 'Interview date booking is not available right now.';
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$day || $day->format('Y-m-d') !== $date) {
            return 'Choose a valid interview date.';
        }
        $applicationId = (int) $application['id'];
        // Plain reads of settings first, so the statements inside the transaction are the locked ones.
        $location = get_setting($this->pdo, 'appointment_location', AppointmentService::DEFAULT_LOCATION);
        $calendar = new InterviewCalendarService($this->pdo);
        $dateService = new InterviewDateService($this->pdo);
        $default = $dateService->defaultCapacity();

        $this->pdo->beginTransaction();
        try {
            $app = $this->application($applicationId, (int) $user['id'], true);
            if ($app === null) {
                $this->pdo->rollBack();
                return 'Application not found.';
            }
            if (!empty($app['renewal_simplified'])) {
                $this->pdo->rollBack();
                return 'This renewal uses document review and approval; an interview is not required.';
            }
            if (!in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
                $this->pdo->rollBack();
                return 'Interview booking is only available while your application is being processed.';
            }
            $docIds = $this->pdo->prepare('SELECT id FROM application_documents WHERE application_id = ? ORDER BY id FOR UPDATE');
            $docIds->execute([$applicationId]);
            $docIds->fetchAll();
            $invitation = $this->invitations->find($applicationId, true);
            $appointment = $this->appointments->interviewFor($applicationId);
            $outstanding = $this->documents->outstandingRequired($app);
            if ($error = $this->invitationProblem($invitation, $appointment, $app, $outstanding)) {
                $this->pdo->rollBack();
                return $error;
            }
            // The date: a valid office day whose online-cancellation cutoff is still ahead (fail closed).
            if ($error = $calendar->dateError($date)) {
                $this->pdo->rollBack();
                return $error;
            }
            $cutoff = $calendar->cancellationCutoff($date);
            if ($cutoff === null) {
                $this->pdo->rollBack();
                return 'This date cannot be booked online right now. Choose another date or contact the MSWDO office.';
            }
            if (time() >= $cutoff->getTimestamp()) {
                $this->pdo->rollBack();
                return 'The online booking deadline for this date has passed. Choose a later date.';
            }
            if ($date > (new DateTimeImmutable(Appointment::manilaDate()))->modify('+' . self::BOOKING_WINDOW_DAYS . ' days')->format('Y-m-d')) {
                $this->pdo->rollBack();
                return 'That date is too far ahead to book yet. Choose an earlier date.';
            }
            // The capacity lock: the date row (created here on the first booking of an office day; staff no longer open
            // dates), then a locking seat count. A row staff closed stays closed.
            $this->dates->insertOpenIfMissing($date, (int) $user['id']);
            $row = $this->dates->find($date, true);
            if ($row === null || $row['status'] !== 'OPEN') {
                $this->pdo->rollBack();
                return 'This date is not open for booking. Choose another date.';
            }
            $capacity = $dateService->effectiveCapacity($row, $default);
            if ($capacity < 1 || $this->dates->seatsUsed($date, true) >= $capacity) {
                $this->pdo->rollBack();
                return 'This date is already full. Choose another date.';
            }

            $counted = $this->isReplacement($appointment, $app);
            $this->appointments->bookDateOnly($applicationId, $date, $location);
            if (!$this->invitations->markBooked($applicationId, $counted)) {
                $this->pdo->rollBack();
                return 'Your invitation changed. Refresh the page and try again.';
            }
            $when = $day->format('l, F j, Y');
            $this->applications->setStatus($applicationId, 'Interview Scheduled');
            $this->applications->addHistory($applicationId, 'Interview Scheduled', 'Interview date ' . $when . ' booked by the applicant', (string) $user['full_name'], (int) $user['id']);
            $saved = $this->appointments->interviewFor($applicationId);
            $details = $counted
                ? 'Replacement booking for ' . $date . ' (previous date ' . substr((string) $appointment['scheduled_at'], 0, 10) . '). Reschedules used '
                    . ((int) $invitation['reschedules_used'] + 1) . ' of ' . (int) $invitation['reschedules_allowed'] . '.'
                : 'Booked ' . $date . '.';
            $this->events->record($applicationId, $counted ? 'RESCHEDULED' : 'BOOKED', 'APPLICANT', $user, null, $details, $saved !== null ? (int) $saved['id'] : null, $date);
            audit_log($this->pdo, (int) $user['id'], (string) $user['full_name'], (string) $user['role'],
                $counted ? 'Interview Date Rescheduled' : 'Interview Date Booked', 'application', $applicationId, $app['reference_no'] . ' · ' . $details);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // After the commit: a failed notification never undoes the booking.
        try {
            create_notification($this->pdo, (int) $user['id'], 'appointment_confirmation',
                'Your interview for ' . $app['reference_no'] . ' is booked for ' . $when . ' at ' . $location . '.', $applicationId);
            (new NotificationService($this->pdo))->notifyStaff('appointment_update',
                $app['reference_no'] . ' (' . $user['full_name'] . ') booked an interview date: ' . $when . '.', $applicationId);
        } catch (Throwable $e) {
            error_log('Interview booking notification failed: ' . $e->getMessage());
        }
        // The confirmation SMS (interview_date_booked) for a first booking and a replacement alike, through SMSService (a
        // disabled template sends and logs nothing). Its sms_logs id is stored on the appointment. Like the notices above,
        // a failure is logged and never undoes the committed booking.
        try {
            $sms = (new SMSService($this->pdo))->notifyInterviewDateBooked($applicationId, (string) $app['mobile'], (string) $app['reference_no'], $date, $location);
            if (!empty($sms['id'])) {
                $this->appointments->linkConfirmationSms($applicationId, (int) $sms['id']);
            }
        } catch (Throwable $e) {
            error_log('Interview booking SMS failed: ' . $e->getMessage());
        }
        return null;
    }

    // ---- Internals ----

    /**
     * "Label (reason); Label (reason)" for Document::outstandingRequired() rows. The reason is read from the same fields the
     * staff scheduling message uses, in the same order: expired, else the uploaded file's verification status
     * (awaiting verification, needs correction, ...), else not uploaded. It only describes; it decides nothing.
     */
    private function outstandingText(array $outstanding): string
    {
        return implode('; ', array_map(function ($item) {
            $upload = $item['uploaded_document'] ?? null;
            $expired = !empty($item['expires_at']) && $item['expires_at'] < date('Y-m-d');
            if ($expired) {
                $reason = 'expired';
            } elseif ($upload) {
                $reason = (string) $upload['verification_status'] === 'FOR_VERIFICATION'
                    ? 'awaiting verification' : strtolower(document_status_label((string) $upload['verification_status']));
            } else {
                $reason = 'not uploaded';
            }
            return (string) $item['label'] . ' (' . $reason . ')';
        }, $outstanding));
    }

    /** Why the invitation does not allow a booking now, or null. $outstanding is Document::outstandingRequired(). */
    private function invitationProblem(?array $invitation, ?array $appointment, array $app, array $outstanding): ?string
    {
        if ($invitation === null) {
            return 'MSWDO has not opened interview booking for your application yet.';
        }
        if ($invitation['status'] === 'BOOKED' || ($appointment !== null && in_array($appointment['status'], ['BOOKED', 'COMPLETED'], true))) {
            return 'You already have an interview. Cancel it first if you need to change the date.';
        }
        if ($invitation['status'] !== 'OPEN') {
            return 'Interview booking is closed for your application. Please contact the MSWDO office.';
        }
        if ($outstanding) {
            return 'Interview booking is paused until your required documents are verified: ' . $this->outstandingText($outstanding) . '.';
        }
        if ($this->isReplacement($appointment, $app) && (int) $invitation['reschedules_used'] >= (int) $invitation['reschedules_allowed']) {
            return 'You have used every reschedule allowed. Please contact the MSWDO office.';
        }
        return null;
    }

    /**
     * True when a booking now would be a COUNTED replacement: the previous appointment row is CANCELLED, was cancelled by
     * this applicant (cancelled_by = the application's user) and was a date booking. A staff-made timed appointment the
     * applicant cancelled, and any staff cancellation or MISSED, are not.
     */
    private function isReplacement(?array $appointment, array $app): bool
    {
        return $appointment !== null && $appointment['status'] === 'CANCELLED' && interview_is_date_only($appointment)
            && $appointment['cancelled_by'] !== null && (int) $appointment['cancelled_by'] === (int) $app['user_id'];
    }

    /** The applicant's own application (null when it is not theirs); with $lock the row is read with FOR UPDATE (first lock of book()). */
    private function application(int $applicationId, int $userId, bool $lock): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reference_no, mobile, user_id, status, type, category_id, renewal_simplified FROM applications WHERE id = ? AND user_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$applicationId, $userId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }
}
