<?php
require_once __DIR__ . '/InterviewDateFeature.php';
require_once __DIR__ . '/IdReleaseService.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/Document.php';

/**
 * The "next step" line on each row of the staff Applications list: where an application is in the processing flow and
 * what happens next. Read-only and derived from recorded data only (documents, invitation, appointment, ID release),
 * so it can never disagree with the review page that makes the same decisions.
 *
 *   Pending Review -> verify documents -> send interview invitation -> applicant chooses a date -> interview day ->
 *   record result -> decision -> ID release -> done
 *
 * tone: 'action' (staff should act), 'wait' (waiting on the applicant or on a date), 'done' (nothing left to do).
 */
class ApplicationNextStepService
{
    private PDO $pdo;
    private Document $documents;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->documents = new Document($pdo);
    }

    /**
     * @param array<int, array> $apps rows from Application::search()
     * @return array<int, array{label: string, tone: string}> keyed by application id
     */
    public function forRows(array $apps): array
    {
        $ids = array_map(fn($a) => (int) $a['id'], $apps);
        if (!$ids) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $appointments = $this->keyed("SELECT * FROM appointments WHERE application_id IN ($marks)", $ids);
        $invitations = InterviewDateFeature::enabled($this->pdo)
            ? $this->keyed("SELECT application_id, status FROM interview_invitations WHERE application_id IN ($marks)", $ids) : null;
        $releases = $this->keyed("SELECT application_id, scheduled_at, released_at FROM id_release_schedules WHERE application_id IN ($marks)", $ids);

        $out = [];
        foreach ($apps as $app) {
            $id = (int) $app['id'];
            $out[$id] = $this->one($app, $appointments[$id] ?? null, $invitations === null ? false : ($invitations[$id] ?? null), $releases[$id] ?? null);
        }
        return $out;
    }

    /** @param array|false|null $invitation false = date booking is not enabled (no invitation step); null = none sent */
    private function one(array $app, ?array $appointment, $invitation, ?array $release): array
    {
        switch ($app['status']) {
            case 'Rejected':
                return $this->step('Closed: not approved', 'done');
            case 'ID Released':
                return $this->step('Completed: ID handed over', 'done');
            case 'Approved':
                return $release && empty($release['released_at'])
                    ? $this->step('ID release on ' . date('M j, Y', strtotime((string) $release['scheduled_at'])) . ': confirm the hand-over once done', 'wait')
                    : $this->step('Schedule the ID release', 'action');
            case 'For Revision':
                return $this->step('Waiting for the applicant to upload corrections', 'wait');
            case 'Pending Review':
                return $this->step('Open it to start the review', 'action');
        }
        // Under Verification / Interview Scheduled
        $simplified = !empty($app['renewal_simplified']);
        $outstanding = $this->documents->outstandingRequired($app);
        if ($outstanding) {
            return $this->step('Verify documents: ' . count($outstanding) . ' still outstanding', 'action');
        }
        if ($simplified) {
            return $this->step('Documents verified: ready for a decision', 'action');
        }
        if ($appointment && in_array($appointment['status'], ['BOOKED', 'COMPLETED'], true)) {
            $dateOnly = interview_is_date_only($appointment);
            $when = date('M j, Y', strtotime((string) $appointment['scheduled_at']));
            if ($appointment['status'] === 'COMPLETED') {
                return Appointment::interviewSuccessful($appointment)
                    ? $this->step('Interview successful: ready for a decision', 'action')
                    : $this->step('Interview result: ' . (Appointment::interviewOutcome($appointment) ?? 'recorded') . '. Review the application', 'action');
            }
            $day = substr((string) $appointment['scheduled_at'], 0, 10);
            $today = Appointment::manilaDate();
            if ($day < $today) {
                return $this->step('Interview was ' . $when . ': record the result', 'action');
            }
            if ($day === $today) {
                // A date-only interview can be recorded all day; a timed one once its time has come.
                return $dateOnly || strtotime((string) $appointment['scheduled_at']) <= time()
                    ? $this->step('Interview today: record the result', 'action')
                    : $this->step('Interview today at ' . date('g:i A', strtotime((string) $appointment['scheduled_at'])), 'wait');
            }
            return $this->step('Interview on ' . $when, 'wait');
        }
        if ($invitation === false) {
            return $this->step('Documents verified: schedule the interview', 'action');
        }
        if (is_array($invitation) && $invitation['status'] === 'OPEN') {
            return $this->step('Invitation sent: waiting for the applicant to choose a date', 'wait');
        }
        if (is_array($invitation) && $invitation['status'] === 'CLOSED') {
            return $this->step('Invitation closed: reopen it or book for the applicant', 'action');
        }
        return $this->step('Documents verified: send the interview invitation', 'action');
    }

    private function step(string $label, string $tone): array
    {
        return ['label' => $label, 'tone' => $tone];
    }

    /** application_id => row (the first row for an application). */
    private function keyed(string $sql, array $ids): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['application_id']] ??= $row;
        }
        return $out;
    }
}
