<?php
/**
 * Thin delegation layer for admin/settings.php and the reminder cron
 * script; the real logic lives in services/AppointmentService.php.
 */
require_once __DIR__ . '/../services/AppointmentService.php';

const APPOINTMENT_DEFAULT_LOCATION = AppointmentService::DEFAULT_LOCATION;

function send_due_appointment_reminders(PDO $pdo): array
{
    return (new AppointmentService($pdo))->sendDueReminders();
}
