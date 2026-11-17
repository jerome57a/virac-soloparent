<?php
require_once __DIR__ . '/InterviewDateFeature.php';
require_once __DIR__ . '/InterviewDateService.php';
require_once __DIR__ . '/SMSService.php';
require_once __DIR__ . '/../models/InterviewDate.php';
require_once __DIR__ . '/../models/InterviewInvitation.php';
require_once __DIR__ . '/../models/AppointmentEvent.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/Document.php';

/**
 * Staff management of interview booking invitations: open, close, reopen and grant an extra reschedule.
 *
 * Staff-side only. Nothing here lets an applicant see or use an invitation, books a date, sends an SMS, uses up the
 * allowance or reopens an invitation by itself; those belong to the applicant booking step.
 *
 * Every method refuses unless the staff member holds applications.process AND InterviewDateFeature::enabled() (flag on
 * and the whole schema ready), so with the flag off no invitation table is read or written.
 *
 * Rules (the approved design in schema.sql):
 *  - One invitation per application; it is closed and reopened, never duplicated; it never expires by itself.
 *  - Open / reopen / grant need an application that is still being processed (Application::IN_PROGRESS_STATUSES), not a
 *    simplified renewal (no interview). Open / reopen also need every required document verified and unexpired, and no
 *    BOOKED or COMPLETED interview. Close needs only the invitation to be OPEN.
 *  - The allowance defaults to 1 reschedule; it is exhausted when reschedules_used >= reschedules_allowed. An extra one
 *    is granted only while it is exhausted, the invitation is CLOSED and the applicant cancelled their own date booking,
 *    one at a time with a reason, up to MAX_ALLOWED in total; reopening is a separate action. An exhausted allowance
 *    after such a cancellation cannot be reopened until staff grant one.
 *
 * Each change runs in one transaction: the application row is locked first, then (open / reopen) its document rows in
 * ascending id, then the invitation row, before anything is read without a lock. It writes an appointment_events row and
 * an audit_logs entry in that same transaction. Each action is its own event (INVITATION_OPENED, INVITATION_CLOSED,
 * INVITATION_REOPENED, RESCHEDULE_GRANTED).
 */
class InterviewInvitationService
{
    public const MAX_ALLOWED = InterviewInvitation::MAX_ALLOWED;

