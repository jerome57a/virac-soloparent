<?php
/** Shared date/time rules for every way an interview can be booked. */
class InterviewCalendarService
{
    private array $holidays;
    private array $additionalHolidays;
    private array $closures;
    private array $weekdays;
    private string $opens;
    private string $closes;

    public function __construct(PDO $pdo)
    {
        $this->holidays = require __DIR__ . '/../config/interview-holidays.php';
        $this->additionalHolidays = self::dates(get_setting($pdo, 'appointment_holiday_dates', ''));
        $this->closures = self::dates(get_setting($pdo, 'appointment_closure_dates', ''));
        $this->weekdays = explode(',', get_setting($pdo, 'appointment_days', '1,2,3,4,5'));
        $this->opens = get_setting($pdo, 'appointment_day_start', '08:00');
        $this->closes = get_setting($pdo, 'appointment_day_end', '17:00');
    }

    public static function dates(string $value): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', trim($value)) ?: []));
    }

    /** Returns the reason this date is closed, or null on an office day. */
    public function dateError(string $date): ?string
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$day || $day->format('Y-m-d') !== $date) {
            return 'Select a valid interview date.';
        }
        if ((int) $day->format('N') >= 6) {
            return 'No interviews on Saturday or Sunday. Choose an office day.';
        }
        if (in_array($date, $this->closures, true)) {
            return 'The office is closed on this date. Choose another interview date.';
        }
        // RA 11139: October 26 is a non-working holiday throughout Catanduanes.
        if ($day->format('m-d') === '10-26' || in_array($date, $this->additionalHolidays, true)
            || in_array($day->format('m-d'), $this->holidays[(int) $day->format('Y')] ?? [], true)) {
            return 'No interviews on this holiday. Choose another office day.';
        }
        if (!isset($this->holidays[(int) $day->format('Y')])) {
            return 'The holiday calendar for this year must be updated before interviews can be booked.';
        }
        if (!in_array($day->format('N'), $this->weekdays, true)) {
            return 'The office does not hold interviews on this weekday. Choose an allowed office day.';
        }
        return null;
    }

    public function timeError(string $date, string $start, ?string $end = null): ?string
    {
        if ($error = $this->dateError($date)) {
            return $error;
        }
        $start = substr($start, 0, 5);
        $end = $end !== null ? substr($end, 0, 5) : null;
        if ($start < $this->opens || $start >= $this->closes || ($end !== null && ($end <= $start || $end > $this->closes))) {
            return 'Interviews must be within office hours: ' . $this->opens . ' to ' . $this->closes . '.';
        }
        return null;
    }
}
