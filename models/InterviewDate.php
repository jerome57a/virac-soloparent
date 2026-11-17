<?php
require_once __DIR__ . '/Model.php';

/**
 * Data access for interview_dates: the dates staff have opened for date-only interview booking. A row is the
 * date-level capacity lock (see database/schema.sql): every writer locks it with find($date, true) before it counts
 * seats or changes the row. A date with no row is not open. Rows are closed, never deleted. Nothing here checks the
 * feature flag or the schema (InterviewDateService does) and nothing here starts a transaction (the caller does).
 */
class InterviewDate extends Model
{
    public const STATUSES = ['OPEN', 'CLOSED'];

    /** The row for $date (Y-m-d), or null. With $lock it is read with FOR UPDATE (inside the caller's transaction). */
    public function find(string $date, bool $lock = false): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM interview_dates WHERE interview_date = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$date]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * Creates the row as OPEN if it does not exist; an existing row is left exactly as it is (the no-op update is
     * not an error, and other errors are not hidden). Returns true when a row was inserted. Follow it with
     * find($date, true): a plain SELECT ... FOR UPDATE on a missing row could deadlock two staff members.
     */
    public function insertOpenIfMissing(string $date, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO interview_dates (interview_date, status, created_by) VALUES (?, 'OPEN', ?)
             ON DUPLICATE KEY UPDATE interview_date = interview_date"
        );
        $stmt->execute([$date, $userId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Seats used on $date: BOOKED and COMPLETED appointments (legacy timed rows included, through the generated
     * interview_date column). Cancelled and missed appointments do not hold a seat. A booking decision must use
     * $lock = true after find($date, true): a plain count could read a snapshot older than the date lock and miss a
     * booking committed meanwhile. The plain form is for display.
     */
    public function seatsUsed(string $date, bool $lock = false): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM appointments WHERE interview_date = ? AND status IN ('BOOKED','COMPLETED')" . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$date]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Dates from $from (Y-m-d) onward in date order, each with `seats_used` (a plain count, for display). With $openOnly
     * only OPEN dates are listed, so CLOSED rows cannot use up $limit (the applicant booking list); the default lists
     * every status, as the staff screen needs.
     */
    public function upcoming(string $from, int $limit = 100, bool $openOnly = false): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.*, (SELECT COUNT(*) FROM appointments a
                          WHERE a.interview_date = d.interview_date AND a.status IN ('BOOKED','COMPLETED')) AS seats_used
             FROM interview_dates d WHERE d.interview_date >= ?" . ($openOnly ? " AND d.status = 'OPEN'" : '') . " ORDER BY d.interview_date LIMIT " . max(1, min(500, $limit))
        );
        $stmt->execute([$from]);
        return $stmt->fetchAll();
    }

    /** Seats used per date between $from and $to (Y-m-d, inclusive): [Y-m-d => count]. A plain count, for display. */
    public function seatsByDate(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT interview_date, COUNT(*) AS seats FROM appointments
             WHERE interview_date BETWEEN ? AND ? AND status IN ('BOOKED','COMPLETED') GROUP BY interview_date"
        );
        $stmt->execute([$from, $to]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['interview_date']] = (int) $row['seats'];
        }
        return $out;
    }

    /** Open dates from $from onward that have no override and so follow the default capacity, with their seats used. */
    public function openDatesFollowingDefault(string $from): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.interview_date, (SELECT COUNT(*) FROM appointments a
                          WHERE a.interview_date = d.interview_date AND a.status IN ('BOOKED','COMPLETED')) AS seats_used
             FROM interview_dates d WHERE d.interview_date >= ? AND d.status = 'OPEN' AND d.capacity_override IS NULL ORDER BY d.interview_date"
        );
        $stmt->execute([$from]);
        return $stmt->fetchAll();
    }
}
