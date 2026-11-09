<?php
/**
 * @var array  $staff
 * @var string $error
 * @var string $success
 * @var array  $faqs
 */
?>
<div class="portal-container">
  <?php require APP_ROOT . '/views/partials/admin-page-heading.php'; ?>

  <?php if ($error): ?><div class="auth-error mb-3" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="info-box" style="max-width:none" role="status"><?= e($success) ?></div><?php endif; ?>

  <div class="row g-4 admin-editor-layout">
    <div class="col-12 col-lg-7" id="admin-faq-list">
      <div class="admin-local-search"><label for="admin-faq-search" data-i18n>Search FAQ entries</label><input type="search" id="admin-faq-search" data-admin-faq-search placeholder="Search questions or answers" data-i18n-attrs="placeholder"></div>
      <p data-admin-faq-empty role="status" hidden data-i18n>No FAQ entries match your search.</p>
      <?php if (!$faqs): ?>
        <div style="font-size:14px;color:var(--muted)">No FAQ entries yet.</div>
      <?php endif; ?>
      <?php if ($faqs): ?><div class="admin-list-scroll" role="region" aria-label="FAQ entries" tabindex="0"><?php endif; ?>
      <?php foreach ($faqs as $f): ?>
        <details class="admin-editor" <?= $error && (int) ($_POST['faq_id'] ?? 0) === (int) $f['id'] ? 'open' : '' ?>>
          <summary><?= e($f['question']) ?><?= !$f['is_active'] ? ' (Hidden)' : '' ?></summary>
          <div class="admin-editor-content">
          <form method="post" class="d-flex flex-column gap-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="faq_id" value="<?= (int) $f['id'] ?>">
            <label class="user-label"><span data-i18n>Question</span><input class="form-control" type="text" name="question" aria-label="FAQ question" value="<?= e($f['question']) ?>" required></label>
            <label class="user-label"><span data-i18n>Answer</span><textarea class="form-control" name="answer" aria-label="FAQ answer" rows="3" required><?= e($f['answer']) ?></textarea></label>
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <input aria-label="Sort order" type="number" name="sort_order" value="<?= (int) $f['sort_order'] ?>" class="form-control" style="max-width:100px;padding:8px;border:1px solid var(--input-border);border-radius:6px">
              <button type="submit" class="btn-outline" style="padding:6px 14px;font-size:12px" data-i18n>Save</button>
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
              <button type="submit" class="btn-danger-outline btn-sm" data-i18n>Delete</button>
            </form>
          </div>
          </div>
        </details>
      <?php endforeach; ?>
      <?php if ($faqs): ?></div><?php endif; ?>
    </div>

    <div class="col-12 col-lg-5" id="admin-faq-create">
      <div class="review-card">
        <div class="review-card-head"><div class="title">Add FAQ</div></div>
        <form method="post" class="d-flex flex-column gap-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <label class="user-label"><span data-i18n>Question</span><input class="form-control" type="text" name="question" aria-label="Question" placeholder="Question" required></label>
          <label class="user-label"><span data-i18n>Answer</span><textarea class="form-control" name="answer" aria-label="Answer" rows="4" placeholder="Answer" required></textarea></label>
          <label style="font-size:13px;font-weight:600;color:var(--ink)">
            Sort order (lower shows first)
            <input type="number" name="sort_order" value="0" class="form-control mt-1" style="padding:10px;border:1px solid var(--input-border);border-radius:6px">
          </label>
          <button type="submit" class="btn-navy">Add FAQ</button>
        </form>
      </div>
    </div>
  </div>
</div>
