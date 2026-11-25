<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/AppointmentEvent.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../models/InterviewDate.php';
require_once __DIR__ . '/InterviewBookingService.php';
require_once __DIR__ . '/NotificationService.php';

/**
 * Office check-in: a Solo Parent arrives at the MSWDO office and shows the reference number; MSWDO staff type it (or its last 4
 * digits, a name or a mobile number), compare the record with the Solo Parent's valid ID, and record the arrival. "Today at the
 * office" lists who is waiting (in arrival order), who is expected but not here yet, and who is done today.
 * The reference number only FINDS the record: it is not proof of identity (staff always check the valid ID).
 * Needs appointments.arrived_at / arrived_by (database/migrate_office_checkin.php); without them the panel only lists today's
 * interviews and check-in is unavailable.
 */
final class OfficeCheckInService
{
    private PDO $pdo;
    private static ?bool $ready = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** True when the arrival columns exist. */
    public function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = (int) $this->pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME IN ('arrived_at', 'arrived_by')")->fetchColumn() === 2;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function forgetReady(): void
    {
        self::$ready = null;
    }

    private function today(): string
    {
        return Appointment::manilaDate();
    }

    /**
     * The applications a typed text points to, newest first (at most 10). Accepts the full reference number, its last digits ("62",
     * "0062", "2026-0062"), a mobile number (09... or +639..., compared on the last 10 digits) or part of a name (3 letters or more).
     * Only applications whose Solo Parent account still exists.
     */
    public function find(string $query): array
    {
        $q = trim($query);
        if ($q === '') {
            return [];
        }
        $digits = preg_replace('/\D/', '', $q);
        $where = '';
        $args = [];
        if (preg_match('/^[A-Za-z]+-[A-Za-z]+-\d{4}-\d+$/', $q)) {                       // VIRAC-SP-2026-0062
            $where = 'UPPER(a.reference_no) = ?';
            $args[] = strtoupper($q);
        } elseif (preg_match('/^\d{4}-\d{1,4}$/', $q)) {                                  // 2026-62
            [$y, $n] = explode('-', $q);
            $where = 'a.reference_no LIKE ?';
            $args[] = '%-' . $y . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
        } elseif (preg_match('/^\d{1,4}$/', $q)) {                                        // 62, 0062
            $where = 'a.reference_no LIKE ?';
            $args[] = '%-' . str_pad($q, 4, '0', STR_PAD_LEFT);
        } elseif (strlen($digits) >= 10 && !preg_match('/[A-Za-z]/', $q)) {              // a mobile number
            $tail = substr($digits, -10);
            $where = "(RIGHT(REGEXP_REPLACE(a.mobile, '[^0-9]', ''), 10) = ? OR RIGHT(REGEXP_REPLACE(u.mobile, '[^0-9]', ''), 10) = ?)";
            $args = [$tail, $tail];
        } elseif (mb_strlen($q) >= 3) {                                                   // part of a name
            $where = 'a.full_name LIKE ?';
            $args[] = '%' . addcslashes($q, '%_\\') . '%';
        } else {
            return [];
        }
        $stmt = $this->pdo->prepare(
            "SELECT a.id FROM applications a JOIN users u ON u.id = a.user_id WHERE $where ORDER BY a.id DESC LIMIT 10"
        );
        $stmt->execute($args);
        $apps = new Application($this->pdo);
        return array_values(array_filter(array_map(fn($id) => $apps->findForReview((int) $id), $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    /**
     * What check-in can do for one application today:
     *   today      booked for today, not checked in yet            -> Arrived
     *   already    checked in today                              -> Open interview
     *   other_day  booked for another day                        -> move to today and check in
     *   no_date    documents verified, no interview date          -> schedule today and check in
     *   docs       documents still to verify                      -> open the documents
     *   done       interview result already recorded              -> open the application
     *   closed     released or rejected                           -> open the application
     * An APPROVED application is about the ID release (the Solo Parent comes back for the card):
     *   release_today / release_later / release_overdue / release_unscheduled  -> Open ID release (staff check the valid ID there)
     * @return array{kind: string, app: array, appointment: ?array, children: array, outstanding: int, date: ?string, position: ?int, release: ?array}
     */
    public function situation(array $app): array
    {
        $appointment = (new Appointment($this->pdo))->interviewFor((int) $app['id']);
        $stmt = $this->pdo->prepare('SELECT full_name, birthdate FROM application_children WHERE application_id = ? ORDER BY birthdate DESC');
        $stmt->execute([(int) $app['id']]);
        $out = ['kind' => 'closed', 'app' => $app, 'appointment' => $appointment, 'children' => $stmt->fetchAll(), 'outstanding' => 0, 'date' => null, 'position' => null, 'release' => null];
        $today = $this->today();
        if ($app['status'] === 'Approved') {
            $rel = $this->pdo->prepare('SELECT scheduled_at, location, released_at FROM id_release_schedules WHERE application_id = ?');
            $rel->execute([(int) $app['id']]);
            $release = $rel->fetch() ?: null;
            $out['release'] = $release;
            if (!$release || $release['released_at'] !== null) {
                $out['kind'] = 'release_unscheduled';
                return $out;
            }
            $day = substr((string) $release['scheduled_at'], 0, 10);
            $out['date'] = $day;
            $out['kind'] = $day === $today ? 'release_today' : ($day < $today ? 'release_overdue' : 'release_later');
            return $out;
        }
        if (!in_array($app['status'], Application::IN_PROGRESS_STATUSES, true)) {
            return $out;
        }
        if ($appointment && $appointment['status'] === 'BOOKED') {
            $date = substr((string) $appointment['scheduled_at'], 0, 10);
            $out['date'] = $date;
            if ($date !== $today) {
                $out['kind'] = 'other_day';
                return $out;
            }
            if ($this->ready() && !empty($appointment['arrived_at']) && substr((string) $appointment['arrived_at'], 0, 10) === $today) {
                $out['kind'] = 'already';
                $out['position'] = $this->position((int) $appointment['id']);
                return $out;
            }
            $out['kind'] = 'today';
            return $out;
        }
        if ($appointment && $appointment['status'] === 'COMPLETED' && Appointment::interviewOutcome($appointment) !== 'Unsuccessful') {
            $out['kind'] = 'done';
            return $out;
        }
        $outstanding = (new Document($this->pdo))->outstandingRequired($app);
        $out['outstanding'] = count($outstanding);
        $out['kind'] = $outstanding ? 'docs' : 'no_date';
        return $out;
    }

    /** Place in today's waiting list (1 = first), or null when not waiting. */
    public function position(int $appointmentId): ?int
    {
        foreach ($this->board()['waiting'] as $i => $row) {
            if ((int) $row['appointment_id'] === $appointmentId) {
                return $i + 1;
            }
        }
        return null;
    }

    /**
     * Records the arrival. $moveToToday: the booking was for another day and the Solo Parent came today; the interview moves to
     * today (a history line and an interview event say so). Returns [error|null, place in the waiting list].
     */
    public function arrive(array $staff, int $applicationId, bool $moveToToday = false): array
    {
        if (!can($staff, 'applications.process')) {
            return ['Only MSWDO staff can check a Solo Parent in.', null];
        }
        if (!$this->ready()) {
            return ['Check-in is not set up yet: run database/migrate_office_checkin.php.', null];
        }
        $today = $this->today();
        $apps = new Application($this->pdo);
        $events = new AppointmentEvent($this->pdo);
        $this->pdo->beginTransaction();
        try {
            $app = $apps->findForReview($applicationId);
            $lock = $this->pdo->prepare("SELECT * FROM appointments WHERE application_id = ? FOR UPDATE");
            $lock->execute([$applicationId]);
            $appt = $lock->fetch();
            if (!$app || !in_array($app['status'], Application::IN_PROGRESS_STATUSES, true) || !$appt || $appt['status'] !== 'BOOKED') {
                $this->pdo->rollBack();
                return ['This Solo Parent has no interview booked. Refresh and try again.', null];
            }
            $date = substr((string) $appt['scheduled_at'], 0, 10);
            if (!empty($appt['arrived_at']) && substr((string) $appt['arrived_at'], 0, 10) === $today && $date === $today) {
                $this->pdo->rollBack();
                return [null, $this->position((int) $appt['id'])];   // already checked in: nothing to change
            }
            if ($date !== $today) {
                if (!$moveToToday) {
                    $this->pdo->rollBack();
                    return ['The interview is on ' . date('l, F j, Y', strtotime($date)) . ', not today.', null];
                }
                (new InterviewDate($this->pdo))->insertOpenIfMissing($today, (int) $staff['id']);
                $this->pdo->prepare('UPDATE appointments SET scheduled_at = ?, date_only = 1 WHERE id = ?')->execute([$today . ' 00:00:00', (int) $appt['id']]);
                $apps->addHistory($applicationId, $app['status'], 'Interview moved from ' . date('F j, Y', strtotime($date)) . ' to today: the Solo Parent came to the MSWDO office.', (string) $staff['full_name'], (int) $staff['id']);
                $events->record($applicationId, 'MOVED_TO_TODAY', 'STAFF', $staff, 'The Solo Parent came to the office on another day.', 'From ' . $date, (int) $appt['id'], $today);
            }
            $this->pdo->prepare('UPDATE appointments SET arrived_at = NOW(), arrived_by = ? WHERE id = ?')->execute([(int) $staff['id'], (int) $appt['id']]);
            $events->record($applicationId, 'ARRIVED', 'STAFF', $staff, null, 'Checked in at the MSWDO office', (int) $appt['id'], $today);
            $apps->addHistory($applicationId, $app['status'], 'Arrived at the MSWDO office (checked in by ' . $staff['full_name'] . ').', (string) $staff['full_name'], (int) $staff['id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        $position = $this->position((int) $appt['id']);
        // The other MSWDO staff see a pop-up; the one who checked them in does not.
        try {
            (new NotificationService($this->pdo))->notifyStaff('office_arrival',
                $app['reference_no'] . ' (' . $app['full_name'] . ') arrived at the office at ' . date('g:i A') . ($position ? '. Number ' . $position . ' waiting.' : '.'),
                $applicationId, 'decision', (int) $staff['id']);
            audit_log($this->pdo, (int) $staff['id'], (string) $staff['full_name'], (string) $staff['role'], 'Office Check-in', 'application', $applicationId,
                $app['reference_no'] . ' arrived at the MSWDO office' . ($moveToToday && $date !== $today ? ' (interview moved from ' . $date . ' to today)' : ''));
        } catch (Throwable $e) {
            error_log('Check-in notice failed: ' . $e->getMessage());
        }
        return [null, $position];
    }

    /** No interview date yet: books today through the normal staff scheduling (the Solo Parent gets its text), then checks in. */
    public function scheduleTodayAndArrive(array $staff, int $applicationId): array
    {
        $sms = null;
        $error = (new InterviewBookingService($this->pdo))->scheduleForApplicant($staff, $applicationId, $this->today(), null, $sms, null);
        if ($error !== null) {
            return [$error, null];
        }
        return $this->arrive($staff, $applicationId);
    }

    /**
     * Today at the office: waiting (checked in, oldest arrival first), expected (booked for today, not here yet) and done today
     * (interview result recorded today).
     * @return array{waiting: array, expected: array, done: array, ready: bool}
     */
    public function board(): array
    {
        $today = $this->today();
        $ready = $this->ready();
        $arrived = $ready ? 'i.arrived_at, i.arrived_by, u.full_name AS arrived_by_name' : 'NULL AS arrived_at, NULL AS arrived_by, NULL AS arrived_by_name';
        $join = $ready ? 'LEFT JOIN users u ON u.id = i.arrived_by' : '';
        $stmt = $this->pdo->prepare(
            "SELECT i.id AS appointment_id, i.application_id, i.status, i.scheduled_at, i.result, i.result_recorded_at, $arrived,
                    a.reference_no, a.full_name, a.type, c.name AS category_name
             FROM appointments i JOIN applications a ON a.id = i.application_id LEFT JOIN categories c ON c.id = a.category_id $join
             WHERE (i.scheduled_at >= ? AND i.scheduled_at < ? + INTERVAL 1 DAY) OR (i.result_recorded_at >= ? AND i.result_recorded_at < ? + INTERVAL 1 DAY)"
        );
        $start = $today . ' 00:00:00';
        $stmt->execute([$start, $start, $start, $start]);
        $waiting = $expected = $done = [];
        foreach ($stmt->fetchAll() as $r) {
            $arrivedToday = !empty($r['arrived_at']) && substr((string) $r['arrived_at'], 0, 10) === $today;
            $bookedToday = substr((string) $r['scheduled_at'], 0, 10) === $today;
            if ($r['status'] === 'BOOKED' && $bookedToday) {
                $arrivedToday ? $waiting[] = $r : $expected[] = $r;
            } elseif ($r['status'] === 'COMPLETED' && !empty($r['result_recorded_at']) && substr((string) $r['result_recorded_at'], 0, 10) === $today) {
                $r['outcome'] = Appointment::interviewOutcome($r);
                $done[] = $r;
            }
        }
        usort($waiting, fn($x, $y) => strcmp((string) $x['arrived_at'], (string) $y['arrived_at']));
        usort($expected, fn($x, $y) => strcmp((string) $x['scheduled_at'] . $x['reference_no'], (string) $y['scheduled_at'] . $y['reference_no']));
        usort($done, fn($x, $y) => strcmp((string) $y['result_recorded_at'], (string) $x['result_recorded_at']));
        return ['waiting' => $waiting, 'expected' => $expected, 'done' => $done, 'ready' => $ready];
    }

    /** Everything the "Today at the office" panel shows: the board, and the result of the typed search (null when none). */
    public function panel(?string $query): array
    {
        $panel = ['board' => $this->board(), 'query' => null, 'matches' => [], 'situation' => null];
        if ($query !== null && trim($query) !== '') {
            $panel['query'] = mb_substr(trim($query), 0, 60);
            $panel['matches'] = $this->find($panel['query']);
            if (count($panel['matches']) === 1) {
                $panel['situation'] = $this->situation($panel['matches'][0]);
            }
        }
        return $panel;
    }
}
