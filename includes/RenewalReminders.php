<?php
/**
 * Thin delegation layer for the renewal-reminder cron script; the real
 * logic lives in services/RenewalService.php.
 */
require_once __DIR__ . '/../services/RenewalService.php';

function send_due_renewal_reminders(PDO $pdo): array
{
    return (new RenewalService($pdo))->sendDueReminders();
}
