<?php
/**
 * Applicant home: current application, appointment, ID and renewal status,
 * and latest notifications. Everything is live data.
 *
 * @var array  $user
 * @var bool   $isFirstLogin        true throughout the applicant's first sign-in session
 * @var ?array $latestApplication
 * @var ?array $latestTracker        ApplicationTrackerService::track() for it
 * @var ?array $idApplication
 * @var ?array $idExpiryInfo
 * @var array  $renewal              state: none|valid|due|expired|in_progress (+ daysLeft, expiresAt, windowDays, application)
 * @var ?array $upcomingAppointment  application row with interview_at / interview_location
 * @var bool   $canBookAppointment
 * @var bool   $bookingOpen          interview date booking is on and the applicant can choose a date now
 * @var ?array $nextStep             tone/title/text/button for the "what happens next" banner (null = none)
 * @var bool   $staffScheduled       the booked date was set by MSWD staff (the applicant may keep or change it)
 * @var array  $recentNotifications
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
    'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 2-3 4"/><path d="M12 17h.01"/>',
];
$notifIcons = [
    'registration' => 'apply', 'submission' => 'apply', 'appointment_confirmation' => 'calendar',
    'appointment_reminder' => 'calendar', 'correction' => 'documents', 'approval' => 'apply',
    'rejection' => 'apply', 'renewal_reminder' => 'renew',
];
$firstName = preg_split('/\s+/u', trim($user['full_name']))[0] ?? '';
$welcomeText = !empty($isFirstLogin) ? 'Welcome' : 'Welcome back';
?>
      <div class="applicant-home" data-i18n>
      <section class="dash-home-welcome" aria-labelledby="dashboard-welcome">
        <h2 class="dash-welcome" id="dashboard-welcome"><span data-i18n><?= e($welcomeText) ?></span><?= $firstName !== '' ? ', ' . e($firstName) : '' ?></h2>
        <p class="dash-welcome-sub" data-i18n><?= !empty($isFirstLogin) ? 'Welcome to your Solo Parent portal. Start your application and find the guidance you need here.' : 'Your application, appointments, and ID updates in one place.' ?></p>
      </section>

      <?php if (!empty($documentsToFix)): $fixN = count($documentsToFix); ?>
        <section class="fix-alert" role="alert" aria-labelledby="fix-alert-title">
          <div class="fix-alert-head">
            <span class="fix-alert-icon" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 20h20L12 3z"/><path d="M12 10v5M12 18h.01"/></svg></span>
            <div>
              <h2 id="fix-alert-title" class="fix-alert-title"><?= $fixN === 1 ? '1 document needs a new file' : $fixN . ' documents need a new file' ?></h2>
              <p class="fix-alert-sub">MSWDO could not accept <?= $fixN === 1 ? 'this document' : 'these documents' ?>. Tap <b>Upload new file</b> to read the reason and send a replacement. Your application cannot move forward until <?= $fixN === 1 ? 'it is' : 'they are' ?> fixed.</p>
            </div>
          </div>
          <ul class="fix-alert-list">
            <?php foreach ($documentsToFix as $fix): ?>
              <li class="fix-alert-item">
                <div class="fix-alert-text">
                  <strong><?= e($fix['label']) ?></strong>
                  <?php if (count(array_unique(array_column($documentsToFix, 'application_id'))) > 1): ?><span class="fix-alert-ref"><?= e($fix['reference_no']) ?></span><?php endif; ?>
                  <span class="fix-alert-reason"><?= $fix['remarks'] !== '' ? e(mb_strimwidth($fix['remarks'], 0, 140, '…')) : 'MSWDO asked for a clearer or correct file.' ?></span>
                </div>
                <a class="btn-navy fix-alert-btn" href="?section=documents#doc-<?= (int) $fix['application_id'] ?>-<?= e(preg_replace('/[^a-z0-9_-]/i', '', $fix['doc_key'])) ?>">Upload new file</a>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>

      <?php if (!empty($nextStep)): ?>
        <section class="next-banner next-banner--<?= e($nextStep['tone']) ?>" role="status" aria-labelledby="next-banner-title">
          <div class="next-banner-text"><h2 id="next-banner-title" class="next-banner-title"><?= e($nextStep['title']) ?></h2><p><?= e($nextStep['text']) ?></p></div>
          <?php if (!empty($nextStep['button'])): ?><a class="btn-navy next-banner-btn" href="<?= e($nextStep['button'][1]) ?>"><?= e($nextStep['button'][0]) ?></a><?php endif; ?>
        </section>
      <?php endif; ?>

      <?php require __DIR__ . '/application/draft-card.php'; ?>

      <?php if ($latestApplication): $st = status_style($latestApplication['status']); ?>
        <div class="dash-card dash-home-application">
          <h2 class="dash-card-label dash-home-application-heading" data-i18n>Current Application <span class="doc-tag" data-i18n><?= $latestApplication['type'] === 'renewal' ? 'RENEWAL' : 'NEW' ?></span></h2>
          <div class="dash-status-row">
            <div>
              <div class="dash-home-reference" data-i18n>Reference: <span><?= e($latestApplication['reference_no']) ?></span></div>
              <div class="dash-status-cat" data-i18n><?= e($latestApplication['category_name']) ?> · Submitted <?= e(date('M j, Y', strtotime($latestApplication['submitted_at']))) ?></div>
            </div>
            <div class="status-pill dash-home-status" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>" data-i18n><?= e($latestApplication['status']) ?></div>
          </div>
          <?php
          $doneCount = count(array_filter($latestTracker['stages'], fn($s) => $s['state'] === 'done'));
          $stageCount = count($latestTracker['stages']);
          $stageStateLabels = ['done' => 'Completed', 'active' => 'In progress', 'attention' => 'Needs your action', 'pending' => 'Not reached yet', 'stopped' => 'Stopped'];
          ?>
          <div class="dash-home-progress-summary">
            <div>
              <div class="dash-home-progress-caption" data-i18n>Current stage</div>
              <h3 class="dash-home-current-stage"><?= e($latestTracker['current']) ?></h3>
            </div>
            <span class="dash-home-progress-count" data-i18n><?= $doneCount ?> of <?= $stageCount ?> steps completed</span>
          </div>
          <ol class="dash-home-stages" aria-label="Application progress">
            <?php foreach ($latestTracker['stages'] as $stageIndex => $s): ?>
              <li class="dash-home-stage <?= e($s['state']) ?>"<?= $s['label'] === $latestTracker['current'] && !$latestTracker['rejected'] ? ' aria-current="step"' : '' ?>>
                <span class="dash-home-stage-marker" aria-hidden="true">
                  <?php if ($s['state'] === 'done'): ?>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg>
                  <?php elseif ($s['state'] === 'attention'): ?>!
                  <?php elseif ($s['state'] === 'stopped'): ?>&ndash;
                  <?php else: ?><?= $stageIndex + 1 ?><?php endif; ?>
                </span>
                <div class="dash-home-stage-text">
                  <span class="dash-home-stage-label" data-i18n><?= e($s['label']) ?></span>
                  <span class="dash-home-stage-state"><?= e($stageStateLabels[$s['state']]) ?></span>
                </div>
              </li>
            <?php endforeach; ?>
          </ol>
          <?php if ($latestTracker['correction'] && empty($documentsToFix)): ?>
            <div class="step-alert" role="alert">
              <b data-i18n>Action needed:</b> MSWDO has asked for corrections<?= $latestTracker['correction']['reason'] ? ': ' . e($latestTracker['correction']['reason']) : '' ?>.
              <div class="mt-2"><a href="?section=documents" class="btn-red btn-sm" style="text-decoration:none;color:#fff" data-i18n>Fix My Documents</a></div>
            </div>
          <?php elseif ($latestTracker['rejected']): ?>
            <div class="step-alert" role="alert"><b data-i18n>Not approved:</b> This application was not approved<?= $latestTracker['rejected']['reason'] ? ': ' . e($latestTracker['rejected']['reason']) : '.' ?></div>
          <?php endif; ?>
          <a href="?section=application" class="btn-outline text-decoration-none dash-card-link" data-i18n>View Application</a>
        </div>
      <?php elseif (!$applicationDraft): // a saved draft already has its own "Continue" card above ?>
        <div class="dash-card">
          <h2 class="dash-card-label" data-i18n>Get started with your application</h2>
          <p class="dash-home-body" data-i18n>Complete your personal and family details, upload the required documents, and submit your application.</p>
          <?php if ($eligibilityCheck): ?>
            <div style="font-size:14px;color:var(--ink);margin:8px 0 4px">
              Your preliminary eligibility check (<?= e(date('M j, Y', strtotime($eligibilityCheck['checkedAt']))) ?>):
              <?php if (($eligibilityCheck['result']['status'] ?? '') === 'NEEDS_GUIDANCE'): ?>
                <span class="status-pill" style="background:#fdf1d6;color:#7a5f12" data-i18n>MSWDO guidance needed</span>
              <?php elseif ($eligibilityCheck['result']['eligible']): ?>
                <span class="status-pill" style="background:#dcf3e2;color:#1c7a3f" data-i18n>May be eligible</span>
              <?php else: ?>
                <span class="status-pill" style="background:#fdf1d6;color:#7a5f12" data-i18n>May not meet the criteria</span>
              <?php endif; ?>
            </div>
            <div style="font-size:12px;color:var(--faint);margin-bottom:14px" data-i18n>Preliminary only — final eligibility is decided by MSWD.</div>
          <?php else: ?>
            <div style="font-size:14px;color:var(--muted);margin:8px 0 14px" data-i18n>Start your Solo Parent ID application online in a few easy steps.</div>
          <?php endif; ?>
          <div class="d-flex flex-wrap gap-2">
            <a href="<?= base_url('applicant/dashboard.php?section=apply') ?>" class="btn-navy text-decoration-none dash-card-link" data-i18n>Start Application</a>
          </div>
        </div>
      <?php endif; ?>

      <div class="dash-two-col">
        <div class="dash-card">
          <h2 class="dash-card-label dash-home-card-heading" data-i18n><svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icons['calendar'] ?></svg>Appointment</h2>
          <?php if ($upcomingAppointment): ?>
            <div class="dash-home-appointment-date">
              <div class="dash-home-date-tile" aria-hidden="true"><span><?= e(date('M', strtotime($upcomingAppointment['interview_at']))) ?></span><b><?= e(date('j', strtotime($upcomingAppointment['interview_at']))) ?></b></div>
              <?php $apptDay = date('Y-m-d', strtotime($upcomingAppointment['interview_at'])); $apptIn = (int) round((strtotime($apptDay) - strtotime(date('Y-m-d'))) / 86400); ?>
              <div><div class="dash-home-date-label"><?= e(date('l, F j, Y', strtotime($upcomingAppointment['interview_at']))) ?> <span class="dash-home-when"><?= $apptIn <= 0 ? 'Today' : ($apptIn === 1 ? 'Tomorrow' : 'In ' . $apptIn . ' days') ?></span></div><?php if (!interview_is_date_only($upcomingAppointment, 'interview_date_only') || date('His', strtotime($upcomingAppointment['interview_at'])) !== '000000'): ?><div class="dash-home-time"><?= e(date('g:i A', strtotime($upcomingAppointment['interview_at']))) ?></div><?php else: ?><div class="dash-home-note">Come any time, 7:00 AM to 5:00 PM</div><?php endif; ?></div>
            </div>
            <dl class="dash-home-details"><div><dt data-i18n>Location</dt><dd><?= e($upcomingAppointment['interview_location'] ?: 'Contact MSWDO for the location') ?></dd></div><div><dt data-i18n>Application</dt><dd><?= e($upcomingAppointment['reference_no']) ?></dd></div></dl>
            <?php $preparationItems = $upcomingRequirements ?? []; require APP_ROOT . '/views/partials/requirements-preparation.php'; ?>
            <a href="?section=appointment" class="btn-outline text-decoration-none dash-card-link"><?= !empty($staffScheduled) ? 'View or change date' : 'View Appointment' ?></a>
          <?php elseif ($canBookAppointment && !empty($bookingOpen)): ?>
            <p class="dash-home-body">Interview booking is open. Choose your interview date from the available dates.</p>
            <a href="?section=appointment" class="btn-navy text-decoration-none dash-card-link">Choose Interview Date</a>
          <?php elseif ($canBookAppointment): ?>
            <p class="dash-home-body" data-i18n>MSWDO will arrange your interview after all required documents are uploaded and verified. Replace any document that needs correction; MSWDO staff must verify the replacement before your interview can be scheduled. You can view your interview schedule in your account.</p>
            <a href="?section=appointment" class="btn-navy text-decoration-none dash-card-link" data-i18n>View Interview Status</a>
          <?php else: ?>
            <div class="dash-home-empty"><h3 data-i18n>No upcoming appointment</h3><p data-i18n>MSWDO will notify you when your interview is scheduled.</p></div>
          <?php endif; ?>
        </div>

        <?php require APP_ROOT . '/views/partials/dashboard-id-card.php'; ?>
      </div>

      <div class="dash-card dash-home-notifications">
        <div class="dash-home-notification-heading">
          <h2 class="dash-card-label m-0">Recent Notifications<?php if ($unreadNotificationCount > 0): ?> <span class="dash-home-unread-count"><?= (int) $unreadNotificationCount ?> unread</span><?php endif; ?></h2>
          <a href="?section=notifications" class="dash-home-view-all" data-i18n>View all<span class="visually-hidden" data-i18n> notifications</span></a>
        </div>
        <?php if (!$recentNotifications): ?>
          <div class="dash-home-empty"><h3 data-i18n>You're all caught up</h3><p data-i18n>Updates about your application, documents, and appointments will appear here.</p></div>
        <?php endif; ?>
        <?php foreach (array_slice($recentNotifications, 0, 3) as $n): ?>
          <a href="?section=notifications&amp;open=<?= (int) $n['id'] ?>" class="notif-preview dash-home-notification <?= $n['is_read'] ? '' : 'unread' ?>">
            <span class="dash-home-action-icon" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$notifIcons[$n['type']] ?? 'bell'] ?></svg></span>
            <div class="dash-home-notification-text">
              <?php if (!$n['is_read']): ?><span class="dash-home-unread-label" data-i18n>Unread</span><?php endif; ?>
              <div><?= e($n['message']) ?></div>
              <div class="when"><?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      </div>
