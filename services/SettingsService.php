<?php
require_once __DIR__ . '/AppointmentService.php';

/** Admin-editable runtime settings (system_settings table), with their defaults and allowed ranges. */
class SettingsService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Current values, falling back to code defaults for anything never saved. */
    public function current(): array
    {
        return [
            'appointment_location' => get_setting($this->pdo, 'appointment_location', AppointmentService::DEFAULT_LOCATION),
            'id_validity_years' => (int) get_setting($this->pdo, 'id_validity_years', (string) ID_VALIDITY_YEARS),
            'renewal_reminder_days' => (int) get_setting($this->pdo, 'renewal_reminder_days', '30'),
            'appointment_reminder_hours' => (int) get_setting($this->pdo, 'appointment_reminder_hours', '24'),
            'appointment_default_capacity' => (int) get_setting($this->pdo, 'appointment_default_capacity', '10'),
            'document_max_size_mb' => (float) get_setting($this->pdo, 'document_max_size_mb', (string) round(MAX_UPLOAD_BYTES / 1024 / 1024, 1)),
        ];
    }

    /** Validates and saves every setting at once. Returns an error message, or null on success. */
    public function save(array $admin, array $input): ?string
    {
        $location = trim((string) ($input['appointment_location'] ?? ''));
        $idValidityYears = (int) ($input['id_validity_years'] ?? 0);
        $reminderDays = (int) ($input['renewal_reminder_days'] ?? 0);
        $apptReminderHours = (int) ($input['appointment_reminder_hours'] ?? 0);
        $apptDefaultCapacity = (int) ($input['appointment_default_capacity'] ?? 0);
        $docMaxSizeMb = (float) ($input['document_max_size_mb'] ?? 0);

        if ($location === '') {
            return 'Enter a default appointment location.';
        }
        if ($idValidityYears < 1 || $idValidityYears > 10) {
            return 'ID validity years must be between 1 and 10.';
        }
        if ($reminderDays < 1 || $reminderDays > 180) {
            return 'Renewal reminder period must be between 1 and 180 days.';
        }
        if ($apptReminderHours < 1 || $apptReminderHours > 168) {
            return 'Appointment reminder window must be between 1 and 168 hours.';
        }
        if ($apptDefaultCapacity < 1 || $apptDefaultCapacity > 100) {
            return 'Default appointment capacity must be between 1 and 100.';
        }
        if ($docMaxSizeMb < 0.5 || $docMaxSizeMb > 20) {
            return 'Document max size must be between 0.5 and 20 MB.';
        }

        set_setting($this->pdo, 'appointment_location', $location);
        set_setting($this->pdo, 'id_validity_years', (string) $idValidityYears);
        set_setting($this->pdo, 'renewal_reminder_days', (string) $reminderDays);
        set_setting($this->pdo, 'appointment_reminder_hours', (string) $apptReminderHours);
        set_setting($this->pdo, 'appointment_default_capacity', (string) $apptDefaultCapacity);
        set_setting($this->pdo, 'document_max_size_mb', (string) $docMaxSizeMb);
        audit_log($this->pdo, (int) $admin['id'], $admin['full_name'], $admin['role'], 'System Settings Updated');
        return null;
    }

    /** Last 10 renewal reminders sent. */
    public function recentRenewalReminders(): array
    {
        return $this->pdo->query(
            'SELECT r.sent_at, a.reference_no, a.full_name, a.id_expires_at
             FROM renewal_reminders r JOIN applications a ON a.id = r.application_id
             ORDER BY r.sent_at DESC LIMIT 10'
        )->fetchAll();
    }

    /** Last 10 appointment reminders sent. */
    public function recentAppointmentReminders(): array
    {
        return $this->pdo->query(
            'SELECT i.reminder_sent_at, a.reference_no, a.full_name, i.scheduled_at
             FROM interviews i JOIN applications a ON a.id = i.application_id
             WHERE i.reminder_sent_at IS NOT NULL
             ORDER BY i.reminder_sent_at DESC LIMIT 10'
        )->fetchAll();
    }
}
