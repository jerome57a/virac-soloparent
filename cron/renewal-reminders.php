<?php
/**
 * Scheduled job: the ID expiry-day message.
 *
 * Renewal opens on the day a Solo Parent ID expires, and this job tells the Solo Parent that day: every released ID whose expiry date
 * (Manila date, as entered by MSWDO staff from the card) is TODAY gets one in-app notification and one SMS ("expires today ... you can
 * renew online starting today", logged in sms_logs with the provider result). Nothing is sent before the expiry date, and nothing after
 * it by itself. An SMS that fails is retried by later runs the same day (up to RenewalReminder::MAX_ATTEMPTS). IDs that already have a
 * renewal filed, and deactivated accounts, are skipped. If the job did not run on the expiry day, MSWDO staff see the ID under Failed
 * Texts (and the admin under ID expiry SMS not sent) and press Send now, which sends "expired on <date>".
 * Each message is recorded in renewal_reminders and each run in scheduled_job_runs; it is safe to run as often as you like.
 *
 * Schedule it daily with Windows Task Scheduler:
 *   Program:   C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\virac-soloparent\cron\renewal-reminders.php
 * or cron:  0 8 * * *  php /path/to/virac-soloparent/cron/renewal-reminders.php
 */
require __DIR__ . '/bootstrap.php';
run_scheduled_job($pdo, 'renewal_reminders');