<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/SmsService.php';

$staff = require_staff_login($pdo);

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT a.*, c.name AS category_name, r.full_name AS reviewer_name
     FROM applications a
     JOIN categories c ON c.id = a.category_id
     LEFT JOIN users r ON r.id = a.reviewed_by
     WHERE a.id = ?'
);
$stmt->execute([$id]);
$app = $stmt->fetch();
if (!$app) {
    http_response_code(404);
    die('Application not found.');
}

$validStatuses = ['Pending Review', 'Under Verification', 'For Revision', 'Interview Scheduled', 'Approved', 'ID Released', 'Rejected'];
$statusError = '';
$interviewError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $formAction = (string) ($_POST['form_action'] ?? 'update_status');

    if ($formAction === 'schedule_interview') {
        $scheduledAt = (string) ($_POST['scheduled_at'] ?? '');
        $location = trim((string) ($_POST['location'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $scheduledAt);
        if (!$dt) {
            $interviewError = 'Select a valid interview date and time.';
        } elseif ($location === '') {
            $interviewError = 'Enter an interview location.';
        } else {
            $scheduledAtSql = $dt->format('Y-m-d H:i:s');
            $pdo->prepare(
                'INSERT INTO interviews (application_id, scheduled_at, location, notes, scheduled_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE scheduled_at = VALUES(scheduled_at), location = VALUES(location),
                     notes = VALUES(notes), scheduled_by = VALUES(scheduled_by), result = NULL, result_recorded_at = NULL'
            )->execute([$id, $scheduledAtSql, $location, $notes !== '' ? $notes : null, $staff['id']]);

            $pdo->prepare('UPDATE applications SET status = ?, reviewed_by = ? WHERE id = ?')
                ->execute(['Interview Scheduled', $staff['id'], $id]);
            $pdo->prepare('INSERT INTO status_history (application_id, status, remarks, changed_by) VALUES (?, \'Interview Scheduled\', ?, ?)')
                ->execute([$id, 'Interview at ' . $location . ' on ' . $dt->format('M j, Y g:i A'), $staff['full_name']]);
            (new SmsService($pdo))->notifyInterviewScheduled($id, $app['mobile'], $app['reference_no'], $scheduledAtSql, $location);
            audit_log($pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Interview Scheduling', 'application', $id, $location . ' on ' . $dt->format('M j, Y g:i A'));
            redirect(base_url('admin/application.php?id=' . $id));
        }
    } elseif ($formAction === 'record_result') {
        $result = trim((string) ($_POST['result'] ?? ''));
        if ($result === '') {
            $interviewError = 'Enter the interview result/notes before saving.';
        } else {
            $pdo->prepare('UPDATE interviews SET result = ?, result_recorded_at = NOW() WHERE application_id = ?')
                ->execute([$result, $id]);
            audit_log($pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Interview Result Recorded', 'application', $id, $result);
            redirect(base_url('admin/application.php?id=' . $id));
        }
    } else {
        $newStatus = (string) ($_POST['status'] ?? '');
        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if (!in_array($newStatus, $validStatuses, true)) {
            $statusError = 'Select a valid status.';
        } else {
            $pdo->prepare('UPDATE applications SET status = ?, reviewed_by = ? WHERE id = ?')->execute([$newStatus, $staff['id'], $id]);
            $pdo->prepare('INSERT INTO status_history (application_id, status, remarks, changed_by) VALUES (?, ?, ?, ?)')
                ->execute([$id, $newStatus, $remarks !== '' ? $remarks : null, $staff['full_name']]);
            (new SmsService($pdo))->notifyStatusChange($id, $app['mobile'], $app['reference_no'], $newStatus, $remarks);
            $auditAction = ['Approved' => 'Application Approval', 'Rejected' => 'Application Rejection'][$newStatus] ?? 'Status Change';
            audit_log($pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], $auditAction, 'application', $id, $app['reference_no'] . ' → ' . $newStatus);
            redirect(base_url('admin/application.php?id=' . $id));
        }
    }
}

$interviewStmt = $pdo->prepare('SELECT * FROM interviews WHERE application_id = ?');
$interviewStmt->execute([$id]);
$interview = $interviewStmt->fetch();

$children = $pdo->prepare('SELECT * FROM application_children WHERE application_id = ?');
$children->execute([$id]);
$children = $children->fetchAll();

$docs = $pdo->prepare('SELECT * FROM application_documents WHERE application_id = ?');
$docs->execute([$id]);
$docs = $docs->fetchAll();

$history = $pdo->prepare('SELECT * FROM status_history WHERE application_id = ? ORDER BY changed_at DESC');
$history->execute([$id]);
$history = $history->fetchAll();

$st = status_style($app['status']);
$pageTitle = 'Application ' . $app['reference_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="portal-container">
  <a href="<?= base_url('admin/index.php') ?>" style="font-size:13px">&larr; Back to applications</a>
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mt-2 mb-4">
    <div>
      <h1 class="font-heading m-0" style="font-size:26px"><?= e($app['reference_no']) ?> <?php if ($app['type'] === 'renewal'): ?><span class="doc-tag" style="vertical-align:middle">RENEWAL</span><?php endif; ?></h1>
      <div style="font-size:13px;color:var(--muted)">Submitted <?= e(date('F j, Y g:i A', strtotime($app['submitted_at']))) ?><?php if ($app['reviewer_name']): ?> · Reviewed by <?= e($app['reviewer_name']) ?><?php endif; ?></div>
    </div>
    <div class="status-pill" style="background:<?= e($st['bg']) ?>;color:<?= e($st['color']) ?>;font-size:14px"><?= e($app['status']) ?></div>
  </div>

  <div class="row g-4">
    <div class="col-12 col-lg-7">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Personal Info</div></div>
        <div class="review-grid">
          <div class="review-row"><div class="k">Full name</div><div class="v"><?= e($app['full_name']) ?></div></div>
          <div class="review-row"><div class="k">Birthdate</div><div class="v"><?= e(format_date_long($app['birthdate'])) ?></div></div>
          <div class="review-row"><div class="k">Address</div><div class="v"><?= e($app['address']) ?>, Brgy. <?= e($app['barangay']) ?></div></div>
          <div class="review-row"><div class="k">Mobile</div><div class="v"><?= e($app['mobile']) ?></div></div>
          <div class="review-row"><div class="k">Email</div><div class="v"><?= e($app['email']) ?></div></div>
          <div class="review-row"><div class="k">Sex</div><div class="v"><?= e($app['sex']) ?></div></div>
          <div class="review-row"><div class="k">Civil status</div><div class="v"><?= e($app['civil_status']) ?></div></div>
          <div class="review-row"><div class="k">Category</div><div class="v"><?= e($app['category_name']) ?></div></div>
        </div>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Children</div></div>
        <div class="review-grid">
          <?php foreach ($children as $i => $c): ?>
            <div class="review-row"><div class="k">Child <?= $i + 1 ?></div><div class="v"><?= e($c['full_name']) ?> · <?= e(format_date_long($c['birthdate'])) ?></div></div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Documents</div></div>
        <?php foreach ($docs as $d): ?>
          <div class="d-flex justify-content-between align-items-center mb-2" style="font-size:14px">
            <span><?= e($d['doc_key']) ?> — <?= e($d['original_name']) ?></span>
            <a href="<?= base_url('download.php?doc_id=' . (int) $d['id']) ?>" target="_blank" class="btn-outline text-decoration-none" style="padding:6px 12px;font-size:12px">View</a>
          </div>
        <?php endforeach; ?>
        <?php if (!$docs): ?><div style="font-size:13px;color:var(--muted)">No documents on file.</div><?php endif; ?>
      </div>
    </div>

    <div class="col-12 col-lg-5">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Update Status</div></div>
        <?php if ($statusError): ?><div class="auth-error"><?= e($statusError) ?></div><?php endif; ?>
        <form method="post" class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <select name="status" class="form-select" style="padding:10px;border:1px solid var(--input-border);border-radius:6px">
            <?php foreach ($validStatuses as $s): ?>
              <option value="<?= e($s) ?>" <?= $app['status'] === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
          <textarea name="remarks" rows="3" placeholder="Remarks sent to the applicant via SMS (optional)" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px"></textarea>
          <input type="hidden" name="form_action" value="update_status">
          <button type="submit" class="btn-navy">Save Status</button>
        </form>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Interview</div></div>
        <?php if ($interviewError): ?><div class="auth-error"><?= e($interviewError) ?></div><?php endif; ?>

        <?php if ($interview): ?>
          <div class="review-grid mb-3">
            <div class="review-row"><div class="k">Scheduled</div><div class="v"><?= e(date('F j, Y g:i A', strtotime($interview['scheduled_at']))) ?></div></div>
            <div class="review-row"><div class="k">Location</div><div class="v"><?= e($interview['location']) ?></div></div>
            <?php if ($interview['notes']): ?><div class="review-row"><div class="k">Notes</div><div class="v"><?= e($interview['notes']) ?></div></div><?php endif; ?>
          </div>
        <?php endif; ?>

        <form method="post" class="d-flex flex-column gap-3 mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="form_action" value="schedule_interview">
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            <?= $interview ? 'Reschedule interview' : 'Schedule interview' ?>
            <input type="datetime-local" name="scheduled_at" value="<?= $interview ? e(date('Y-m-d\TH:i', strtotime($interview['scheduled_at']))) : '' ?>" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px" required>
          </label>
          <input type="text" name="location" value="<?= $interview ? e($interview['location']) : '' ?>" placeholder="Location (e.g. MSWDO Office, Municipal Building)" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px" required>
          <textarea name="notes" rows="2" placeholder="Notes for the applicant (optional)" class="form-control" style="padding:10px;border:1px solid var(--input-border);border-radius:6px"><?= $interview ? e($interview['notes']) : '' ?></textarea>
          <button type="submit" class="btn-outline"><?= $interview ? 'Update Interview Schedule' : 'Schedule Interview' ?></button>
        </form>

        <?php if ($interview): ?>
          <form method="post" class="d-flex flex-column gap-3">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="record_result">
            <label style="font-size:13px;font-weight:600;color:var(--ink)">
              Interview result
              <textarea name="result" rows="3" placeholder="What happened at the interview?" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px"><?= e($interview['result'] ?? '') ?></textarea>
            </label>
            <?php if ($interview['result_recorded_at']): ?>
              <div style="font-size:12px;color:var(--faint)">Recorded <?= e(date('M j, Y g:i A', strtotime($interview['result_recorded_at']))) ?></div>
            <?php endif; ?>
            <button type="submit" class="btn-navy">Save Result</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="review-card">
        <div class="review-card-head"><div class="title">Status History</div></div>
        <?php foreach ($history as $h): ?>
          <div class="mb-2" style="font-size:13px">
            <b><?= e($h['status']) ?></b> — <?= e(date('M j, Y g:i A', strtotime($h['changed_at']))) ?><br>
            <?php if ($h['remarks']): ?><span style="color:var(--muted)"><?= e($h['remarks']) ?></span><br><?php endif; ?>
            <span style="color:var(--faint)">by <?= e($h['changed_by'] ?? 'system') ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php
$footerVariant = 'portal';
require __DIR__ . '/../includes/footer.php';