    private PDO $pdo;
    private InterviewInvitation $invitations;
    private AppointmentEvent $events;
    private Appointment $appointments;
    private Document $documents;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->invitations = new InterviewInvitation($pdo);
        $this->events = new AppointmentEvent($pdo);
        $this->appointments = new Appointment($pdo);
        $this->documents = new Document($pdo);
    }

    // ---- Display ----

    /**
     * What the staff screen needs for one application (plain reads, nothing locked): the invitation row (or null) and,
     * per action, the reason it is not available now (null = available). Null when the feature is not enabled.
     *
     * @return array{invitation: ?array, problems: array<string, ?string>, exhausted: bool}|null
     */
    public function panel(int $applicationId): ?array
    {
        if (!InterviewDateFeature::enabled($this->pdo)) {
            return null;
        }
        $app = $this->application($applicationId, false);
        if ($app === null) {
            return null;
        }
        $invitation = $this->invitations->find($applicationId);
        $appointment = $this->appointments->interviewFor($applicationId);
        $outstanding = $this->documents->outstandingRequired($app);
        $problems = [];
        foreach (['open', 'close', 'reopen', 'grant'] as $action) {
            $problems[$action] = $this->problem($action, $app, $invitation, $appointment, $outstanding);
        }
        return ['invitation' => $invitation, 'problems' => $problems, 'exhausted' => $invitation !== null && $this->exhausted($invitation)];
    }

    // ---- Actions (each returns an error message, or null on success) ----

    /** Opens interview scheduling for an application that has no invitation yet. A closed one is reopened with reopen(). */
    public function open(array $staff, int $applicationId, string $note = '', ?string $customMessage = null): ?string
    {
        return $this->guard($staff) ?? $this->noteProblem($note) ?? $this->messageProblem($customMessage) ?? $this->enable('open', $staff, $applicationId, trim($note), $customMessage);
    }

    /** Reopens a closed invitation (its own event and audit entry). */
    public function reopen(array $staff, int $applicationId, string $note = '', ?string $customMessage = null): ?string
    {
        return $this->guard($staff) ?? $this->noteProblem($note) ?? $this->messageProblem($customMessage) ?? $this->enable('reopen', $staff, $applicationId, trim($note), $customMessage);
    }

    /** Closes an open invitation to new bookings. A reason is required. */
    public function close(array $staff, int $applicationId, string $reason): ?string
    {
        if ($error = $this->guard($staff) ?? $this->reasonProblem($reason, 'closing this invitation')) {
            return $error;
        }
        $reason = trim($reason);
        $this->pdo->beginTransaction();
        try {
            $app = $this->application($applicationId, true);
            if ($app === null) {
                $this->pdo->rollBack();
                return 'Application not found.';
            }
            $invitation = $this->invitations->find($applicationId, true);
            if ($error = $this->problem('close', $app, $invitation, null, [])) {
                $this->pdo->rollBack();
                return $error;
            }
            if (!$this->invitations->markClosed($applicationId, (int) $staff['id'], $reason, date('Y-m-d H:i:s'))) {
                $this->pdo->rollBack();
                return 'This invitation changed. Refresh and try again.';
            }
            $this->record($staff, $applicationId, 'INVITATION_CLOSED', 'Interview Invitation Closed', $reason,
                'Closed by staff. Reschedules used ' . (int) $invitation['reschedules_used'] . ' of ' . (int) $invitation['reschedules_allowed'] . '.');
            $this->pdo->commit();
            return null;
        } catch (Throwable $e) {
            $this->rollBackQuietly();
            throw $e;
        }
    }

    /** Allows one more reschedule (reason required; up to MAX_ALLOWED in total). It does not reopen the invitation. */
    public function grantReschedule(array $staff, int $applicationId, string $reason): ?string
    {
        if ($error = $this->guard($staff) ?? $this->reasonProblem($reason, 'granting an extra reschedule')) {
            return $error;
        }
        $reason = trim($reason);
        $this->pdo->beginTransaction();
        try {
            $app = $this->application($applicationId, true);
            if ($app === null) {
                $this->pdo->rollBack();
                return 'Application not found.';
            }
            $invitation = $this->invitations->find($applicationId, true);
            $appointment = $this->appointments->interviewFor($applicationId);
            if ($error = $this->problem('grant', $app, $invitation, $appointment, [])) {
                $this->pdo->rollBack();
                return $error;
            }
            if (!$this->invitations->grantOneReschedule($applicationId)) {
                $this->pdo->rollBack();
                return 'This invitation changed. Refresh and try again.';
            }
            $allowed = (int) $invitation['reschedules_allowed'];
            $this->record($staff, $applicationId, 'RESCHEDULE_GRANTED', 'Interview Reschedule Granted', $reason,
                'Reschedules allowed ' . $allowed . ' → ' . ($allowed + 1) . ' (used ' . (int) $invitation['reschedules_used'] . ').');
            $this->pdo->commit();
            return null;
        } catch (Throwable $e) {
            $this->rollBackQuietly();
            throw $e;
        }
    }

    // ---- Hook from the appointment flows ----
    // It runs INSIDE the caller's transaction (which already holds the application row lock, so the lock order stays
    // application -> invitation), open no transaction of their own, and do nothing unless the feature is enabled, so with
    // the flag off no invitation or event table is touched.

    /**
     * After an appointment is CANCELLED or marked MISSED (AppointmentService::closeAppointment). Writes the CANCELLED /
     * MISSED event for a date booking or for an application that has an invitation, then moves a BOOKED invitation:
     *  - the applicant cancelled: back to OPEN (INVITATION_REOPENED, source APPLICANT) while reschedules_used <
     *    reschedules_allowed, else CLOSED (INVITATION_CLOSED, source SYSTEM). reschedules_used is never changed here;
     *  - staff cancelled or marked it missed: CLOSED (INVITATION_CLOSED, source STAFF).
     * An OPEN or CLOSED invitation is left as it is.
     */
    public function afterAppointmentClosed(array $actor, array $appointment, string $outcome, bool $byStaff, ?string $reason): void
    {
        if (!InterviewDateFeature::enabled($this->pdo)) {
            return;
        }
        $applicationId = (int) $appointment['application_id'];
        $invitation = $this->invitations->find($applicationId, true);
        if ($invitation === null && !interview_is_date_only($appointment)) {
            return;
        }
        $appointmentId = (int) $appointment['id'];
        $this->events->record($applicationId, $outcome === 'CANCELLED' ? 'CANCELLED' : 'MISSED', $byStaff ? 'STAFF' : 'APPLICANT', $actor,
            $reason !== null && $reason !== '' ? $reason : null, null, $appointmentId, substr((string) $appointment['scheduled_at'], 0, 10));
        if ($invitation === null || $invitation['status'] !== 'BOOKED') {
            return;
        }
        $used = (int) $invitation['reschedules_used'];
        $allowed = (int) $invitation['reschedules_allowed'];
        if (!$byStaff && $outcome === 'CANCELLED' && $used < $allowed) {
            if (!$this->invitations->releaseBooking($applicationId)) {
                // Impossible while the invitation row is locked by this transaction; if it ever happens, roll the caller back.
                throw new RuntimeException('The interview invitation changed while the appointment was being closed.');
            }
            $details = 'Reopened automatically after the applicant cancelled. Reschedules used ' . $used . ' of ' . $allowed . '.';
            $this->events->record($applicationId, 'INVITATION_REOPENED', 'APPLICANT', $actor, null, $details, $appointmentId);
            $this->auditFor($actor, $applicationId, 'Interview Invitation Reopened', $details);
            return;
        }
        $closeReason = $byStaff ? ($outcome === 'CANCELLED' ? 'Appointment cancelled by staff' : 'Appointment marked missed by staff') : 'Reschedule allowance used up';
        if (!$this->invitations->markClosed($applicationId, $byStaff ? (int) $actor['id'] : null, $closeReason, date('Y-m-d H:i:s'), 'BOOKED')) {
            throw new RuntimeException('The interview invitation changed while the appointment was being closed.');
        }
        $details = 'Closed. Reschedules used ' . $used . ' of ' . $allowed . '.';
        $this->events->record($applicationId, 'INVITATION_CLOSED', $byStaff ? 'STAFF' : 'SYSTEM', $actor, $closeReason, $details, $appointmentId);
        $this->auditFor($actor, $applicationId, 'Interview Invitation Closed', $details . ' ' . $closeReason . '.');
    }

    // ---- Internals ----

    /**
     * In-app notice and SMS to the applicant after staff open or manually reopen booking ($action is 'open' or 'reopen').
     * Sent only after the transaction has committed, each in its own try/catch: a failure is logged and never undoes the
     * staff action (nor stops the other message). The SMS uses the interview_booking_open template through SMSService
     * (disabled template: nothing is sent or logged) and its sms_logs id is stored on the invitation. One message per
     * committed action, with no suppression between separate manual actions. (The automatic reopening after the
     * applicant's own cancellation and the grant action do not call this.)
     */
    private function notifyBookingOpened(array $app, string $action, ?string $customMessage = null): void
    {
        try {
            create_notification($this->pdo, (int) $app['user_id'], 'appointment_confirmation',
                'MSWDO has ' . ($action === 'open' ? 'opened' : 'reopened') . ' interview booking for your application ' . $app['reference_no'] . '. Choose your interview date under Appointment.',
                (int) $app['id'], 'applicant/dashboard.php?section=appointment');
        } catch (Throwable $e) {
            error_log('Interview booking notification failed: ' . $e->getMessage());
        }
        try {
            $sms = (new SMSService($this->pdo))->notifyInterviewBookingOpen((int) $app['id'], (string) $app['mobile'], (string) $app['reference_no'], $customMessage);
            if (!empty($sms['id'])) {
                $this->invitations->linkSms((int) $app['id'], (int) $sms['id']);
            }
        } catch (Throwable $e) {
            error_log('Interview booking SMS failed: ' . $e->getMessage());
        }
    }

    private function auditFor(array $actor, int $applicationId, string $action, string $details): void
    {
        audit_log($this->pdo, (int) $actor['id'], (string) $actor['full_name'], (string) $actor['role'], $action, 'application', $applicationId, $details);
    }

    /** Shared by open() and reopen(): the same document, status and appointment checks, under the same locks. */
    private function enable(string $action, array $staff, int $applicationId, string $note, ?string $customMessage = null): ?string
    {
        $this->pdo->beginTransaction();
        try {
            $app = $this->application($applicationId, true);
            if ($app === null) {
                $this->pdo->rollBack();
                return 'Application not found.';
            }
            // Locks first (application, its documents in ascending id, the invitation), plain reads after: the reads
            // below then see the committed, current state, as in InterviewBookingService::book().
            $docIds = $this->pdo->prepare('SELECT id FROM application_documents WHERE application_id = ? ORDER BY id FOR UPDATE');
            $docIds->execute([$applicationId]);
            $docIds->fetchAll();
            $invitation = $this->invitations->find($applicationId, true);
            $appointment = $this->appointments->interviewFor($applicationId);
            $outstanding = $this->documents->outstandingRequired($app);
            if ($error = $this->problem($action, $app, $invitation, $appointment, $outstanding)) {
                $this->pdo->rollBack();
                return $error;
            }
            if ($action === 'open') {
                if (!$this->invitations->insertOpenIfMissing($applicationId, (int) $staff['id'])) {
                    $this->pdo->rollBack();
                    return 'This invitation changed. Refresh and try again.';
                }
                $created = $this->invitations->find($applicationId, true);
                $this->record($staff, $applicationId, 'INVITATION_OPENED', 'Interview Invitation Opened', $note !== '' ? $note : null,
                    'Interview scheduling opened. Reschedules allowed ' . (int) ($created['reschedules_allowed'] ?? 1) . ', used 0.');
            } else {
                if (!$this->invitations->markReopened($applicationId, (int) $staff['id'], date('Y-m-d H:i:s'))) {
                    $this->pdo->rollBack();
                    return 'This invitation changed. Refresh and try again.';
                }
                $this->record($staff, $applicationId, 'INVITATION_REOPENED', 'Interview Invitation Reopened', $note !== '' ? $note : null,
                    'Reopened by staff. Reschedules used ' . (int) $invitation['reschedules_used'] . ' of ' . (int) $invitation['reschedules_allowed']
                    . ((string) ($invitation['close_reason'] ?? '') !== '' ? '. Previously closed: ' . $invitation['close_reason'] : '') . '.');
            }
            $this->pdo->commit();
            $this->notifyBookingOpened($app, $action, $customMessage);
            return null;
        } catch (Throwable $e) {
            $this->rollBackQuietly();
            throw $e;
        }
    }

    /**
     * Why $action cannot be done now, or null when it can. Used by the screen (plain reads) and by every action (locked
     * reads), so the two cannot drift apart. $outstanding is Document::outstandingRequired(); it is only consulted by
     * open and reopen.
     */
    private function problem(string $action, array $app, ?array $invitation, ?array $appointment, array $outstanding): ?string
    {
        if ($action !== 'close') {
            if (!empty($app['renewal_simplified'])) {
                return 'This renewal uses document review and approval; a new interview is not required.';
            }
            if (!in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
                return 'Interview invitations can only be managed while the application is being processed (it is ' . $app['status'] . ').';
            }
        }
        switch ($action) {
            case 'open':
                if ($invitation !== null) {
                    return $invitation['status'] === 'CLOSED' ? 'This application already has a closed invitation. Reopen it instead.'
                        : ($invitation['status'] === 'BOOKED' ? 'This application already has a booked invitation.' : 'The invitation is already open.');
                }
                return $this->enableProblem($appointment, $outstanding);
            case 'reopen':
                if ($invitation === null) {
                    return 'No invitation has been opened for this application.';
                }
                if ($invitation['status'] !== 'CLOSED') {
                    return $invitation['status'] === 'BOOKED' ? 'This invitation is booked. Cancel the appointment first.' : 'The invitation is already open.';
                }
                if ($error = $this->enableProblem($appointment, $outstanding)) {
                    return $error;
                }
                if ($this->exhausted($invitation) && $this->cancelledByApplicant($appointment, $app)) {
                    return 'The applicant has used every reschedule allowed (' . (int) $invitation['reschedules_used'] . ' of ' . (int) $invitation['reschedules_allowed'] . '). '
                        . ((int) $invitation['reschedules_allowed'] >= self::MAX_ALLOWED
                            ? 'No more can be granted. Create the booking for the applicant instead.'
                            : 'Grant an extra reschedule first, then reopen the invitation.');
                }
                return null;
            case 'close':
                if ($invitation === null) {
                    return 'No invitation has been opened for this application.';
                }
                if ($invitation['status'] === 'BOOKED') {
                    return 'This invitation is booked. Cancel the appointment first.';
                }
                return $invitation['status'] === 'OPEN' ? null : 'The invitation is already closed.';
            case 'grant':
                if ($invitation === null) {
                    return 'No invitation has been opened for this application.';
                }
                if ($invitation['status'] !== 'CLOSED') {
                    return 'An extra reschedule can only be granted while the invitation is closed.';
                }
                if (!$this->exhausted($invitation)) {
                    return 'The applicant still has a reschedule left (' . (int) $invitation['reschedules_used'] . ' of ' . (int) $invitation['reschedules_allowed'] . ' used), so none can be granted.';
                }
                if (!$this->cancelledByApplicant($appointment, $app)) {
                    return 'An extra reschedule can only be granted after the applicant cancelled their own date booking.';
                }
                return (int) $invitation['reschedules_allowed'] >= self::MAX_ALLOWED
                    ? 'No more than ' . self::MAX_ALLOWED . ' reschedules can be allowed. Create the booking for the applicant instead.' : null;
        }
        return 'Choose a valid action.';
    }

    /** The checks open and reopen share: no booked or completed interview, and every required document verified and unexpired. */
    private function enableProblem(?array $appointment, array $outstanding): ?string
    {
        if ($appointment !== null && in_array($appointment['status'], ['BOOKED', 'COMPLETED'], true)) {
            return 'This application already has a ' . strtolower($appointment['status']) . ' interview.';
        }
        if ($outstanding) {
            return 'An invitation cannot be opened until all required documents are verified. Still outstanding: '
                . implode('; ', array_map(fn($item) => (string) $item['label'], $outstanding)) . '.';
        }
        return null;
    }

    private function exhausted(array $invitation): bool
    {
        return (int) $invitation['reschedules_used'] >= (int) $invitation['reschedules_allowed'];
    }

    /**
     * True when the latest appointment is a CANCELLED date booking (date_only = 1) and the cancelling user is the applicant
     * who owns the application: the same rule as a counted replacement booking (InterviewBookingService::isReplacement).
     * A staff-made timed appointment the applicant cancelled does not qualify.
     */
    private function cancelledByApplicant(?array $appointment, array $app): bool
    {
        return $appointment !== null && $appointment['status'] === 'CANCELLED' && interview_is_date_only($appointment)
            && $appointment['cancelled_by'] !== null && (int) $appointment['cancelled_by'] === (int) $app['user_id'];
    }

    /** The application fields these rules need; with $lock the row is read with FOR UPDATE (the first lock of every action). */
    private function application(int $applicationId, bool $lock): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, reference_no, mobile, user_id, status, type, category_id, renewal_simplified FROM applications WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$applicationId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** Permission (applications.process) and the effective feature state; null when allowed. */
    private function guard(array $staff): ?string
    {
        if (!can($staff, 'applications.process')) {
            return 'You do not have permission to manage interview invitations.';
        }
        if (!InterviewDateFeature::enabled($this->pdo)) {
            return 'Interview date booking is not enabled.';
        }
        return null;
    }

    private function noteProblem(string $note): ?string
    {
        return mb_strlen(trim($note)) > 255 ? 'The note is too long (up to 255 characters).' : null;
    }

    /** null = the automatic message is used; a custom message must have text and fit one SMS (500 characters). */
    private function messageProblem(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }
        $message = trim($message);
        if ($message === '') {
            return 'Write the custom SMS message, or choose the automatic message.';
        }
        return mb_strlen($message) > 500 ? 'The custom SMS message is too long (up to 500 characters).' : null;
    }

    private function reasonProblem(string $reason, string $for): ?string
    {
        $reason = trim($reason);
        if ($reason === '') {
            return 'Give a reason for ' . $for . '.';
        }
        return mb_strlen($reason) > 255 ? 'The reason is too long (up to 255 characters).' : null;
    }

    /** The appointment_events row and the audit_logs entry, both inside the caller's transaction. */
    private function record(array $staff, int $applicationId, string $eventType, string $auditAction, ?string $reason, string $details): void
    {
        $this->events->record($applicationId, $eventType, 'STAFF', $staff, $reason, $details);
        audit_log($this->pdo, (int) $staff['id'], (string) $staff['full_name'], (string) $staff['role'], $auditAction, 'application', $applicationId,
            $details . ($reason !== null && $reason !== '' ? ' · ' . $reason : ''));
    }

    private function rollBackQuietly(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
