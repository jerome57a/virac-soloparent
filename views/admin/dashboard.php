<?php
/** Administrator overview. ReportService supplies all live counts; application links are monitoring only. */
$trend = (int) $summary['thisMonth'] - (int) $summary['lastMonth'];
$pending = (int) $stats['pendingNew'] + (int) $stats['pendingRenewals'];
$attentionCount = ($stats['smsFailed'] > 0 ? 1 : 0) + ($userStats['locked'] > 0 ? 1 : 0);
$dashboardIcons = [
    'applications' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5"/>',
    'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'id' => '<rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="8" cy="10" r="2"/><path d="M5 16c0-3 6-3 6 0M14 9h5M14 13h5"/>',
    'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M8 14h2M14 14h2"/>',
    'reports' => '<path d="M4 3v18h17M8 16v-4M13 16V8M18 16V5"/>',
    'settings' => '<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3"/><circle cx="15" cy="17" r="3"/>',
    'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    'search' => '<circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 5 5"/>',
];
$dashboardIcon = static fn(string $key): string => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $dashboardIcons[$key] . '</svg>';
$applicationRows = [
    ['New applications awaiting review', 'pendingNew', 'type=new&status=Pending+Review', 'amber'],
    ['Renewals awaiting review', 'pendingRenewals', 'type=renewal&status=Pending+Review', 'amber'],
    ['Under Verification', 'underVerification', 'status=Under+Verification', 'blue'],
    ['Needs Correction', 'forRevision', 'status=For+Revision', 'orange'],
    ['Interview scheduled', 'interviewScheduled', 'status=Interview+Scheduled', 'blue'],
    ['Approved, ID not yet released', 'approvedAwaitingId', 'status=Approved', 'green'],
    ['ID released', 'idReleased', 'status=ID+Released', 'green'],
    ['Rejected', 'rejectedApps', 'status=Rejected', 'red'],
];
$shortcuts = [
    ['Solo Parent Records', 'Find a Solo Parent and view their records.', 'applicants.php', 'id'],
    ['User Management', 'Manage accounts, roles, and access.', 'users.php', 'users'],
    ['Reports', 'View reports and export records.', 'reports.php', 'reports'],
    ['System Settings', 'Update service settings and requirements.', 'settings.php', 'settings'],
];
?>
<link rel="stylesheet" href="<?= public_asset('css/admin-dashboard.css') ?>">
<div class="portal-container admin-dashboard" id="admin-dashboard">
  <header class="ad-heading personnel-page-heading">
    <div><p class="ad-eyebrow" data-i18n>Administration</p><h1 class="page-title" data-i18n>MSWDO Admin Dashboard</h1><p class="ad-description" data-i18n>Monitor applications, manage services, and keep the system running smoothly.</p></div>
    <div class="ad-heading-meta"><span class="ad-date"><?= $dashboardIcon('calendar') ?><time datetime="<?= e(date('Y-m-d')) ?>"><?= e(date('M j, Y')) ?></time></span><span class="ad-signed-in"><span data-i18n>Signed in as</span> <strong><?= e($staff['full_name']) ?></strong></span></div>
  </header>
  <section id="admin-overview" class="ad-overview" aria-label="Overview" data-i18n-attrs="aria-label">
    <div class="ad-section-heading"><span data-i18n>All-time totals unless noted</span></div>
    <div class="ad-metrics stat-grid">
      <a class="ad-metric stat-tile" href="<?= base_url('admin/applications.php') ?>"><span class="ad-metric-top"><span data-i18n>Total Applications</span><span class="ad-icon ad-blue"><?= $dashboardIcon('applications') ?></span></span><strong><?= number_format((int) $stats['totalApplications']) ?></strong><span class="ad-metric-bottom"><span><b><?= number_format((int) $stats['totalRenewals']) ?></b> <span data-i18n>renewals included</span></span><?= $dashboardIcon('arrow') ?></span></a>
      <a class="ad-metric stat-tile" href="<?= base_url('admin/applications.php?status=Pending+Review') ?>"><span class="ad-metric-top"><span data-i18n>Awaiting review</span><span class="ad-icon ad-amber"><?= $dashboardIcon('clock') ?></span></span><strong><?= number_format($pending) ?></strong><span class="ad-metric-bottom"><span data-i18n>New applications and renewals</span><?= $dashboardIcon('arrow') ?></span></a>
      <a class="ad-metric stat-tile" href="<?= base_url('admin/users.php?role=applicant') ?>"><span class="ad-metric-top"><span data-i18n>Registered Solo Parents</span><span class="ad-icon ad-green"><?= $dashboardIcon('users') ?></span></span><strong><?= number_format((int) $userStats['applicants']) ?></strong><span class="ad-metric-bottom"><span><b><?= number_format((int) $userStats['newThisMonth']) ?></b> <span data-i18n>new this month</span></span><?= $dashboardIcon('arrow') ?></span></a>
      <a class="ad-metric stat-tile" href="<?= base_url('admin/reports.php?type=expiring') ?>"><span class="ad-metric-top"><span data-i18n>Expiring IDs</span><span class="ad-icon ad-orange"><?= $dashboardIcon('id') ?></span></span><strong><?= number_format((int) $stats['expiringIds']) ?></strong><span class="ad-metric-bottom"><span><span data-i18n>Within the next</span> <?= (int) $stats['expiringWindow'] ?> <span data-i18n>days</span></span><?= $dashboardIcon('arrow') ?></span></a>
    </div>
  </section>
  <div class="ad-workspace dash-two-col">
    <section class="ad-panel review-card" id="admin-applications" aria-labelledby="admin-applications-title">
      <div class="ad-panel-heading review-card-head"><div><h2 id="admin-applications-title" data-i18n>Application overview</h2><p data-i18n>Select a status to view matching applications.</p></div><a class="ad-text-link" href="<?= base_url('admin/applications.php') ?>"><span data-i18n>View all</span><?= $dashboardIcon('arrow') ?></a></div>
      <form class="ad-search" method="get" action="<?= base_url('admin/applications.php') ?>" role="search">
        <label class="ad-search-label" for="admin-application-search" data-i18n>Find an application</label>
        <div class="ad-search-controls"><span><?= $dashboardIcon('search') ?></span><input id="admin-application-search" type="search" name="q" placeholder="Name or application reference" maxlength="100" data-i18n-attrs="placeholder"><button type="submit" data-i18n>Search</button></div>
      </form>
      <div class="ad-status-list">
        <?php foreach ($applicationRows as [$label, $key, $query, $tone]): ?>
          <a href="<?= e(base_url('admin/applications.php?' . $query)) ?>" class="ad-status-row"><span class="ad-status-name"><span class="ad-status-dot ad-<?= e($tone) ?>" aria-hidden="true"></span><span data-i18n><?= e($label) ?></span></span><strong><?= number_format((int) $stats[$key]) ?></strong><?= $dashboardIcon('arrow') ?></a>
        <?php endforeach; ?>
      </div>
      <div class="ad-trend-summary">
        <div><span data-i18n>Applications this month</span><strong><?= number_format((int) $summary['thisMonth']) ?></strong><small><span><?= $trend > 0 ? '+' : '' ?><?= number_format($trend) ?></span> <span data-i18n>compared with last month</span><span> (<?= number_format((int) $summary['lastMonth']) ?>)</span></small></div>
        <div><span data-i18n>Approval Rate</span><strong><?= $summary['approvalRate'] === null ? '—' : (int) $summary['approvalRate'] . '%' ?></strong><small><?php if ($summary['approvalRate'] === null): ?><span data-i18n>No decided applications yet</span><?php else: ?><span data-i18n>Based on decided applications</span><span> (<?= number_format((int) $summary['decided']) ?>)</span><?php endif; ?></small></div>
      </div>
    </section>
      <div class="ad-stack">
      <section class="ad-panel ad-attention review-card" aria-labelledby="admin-attention-title">
        <div class="ad-panel-heading review-card-head"><div><h2 id="admin-attention-title" data-i18n>Needs attention</h2><p data-i18n>Account access and message delivery.</p></div><span class="ad-attention-count<?= !$attentionCount ? ' is-clear' : '' ?>"><b><?= $attentionCount ?></b><span data-i18n>to check</span></span></div>
        <?php if (!$attentionCount): ?><p class="ad-clear-message" data-i18n>No failed SMS or locked accounts recorded.</p><?php endif; ?>
        <a class="ad-attention-row" href="<?= base_url('admin/sms.php?status=failed') ?>"><span><strong data-i18n>Failed SMS</strong><small data-i18n>Check messages that were not sent.</small></span><b class="<?= $stats['smsFailed'] > 0 ? 'ad-count-warn' : '' ?>"><?= number_format((int) $stats['smsFailed']) ?></b><?= $dashboardIcon('arrow') ?></a>
        <a class="ad-attention-row" href="<?= base_url('admin/users.php?status=locked') ?>"><span><strong data-i18n>Locked accounts</strong><small data-i18n>Review accounts after failed sign-ins.</small></span><b class="<?= $userStats['locked'] > 0 ? 'ad-count-warn' : '' ?>"><?= number_format((int) $userStats['locked']) ?></b><?= $dashboardIcon('arrow') ?></a>
      </section>
  <details class="ad-panel ad-details review-card" open>
    <summary><span><strong data-i18n>System &amp; account details</strong><span data-i18n>MSWDO staff accounts, SMS totals, and security activity</span></span><span class="ad-details-toggle" aria-hidden="true">+</span></summary>
    <div class="ad-details-grid">
      <section><h3 data-i18n>Accounts</h3><dl>
        <div><dt data-i18n>Active Accounts</dt><dd><?= number_format((int) $stats['activeUsers']) ?></dd></div>
        <div><dt data-i18n>Deactivated accounts</dt><dd><?= number_format((int) $userStats['deactivated']) ?></dd></div>
        <div><dt data-i18n>MSWDO Staff</dt><dd><?= number_format((int) $userStats['mswdStaff']) ?></dd></div>
        <div><dt data-i18n>MSWDO Admins</dt><dd><?= number_format((int) $userStats['admins']) ?></dd></div>
      </dl><a class="ad-text-link" href="<?= base_url('admin/users.php') ?>" data-i18n>Manage Users</a></section>
      <section><h3 data-i18n>SMS delivery</h3><dl>
        <div><dt><a href="<?= base_url('admin/sms.php?status=sent') ?>" data-i18n>Sent</a></dt><dd><?= number_format((int) $stats['smsSent']) ?></dd></div>
        <div><dt><a href="<?= base_url('admin/sms.php?status=pending') ?>" data-i18n>Pending</a></dt><dd><?= number_format((int) $stats['smsPending']) ?></dd></div>
      </dl><a class="ad-text-link" href="<?= base_url('admin/sms.php') ?>" data-i18n>View SMS logs</a></section>
      <section><h3 data-i18n>Security activity</h3><dl>
        <div><dt data-i18n>Audit events today</dt><dd><?= number_format((int) $auditStats['eventsToday']) ?></dd></div>
        <div><dt data-i18n>Sign-ins today</dt><dd><?= number_format((int) $auditStats['loginsToday']) ?></dd></div>
        <div><dt data-i18n>Access denied (7 days)</dt><dd><?= number_format((int) $auditStats['accessDenied7d']) ?></dd></div>
        <div><dt data-i18n>Failed sign-ins / reset codes (7 days)</dt><dd><?= number_format((int) $auditStats['failedLogins7d']) ?></dd></div>
        <div><dt data-i18n>Password resets (7 days)</dt><dd><?= number_format((int) $auditStats['passwordResets7d']) ?></dd></div>
      </dl><a class="ad-text-link" href="<?= base_url('admin/audit_log.php') ?>" data-i18n>Audit Logs</a></section>
    </div>
  </details>
      </div>
  </div>
  <div class="ad-workspace ad-secondary-workspace ad-single">
  <section id="admin-activity" class="ad-panel ad-activity review-card" aria-labelledby="admin-activity-title">
    <div class="ad-panel-heading review-card-head"><div><h2 id="admin-activity-title" data-i18n>Recent Activity</h2><p data-i18n>Latest recorded actions across the system.</p></div><a class="ad-text-link" href="<?= base_url('admin/audit_log.php') ?>"><span data-i18n>View full audit log</span><?= $dashboardIcon('arrow') ?></a></div>
    <?php if (!$recentActivity): ?><div class="ad-empty"><span class="ad-icon ad-blue"><?= $dashboardIcon('clock') ?></span><strong data-i18n>No activity yet.</strong><p data-i18n>Recorded actions will appear here.</p></div><?php else: ?>
      <ol class="ad-activity-list">
        <?php foreach ($recentActivity as $log): ?>
          <li><span class="ad-activity-marker" aria-hidden="true"></span><div><strong><?= e($log['action']) ?></strong><span><?= e($log['actor_name']) ?></span></div><time datetime="<?= e(date('c', strtotime($log['created_at']))) ?>"><?= e(date('M j, Y · g:i A', strtotime($log['created_at']))) ?></time></li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>
  </div>
  <section id="admin-tools" class="ad-tools" aria-labelledby="admin-tools-title">
    <div class="ad-section-heading"><h2 id="admin-tools-title" data-i18n>Quick access</h2><span data-i18n>Common administration tasks</span></div>
    <div class="ad-tools-grid">
      <?php foreach ($shortcuts as [$label, $description, $route, $icon]): ?>
        <a class="ad-tool" href="<?= e(base_url('admin/' . $route)) ?>"><span class="ad-icon ad-blue"><?= $dashboardIcon($icon) ?></span><span><strong data-i18n><?= e($label) ?></strong><small data-i18n><?= e($description) ?></small></span><?= $dashboardIcon('arrow') ?></a>
      <?php endforeach; ?>
    </div>
  </section>
  <nav class="ad-section-nav" aria-label="Dashboard sections" data-i18n-attrs="aria-label">
    <a href="#admin-overview" data-i18n>Overview</a><a href="#admin-applications" data-i18n>Applications</a><a href="#admin-tools" data-i18n>Quick access</a><a href="#admin-activity" data-i18n>Recent Activity</a>
  </nav>
</div>
