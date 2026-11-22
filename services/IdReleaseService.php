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
        if (!$dateTime || $dateTime->format('Y-m-d H:i') !== $date . ' ' . $time || $dateTime->getTimestamp() < strtotime(date('Y-m-d H:i'))) {
            return 'Choose a valid ID release date and time (now or later).';
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

    /**
     * Confirms the actual handover, once; scheduling alone never changes the application status. $details comes from the staff form:
     *   valid_from, expires_at  the start and expiration dates printed on the card (Y-m-d); the system counts from these;
     *   identity_checked       staff checked the Solo Parent's valid ID and handed the card to them in person (nobody else may claim it);
     *   override_reason        required only when a required document expired (or is no longer verified) while the ID waited.
     * Without $details (older callers) the dates are today and one validity period later, and such a document still blocks the release.
     */
    public function confirmRelease(array $staff, array $app, ?array $details = null): ?string
    {
        $id = (int) $app['id'];
        $validFrom = date('Y-m-d');
        $expiresAt = date('Y-m-d', strtotime($validFrom . ' +' . self::validityYears($this->pdo) . ' year'));
        $override = '';
        if ($details !== null) {
            $validFrom = trim((string) ($details['valid_from'] ?? ''));
            $expiresAt = trim((string) ($details['expires_at'] ?? ''));
            if ($error = self::datesError($validFrom, $expiresAt, true)) {
                return $error;
            }
            if (empty($details['identity_checked'])) {
                return 'Tick the box to confirm that you checked the Solo Parent\'s valid ID and handed the ID to them in person. Only the Solo Parent can receive the ID.';
            }
            $override = trim((string) ($details['override_reason'] ?? ''));
            if (mb_strlen($override) > 500) {
                return 'The reason is too long (up to 500 characters).';
            }
        }

        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM applications WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $current = $lock->fetch();
            if (!$current || $current['status'] !== 'Approved') {
                $this->pdo->rollBack();
                return 'Only an approved application can be confirmed as released.';
            }
            $outstanding = (new Document($this->pdo))->outstandingRequired($current);
            if ($outstanding && $details === null) {
                $this->pdo->rollBack();
                return 'Complete all required documents before releasing the ID.';
            }
            if ($outstanding && $override === '') {
                $this->pdo->rollBack();
                return 'A required document expired or is no longer verified (' . implode(', ', array_map(fn($item) => (string) $item['label'], $outstanding))
                    . '). Verify it first, or give the reason for releasing the ID anyway.';
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
            $this->applications->markIdReleased($id, (int) $staff['id'], $validFrom, $expiresAt);
            $actual = $this->pdo->prepare('SELECT released_at FROM id_release_schedules WHERE application_id = ?');
            $actual->execute([$id]);
            $releasedAt = (string) $actual->fetchColumn();
            $validity = 'Valid from ' . date('F j, Y', strtotime($validFrom)) . ' until ' . date('F j, Y', strtotime($expiresAt)) . ' (the dates on the card).';
            $note = 'MSWDO staff confirmed the Solo Parent ID was handed to the Solo Parent in person on ' . date('F j, Y g:i A', strtotime($releasedAt)) . '. ' . $validity
                . ($outstanding ? ' Released although a required document had expired: ' . $override : '');
            $this->applications->addHistory($id, 'ID Released', mb_substr($note, 0, 500), $staff['full_name'], (int) $staff['id']);
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
        $this->notifySoloParent((int) $app['user_id'], 'status_change',
            'Your Solo Parent ID (' . $app['reference_no'] . ') was handed to you. ' . $validity, $id);
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'ID Release Confirmed', 'application', $id,
            mb_substr('Handed to the Solo Parent in person at ' . date('M j, Y g:i A', strtotime($releasedAt)) . '. ' . $validity
                . ($outstanding ? ' Expired document override: ' . $override : ''), 0, 500));
        flash('ID release confirmed. ' . $validity . ' The Solo Parent gets one SMS on the expiration date.');
        return null;
    }

    /** Corrects the start and expiration dates of a released ID (a typing mistake), with the reason. Returns an error, or null. */
    public function correctDates(array $staff, array $app, string $validFrom, string $expiresAt, string $reason): ?string
    {
        $id = (int) $app['id'];
        $validFrom = trim($validFrom);
        $expiresAt = trim($expiresAt);
        $reason = trim($reason);
        if ($error = self::datesError($validFrom, $expiresAt, false)) {
            return $error;
        }
        if ($reason === '') {
            return 'Give the reason for correcting the ID dates.';
        }
        if (mb_strlen($reason) > 500) {
            return 'The reason is too long (up to 500 characters).';
        }
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT * FROM applications WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $current = $lock->fetch();
            if (!$current || $current['status'] !== 'ID Released') {
                $this->pdo->rollBack();
                return 'Only the dates of a released ID can be corrected.';
            }
            $oldFrom = (string) ($current['id_valid_from'] ?? '');
            $oldTo = (string) ($current['id_expires_at'] ?? '');
            if ($oldFrom === $validFrom && $oldTo === $expiresAt) {
                $this->pdo->rollBack();
                return 'These are already the dates on record.';
            }
            $this->applications->updateIdDates($id, $validFrom, $expiresAt);
            $text = 'ID dates corrected from ' . ($oldFrom !== '' ? date('M j, Y', strtotime($oldFrom)) : '(no start date)') . ' – '
                . ($oldTo !== '' ? date('M j, Y', strtotime($oldTo)) : '(no expiry)') . ' to ' . date('M j, Y', strtotime($validFrom)) . ' – '
                . date('M j, Y', strtotime($expiresAt)) . '. Reason: ' . $reason;
            $this->applications->addHistory($id, 'ID Released', mb_substr($text, 0, 500), $staff['full_name'], (int) $staff['id']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'ID Dates Corrected', 'application', $id, mb_substr($text, 0, 500));
        $this->notifySoloParent((int) $current['user_id'], 'status_change',
            'The dates of your Solo Parent ID (' . $current['reference_no'] . ') were corrected: valid from ' . date('F j, Y', strtotime($validFrom))
                . ' until ' . date('F j, Y', strtotime($expiresAt)) . '.', $id);
        flash('ID dates corrected: valid from ' . date('F j, Y', strtotime($validFrom)) . ' until ' . date('F j, Y', strtotime($expiresAt)) . '.');
        return null;
    }

    /**
     * Why these ID dates cannot be saved, or null. $forHandover: the start date may not be later than today and the ID must still be
     * valid. A span over five years is refused as a typing mistake; a span far from one year is only questioned by the page itself.
     */
    public static function datesError(string $validFrom, string $expiresAt, bool $forHandover): ?string
    {
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $validFrom);
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $expiresAt);
        if (!$from || $from->format('Y-m-d') !== $validFrom || !$to || $to->format('Y-m-d') !== $expiresAt) {
            return 'Enter the start date and the expiration date printed on the ID.';
        }
        if ($expiresAt <= $validFrom) {
            return 'The expiration date must be after the start date.';
        }
        if ($forHandover && $validFrom > date('Y-m-d')) {
            return 'The start date cannot be later than today.';
        }
        if ($forHandover && $expiresAt <= date('Y-m-d')) {
            return 'The expiration date must be after today.';
        }
        if ($to > $from->modify('+5 years')) {
            return 'Check the expiration date: it is more than five years after the start date.';
        }
        return null;
    }

    /** The ID validity in years (Settings: id_validity_years); used only to pre-fill the expiration date. */
    public static function validityYears(PDO $pdo): int
    {
        $years = (int) get_setting($pdo, 'id_validity_years', '1');
        return $years >= 1 && $years <= 5 ? $years : 1;
    }

    /** How many release dates have passed with the ID still at the office (the staff dashboard tile and menu badge). */
    public function overdueCount(): int
    {
        return count($this->overdue(500));
    }

    /** An in-app notice to the Solo Parent; never breaks the action (an account that no longer exists is skipped). */
    private function notifySoloParent(int $userId, string $type, string $message, int $applicationId): void
    {
        try {
            $exists = $this->pdo->prepare('SELECT 1 FROM users WHERE id = ?');
            $exists->execute([$userId]);
            if ($userId > 0 && $exists->fetchColumn()) {
                create_notification($this->pdo, $userId, $type, $message, $applicationId);
            }
        } catch (Throwable $e) {
            error_log('ID release notice not saved: ' . $e->getMessage());
        }
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
