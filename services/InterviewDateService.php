<?php
require_once __DIR__ . '/InterviewDateFeature.php';
require_once __DIR__ . '/InterviewCalendarService.php';
require_once __DIR__ . '/../models/InterviewDate.php';
require_once __DIR__ . '/../models/Appointment.php';

/**
 * Interview days: the default daily capacity and how many seats are used.
 *
 * Staff no longer open dates one by one: an applicant can choose any office day (InterviewCalendarService decides which
 * days are offered), and a day's row is created by InterviewBookingService on its first booking. A day's row may still carry
 * its own capacity_override or be CLOSED, and both are respected. What remains here is read-only: the configured default
 * capacity (system_settings interview_default_capacity; 0 = not configured), a day's effective capacity, the seats used
 * (BOOKED and COMPLETED appointments hold a seat; capacity is 1 to MAX_CAPACITY), and the upcoming-days list for display.
 */
class InterviewDateService
{
    public const MAX_CAPACITY = 500;
    public const DEFAULT_CAPACITY_KEY = 'interview_default_capacity';

    private PDO $pdo;
    private InterviewDate $dates;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->dates = new InterviewDate($pdo);
    }

    // ---- Capacity ----

    /** The configured default daily capacity; 0 when it is not configured or not a whole number from 1 to 500 (fail closed). */
    public function defaultCapacity(): int
    {
        $value = get_setting($this->pdo, self::DEFAULT_CAPACITY_KEY, '0');
        if (!preg_match('/^\d{1,3}$/', $value)) {
            return 0;
        }
        $n = (int) $value;
        return $n >= 1 && $n <= self::MAX_CAPACITY ? $n : 0;
    }

    /** A date's capacity: its override when set (at least 1), else the default. 0 means none is configured, so nothing can be booked. */
    public function effectiveCapacity(?array $row, ?int $default = null): int
    {
        $override = $row['capacity_override'] ?? null;
        return $override !== null && (int) $override >= 1 ? (int) $override : ($default ?? $this->defaultCapacity());
    }

    /** Seats used on a date (plain count, for display), or null when the feature is not enabled. */
    public function seatsUsed(string $date): ?int
    {
        return InterviewDateFeature::enabled($this->pdo) ? $this->dates->seatsUsed($date) : null;
    }

    /**
     * Dates from $from (default today, Manila) onward with their capacity and seats, for display. Empty when the
     * feature is not enabled. Each row gains effective_capacity, capacity_configured, seats_used, remaining, full.
     */
    public function upcoming(?string $from = null, int $limit = 100): array
    {
        if (!InterviewDateFeature::enabled($this->pdo)) {
            return [];
        }
        $rows = $this->dates->upcoming($from ?? Appointment::manilaDate(), $limit);
        foreach ($rows as &$row) {
            $capacity = $this->effectiveCapacity($row);
            $used = (int) $row['seats_used'];
            $row['seats_used'] = $used;
            $row['effective_capacity'] = $capacity;
            $row['capacity_configured'] = $capacity >= 1;
            $row['remaining'] = max(0, $capacity - $used);
            $row['full'] = $capacity >= 1 && $used >= $capacity;
        }
        unset($row);
        return $rows;
    }

    /**
     * For the settings save: an error message when lowering the default capacity to $newDefault would leave an open
     * date that follows the default with more seats used than its capacity, else null. Null as well when the
     * feature is not enabled (no booking can exist). A plain read: the booking path itself re-checks under the lock.
     */
    public function defaultCapacityConflict(int $newDefault): ?string
    {
        if (!InterviewDateFeature::enabled($this->pdo)) {
            return null;
        }
        $over = array_values(array_filter(
            $this->dates->openDatesFollowingDefault(Appointment::manilaDate()),
            fn($d) => (int) $d['seats_used'] > $newDefault
        ));
        if (!$over) {
            return null;
        }
        $first = array_slice(array_map(fn($d) => $d['interview_date'] . ' (' . (int) $d['seats_used'] . ' used)', $over), 0, 3);
        return 'The default daily capacity cannot be lowered to ' . $newDefault . ': ' . count($over)
            . ' open interview date(s) that follow the default already have more seats used (' . implode(', ', $first) . '). No settings were changed.';
    }
}
