<?php
/**
 * @var array  $staff
 * @var string $error
 * @var string $success
 * @var array  $faqs
 */
?>
<div class="portal-container">
  <a href="<?= base_url('admin/dashboard.php') ?>" style="font-size:13px">&larr; Back to dashboard</a>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mt-2 mb-4">
    <h1 class="font-heading m-0" style="font-size:26px">Manage FAQ</h1>
    <span style="font-size:14px">Signed in as <b><?= e($staff['full_name']) ?></b></span>
  </div>
  <div style="font-size:13px;color:var(--muted);margin-bottom:20px;max-width:640px">These questions and answers appear on the applicant's Chat Support page as self-service help. Inactive entries are hidden from applicants but kept here for reuse.</div>

  <?php if ($error): ?><div class="auth-error mb-3"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none"><?= e($success) ?></div><?php endif; ?>

  <div class="row g-4">
    <div class="col-12 col-lg-5">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Add FAQ</div></div>
        <form method="post" class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <input class="form-control" type="text" name="question" placeholder="Question" required>
          <textarea class="form-control" name="answer" rows="4" placeholder="Answer" required></textarea>
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            Sort order (lower shows first)
            <input type="number" name="sort_order" value="0" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px">
          </label>
          <button type="submit" class="btn-navy">Add FAQ</button>
        </form>
      </div>
    </div>

    <div class="col-12 col-lg-7">
      <?php if (!$faqs): ?>
        <div style="font-size:14px;color:var(--muted)">No FAQ entries yet.</div>
      <?php endif; ?>
      <?php foreach ($faqs as $f): ?>
        <div class="review-card">
          <form method="post" class="d-flex flex-column gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="faq_id" value="<?= (int) $f['id'] ?>">
            <input class="form-control" type="text" name="question" value="<?= e($f['question']) ?>" required>
            <textarea class="form-control" name="answer" rows="3" required><?= e($f['answer']) ?></textarea>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <input type="number" name="sort_order" value="<?= (int) $f['sort_order'] ?>" class="form-control" style="max-width:100px;padding:8px;border:1px solid var(--input-border);border-radius:6px">
              <button type="submit" class="btn-outline" style="padding:6px 14px;font-size:12px">Save</button>
              <?php if (!$f['is_active']): ?><span class="status-pill" style="background:#fde8e8;color:#a51c1c">Hidden</span><?php endif; ?>
            </div>
          </form>
          <div class="d-flex gap-2 mt-2">
            <form method="post" class="m-0">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="faq_id" value="<?= (int) $f['id'] ?>">
              <button type="submit" class="btn-outline" style="padding:6px 12px;font-size:12px"><?= $f['is_active'] ? 'Hide' : 'Show' ?></button>
            </form>
            <form method="post" class="m-0" onsubmit="return confirm('Delete this FAQ entry?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="faq_id" value="<?= (int) $f['id'] ?>">
              <button type="submit" class="btn-outline" style="padding:6px 12px;font-size:12px;color:#a51c1c">Delete</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
