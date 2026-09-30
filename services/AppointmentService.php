<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../includes/SmsService.php';

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

    public function openSlots(): array
    {
        return $this->appointments->openSlots();
    }

    /** The first still-in-progress application an appointment applies to, if any. */
    public function findBookableApplication(array $applications): ?array
    {
        foreach ($applications as $app) {
            if (in_array($app['status'], self::BOOKABLE_STATUSES, true)) {
                return $app;
            }
        }
        return null;
    }

    public function location(): string
    {
        return get_setting($this->pdo, 'appointment_location', self::DEFAULT_LOCATION);
    }

    /**
     * Books (or reschedules) the application into an open slot. Capacity is
     * re-checked here to avoid racing another applicant for the last seat.
     * Returns an error message, or null on success.
     */
    public function book(array $user, array $application, int $slotId): ?string
    {
        $slot = $this->appointments->findUpcomingSlot($slotId);
        if (!$slot) {
            return 'That appointment slot is no longer available.';
        }
        if ($this->appointments->bookedCountExcluding($slotId, (int) $application['id']) >= (int) $slot['capacity']) {
            return 'That appointment slot just filled up. Please pick another.';
        }

        $location = $this->location();
        $scheduledAt = $slot['slot_date'] . ' ' . $slot['start_time'];
        $when = date('M j, Y g:i A', strtotime($scheduledAt));
        $applicationId = (int) $application['id'];

        $this->appointments->upsertSelfBooked($applicationId, $slotId, $scheduledAt, $location);
        $this->applications->setStatus($applicationId, 'Interview Scheduled');
        $this->applications->addHistory($applicationId, 'Interview Scheduled', 'Appointment self-booked for ' . $when, $user['full_name']);

        (new SmsService($this->pdo))->notifyInterviewScheduled($applicationId, $application['mobile'], $application['reference_no'], $scheduledAt, $location);
        create_notification(
            $this->pdo, (int) $user['id'], 'appointment_confirmation',
            'Your appointment for ' . $application['reference_no'] . ' is confirmed: ' . $when . ' at ' . $location . '.',
            $applicationId
        );
        audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Appointment Booked', 'application', $applicationId, $when);

        return null;
    }

    /** Staff: adds a bookable slot. Times are HH:MM. Returns an error message, or null on success. */
    public function createSlot(array $staff, string $date, string $startTime, string $endTime, int $capacity): ?string
    {
        if (!DateTime::createFromFormat('Y-m-d', $date) || $date < date('Y-m-d')) {
            return 'Select a valid date (today or later).';
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime) || $endTime <= $startTime) {
            return 'Select a valid start and end time (end must be after start).';
        }
        if ($capacity < 1 || $capacity > 100) {
            return 'Capacity must be between 1 and 100.';
        }
        if ($this->appointments->slotExists($date, $startTime)) {
            return 'A slot already exists for that date and start time.';
        }
        $slotId = $this->appointments->createSlot($date, $startTime, $endTime, $capacity, (int) $staff['id']);
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Appointment Slot Created', 'appointment_slot', $slotId, "$date $startTime-$endTime x$capacity");
        return null;
    }

    /** Staff: removes a slot, refusing if anyone is booked into it. Returns an error message, or null on success. */
    public function deleteSlot(array $staff, int $slotId): ?string
    {
        if ($this->appointments->bookedCount($slotId) > 0) {
            return 'This slot already has appointments booked and cannot be deleted.';
        }
        $this->appointments->deleteSlot($slotId);
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Appointment Slot Deleted', 'appointment_slot', $slotId);
        return null;
    }

    /**
     * Sends a reminder SMS + in-app notification for every interview inside
     * the configured window (default 24h) not yet reminded. Rescheduling
     * resets reminder_sent_at, so a moved appointment gets a fresh reminder.
     */
    public function sendDueReminders(): array
    {
        $hours = (int) get_setting($this->pdo, 'appointment_reminder_hours', '24');
        $hours = $hours > 0 ? $hours : 24;
        $due = $this->appointments->dueForReminder($hours);

        $sms = new SmsService($this->pdo);
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
}
