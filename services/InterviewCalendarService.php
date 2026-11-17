<?php
/** Shared date/time rules for every way an interview can be booked. */
class InterviewCalendarService
{
    private array $holidays;
    private array $additionalHolidays;
    private array $closures;
    private array $weekdays;
    private string $closes;

    public function __construct(PDO $pdo)
    {
        $this->holidays = require __DIR__ . '/../config/interview-holidays.php';
        $this->additionalHolidays = self::dates(get_setting($pdo, 'appointment_holiday_dates', ''));
        $this->closures = self::dates(get_setting($pdo, 'appointment_closure_dates', ''));
        $this->weekdays = explode(',', get_setting($pdo, 'appointment_days', '1,2,3,4,5'));
        $this->closes = get_setting($pdo, 'appointment_day_end', '17:00');
    }

    public static function dates(string $value): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', trim($value)) ?: []));
    }

    /** Returns the reason this date is closed, or null on an office day. */
    public function dateError(string $date): ?string
    {
        return $this->classify($date)[1];
    }

    /**
     * [status, message]: 'office' (message null), 'closed' (weekend, closure, holiday or a weekday the office does not
     * use) or 'unknown' (not a real date, or the holiday calendar has no entry for that year, so the day cannot be
     * judged). The rules and their order are exactly those dateError() has always applied.
     */
    private function classify(string $date): array
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$day || $day->format('Y-m-d') !== $date) {
            return ['unknown', 'Select a valid interview date.'];
        }
        if ((int) $day->format('N') >= 6) {
            return ['closed', 'No interviews on Saturday or Sunday. Choose an office day.'];
        }
        if (in_array($date, $this->closures, true)) {
            return ['closed', 'The office is closed on this date. Choose another interview date.'];
        }
        // RA 11139: October 26 is a non-working holiday throughout Catanduanes.
        if ($day->format('m-d') === '10-26' || in_array($date, $this->additionalHolidays, true)
            || in_array($day->format('m-d'), $this->holidays[(int) $day->format('Y')] ?? [], true)) {
            return ['closed', 'No interviews on this holiday. Choose another office day.'];
        }
        if (!isset($this->holidays[(int) $day->format('Y')])) {
            return ['unknown', 'The holiday calendar for this year must be updated before interviews can be booked.'];
        }
        if (!in_array($day->format('N'), $this->weekdays, true)) {
            return ['closed', 'The office does not hold interviews on this weekday. Choose an allowed office day.'];
        }
        return ['office', null];
    }

    /**
     * The last office day before $date (Y-m-d), looking back at most $maxLookback days, or null when it cannot be
     * determined reliably: $date is not a real date, a day on the way back cannot be judged (see classify()), or no
     * office day is found within the limit.
     */
    public function previousOfficeDay(string $date, int $maxLookback = 14): ?string
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$day || $day->format('Y-m-d') !== $date) {
            return null;
        }
        for ($back = 1; $back <= $maxLookback; $back++) {
            $candidate = $day->modify('-' . $back . ' day')->format('Y-m-d');
            $status = $this->classify($candidate)[0];
            if ($status === 'office') {
                return $candidate;
            }
            if ($status === 'unknown') {
                return null;
            }
        }
        return null;
    }

    /** The office closing time (HH:MM) for the cutoff: the appointment_day_end setting, or null if it is not a valid HH:MM. */
    public function closingTime(): ?string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $this->closes) === 1 ? $this->closes : null;
    }

    /**
     * When online cancellation of a date-only appointment on $appointmentDate (Y-m-d) closes: the office closing time
     * on the previous office day, in Manila time. Cancelling is allowed strictly before this instant. Null (fail
     * closed) when the previous office day or the closing time cannot be determined.
     */
    public function cancellationCutoff(string $appointmentDate): ?DateTimeImmutable
    {
        $previous = $this->previousOfficeDay($appointmentDate);
        $closing = $this->closingTime();
        if ($previous === null || $closing === null) {
            return null;
        }
        $cutoff = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $previous . ' ' . $closing, new DateTimeZone('Asia/Manila'));
        return $cutoff ?: null;
    }
}
