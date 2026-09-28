<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/data.php';
require __DIR__ . '/includes/QrService.php';
require __DIR__ . '/includes/uploads.php';
require __DIR__ . '/includes/RenewalSubmit.php';

$user = current_user($pdo);
if (!$user) {
    redirect(base_url('portal.php?tab=login'));
}

$validSections = ['dashboard', 'application', 'news', 'renewal', 'myid', 'notifications', 'documents', 'profile', 'help', 'settings'];
$section = $_GET['section'] ?? 'dashboard';
if (!in_array($section, $validSections, true)) {
    $section = 'dashboard';
}

$renewalTypeLabels = ['new' => 'Renew ID', 'status' => 'Renewal Status', 'history' => 'Renewal History'];
$renewalType = $_GET['type'] ?? '';
if (!isset($renewalTypeLabels[$renewalType])) {
    $renewalType = 'new';
}

$renewalSource = renewal_source_application($pdo, $user);
$renewalError = null;

if ($section === 'renewal' && $renewalType === 'new' && $_SERVER['REQUEST_METHOD'] === 'POST' && $renewalSource) {
    csrf_require();
    $renewalAction = $_POST['renewal_action'] ?? '';
    $draft = renewal_get_draft();

    if ($renewalAction === 'upload_doc') {
        $docKey = (string) ($_POST['doc_key'] ?? '');
        $result = handle_doc_upload($_FILES['file'] ?? [], renewal_tmp_dir());
        if ($result['ok']) {
            if (!empty($draft['documents'][$docKey]['path']) && is_file($draft['documents'][$docKey]['path'])) {
                @unlink($draft['documents'][$docKey]['path']);
            }
            $draft['documents'][$docKey] = $result['meta'];
            unset($draft['docErrors'][$docKey]);
        } else {
            $draft['docErrors'][$docKey] = $result['error'];
        }
        renewal_save_draft($draft);
        redirect(base_url('dashboard.php?section=renewal&type=new'));
    } elseif ($renewalAction === 'remove_doc') {
        $docKey = (string) ($_POST['doc_key'] ?? '');
        if (!empty($draft['documents'][$docKey]['path']) && is_file($draft['documents'][$docKey]['path'])) {
            @unlink($draft['documents'][$docKey]['path']);
        }
        unset($draft['documents'][$docKey], $draft['docErrors'][$docKey]);
        renewal_save_draft($draft);
        redirect(base_url('dashboard.php?section=renewal&type=new'));
    } elseif ($renewalAction === 'submit') {
        $errors = [];
        foreach (renewal_doc_defs($pdo) as $d) {
            if ($d['is_required'] && empty($draft['documents'][$d['doc_key']])) {
                $errors['doc_' . $d['doc_key']] = 'This document is required.';
            }
        }
        if (empty($_POST['consent'])) {
            $errors['consent'] = 'Please confirm to submit.';
        }
        if ($errors) {
            $draft['docErrors'] = $errors;
            renewal_save_draft($draft);
            $renewalError = 'Please attach all required documents and confirm to submit.';
        } else {
            try {
                $refNumber = renewal_finalize_submission($pdo, $user, $renewalSource, $draft['documents']);
                redirect(base_url('slip.php?ref=' . urlencode($refNumber)));
            } catch (Throwable $e) {
                $renewalError = 'Something went wrong while submitting your renewal. Please try again.';
            }
        }
    }
}

$renewalDraft = renewal_get_draft();
$renewalDocDefs = renewal_doc_defs($pdo);

$sectionTitles = [
    'dashboard'     => 'Dashboard',
    'application'   => 'My Application',
    'renewal'       => $renewalTypeLabels[$renewalType],
    'myid'          => 'My Solo Parent ID',
    'news'          => 'News & Announcements',
    'notifications' => 'Notifications',
    'documents'     => 'My Documents',
    'profile'       => 'My Profile',
    'help'          => 'Help & Support',
    'settings'      => 'Settings',
];

$appsStmt = $pdo->prepare(
    'SELECT a.*, c.name AS category_name, i.scheduled_at AS interview_at, i.location AS interview_location
     FROM applications a
     JOIN categories c ON c.id = a.category_id
     LEFT JOIN interviews i ON i.application_id = a.id
     WHERE a.user_id = ? ORDER BY a.submitted_at DESC'
);
$appsStmt->execute([$user['id']]);
$myApplications = $appsStmt->fetchAll();
$latestApplication = $myApplications[0] ?? null;

