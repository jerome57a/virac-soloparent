<?php
/** Presentation metadata only. Controllers remain responsible for authorization and data. */
return [
    'notifications.php' => ['Notifications', 'Review your notifications.', 'Main', ['Inbox' => 'admin-inbox']],
    'applicants.php' => ['Solo Parent Records', 'Find a Solo Parent, check their application history, and open their complete record.', 'Main', ['Search records' => 'admin-record-filters', 'Solo Parent list' => 'admin-record-list']],
    'appointments.php' => ['Appointment Overview', 'Monitor interview schedules and appointments awaiting a staff outcome.', 'Main', ["Today" => 'admin-appointments-today', 'Upcoming' => 'admin-appointments-upcoming']],
    'reports.php' => ['Reports', 'Choose a report, narrow the results, then print or download the records you need.', 'Main', ['Report types' => 'admin-report-types', 'Filters' => 'admin-report-filters', 'Results' => 'admin-report-results']],
    'eligibility_logs.php' => ['Eligibility Logs', 'Review preliminary eligibility checks and their results. These are guidance, not application decisions.', 'Administration', ['Filters' => 'admin-eligibility-filters', 'Results' => 'admin-eligibility-results']],
    'applications.php' => ['Application Monitoring', 'Track submitted applications and renewals. MSWD staff handle approval, corrections, and rejection.', 'Administration', ['Application status' => 'admin-application-status', 'Filters' => 'admin-application-filters', 'Results' => 'admin-application-results']],
    'users.php' => ['User Management', 'Find accounts, manage access, and create accounts for new users.', 'Administration', ['Accounts' => 'admin-user-list', 'Create Account' => 'admin-user-create']],
    'sms.php' => ['SMS Management', 'Check message delivery, review failed messages, and manage notification templates.', 'Administration', ['SMS Logs' => 'admin-sms-logs', 'Send a Test SMS' => 'admin-sms-test', 'Notification Templates' => 'admin-sms-templates']],
    'settings.php' => ['System Settings', 'Choose a settings section and save it separately. Changes are recorded in the audit log.', 'Administration', ['Settings sections' => 'admin-settings-sections', 'Reminder Jobs' => 'jobs', 'Server' => 'server']],
    'audit_log.php' => ['Audit Logs', 'Find who performed an action and when. Audit records are permanent and cannot be edited here.', 'Administration', ['Event category' => 'admin-audit-categories', 'Filters' => 'admin-audit-filters', 'Results' => 'admin-audit-results']],
];
