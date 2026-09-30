<?php
/**
 * @var array      $user
 * @var ?array     $latestApplication
 * @var ?array     $idApplication
 * @var ?array     $idExpiryInfo
 * @var int        $unreadNotificationCount
 */
?>
      <div class="dash-welcome">Welcome back, <?= e(explode(' ', trim($user['full_name']))[0]) ?> 👋</div>
      <div class="dash-welcome-sub">Here's a quick look at your Solo Parent ID application.</div>

      <?php if ($unreadNotificationCount > 0): ?>
        <div class="info-box" style="max-width:none">🔔 You have <?= (int) $unreadNotificationCount ?> new notification<?= $unreadNotificationCount === 1 ? '' : 's' ?>. <a href="?section=notifications">View</a></div>
      <?php endif; ?>

      <?php if ($latestApplication): $st = status_style($latestApplication['status']); $homeStage = application_stage_info($latestApplication['status']); ?>
        <div class="dash-card">
          <div class="dash-card-label">Latest Application <span class="doc-tag" style="vertical-align:middle"><?= $latestApplication['type'] === 'renewal' ? 'RENEWAL' : 'NEW' ?></span></div>
          <div class="dash-status-row">
            <div>
              <div class="dash-status-ref"><?= e($latestApplication['reference_no']) ?></div>
              <div class="dash-status-cat"><?= e($latestApplication['category_name']) ?></div>
            </div>
            <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($latestApplication['status']) ?></div>
          </div>
          <div style="font-size:13px;color:var(--muted);margin:10px 0 14px">Current stage: <b style="color:var(--ink)"><?= e($homeStage['currentLabel']) ?></b></div>
          <?php if ($latestApplication['interview_at']): ?>
            <div class="info-box" style="max-width:none;margin-bottom:16px">📅 Interview scheduled for <b><?= e(date('F j, Y g:i A', strtotime($latestApplication['interview_at']))) ?></b> at <?= e($latestApplication['interview_location']) ?>.</div>
          <?php endif; ?>
          <a href="?section=application" class="btn-outline text-decoration-none dash-card-link">View Application</a>
        </div>
      <?php else: ?>
        <div class="dash-card">
          <div class="dash-card-label">No Application Yet</div>
          <div style="font-size:14px;color:var(--muted);margin:8px 0 14px">Start your Solo Parent ID application online in a few easy steps.</div>
          <a href="<?= base_url('portal.php?tab=apply') ?>" class="btn-red text-decoration-none">Apply Online Now</a>
        </div>
      <?php endif; ?>

      <?php if ($idApplication): $idSt = status_style($idApplication['status']); ?>
        <div class="dash-card">
          <div class="dash-card-label">Solo Parent ID Status</div>
          <div style="font-size:14px;color:var(--ink);margin:10px 0 14px">
            <?php if ($idApplication['status'] === 'ID Released' && $idExpiryInfo): ?>
              <?php if ($idExpiryInfo['expired']): ?>
                <span class="status-pill" style="background:#fde8e8;color:#a51c1c">Expired</span> <?= e(format_date_long($idExpiryInfo['date'])) ?> &mdash; please renew.
              <?php else: ?>
                <span class="status-pill" style="background:#dcf3e2;color:#1c7a3f">Valid</span> until <?= e(format_date_long($idExpiryInfo['date'])) ?>
              <?php endif; ?>
            <?php else: ?>
              <span class="status-pill" style="background:<?= e($idSt['bg']) ?>;color:<?= e($idSt['color']) ?>"><?= e($idApplication['status']) ?></span>
            <?php endif; ?>
          </div>
          <a href="?section=myid" class="btn-outline text-decoration-none dash-card-link">View My ID</a>
        </div>
      <?php endif; ?>

      <div class="dash-quick-grid">
        <a href="?section=eligibility" class="dash-quick-card">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
          Eligibility Checker
        </a>
        <a href="?section=appointment" class="dash-quick-card">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Appointment
        </a>
        <a href="?section=news" class="dash-quick-card">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h12v14a2 2 0 0 0 2 2H6a2 2 0 0 1-2-2V4z"/><path d="M18 8h2a2 2 0 0 1 2 2v9a1 1 0 0 1-1 1H8"/></svg>
          News &amp; Announcements
        </a>
        <a href="?section=chat" class="dash-quick-card">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          Chat Support
        </a>
      </div>
