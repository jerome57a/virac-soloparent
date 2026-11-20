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
 * Interview booking invitations, as far as the system still uses them: the read-only panel on the staff application
 * screen, and the hook that moves an invitation when an appointment is cancelled or missed.
 *
 * (Staff can no longer open, close, reopen or grant extra reschedules by hand; those controls were removed because no
 * screen used them. Staff set the interview date themselves, and an applicant whose booking is released rebooks.)
 *
 * The hook runs inside the caller's transaction (it already holds the application row lock) and does nothing unless
 * InterviewDateFeature::enabled(), so with the flag off no invitation or event table is touched.
 */
class InterviewInvitationService
{
    /** Interviews an applicant may miss and still choose a new date themselves; the invitation closes when this many are missed. */
    public const MISSED_LIMIT = 5;

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
     * What the staff screen needs for one application (plain reads, nothing locked): the invitation row (or null) and the
     * reason an interview date cannot be set yet ('open', null = it can). Null when the feature is not enabled.
     *
     * @return array{invitation: ?array, problems: array{open: ?string}}|null
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
        $problem = null;
        if (!empty($app['renewal_simplified'])) {
            $problem = 'This renewal uses document review and approval; a new interview is not required.';
        } elseif (!in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
            $problem = 'Interview invitations can only be managed while the application is being processed (it is ' . $app['status'] . ').';
        } elseif ($invitation === null) {
            $appointment = $this->appointments->interviewFor($applicationId);
            $outstanding = $this->documents->outstandingRequired($app);
            if ($appointment !== null && in_array($appointment['status'], ['BOOKED', 'COMPLETED'], true)) {
                $problem = 'This application already has a ' . strtolower($appointment['status']) . ' interview.';
            } elseif ($outstanding) {
                $problem = 'An invitation cannot be opened until all required documents are verified. Still outstanding: '
                    . implode('; ', array_map(fn($item) => (string) $item['label'], $outstanding)) . '.';
            }
        }
        return ['invitation' => $invitation, 'problems' => ['open' => $problem]];
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
     *  - staff marked it missed: back to OPEN (INVITATION_REOPENED, source SYSTEM) so the applicant can choose a new date,
     *    without using up a reschedule, until MISSED_LIMIT misses have been recorded; then CLOSED like the case below;
     *  - staff cancelled (or marked the last allowed miss): CLOSED (INVITATION_CLOSED, source STAFF).
     * An OPEN or CLOSED invitation is left as it is. Returns true when the applicant can now choose a new date themselves.
     */
    public function afterAppointmentClosed(array $actor, array $appointment, string $outcome, bool $byStaff, ?string $reason): bool
    {
        if (!InterviewDateFeature::enabled($this->pdo)) {
            return false;
        }
        $applicationId = (int) $appointment['application_id'];
        $invitation = $this->invitations->find($applicationId, true);
        if ($invitation === null && !interview_is_date_only($appointment)) {
            return false;
        }
        $appointmentId = (int) $appointment['id'];
        $this->events->record($applicationId, $outcome === 'CANCELLED' ? 'CANCELLED' : 'MISSED', $byStaff ? 'STAFF' : 'APPLICANT', $actor,
            $reason !== null && $reason !== '' ? $reason : null, null, $appointmentId, substr((string) $appointment['scheduled_at'], 0, 10));
        if ($invitation === null || $invitation['status'] !== 'BOOKED') {
            return false;
        }
        $used = (int) $invitation['reschedules_used'];
        $allowed = (int) $invitation['reschedules_allowed'];
        if ($byStaff && $outcome === 'MISSED') {
            // Staff confirmed the applicant did not come. The applicant may pick a new date themselves (a miss does not use up
            // a reschedule) until MISSED_LIMIT misses; the miss recorded just above is already counted.
            $missed = $this->events->countOf($applicationId, 'MISSED');
            if ($missed < self::MISSED_LIMIT) {
                if (!$this->invitations->releaseBooking($applicationId)) {
                    throw new RuntimeException('The interview invitation changed while the appointment was being closed.');
                }
                $details = 'Reopened after a missed interview (' . $missed . ' of ' . self::MISSED_LIMIT . ' allowed). The Solo Parent may choose a new date.';
                $this->events->record($applicationId, 'INVITATION_REOPENED', 'SYSTEM', $actor, null, $details, $appointmentId);
                $this->auditFor($actor, $applicationId, 'Interview Invitation Reopened', $details);
                return true;
            }
        }
        if (!$byStaff && $outcome === 'CANCELLED' && $used < $allowed) {
            if (!$this->invitations->releaseBooking($applicationId)) {
                // Impossible while the invitation row is locked by this transaction; if it ever happens, roll the caller back.
                throw new RuntimeException('The interview invitation changed while the appointment was being closed.');
            }
            $details = 'Reopened automatically after the Solo Parent cancelled. Reschedules used ' . $used . ' of ' . $allowed . '.';
            $this->events->record($applicationId, 'INVITATION_REOPENED', 'APPLICANT', $actor, null, $details, $appointmentId);
            $this->auditFor($actor, $applicationId, 'Interview Invitation Reopened', $details);
            return true;
        }
        $closeReason = $byStaff ? ($outcome === 'CANCELLED' ? 'Appointment cancelled by staff' : 'Appointment marked missed by staff') : 'Reschedule allowance used up';
        if (!$this->invitations->markClosed($applicationId, $byStaff ? (int) $actor['id'] : null, $closeReason, date('Y-m-d H:i:s'), 'BOOKED')) {
            throw new RuntimeException('The interview invitation changed while the appointment was being closed.');
        }
        $details = 'Closed. Reschedules used ' . $used . ' of ' . $allowed . '.';
        $this->events->record($applicationId, 'INVITATION_CLOSED', $byStaff ? 'STAFF' : 'SYSTEM', $actor, $closeReason, $details, $appointmentId);
        $this->auditFor($actor, $applicationId, 'Interview Invitation Closed', $details . ' ' . $closeReason . '.');
        return false;
    }

    // ---- Internals ----

    private function auditFor(array $actor, int $applicationId, string $action, string $details): void
    {
        audit_log($this->pdo, (int) $actor['id'], (string) $actor['full_name'], (string) $actor['role'], $action, 'application', $applicationId, $details);
    }


    /** The application fields these rules need; with $lock the row is read with FOR UPDATE (the first lock of every action). */
    private function application(int $applicationId, bool $lock): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, reference_no, mobile, user_id, status, type, category_id, renewal_simplified FROM applications WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$applicationId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }
}
