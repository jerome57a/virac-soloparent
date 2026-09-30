<?php
/**
 * Scheduled job: sends an appointment-reminder SMS + in-app notification
 * for every interview happening within the configured window (default 24
 * hours, see admin/settings.php) that hasn't been reminded yet. Safe to
 * run frequently -- interviews.reminder_sent_at is set once sent and only
 * reset to NULL when that interview is (re)scheduled.
 *
 * Intended to run unattended via Windows Task Scheduler, e.g. every hour:
 *   Program:   C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\virac-soloparent\database\send_appointment_reminders.php
 *
 * Can also be run manually:
 *   C:\xampp\php\php.exe database\send_appointment_reminders.php
 */
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/Appointments.php';

$result = send_due_appointment_reminders($pdo);

if ($result['sent']) {
    audit_log($pdo, null, 'System (Scheduled Job)', 'system', 'Appointment Reminders Sent', 'system', null, implode(', ', $result['sent']));
}

echo "Checked {$result['checked']} upcoming appointment(s), sent " . count($result['sent']) . " reminder(s).\n";
if ($result['sent']) {
    echo "Reminded: " . implode(', ', $result['sent']) . "\n";
}