$idApplication = null;
foreach ($myApplications as $app) {
    if ($app['status'] === 'ID Released') {
        $idApplication = $app;
        break;
    }
}
if (!$idApplication) {
    foreach ($myApplications as $app) {
        if ($app['status'] === 'Approved') {
            $idApplication = $app;
            break;
        }
    }
}

$announcements = announcements_list();
$unreadAnnouncementCount = count_unread_announcements($announcements);

$activeAnnouncement = null;
if ($section === 'news' && isset($_GET['id'])) {
    $activeAnnouncementId = (int) $_GET['id'];
    foreach ($announcements as $a) {
        if ($a['id'] === $activeAnnouncementId) {
            $activeAnnouncement = $a;
            break;
        }
    }
    if ($activeAnnouncement) {
        mark_announcement_read($activeAnnouncement['id']);
        $unreadAnnouncementCount = count_unread_announcements($announcements);
    }
}

$docsStmt = $pdo->prepare(
    'SELECT d.*, a.reference_no, a.category_id, COALESCE(dr.label, d.doc_key) AS doc_label
     FROM application_documents d
     JOIN applications a ON a.id = d.application_id
     LEFT JOIN document_requirements dr ON dr.doc_key = d.doc_key AND (dr.category_id IS NULL OR dr.category_id = a.category_id)
     WHERE a.user_id = ?
     ORDER BY a.submitted_at DESC, d.uploaded_at'
);
$docsStmt->execute([$user['id']]);
$myDocumentsByApp = [];
foreach ($docsStmt->fetchAll() as $doc) {
    $myDocumentsByApp[$doc['reference_no']][] = $doc;
}

$notifStmt = $pdo->prepare(
    'SELECT s.* FROM sms_logs s JOIN applications a ON a.id = s.application_id
     WHERE a.user_id = ? ORDER BY s.sent_at DESC'
);
$notifStmt->execute([$user['id']]);
$myNotifications = $notifStmt->fetchAll();
$unreadNotificationCount = count_unread_notifications($myNotifications);

if ($section === 'notifications') {
    foreach ($myNotifications as $n) {
        mark_notification_read($n['id']);
    }
    $unreadNotificationCount = 0;
}

$pageTitle = $sectionTitles[$section] . ' — ' . APP_NAME;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= asset('css/style.css') ?>?v=<?= @filemtime(APP_ROOT . '/assets/css/style.css') ?: 1 ?>">
<link rel="stylesheet" href="<?= asset('css/dashboard.css') ?>?v=<?= @filemtime(APP_ROOT . '/assets/css/dashboard.css') ?: 1 ?>">
</head>
<body class="dash-body">

<div class="dash-sidebar-overlay" id="dash-sidebar-overlay" hidden></div>

