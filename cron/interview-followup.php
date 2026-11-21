<?php
/**
 * Scheduled job: interviews nobody confirmed. A BOOKED interview that still has no recorded outcome two weekdays after its date
 * is released: the Solo Parent's booking reopens so they can choose a new day. It is not counted as a missed interview and no SMS
 * is sent. The staff dashboard runs the same check (at most every 10 minutes), so this job is optional.
 *
 * Schedule it hourly with Windows Task Scheduler:
 *   Program:   C:\xampp\php\php.exe
 *   Arguments: C:\xampp\htdocs\virac-soloparent\cron\interview-followup.php
 */
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../services/AppointmentService.php';

$released = (new AppointmentService($pdo))->releaseUnconfirmed();
echo date('Y-m-d H:i:s') . " [interview_followup] released {$released} unconfirmed interview(s).\n";
exit(0);
