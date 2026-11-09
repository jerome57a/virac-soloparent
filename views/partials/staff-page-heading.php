<?php
/** Content heading only. Staff navigation remains in the existing header/drawer. */
$staffHeadingPages = [
  'notifications.php' => ['Notifications', 'Read updates about applications, appointments and your account.'],
  'index.php' => ['Applications', 'Search, review and process Solo Parent ID applications and renewals.'],
  'applicants.php' => ['Applicant Records', 'Find a Solo Parent and open their account and application records.'],
  'appointments.php' => ['Appointment Management', 'Check schedules, record interview outcomes and manage available slots.'],
  'qr_verify.php' => ['QR Verification', 'Scan a QR code or enter a slip code or reference number to verify a record.'],
  'chat.php' => ['Chat Support', 'Open an applicant conversation to read their question and reply.'],
  'reports.php' => ['Reports', 'Choose a report, filter its records, then print or download the results.'],
];
$staffHeading = $staffHeadingPages[basename($_SERVER['SCRIPT_NAME'] ?? '')] ?? null;
?>
<?php if ($staffHeading): ?>
<a class="staff-back-link" href="<?= e($ownDashboard ?? base_url('staff/dashboard.php')) ?>"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m10 6-6 6 6 6M4 12h16"/></svg><span data-i18n>Back to Dashboard</span></a>
<header class="personnel-page-heading staff-page-heading"><div><h1 class="page-title" data-i18n><?= e($staffHeading[0]) ?></h1><p data-i18n><?= e($staffHeading[1]) ?></p></div></header>
<?php endif; ?>
