<?php
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/SMSService.php';

/** ID release appointment and handover workflow for one approved application. */
class IdReleaseService
{
    private PDO $pdo;
    private Application $applications;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->applications = new Application($pdo);
    }

    /** Save or reschedule the handover and send exactly the selected message. */
    public function schedule(array $staff, array $app, string $date, string $time, string $location, string $messageType, string $customMessage): ?string
    {
        $dateTime = DateTime::createFromFormat('!Y-m-d H:i', $date . ' ' . $time);
        if (!$dateTime || $dateTime->format('Y-m-d H:i') !== $date . ' ' . $time || $dateTime->getTimestamp() <= time()) {
            return 'Choose a valid future ID release date and time.';
        }
        $location = trim($location);
        if ($location === '' || mb_strlen($location) > 255) {
            return 'Enter a release location of up to 255 characters.';
        }
        if (!in_array($messageType, ['automatic', 'custom'], true)) {
            return 'Choose Automatic Message or Custom Message.';
        }
        $scheduledAt = $dateTime->format('Y-m-d H:i:s');
        $when = date('F j, Y', $dateTime->getTimestamp()) . ' at ' . date('g:i A', $dateTime->getTimestamp());
        $message = $messageType === 'custom'
            ? trim($customMessage)
            : SMSService::idReleaseMessage((string) $app['full_name'], $dateTime->getTimestamp(), $location);
        if ($message === '') {
            return 'Enter the custom SMS message before scheduling.';
        }
        if (mb_strlen($message) > 500) {
            return 'The SMS message is too long (maximum 500 characters). Shorten the location or custom message.';
        }

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM applications WHERE id = ? FOR UPDATE');
            $lock->execute([(int) $app['id']]);
            $current = $lock->fetch();
            if (!$current || $current['status'] !== 'Approved') {
                $this->pdo->rollBack();
                return 'ID release can only be scheduled for an approved application.';
            }
            if ((new Document($this->pdo))->outstandingRequired($current)) {
                $this->pdo->rollBack();
                return 'Complete all required documents before scheduling ID release.';
            }
            $stmt = $this->pdo->prepare('SELECT * FROM id_release_schedules WHERE application_id = ? FOR UPDATE');
            $stmt->execute([(int) $app['id']]);
            $existing = $stmt->fetch() ?: null;
            if ($existing && $existing['released_at'] !== null) {
                $this->pdo->rollBack();
                return 'This ID has already been released.';
            }
            if ($existing && $existing['scheduled_at'] === $scheduledAt && $existing['location'] === $location && $existing['sms_message'] === $message) {
                $this->pdo->commit();
                flash('This ID release schedule and message are already saved; no duplicate SMS was sent.');
                return null;
            }

            $this->pdo->prepare(
                'INSERT INTO id_release_schedules (application_id, scheduled_at, location, scheduled_by, sms_message)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE scheduled_at = VALUES(scheduled_at), location = VALUES(location), scheduled_by = VALUES(scheduled_by), sms_message = VALUES(sms_message), sms_log_id = NULL'
            )->execute([(int) $app['id'], $scheduledAt, $location, (int) $staff['id'], $message]);
            $label = $existing ? 'rescheduled' : 'scheduled';
            $historyNote = 'ID release appointment ' . $label;
            if ($existing) {
                $historyNote .= ' from ' . date('M j, Y g:i A', strtotime($existing['scheduled_at'])) . ' at ' . $existing['location'];
            }
            $historyNote .= ' to ' . $when . ' at ' . $location . '.';
            $this->applications->addHistory((int) $app['id'], 'Approved', mb_substr($historyNote, 0, 500), $staff['full_name'], (int) $staff['id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Commit the appointment before calling the external SMS provider.
        $sms = (new SMSService($this->pdo))->notifyIdReleaseScheduled((int) $app['id'], $app['mobile'], $message, $messageType === 'custom');
        $this->pdo->prepare('UPDATE id_release_schedules SET sms_log_id = ? WHERE application_id = ? AND released_at IS NULL')
            ->execute([$sms['id'], (int) $app['id']]);
        create_notification($this->pdo, (int) $app['user_id'], 'appointment_confirmation', 'Your Solo Parent ID release is scheduled for ' . $when . ' at ' . $location . '.', (int) $app['id']);
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'ID Release Scheduled', 'application', (int) $app['id'], $when . ' at ' . $location . ' (' . strtoupper($messageType) . ' SMS)');
        $this->flashSmsResult($sms, 'ID release schedule saved');
        return null;
    }

    /** Confirm actual handover once; scheduling alone never changes application status. */
    public function confirmRelease(array $staff, array $app): ?string
    {
        $id = (int) $app['id'];
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM applications WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $current = $lock->fetch();
            if (!$current || $current['status'] !== 'Approved') {
                $this->pdo->rollBack();
                return 'Only an approved application can be confirmed as released.';
            }
            if ((new Document($this->pdo))->outstandingRequired($current)) {
                $this->pdo->rollBack();
                return 'Complete all required documents before releasing the ID.';
            }
            $stmt = $this->pdo->prepare('SELECT * FROM id_release_schedules WHERE application_id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $schedule = $stmt->fetch();
            if (!$schedule) {
                $this->pdo->rollBack();
                return 'Schedule the ID release appointment before confirming handover.';
            }
            if ($schedule['released_at'] !== null) {
                $this->pdo->rollBack();
                return 'ID release has already been confirmed.';
            }

            $this->pdo->prepare('UPDATE id_release_schedules SET released_at = NOW(), released_by = ? WHERE application_id = ? AND released_at IS NULL')
                ->execute([(int) $staff['id'], $id]);
            $this->applications->setReviewedStatus($id, 'ID Released', (int) $staff['id']);
            $actual = $this->pdo->prepare('SELECT released_at FROM id_release_schedules WHERE application_id = ?');
            $actual->execute([$id]);
            $releasedAt = (string) $actual->fetchColumn();
            $this->applications->addHistory($id, 'ID Released', 'MSWDO staff confirmed the Solo Parent ID was handed to the Solo Parent on ' . date('F j, Y g:i A', strtotime($releasedAt)) . '.', $staff['full_name'], (int) $staff['id']);
            $expires = $this->pdo->prepare('SELECT id_expires_at FROM applications WHERE id = ?');
            $expires->execute([$id]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $releaseSms = (new SMSService($this->pdo))->notifyStatusChange($id, $app['mobile'], $app['reference_no'], 'ID Released', 'Your ID handover was confirmed.');
        if (($releaseSms['status'] ?? '') !== 'DISABLED') { // the generic status text may be switched off on purpose
            set_sms_notice(SMSService::outcomeNotice($releaseSms, 'ID release confirmed'));
        }
        $expiresAt = (string) $expires->fetchColumn();
        create_notification($this->pdo, (int) $app['user_id'], 'status_change', 'Application ' . $app['reference_no'] . ' is now ID Released.', $id);
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'ID Release Confirmed', 'application', $id, 'Handed to Solo Parent at ' . date('M j, Y g:i A', strtotime($releasedAt)));
        flash('ID release confirmed. The application is now ID Released.'
            . ($expiresAt !== '' ? ' The one-year validity starts today: the ID expires on ' . date('F j, Y', strtotime($expiresAt)) . ', and the Solo Parent is reminded automatically before then.' : ''));
        return null;
    }

    /**
     * Release dates that have passed with the ID still not handed over, oldest first. There is no deadline: the ID keeps
     * waiting at the office, so staff confirm the release when the applicant comes, or set a new date on request.
     */
    public function overdue(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.application_id, r.scheduled_at, r.location, a.reference_no, u.full_name
             FROM id_release_schedules r
             JOIN applications a ON a.id = r.application_id AND a.status = 'Approved'
             JOIN users u ON u.id = a.user_id
             WHERE r.released_at IS NULL AND r.scheduled_at < NOW()
             ORDER BY r.scheduled_at ASC LIMIT " . max(1, $limit)
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function scheduleFor(int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, s.status AS sms_status, s.provider_status AS sms_provider_status, s.template_key AS sms_template_key, s.test_mode AS sms_test_mode, s.error AS sms_error, s.mobile AS sms_mobile,
                    s.attempts AS sms_attempts, s.message AS logged_message,
                    u.full_name AS scheduled_by_name, ru.full_name AS released_by_name
             FROM id_release_schedules r
             LEFT JOIN sms_logs s ON s.id = r.sms_log_id
             LEFT JOIN users u ON u.id = r.scheduled_by
             LEFT JOIN users ru ON ru.id = r.released_by
             WHERE r.application_id = ?'
        );
        $stmt->execute([$applicationId]);
        return $stmt->fetch() ?: null;
    }

    private function flashSmsResult(array $sms, string $prefix): void
    {
        flash($prefix . '.');
        // The text's outcome is a separate alert (green when sent, red with a Retry button when it failed).
        set_sms_notice(SMSService::outcomeNotice($sms, $prefix));
    }
}
