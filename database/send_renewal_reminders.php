<?php
/**
 * Scheduled job: sends a renewal-reminder SMS for every released ID
 * expiring within the configured window (default 30 days, see
 * admin/settings.php) that hasn't been reminded yet. Safe to run daily --
 * renewal_reminders.application_id is unique, so an application already
 * reminded is skipped on every later run.
 *
 * Intended to run unattended via Windows Task Scheduler, once a day:
 *   Program:   C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\virac-soloparent\database\send_renewal_reminders.php
 *
 * Can also be run manually:
 *   C:\xampp\php\php.exe database\send_renewal_reminders.php
 */
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/RenewalReminders.php';

$result = send_due_renewal_reminders($pdo);

if ($result['sent']) {
    audit_log($pdo, null, 'System (Scheduled Job)', 'system', 'Renewal Reminders Sent', 'system', null, implode(', ', $result['sent']));
}

echo "Checked {$result['checked']} expiring ID(s), sent " . count($result['sent']) . " reminder(s).\n";
if ($result['sent']) {
    echo "Reminded: " . implode(', ', $result['sent']) . "\n";
}
