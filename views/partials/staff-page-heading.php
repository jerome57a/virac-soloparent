<?php
require_once __DIR__ . '/../../includes/icons.php';
/** Content heading only. Staff navigation remains in the existing header/drawer. */
$staffHeadingPages = [
  'notifications.php' => ['Notifications', 'Read updates about applications, appointments and your account.'],
  'index.php' => ['Applications', 'Search, review and process Solo Parent ID applications and renewals.'],
  'applicants.php' => ['Solo Parent Records', 'Find a Solo Parent and open their account and application records.'],
  'appointments.php' => ['Appointment Management', 'Check schedules, record interview outcomes and see who still needs a schedule.'],
  'verification.php' => ['Document Verification Queue', 'Solo Parents whose documents are waiting for you. The oldest application is first.'],
  'follow_up.php' => ['Missed and Not Rebooked', 'Solo Parents who missed their interview and have not chosen a new date for 7 days. Call them; no SMS is sent.'],
  'sms.php' => ['Failed Texts', 'Texts to Solo Parents that did not go through. Retry them, or contact the Solo Parent another way.'],
  'reports.php' => ['Reports', 'Choose a report, filter its records, then print or download the results.'],
];
// What to do on each page, in two lines (the "What do I do here?" help under the title).
$staffHelp = [
  'notifications.php' => 'Updates about applications and appointments appear here. Open one to go to the page it is about.',
  'index.php' => 'Open an application to review it. Start with Pending Review. Each application page shows the next step to take.',
  'applicants.php' => 'Search by name or reference number, then open a Solo Parent to see their account, applications and history.',
  'appointments.php' => 'Schedule interviews for applications with verified documents, confirm who attended after the date, and check ID releases.',
  'verification.php' => 'Open an application, check each uploaded document, then mark it Verified or ask for a correction. The oldest is first.',
  'follow_up.php' => 'Call these Solo Parents. When one agrees to a new date, open the application and schedule the interview.',
  'sms.php' => 'Retry a failed text. If it keeps failing, contact the Solo Parent another way, such as a call.',
  'reports.php' => 'Pick a report, set the filters, then print or download the results.',
];
$staffScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$staffHeading = $staffHeadingPages[$staffScript] ?? null;
?>
<?php if ($staffHeading): ?>
<a class="staff-back-link" href="<?= e($ownDashboard ?? base_url('staff/dashboard.php')) ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10 6-6 6 6 6M4 12h16"/></svg><span data-i18n>Back to Dashboard</span></a>
<header class="personnel-page-heading staff-page-heading gi-heading"><span class="gi-chip gi-lg"><?= ui_icon(ui_page_icon($staffScript), 24) ?></span><div><h1 class="page-title" data-i18n><?= e($staffHeading[0]) ?></h1><p data-i18n><?= e($staffHeading[1]) ?></p>
  <?php if (isset($staffHelp[$staffScript])): ?><details class="gi-help"><summary><?= ui_icon('circle-help', 16) ?><span data-i18n>What do I do here?</span></summary><p data-i18n><?= e($staffHelp[$staffScript]) ?></p></details><?php endif; ?></div></header>
<?php endif; ?>
