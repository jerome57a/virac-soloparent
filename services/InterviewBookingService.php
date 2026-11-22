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
    /** How far ahead MSWDO staff may set a date (the Solo Parent's own booking keeps BOOKING_WINDOW_DAYS). */
    public const STAFF_WINDOW_DAYS = 365;

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
        $result = ['state' => 'blocked', 'message' => null, 'dates' => [], 'replacement' => false, 'reschedulesLeft' => null, 'invitation' => null, 'staffAssigned' => false];
        if (!in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
            return ['message' => 'Interview booking is only available while your application is being processed.'] + $result;
        }
        $invitation = $this->invitations->find((int) $app['id']);
        if ($invitation === null) {
            return ['state' => 'not_open', 'message' => 'MSWDO has not opened interview booking for your application yet. It opens after every required document is verified.'] + $result;
        }
        $result['invitation'] = $invitation;
        $result['reschedulesLeft'] = max(0, (int) $invitation['reschedules_allowed'] - (int) $invitation['reschedules_used']);
        $appointment = $this->appointments->interviewFor((int) $app['id']);
        // A date MSWD staff scheduled: the applicant may keep it or pick another office day (no reschedule is used up).
        if ($this->isStaffAssigned($appointment)) {
            return ['state' => 'booked', 'staffAssigned' => true, 'dates' => $this->bookableDates()] + $result;
        }
        if ($invitation['status'] === 'BOOKED') {
            return ['state' => 'booked'] + $result;
        }
        if ($invitation['status'] === 'CLOSED') {
            return ['state' => 'closed', 'message' => 'Interview booking is closed for your application. Please contact the MSWDO office.'] + $result;
        }
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
            $staffAssigned = $this->isStaffAssigned($appointment);
            if ($staffAssigned && substr((string) $appointment['scheduled_at'], 0, 10) === $date) {
                $this->pdo->rollBack();
                return 'That is already your scheduled interview date.';
            }
            if ($error = $this->invitationProblem($invitation, $appointment, $app, $outstanding, $staffAssigned)) {
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
            // Replacing a staff-set date: the invitation is already BOOKED and no reschedule is counted.
            if (!$staffAssigned && !$this->invitations->markBooked($applicationId, $counted)) {
                $this->pdo->rollBack();
                return 'Your invitation changed. Refresh the page and try again.';
            }
            $when = $day->format('l, F j, Y');
            // Booking again after an interview that did not happen (or a staff-set date the Solo Parent replaced) is a reschedule:
            // say so in the status history, the event trail and the staff alert, with the previous date.
            $previousDay = $appointment !== null ? substr((string) $appointment['scheduled_at'], 0, 10) : null;
            $rescheduleWhy = null;
            if ($appointment !== null && $appointment['status'] === 'MISSED') {
                $rescheduleWhy = ($appointment['notes'] ?? '') === AppointmentService::RELEASE_NOTE ? 'after an interview with no recorded attendance' : 'after a missed interview';
            } elseif ($appointment !== null && $appointment['status'] === 'CANCELLED') {
                $rescheduleWhy = 'after a cancelled interview';
            } elseif ($staffAssigned) {
                $rescheduleWhy = 'instead of the date MSWDO staff scheduled';
            }
            $rescheduleNote = $rescheduleWhy !== null ? 'Rescheduled by the Solo Parent ' . $rescheduleWhy . ' (previous date ' . date('M j, Y', strtotime((string) $previousDay)) . '): new interview date ' . $when . ' booked' : null;
            $this->applications->setStatus($applicationId, 'Interview Scheduled');
            $this->applications->addHistory($applicationId, 'Interview Scheduled', $rescheduleNote ?? 'Interview date ' . $when . ' booked by the Solo Parent', (string) $user['full_name'], (int) $user['id']);
            $saved = $this->appointments->interviewFor($applicationId);
            $details = $counted
                ? 'Replacement booking for ' . $date . ' (previous date ' . substr((string) $appointment['scheduled_at'], 0, 10) . '). Reschedules used '
                    . ((int) $invitation['reschedules_used'] + 1) . ' of ' . (int) $invitation['reschedules_allowed'] . '.'
                : ($rescheduleWhy !== null ? 'Rescheduled ' . $rescheduleWhy . ': chose ' . $date . ' (previous date ' . $previousDay . ').' : 'Booked ' . $date . '.');
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
                $rescheduleWhy !== null
                    ? 'RESCHEDULED: ' . $app['reference_no'] . ' (' . $user['full_name'] . ') chose a new interview date, ' . $when . ' (' . $rescheduleWhy . ').'
                    : $app['reference_no'] . ' (' . $user['full_name'] . ') booked an interview date: ' . $when . '.', $applicationId);
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

    // ---- Staff scheduling ----

    /**
     * MSWD staff set the interview DATE for an applicant whose required documents are all verified (the invitation is opened
     * for them if it was not yet). The date is booked at once, like an applicant booking, and the applicant is texted: come
     * on that date, or log in and pick another office day. Returns an error message, or null on success.
     */
    public function scheduleForApplicant(array $staff, int $applicationId, string $date, ?string $customMessage = null, ?array &$smsResult = null, ?string $time = null): ?string
    {
        $smsResult = null; // filled with the SMS outcome (status SENT, FAILED or DISABLED) once the schedule is saved
        if (!can($staff, 'applications.process')) {
            return 'You do not have permission to schedule interviews.';
        }
        // Optional time of day (HH:MM). MSWDO staff may set any time, including right now; office hours are not enforced here.
        if ($time !== null) {
            $time = trim($time);
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                return 'Enter the interview time, for example 9:00 AM.';
            }
        }
        if (!InterviewDateFeature::enabled($this->pdo)) {
            return 'Interview date booking is not available right now.';
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$day || $day->format('Y-m-d') !== $date) {
            return 'Choose a valid interview date.';
        }
        if ($customMessage !== null) {
            $customMessage = trim($customMessage);
            if ($customMessage === '') {
                return 'Write the custom SMS message, or choose Automatic Message.';
            }
            if (mb_strlen($customMessage) > 500) {
                return 'The SMS message must be 500 characters or fewer.';
            }
        }
        $location = get_setting($this->pdo, 'appointment_location', AppointmentService::DEFAULT_LOCATION);
        $calendar = new InterviewCalendarService($this->pdo);
        $dateService = new InterviewDateService($this->pdo);
        $default = $dateService->defaultCapacity();

        $this->pdo->beginTransaction();
        try {
            $app = $this->application($applicationId, null, true);
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
                return 'An interview can only be scheduled while the application is being processed (it is ' . $app['status'] . ').';
            }
            $docIds = $this->pdo->prepare('SELECT id FROM application_documents WHERE application_id = ? ORDER BY id FOR UPDATE');
            $docIds->execute([$applicationId]);
            $docIds->fetchAll();
            $invitation = $this->invitations->find($applicationId, true);
            $appointment = $this->appointments->interviewFor($applicationId);
            $outstanding = $this->documents->outstandingRequired($app);
            if ($outstanding) {
                $this->pdo->rollBack();
                return 'Verify every required document before scheduling the interview: ' . $this->outstandingText($outstanding) . '.';
            }
            // Another interview may follow an UNSUCCESSFUL one (staff decide); any other booked or finished interview blocks a new date.
            $retake = $appointment !== null && $appointment['status'] === 'COMPLETED' && Appointment::interviewOutcome($appointment) === 'Unsuccessful';
            if (!$retake && (($invitation !== null && $invitation['status'] === 'BOOKED') || ($appointment !== null && in_array($appointment['status'], ['BOOKED', 'COMPLETED'], true)))) {
                $this->pdo->rollBack();
                return 'This Solo Parent already has an interview date.';
            }
            // MSWDO staff may choose any date from today on (weekends, holidays and closures are the staff member's call).
            $today = new DateTimeImmutable(Appointment::manilaDate());
            if ($date < $today->format('Y-m-d')) {
                $this->pdo->rollBack();
                return 'Choose today or a later date.';
            }
            if ($date > $today->modify('+' . self::STAFF_WINDOW_DAYS . ' days')->format('Y-m-d')) {
                $this->pdo->rollBack();
                return 'That date is too far ahead. Choose an earlier date.';
            }
            $this->dates->insertOpenIfMissing($date, (int) $staff['id']);
            $row = $this->dates->find($date, true);
            if ($row === null || $row['status'] !== 'OPEN') {
                $this->pdo->rollBack();
                return 'This date is not open for interviews. Choose another date.';
            }
            $capacity = $dateService->effectiveCapacity($row, $default);
            if ($capacity < 1 || $this->dates->seatsUsed($date, true) >= $capacity) {
                $this->pdo->rollBack();
                return 'This date is already full. Choose another date.';
            }
            // No invitation yet: open one with this schedule. A closed one (staff closed it, or the applicant used every reschedule):
            // scheduling a date here reopens it for this booking; staff decide, so the applicant's reschedule count is not consulted.
            $reopened = false;
            if ($invitation === null) {
                if (!$this->invitations->insertOpenIfMissing($applicationId, (int) $staff['id'])) {
                    $this->pdo->rollBack();
                    return 'The invitation changed. Refresh and try again.';
                }
            } elseif ($invitation['status'] === 'CLOSED') {
                if (!$this->invitations->markReopened($applicationId, (int) $staff['id'], date('Y-m-d H:i:s'))) {
                    $this->pdo->rollBack();
                    return 'The invitation changed. Refresh and try again.';
                }
                $reopened = true;
            } elseif ($retake && $invitation['status'] === 'BOOKED') {
                if (!$this->invitations->releaseBooking($applicationId)) {
                    $this->pdo->rollBack();
                    return 'The invitation changed. Refresh and try again.';
                }
            }
            $this->appointments->bookDateOnly($applicationId, $date, $location, 'STAFF', (int) $staff['id'], $time);
            if (!$this->invitations->markBooked($applicationId, false)) {
                $this->pdo->rollBack();
                return 'The invitation changed. Refresh and try again.';
            }
            $when = $day->format('l, F j, Y') . ($time !== null ? ' at ' . date('g:i A', strtotime($time)) : '');
            $this->applications->setStatus($applicationId, 'Interview Scheduled');
            $this->applications->addHistory($applicationId, 'Interview Scheduled', ($retake ? 'Another interview ' : 'Interview date ') . $when . ($retake ? ' scheduled by MSWDO staff after an unsuccessful interview' : ' scheduled by MSWDO staff'), (string) $staff['full_name'], (int) $staff['id']);
            $saved = $this->appointments->interviewFor($applicationId);
            $details = 'Scheduled ' . $date . ' by MSWDO staff' . ($invitation === null ? ' (invitation opened with it)' : ($reopened ? ' (closed booking reopened with it)' : '')) . '. The Solo Parent may keep it or choose another date.';
            $this->events->record($applicationId, 'STAFF_SCHEDULED', 'STAFF', $staff, null, $details, $saved !== null ? (int) $saved['id'] : null, $date);
            audit_log($this->pdo, (int) $staff['id'], (string) $staff['full_name'], (string) $staff['role'], 'Interview Date Scheduled by MSWDO Staff', 'application', $applicationId, $app['reference_no'] . ' · ' . $details);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // After the commit: a failed notification or SMS never undoes the schedule.
        try {
            create_notification($this->pdo, (int) $app['user_id'], 'appointment_confirmation',
                'MSWDO scheduled your interview for ' . $app['reference_no'] . ' on ' . $when . ' at ' . $location . '. You may come on that date, or choose another date under Appointment.',
                $applicationId, 'applicant/dashboard.php?section=appointment');
        } catch (Throwable $e) {
            error_log('Interview schedule notification failed: ' . $e->getMessage());
        }
        try {
            $sms = (new SMSService($this->pdo))->notifyInterviewDateAssigned($applicationId, (string) $app['mobile'], (string) $app['reference_no'], $date, $location, $customMessage, $time);
            $smsResult = $sms;
            if (!empty($sms['id'])) {
                $this->appointments->linkConfirmationSms($applicationId, (int) $sms['id']);
            }
        } catch (Throwable $e) {
            error_log('Interview schedule SMS failed: ' . $e->getMessage());
            $smsResult = $smsResult ?? ['status' => 'FAILED', 'id' => null, 'error' => 'the SMS could not be handed to the gateway'];
        }
        return null;
    }

    /** True for a BOOKED date-only appointment that MSWD staff scheduled (the applicant may still choose another date). */
    private function isStaffAssigned(?array $appointment): bool
    {
        // A date MSWDO staff set, with or without a time of day (a timed one is stored as date_only = 0: the database only allows a
        // date-only row at 00:00:00).
        return $appointment !== null && $appointment['status'] === 'BOOKED'
            && (string) ($appointment['booking_source'] ?? '') === 'STAFF';
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
    private function invitationProblem(?array $invitation, ?array $appointment, array $app, array $outstanding, bool $staffAssigned = false): ?string
    {
        if ($invitation === null) {
            return 'MSWDO has not opened interview booking for your application yet.';
        }
        if ($staffAssigned) {
            return null; // a date staff scheduled may be replaced once by the applicant's own choice
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
    private function application(int $applicationId, ?int $userId, bool $lock): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reference_no, mobile, user_id, status, type, category_id, renewal_simplified FROM applications WHERE id = ?' . ($userId !== null ? ' AND user_id = ?' : '') . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute($userId !== null ? [$applicationId, $userId] : [$applicationId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }
}