<aside class="dash-sidebar" id="dash-sidebar">
  <div class="dash-sidebar-brand">
    <img src="<?= asset('images/solo-parent.png') ?>" alt="Virac Solo Parent ID">
    <div>
      <div class="dash-brand-name">Solo Parent ID</div>
      <div class="dash-brand-sub">e-SoloParentID Portal</div>
    </div>
    <button type="button" class="dash-sidebar-close" id="dash-sidebar-close" aria-label="Close menu">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="5" y1="5" x2="19" y2="19"/><line x1="19" y1="5" x2="5" y2="19"/></svg>
    </button>
  </div>

  <nav class="dash-nav">
    <div class="dash-nav-section">
      <div class="dash-nav-label">Main</div>

      <a href="?section=dashboard" class="dash-nav-link <?= $section === 'dashboard' ? 'active' : '' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg>
        <span>Dashboard</span>
      </a>

      <a href="<?= base_url('portal.php?tab=status') ?>" class="dash-nav-link">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <span>Check Status</span>
      </a>

      <a href="?section=renewal" class="dash-nav-link <?= $section === 'renewal' ? 'active' : '' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/></svg>
        <span>ID Renewal</span>
      </a>

      <a href="?section=myid" class="dash-nav-link <?= $section === 'myid' ? 'active' : '' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="12" r="2"/><line x1="14" y1="10" x2="18" y2="10"/><line x1="14" y1="14" x2="18" y2="14"/></svg>
        <span>My Solo Parent ID</span>
      </a>
    </div>

    <div class="dash-nav-section">
      <div class="dash-nav-label">Information</div>

      <a href="?section=news" class="dash-nav-link <?= $section === 'news' ? 'active' : '' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h12v14a2 2 0 0 0 2 2H6a2 2 0 0 1-2-2V4z"/><path d="M18 8h2a2 2 0 0 1 2 2v9a1 1 0 0 1-1 1H8"/><line x1="8" y1="8" x2="14" y2="8"/><line x1="8" y1="12" x2="14" y2="12"/><line x1="8" y1="15" x2="12" y2="15"/></svg>
        <span>News &amp; Announcements</span>
        <?php if ($unreadAnnouncementCount > 0): ?><span class="dash-nav-badge"><?= (int) $unreadAnnouncementCount ?></span><?php endif; ?>
      </a>

      <a href="?section=notifications" class="dash-nav-link <?= $section === 'notifications' ? 'active' : '' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
        <span>Notifications</span>
        <?php if ($unreadNotificationCount > 0): ?><span class="dash-nav-badge"><?= (int) $unreadNotificationCount ?></span><?php endif; ?>
      </a>
    </div>

    <div class="dash-nav-section">
      <div class="dash-nav-label">Account</div>

      <a href="?section=documents" class="dash-nav-link <?= $section === 'documents' ? 'active' : '' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/></svg>
        <span>My Documents</span>
      </a>

      <a href="?section=profile" class="dash-nav-link <?= $section === 'profile' ? 'active' : '' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        <span>My Profile</span>
      </a>

      <a href="?section=settings" class="dash-nav-link <?= $section === 'settings' ? 'active' : '' ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><circle cx="9" cy="6" r="2"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="15" cy="12" r="2"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="9" cy="18" r="2"/></svg>
        <span>Settings</span>
      </a>

      <form method="post" action="<?= base_url('logout.php') ?>" class="dash-nav-logout-form">
        <?= csrf_field() ?>
        <button type="submit" class="dash-nav-link dash-nav-logout">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          <span>Logout</span>
        </button>
      </form>
    </div>
  </nav>

  <div class="dash-sidebar-user">
    <div class="dash-user-avatar"><?= e(mb_strtoupper(mb_substr(trim($user['full_name']), 0, 1))) ?></div>
    <div>
      <div class="dash-user-name"><?= e($user['full_name']) ?></div>
      <div class="dash-user-role">Solo Parent Applicant</div>
    </div>
  </div>
</aside>

