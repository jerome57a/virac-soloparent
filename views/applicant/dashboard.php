<?php
/**
 * Applicant home: current application, appointment, ID and renewal status,
 * latest notifications, and next-step shortcuts. Everything is live data.
 *
 * @var array  $user
 * @var ?array $latestApplication
 * @var ?array $latestTracker        ApplicationTrackerService::track() for it
 * @var ?array $idApplication
 * @var ?array $idExpiryInfo
 * @var array  $renewal              state: none|valid|due|expired|in_progress (+ daysLeft, expiresAt, windowDays, application)
 * @var ?array $upcomingAppointment  application row with interview_at / interview_location
 * @var bool   $canBookAppointment
 * @var array  $recentNotifications
 * @var array  $quickActions         [label, url, icon]
 * @var ?array $eligibilityCheck     latest preliminary check: answers, result, checkedAt
 * @var int    $unreadNotificationCount
 * @var ?array $applicationDraft     saved, unsubmitted application (see application/draft-card.php)
 */
$icons = [
    'apply' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/>',
    'documents' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/>',
    'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    'renew' => '<path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/>',
    'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
    'chat' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
];
$notifIcons = [
    'registration' => '👋', 'submission' => '📨', 'appointment_confirmation' => '📅',
    'appointment_reminder' => '⏰', 'correction' => '⚠️', 'approval' => '✅',
    'rejection' => '❌', 'renewal_reminder' => '🔔',
];
?>
      <div class="dash-welcome">Welcome back, <?= e(explode(' ', trim($user['full_name']))[0]) ?> 👋</div>
      <div class="dash-welcome-sub">Here's a quick look at your Solo Parent ID application.</div>

      <?php if ($unreadNotificationCount > 0): ?>
        <div class="info-box" style="max-width:none">🔔 You have <?= (int) $unreadNotificationCount ?> new notification<?= $unreadNotificationCount === 1 ? '' : 's' ?>. <a href="?section=notifications">View</a></div>
      <?php endif; ?>

      <?php require __DIR__ . '/application/draft-card.php'; ?>

      <?php if ($latestApplication): $st = status_style($latestApplication['status']); ?>
        <div class="dash-card">
          <div class="dash-card-label">Current Application <span class="doc-tag" style="vertical-align:middle"><?= $latestApplication['type'] === 'renewal' ? 'RENEWAL' : 'NEW' ?></span></div>
          <div class="dash-status-row">
            <div>
              <div class="dash-status-ref"><?= e($latestApplication['reference_no']) ?></div>
              <div class="dash-status-cat"><?= e($latestApplication['category_name']) ?> · Submitted <?= e(date('M j, Y', strtotime($latestApplication['submitted_at']))) ?></div>
            </div>
            <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($latestApplication['status']) ?></div>
          </div>
          <?php
          $doneCount = count(array_filter($latestTracker['stages'], fn($s) => $s['state'] === 'done'));
          $stageCount = count($latestTracker['stages']);
          ?>
          <div style="font-size:13px;color:var(--muted);margin:0 0 6px">Current stage: <b style="color:var(--ink)"><?= e($latestTracker['current']) ?></b> · <?= $doneCount ?> of <?= $stageCount ?> steps done</div>
          <div class="tracker-mini" aria-hidden="true">
            <?php foreach ($latestTracker['stages'] as $s): ?><span class="<?= e($s['state']) ?>" title="<?= e($s['label']) ?>"></span><?php endforeach; ?>
          </div>
          <?php if ($latestTracker['correction']): ?>
            <div class="step-alert">⚠️ MSWDO has asked for corrections<?= $latestTracker['correction']['reason'] ? ': ' . e($latestTracker['correction']['reason']) : '' ?>. Open <a href="?section=documents">Upload Documents</a> to fix them.</div>
          <?php elseif ($latestTracker['rejected']): ?>
            <div class="step-alert">❌ This application was not approved<?= $latestTracker['rejected']['reason'] ? ': ' . e($latestTracker['rejected']['reason']) : '.' ?></div>
          <?php endif; ?>
          <a href="?section=application" class="btn-outline text-decoration-none dash-card-link">View Application</a>
        </div>
      <?php elseif (!$applicationDraft): // a saved draft already has its own "Continue" card above ?>
        <div class="dash-card">
          <div class="dash-card-label">No Application Yet</div>
          <?php if ($eligibilityCheck): ?>
            <div style="font-size:14px;color:var(--ink);margin:8px 0 4px">
              Your preliminary eligibility check (<?= e(date('M j, Y', strtotime($eligibilityCheck['checkedAt']))) ?>):
              <?php if ($eligibilityCheck['result']['eligible']): ?>
                <span class="status-pill" style="background:#dcf3e2;color:#1c7a3f">May be eligible</span>
              <?php else: ?>
                <span class="status-pill" style="background:#fdf1d6;color:#8a6d1a">May not meet the criteria</span>
              <?php endif; ?>
            </div>
            <div style="font-size:12px;color:var(--faint);margin-bottom:14px">Preliminary only — final eligibility is decided by MSWD.</div>
          <?php else: ?>
            <div style="font-size:14px;color:var(--muted);margin:8px 0 14px">Start your Solo Parent ID application online in a few easy steps.</div>
          <?php endif; ?>
          <div class="d-flex flex-wrap gap-2">
            <a href="<?= base_url('applicant/dashboard.php?section=apply') ?>" class="btn-red text-decoration-none">Apply Online Now</a>
          </div>
        </div>
      <?php endif; ?>

      <div class="dash-two-col">
        <div class="dash-card">
          <div class="dash-card-label">Appointment</div>
          <?php if ($upcomingAppointment): ?>
            <div style="font-size:16px;font-weight:700;color:var(--ink)"><?= e(date('l, F j, Y', strtotime($upcomingAppointment['interview_at']))) ?></div>
            <div style="font-size:14px;color:var(--ink);margin:2px 0"><?= e(date('g:i A', strtotime($upcomingAppointment['interview_at']))) ?></div>
            <div style="font-size:13px;color:var(--muted);margin-bottom:14px"><?= e($upcomingAppointment['interview_location']) ?> · <?= e($upcomingAppointment['reference_no']) ?></div>
            <a href="?section=appointment" class="btn-outline text-decoration-none dash-card-link">View or Reschedule</a>
          <?php elseif ($canBookAppointment): ?>
            <div style="font-size:14px;color:var(--muted);margin-bottom:14px">You don't have an appointment yet. Book an interview slot with MSWDO.</div>
            <a href="?section=appointment" class="btn-navy text-decoration-none dash-card-link">Book Appointment</a>
          <?php else: ?>
            <div style="font-size:14px;color:var(--muted)">No upcoming appointment.</div>
          <?php endif; ?>
        </div>

        <div class="dash-card">
          <div class="dash-card-label">Solo Parent ID &amp; Renewal</div>
          <?php if ($renewal['state'] === 'in_progress'): $rs = status_style($renewal['application']['status']); ?>
            <div style="font-size:14px;color:var(--ink);margin-bottom:6px">Renewal <b><?= e($renewal['application']['reference_no']) ?></b> is being processed.</div>
            <span class="status-pill" style="background:<?= e($rs['bg']) ?>;color:<?= e($rs['color']) ?>"><?= e($renewal['application']['status']) ?></span>
            <div class="mt-3"><a href="?section=renewal&type=status" class="btn-outline text-decoration-none dash-card-link">Renewal Status</a></div>
          <?php elseif ($renewal['state'] === 'none'): ?>
            <?php if ($idApplication): $idSt = status_style($idApplication['status']); ?>
              <div style="font-size:14px;color:var(--ink);margin-bottom:10px">Your ID is <span class="status-pill" style="background:<?= e($idSt['bg']) ?>;color:<?= e($idSt['color']) ?>"><?= e($idApplication['status']) ?></span> and will be released by MSWDO.</div>
            <?php else: ?>
              <div style="font-size:14px;color:var(--muted)">Your Solo Parent ID will appear here once MSWDO approves and releases it.</div>
            <?php endif; ?>
          <?php else: ?>
            <div style="font-size:14px;color:var(--ink);margin-bottom:4px">
              <?php if ($renewal['state'] === 'expired'): ?>
                <span class="status-pill" style="background:#fde8e8;color:#a51c1c">Expired</span> on <?= e(format_date_long($renewal['expiresAt'])) ?>
              <?php else: ?>
                <span class="status-pill" style="background:<?= $renewal['state'] === 'due' ? '#fdf1d6' : '#dcf3e2' ?>;color:<?= $renewal['state'] === 'due' ? '#8a6d1a' : '#1c7a3f' ?>"><?= $renewal['state'] === 'due' ? 'Expiring soon' : 'Valid' ?></span> until <?= e(format_date_long($renewal['expiresAt'])) ?>
              <?php endif; ?>
            </div>
            <div style="font-size:13px;color:var(--muted);margin-bottom:14px">
              <?php if ($renewal['state'] === 'expired'): ?>
                Renew now to keep your benefits.
              <?php elseif ($renewal['state'] === 'due'): ?>
                <?= (int) $renewal['daysLeft'] ?> day<?= $renewal['daysLeft'] === 1 ? '' : 's' ?> left — renewal is now open.
              <?php else: ?>
                <?= (int) $renewal['daysLeft'] ?> days left. Renewal opens <?= (int) $renewal['windowDays'] ?> days before expiry.
              <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <?php if (in_array($renewal['state'], ['due', 'expired'], true)): ?>
                <a href="?section=renewal&type=new" class="btn-red text-decoration-none dash-card-link">Renew My ID</a>
              <?php endif; ?>
              <a href="?section=myid" class="btn-outline text-decoration-none dash-card-link">View My ID</a>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="dash-card">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <div class="dash-card-label m-0">Recent Notifications</div>
          <a href="?section=notifications" style="font-size:13px">View all</a>
        </div>
        <?php if (!$recentNotifications): ?>
          <div style="font-size:14px;color:var(--muted);margin-top:8px">No notifications yet.</div>
        <?php endif; ?>
        <?php foreach ($recentNotifications as $n): ?>
          <div class="notif-preview <?= $n['is_read'] ? '' : 'unread' ?>">
            <span aria-hidden="true"><?= $notifIcons[$n['type']] ?? '🔔' ?></span>
            <div>
              <div><?= e($n['message']) ?></div>
              <div class="when"><?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="dash-section-title">Quick Actions</div>
      <div class="dash-quick-grid">
        <?php foreach ($quickActions as [$label, $url, $icon]): ?>
          <a href="<?= e($url) ?>" class="dash-quick-card">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icons[$icon] ?></svg>
            <?= e($label) ?>
          </a>
        <?php endforeach; ?>
      </div>
