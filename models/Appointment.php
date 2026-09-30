<?php
require_once __DIR__ . '/Model.php';

/** appointment_slots + the interviews booked against them. */
class Appointment extends Model
{
    /** Upcoming slots (today or later) with remaining capacity, soonest first. */
    public function openSlots(): array
    {
        return $this->pdo->query(
            "SELECT s.*, (s.capacity - COALESCE(b.booked, 0)) AS remaining
             FROM appointment_slots s
             LEFT JOIN (SELECT slot_id, COUNT(*) AS booked FROM interviews WHERE slot_id IS NOT NULL GROUP BY slot_id) b
               ON b.slot_id = s.id
             WHERE s.slot_date >= CURDATE()
             HAVING remaining > 0
             ORDER BY s.slot_date, s.start_time"
        )->fetchAll();
    }

    public function findUpcomingSlot(int $slotId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM appointment_slots WHERE id = ? AND slot_date >= CURDATE()');
        $stmt->execute([$slotId]);
        return $stmt->fetch() ?: null;
    }

    /** Bookings on a slot, not counting the given application's own (so rescheduling into the same slot isn't blocked by itself). */
    public function bookedCountExcluding(int $slotId, int $applicationId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM interviews WHERE slot_id = ? AND application_id != ?');
        $stmt->execute([$slotId, $applicationId]);
        return (int) $stmt->fetchColumn();
    }

    /** Creates or reschedules the application's interview; always clears any old result/reminder. */
    public function upsertSelfBooked(int $applicationId, int $slotId, string $scheduledAt, string $location): void
    {
        $this->pdo->prepare(
            'INSERT INTO interviews (application_id, slot_id, scheduled_at, location, scheduled_by, reminder_sent_at)
             VALUES (?, ?, ?, ?, NULL, NULL)
             ON DUPLICATE KEY UPDATE slot_id = VALUES(slot_id), scheduled_at = VALUES(scheduled_at),
                 location = VALUES(location), scheduled_by = NULL, result = NULL, result_recorded_at = NULL, reminder_sent_at = NULL'
        )->execute([$applicationId, $slotId, $scheduledAt, $location]);
    }

    /** Staff (re)schedules an interview directly, not tied to a slot; clears any old result/reminder. */
    public function upsertStaffScheduled(int $applicationId, string $scheduledAt, string $location, ?string $notes, int $staffId): void
    {
        $this->pdo->prepare(
            'INSERT INTO interviews (application_id, slot_id, scheduled_at, location, notes, scheduled_by, reminder_sent_at)
             VALUES (?, NULL, ?, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE slot_id = NULL, scheduled_at = VALUES(scheduled_at), location = VALUES(location),
                 notes = VALUES(notes), scheduled_by = VALUES(scheduled_by), result = NULL, result_recorded_at = NULL, reminder_sent_at = NULL'
        )->execute([$applicationId, $scheduledAt, $location, $notes, $staffId]);
    }

    public function recordResult(int $applicationId, string $result): void
    {
        $this->pdo->prepare('UPDATE interviews SET result = ?, result_recorded_at = NOW() WHERE application_id = ?')
            ->execute([$result, $applicationId]);
    }

    public function interviewFor(int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM interviews WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        return $stmt->fetch() ?: null;
    }

    /** application_id => interview row, for the given applications. */
    public function interviewsFor(array $applicationIds): array
    {
        if (!$applicationIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($applicationIds), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM interviews WHERE application_id IN ($placeholders)");
        $stmt->execute(array_values($applicationIds));
        return array_column($stmt->fetchAll(), null, 'application_id');
    }

    /** Next $limit interviews from now, with applicant reference and name. */
    public function upcomingInterviews(int $limit): array
    {
        return $this->pdo->query(
            'SELECT i.*, a.reference_no, a.full_name FROM interviews i
             JOIN applications a ON a.id = i.application_id
             WHERE i.scheduled_at >= NOW() ORDER BY i.scheduled_at ASC LIMIT ' . (int) $limit
        )->fetchAll();
    }

    public function todaysInterviews(): array
    {
        return $this->pdo->query(
            'SELECT i.*, a.reference_no, a.full_name, a.id AS application_id
             FROM interviews i JOIN applications a ON a.id = i.application_id
             WHERE DATE(i.scheduled_at) = CURDATE() ORDER BY i.scheduled_at'
        )->fetchAll();
    }

    /** Interviews after today (up to 100), grouped by Y-m-d date. */
    public function laterInterviewsByDate(): array
    {
        $rows = $this->pdo->query(
            'SELECT i.*, a.reference_no, a.full_name, a.id AS application_id
             FROM interviews i JOIN applications a ON a.id = i.application_id
             WHERE i.scheduled_at > NOW() AND DATE(i.scheduled_at) != CURDATE()
             ORDER BY i.scheduled_at LIMIT 100'
        )->fetchAll();
        $byDate = [];
        foreach ($rows as $iv) {
            $byDate[date('Y-m-d', strtotime($iv['scheduled_at']))][] = $iv;
        }
        return $byDate;
    }

    /** Today-or-later slots with their booked count, soonest first. */
    public function upcomingSlotsWithBookings(?int $limit = null): array
    {
        return $this->pdo->query(
            'SELECT s.*, (SELECT COUNT(*) FROM interviews i WHERE i.slot_id = s.id) AS booked
             FROM appointment_slots s
             WHERE s.slot_date >= CURDATE()
             ORDER BY s.slot_date, s.start_time' . ($limit ? ' LIMIT ' . (int) $limit : '')
        )->fetchAll();
    }

    public function slotExists(string $date, string $startTime): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM appointment_slots WHERE slot_date = ? AND start_time = ?');
        $stmt->execute([$date, $startTime]);
        return (bool) $stmt->fetch();
    }

    public function createSlot(string $date, string $startTime, string $endTime, int $capacity, int $staffId): int
    {
        $this->pdo->prepare('INSERT INTO appointment_slots (slot_date, start_time, end_time, capacity, created_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$date, $startTime, $endTime, $capacity, $staffId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function bookedCount(int $slotId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM interviews WHERE slot_id = ?');
        $stmt->execute([$slotId]);
        return (int) $stmt->fetchColumn();
    }

    public function deleteSlot(int $slotId): void
    {
        $this->pdo->prepare('DELETE FROM appointment_slots WHERE id = ?')->execute([$slotId]);
    }

    /** Interviews within the next $hours that haven't been reminded yet. */
    public function dueForReminder(int $hours): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.id AS interview_id, i.scheduled_at, i.location, a.id AS application_id, a.user_id, a.reference_no, a.mobile
             FROM interviews i JOIN applications a ON a.id = i.application_id
             WHERE i.scheduled_at > NOW()
               AND i.scheduled_at <= DATE_ADD(NOW(), INTERVAL ? HOUR)
               AND i.reminder_sent_at IS NULL"
        );
        $stmt->execute([$hours]);
        return $stmt->fetchAll();
    }

    public function markReminded(int $interviewId): void
    {
        $this->pdo->prepare('UPDATE interviews SET reminder_sent_at = NOW() WHERE id = ?')->execute([$interviewId]);
    }
}