<div class="dash-main">
  <header class="dash-topbar">
    <button type="button" class="dash-sidebar-toggle" id="dash-sidebar-toggle" aria-label="Open menu">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div class="dash-topbar-title"><?= e($sectionTitles[$section]) ?></div>
  </header>

  <main class="dash-content">
    <?php if ($section === 'dashboard'): ?>

      <div class="dash-welcome">Welcome back, <?= e(explode(' ', trim($user['full_name']))[0]) ?> 👋</div>
      <div class="dash-welcome-sub">Here's a quick look at your Solo Parent ID application.</div>

      <?php if ($latestApplication): $st = status_style($latestApplication['status']); ?>
        <div class="dash-card">
          <div class="dash-card-label">Latest Application</div>
          <div class="dash-status-row">
            <div>
              <div class="dash-status-ref"><?= e($latestApplication['reference_no']) ?></div>
              <div class="dash-status-cat"><?= e($latestApplication['category_name']) ?></div>
            </div>
            <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($latestApplication['status']) ?></div>
          </div>
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

      <div class="dash-quick-grid">
        <a href="?section=news" class="dash-quick-card">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h12v14a2 2 0 0 0 2 2H6a2 2 0 0 1-2-2V4z"/><path d="M18 8h2a2 2 0 0 1 2 2v9a1 1 0 0 1-1 1H8"/></svg>
          News &amp; Announcements
        </a>
        <a href="<?= base_url('portal.php?tab=status') ?>" class="dash-quick-card">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          Check Status
        </a>
        <a href="<?= base_url('portal.php?tab=overview#benefits') ?>" class="dash-quick-card">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="12" r="2"/><line x1="14" y1="10" x2="18" y2="10"/><line x1="14" y1="14" x2="18" y2="14"/></svg>
          Benefits
        </a>
      </div>

    <?php elseif ($section === 'application'): ?>

      <?php if (!$myApplications): ?>
        <div class="dash-card">
          <div style="font-size:14px;color:var(--muted);margin-bottom:14px">You have not submitted any applications yet.</div>
          <a href="<?= base_url('portal.php?tab=apply') ?>" class="btn-red text-decoration-none">Apply Online Now</a>
        </div>
      <?php else: ?>
        <?php foreach ($myApplications as $app): $st = status_style($app['status']); ?>
          <div class="app-card">
            <div>
              <div class="app-ref"><?= e($app['reference_no']) ?></div>
              <div class="app-date">Submitted <?= e(date('M j, Y', strtotime($app['submitted_at']))) ?></div>
              <?php if ($app['interview_at']): ?>
                <div class="app-date">📅 Interview: <?= e(date('M j, Y g:i A', strtotime($app['interview_at']))) ?> · <?= e($app['interview_location']) ?></div>
              <?php endif; ?>
            </div>
            <div class="d-flex align-items-center flex-wrap gap-2">
              <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($app['status']) ?></div>
              <a href="<?= base_url('slip.php?ref=' . urlencode($app['reference_no'])) ?>" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px">View Slip</a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

    <?php elseif ($section === 'news'): ?>

      <?php if ($activeAnnouncement): ?>
        <div style="max-width:780px">
          <a href="?section=news" style="font-size:13px">&larr; Back to News &amp; Announcements</a>

          <div style="margin-top:14px;margin-bottom:20px">
            <span class="announcement-category"><?= e($activeAnnouncement['category']) ?></span>
            <h2 class="font-heading" style="font-size:24px;margin:10px 0 6px;color:var(--ink)"><?= e($activeAnnouncement['title']) ?></h2>
            <div style="font-size:13px;color:var(--muted)">Published <?= e(format_date_long($activeAnnouncement['published_at'])) ?></div>
          </div>

          <?php if (!empty($activeAnnouncement['image'])): ?>
            <img src="<?= asset('images/' . $activeAnnouncement['image']) ?>" alt="<?= e($activeAnnouncement['title']) ?>" style="width:100%;max-width:560px;height:auto;border-radius:12px;display:block;margin-bottom:20px">
          <?php endif; ?>

          <div class="body-copy" style="white-space:pre-line;max-width:none"><?= e($activeAnnouncement['details']) ?></div>

          <?php if (!empty($activeAnnouncement['attachment'])): ?>
            <a href="<?= asset('images/' . $activeAnnouncement['attachment']) ?>" target="_blank" rel="noopener" class="btn-outline text-decoration-none" style="display:inline-block;margin-top:8px;padding:10px 18px">📎 View Attachment</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <?php foreach ($announcements as $a): $unread = is_announcement_unread($a['id']); ?>
          <a href="?section=news&id=<?= $a['id'] ?>" class="announcement-card text-decoration-none">
            <div class="announcement-card-head">
              <span class="announcement-category"><?= e($a['category']) ?></span>
              <?php if ($unread): ?><span class="announcement-new">NEW</span><?php endif; ?>
            </div>
            <div class="announcement-title"><?= e($a['title']) ?></div>
            <div class="announcement-desc"><?= e($a['short_desc']) ?></div>
            <div class="announcement-date">Published <?= e(format_date_long($a['published_at'])) ?></div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>

    <?php elseif ($section === 'myid'): ?>

      <?php if ($idApplication): $st = status_style($idApplication['status']); $idStatusUrl = base_url('portal.php?tab=status&ref=' . urlencode($idApplication['reference_no'])); $idQrUri = qr_code_data_uri($idStatusUrl); ?>
        <div class="slip-card" style="margin:0 0 24px">
          <img src="<?= asset('images/solo-parent.png') ?>" alt="Virac Solo Parent ID" style="height:56px;object-fit:contain;margin-bottom:8px">
          <div style="font-size:13px;color:var(--muted)">MSWDO Virac — Solo Parent ID</div>
          <div class="ref"><?= e($idApplication['reference_no']) ?></div>

          <?php if ($idQrUri): ?>
            <img src="<?= e($idQrUri) ?>" alt="QR code" style="width:180px;height:180px;margin:12px auto">
          <?php else: ?>
            <div style="font-size:12px;color:var(--faint);margin:16px 0">Status check link: <?= e($idStatusUrl) ?></div>
          <?php endif; ?>

          <div class="text-start mt-3" style="font-size:14px">
            <div class="mb-1"><b>Applicant:</b> <?= e($idApplication['full_name']) ?></div>
            <div class="mb-1"><b>Category:</b> <?= e($idApplication['category_name']) ?></div>
            <div class="mb-3"><b>Status:</b> <span class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($idApplication['status']) ?></span></div>
          </div>

          <a href="<?= base_url('slip.php?ref=' . urlencode($idApplication['reference_no'])) ?>" target="_blank" class="btn-navy text-decoration-none">View Full Slip</a>
        </div>
      <?php elseif ($latestApplication): $st = status_style($latestApplication['status']); ?>
        <div class="dash-card">
          <div class="dash-card-label">ID Not Yet Issued</div>
          <div style="font-size:14px;color:var(--muted);margin-bottom:14px">
            Your application <b><?= e($latestApplication['reference_no']) ?></b> is currently
            <span class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($latestApplication['status']) ?></span>.
            Your Solo Parent ID will appear here once it's approved and released by MSWDO.
          </div>
          <a href="?section=application" class="btn-outline text-decoration-none">View Application</a>
        </div>
      <?php else: ?>
        <div class="dash-card">
          <div class="dash-card-label">No Solo Parent ID Yet</div>
          <div style="font-size:14px;color:var(--muted);margin:8px 0 14px">Apply for your Solo Parent ID to get started.</div>
          <a href="<?= base_url('portal.php?tab=apply') ?>" class="btn-red text-decoration-none">Apply Online Now</a>
        </div>
      <?php endif; ?>

    <?php elseif ($section === 'documents'): ?>

      <?php if (!$myDocumentsByApp): ?>
        <div class="dash-card">
          <div style="font-size:14px;color:var(--muted)">No documents on file yet. Documents you upload during your application will appear here.</div>
        </div>
      <?php else: ?>
        <?php foreach ($myDocumentsByApp as $refNo => $docs): ?>
          <div class="review-card">
            <div class="review-card-head"><div class="title"><?= e($refNo) ?></div></div>
            <?php foreach ($docs as $d): ?>
              <div class="d-flex justify-content-between align-items-center mb-2" style="font-size:14px">
                <span><?= e($d['doc_label']) ?> — <?= e($d['original_name']) ?></span>
                <a href="<?= base_url('download.php?doc_id=' . (int) $d['id']) ?>" target="_blank" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px">View</a>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

    <?php elseif ($section === 'notifications'): ?>

      <?php if (!$myNotifications): ?>
        <div class="dash-card">
          <div style="font-size:14px;color:var(--muted)">No notifications yet. You'll see updates here when your application status changes.</div>
        </div>
      <?php else: ?>
        <?php foreach ($myNotifications as $n): ?>
          <div class="app-card" style="align-items:flex-start">
            <div>
              <div style="font-size:14px;color:var(--ink);margin-bottom:4px"><?= e($n['message']) ?></div>
              <div class="app-date"><?= e(date('M j, Y g:i A', strtotime($n['sent_at']))) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

    <?php elseif ($section === 'renewal'): ?>

      <?php if ($renewalType === 'new'): ?>

        <?php if (!$renewalSource): ?>
          <div class="dash-card">
            <div class="dash-card-label">No Released ID Yet</div>
            <div style="font-size:14px;color:var(--muted);margin:8px 0 14px">You can request a renewal once your Solo Parent ID has been approved and released by MSWDO. Check your application status in the meantime.</div>
            <a href="?section=application" class="btn-outline text-decoration-none">View My Application</a>
          </div>
        <?php else: ?>

          <?php if ($renewalError): ?><div class="step-alert"><?= e($renewalError) ?></div><?php endif; ?>

          <div class="dash-card">
            <div class="dash-card-label">Renewing</div>
            <div style="font-size:14px;color:var(--ink)"><b><?= e($renewalSource['reference_no']) ?></b> · <?= e($renewalSource['category_name']) ?></div>
            <div style="font-size:13px;color:var(--muted)">Issued to <?= e($renewalSource['full_name']) ?></div>
          </div>

          <?php foreach ($renewalDocDefs as $d):
              $docKey = $d['doc_key'];
              $meta = $renewalDraft['documents'][$docKey] ?? null;
              $err = $renewalDraft['docErrors'][$docKey] ?? '';
          ?>
            <div class="doc-row <?= $err ? 'has-error' : '' ?>">
              <div style="min-width:0;flex:1 1 200px">
                <div class="doc-label"><?= e($d['label']) ?> <span class="req"><?= $d['is_required'] ? '*' : '(optional)' ?></span></div>
                <div class="doc-filename <?= $meta ? 'attached' : '' ?>"><?= $meta ? e($meta['original_name']) : 'No file selected' ?></div>
                <?php if ($err): ?><div class="doc-error"><?= e($err) ?></div><?php endif; ?>
              </div>
              <div class="doc-actions">
                <?php if ($meta): ?>
                  <form method="post" class="m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="renewal_action" value="remove_doc">
                    <input type="hidden" name="doc_key" value="<?= e($docKey) ?>">
                    <button type="submit" class="btn-doc-remove">Remove</button>
                  </form>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="m-0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="renewal_action" value="upload_doc">
                  <input type="hidden" name="doc_key" value="<?= e($docKey) ?>">
                  <label class="btn-doc-choose">
                    <?= $meta ? 'Replace' : 'Choose file' ?>
                    <input type="file" name="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" style="display:none" onchange="this.form.submit()">
                  </label>
                </form>
              </div>
            </div>
          <?php endforeach; ?>
          <div style="font-size:12px;color:var(--faint);margin-bottom:20px">Accepted: JPG, PNG, or PDF, max 5MB each.</div>

          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="renewal_action" value="submit">
            <label class="d-flex gap-2 align-items-start" style="font-size:14px;color:var(--body-text);line-height:1.5;cursor:pointer;margin-bottom:14px">
              <input type="checkbox" name="consent" value="1" style="width:20px;height:20px;margin:1px 0 0;flex:none">
              <span>I certify that the information above is true and correct, and I consent to MSWDO Virac processing my data for this renewal.</span>
            </label>
            <button type="submit" class="btn-red">Submit Renewal Request</button>
          </form>

        <?php endif; ?>

      <?php elseif ($renewalType === 'status'): ?>

        <?php
        $latestRenewal = null;
        foreach ($myApplications as $a) {
            if ($a['type'] === 'renewal') { $latestRenewal = $a; break; }
        }
        ?>
        <?php if (!$latestRenewal): ?>
          <div class="dash-card">
            <div style="font-size:14px;color:var(--muted)">You have no renewal requests yet.</div>
          </div>
        <?php else: $st = status_style($latestRenewal['status']); ?>
          <div class="dash-card">
            <div class="dash-status-row">
              <div>
                <div class="dash-status-ref"><?= e($latestRenewal['reference_no']) ?></div>
                <div class="dash-status-cat">Submitted <?= e(date('M j, Y', strtotime($latestRenewal['submitted_at']))) ?></div>
              </div>
              <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($latestRenewal['status']) ?></div>
            </div>
            <?php if ($latestRenewal['interview_at']): ?>
              <div class="info-box" style="max-width:none;margin-bottom:0">📅 Interview scheduled for <b><?= e(date('F j, Y g:i A', strtotime($latestRenewal['interview_at']))) ?></b> at <?= e($latestRenewal['interview_location']) ?>.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      <?php else: ?>

        <?php
        $renewalHistory = array_values(array_filter($myApplications, fn($a) => $a['type'] === 'renewal'));
        ?>
        <?php if (!$renewalHistory): ?>
          <div class="dash-card">
            <div style="font-size:14px;color:var(--muted)">No renewal requests on file yet.</div>
          </div>
        <?php else: ?>
          <?php foreach ($renewalHistory as $r): $st = status_style($r['status']); ?>
            <div class="app-card">
              <div>
                <div class="app-ref"><?= e($r['reference_no']) ?></div>
                <div class="app-date">Submitted <?= e(date('M j, Y', strtotime($r['submitted_at']))) ?></div>
              </div>
              <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>"><?= e($r['status']) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php endif; ?>

    <?php else: ?>

      <div class="dash-card dash-coming-soon">
        <div class="dash-coming-icon">🚧</div>
        <div class="dash-card-label"><?= e($sectionTitles[$section]) ?></div>
        <div style="font-size:14px;color:var(--muted);margin-top:8px;max-width:420px;margin-left:auto;margin-right:auto">
          This feature is coming soon. In the meantime, use the menu to check your application, news &amp; announcements, or your account.
        </div>
      </div>

    <?php endif; ?>
  </main>
</div>

<script src="<?= asset('js/dashboard.js') ?>?v=<?= @filemtime(APP_ROOT . '/assets/js/dashboard.js') ?: 1 ?>"></script>
</body>
</html>
