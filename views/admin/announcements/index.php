<?php
$entry = $editing ?? ['id' => 0, 'title' => '', 'short_desc' => '', 'category' => 'Program Update', 'details' => '', 'status' => 'draft', 'published_at' => date('Y-m-d H:i:s'), 'expires_at' => '', 'priority' => 0];
$datetimeValue = function ($value): string {
    return is_string($value) && strtotime($value) !== false ? date('Y-m-d\TH:i', strtotime($value)) : '';
};
?>
<div class="portal-container">
  <?php require APP_ROOT . '/views/partials/admin-page-heading.php'; ?>

<p class="admin-page-note">Manage news shown on News &amp; Announcements. Higher priority appears first. Dates use Philippine local time. Save as Draft to withdraw an announcement.</p>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-success" role="status"><?= e($notice) ?></div><?php endif; ?>
<div class="row g-4 admin-editor-layout"><div class="col-12 col-lg-7" id="admin-announcement-list">
<div class="review-card"><div class="review-card-head"><div class="title">Announcement List</div></div>
<?php require APP_ROOT . '/views/partials/admin-list-meta.php'; ?>
<div class="table-responsive" role="region" aria-label="Public announcements" tabindex="0"><table class="table admin-announcements-table"><thead><tr><th scope="col" data-i18n>Title</th><th scope="col" data-i18n>Status</th><th scope="col" data-i18n>Publication</th><th scope="col" data-i18n>Actions</th></tr></thead><tbody>
<?php foreach ($announcements as $a):
    $announcementEditUrl = base_url('admin/announcements.php?edit=' . (int) $a['id'] . '#admin-announcement-editor');
    $visibility = $a['status'] === 'draft' ? 'Draft' : ($a['expires_at'] && strtotime($a['expires_at']) <= time() ? 'Expired' : (strtotime($a['published_at']) > time() ? 'Scheduled' : 'Published'));
?>
<tr>
  <td><a class="admin-cell-title" href="<?= e($announcementEditUrl) ?>"><?= e($a['title']) ?></a><div class="admin-cell-meta"><span data-i18n>Priority</span>: <?= (int) $a['priority'] ?></div></td>
  <td><span class="admin-publication-state" data-i18n><?= e($visibility) ?></span></td>
  <td><time datetime="<?= e(date('c', strtotime($a['published_at']))) ?>"><?= e(date('M j, Y', strtotime($a['published_at']))) ?></time><div class="admin-cell-meta"><span data-i18n>Expires</span>: <?= $a['expires_at'] ? e(date('M j, Y', strtotime($a['expires_at']))) : '—' ?></div></td>
  <td><a class="btn-outline" href="<?= e($announcementEditUrl) ?>" aria-label="Edit announcement <?= e($a['title']) ?>" data-i18n>Edit</a></td>
</tr>
<?php endforeach; ?>
<?php if (!$announcements): ?><tr><td colspan="4">No announcements yet.</td></tr><?php endif; ?>
</tbody></table></div>
<?php require APP_ROOT . '/views/partials/pagination.php'; ?>
</div></div><div class="col-12 col-lg-5" id="admin-announcement-editor">
<div class="review-card">
<h2 style="font-size:20px"><?= !empty($entry['id']) ? 'Edit Announcement' : 'New Announcement' ?></h2>
<form method="post" class="d-grid gap-3">
<?= csrf_field() ?>
<input type="hidden" name="id" value="<?= e(is_scalar($entry['id'] ?? null) ? $entry['id'] : 0) ?>">
<fieldset class="admin-form-section"><legend data-i18n>Announcement content</legend>
<?php foreach (['title' => 'Title', 'short_desc' => 'Short Description', 'category' => 'Category'] as $key => $label): ?>
<label><?= e($label) ?><input class="form-control" name="<?= e($key) ?>" maxlength="<?= $key === 'title' ? 200 : ($key === 'category' ? 80 : 500) ?>" required value="<?= e(is_string($entry[$key] ?? null) ? $entry[$key] : '') ?>"></label>
<?php endforeach; ?>
<label data-i18n>Details<textarea class="form-control" name="details" rows="6" maxlength="20000" required><?= e(is_string($entry['details'] ?? null) ? $entry['details'] : '') ?></textarea></label>
</fieldset>
<fieldset class="admin-form-section"><legend data-i18n>Publishing options</legend>
<label data-i18n>Status<select class="form-select" name="status"><option value="draft" data-i18n>Draft</option><option value="published" <?= ($entry['status'] ?? '') === 'published' ? 'selected' : '' ?>>Published</option></select></label>
<label>Publication Date<input class="form-control" type="datetime-local" name="published_at" required value="<?= e($datetimeValue($entry['published_at'] ?? '')) ?>"></label>
<label>Expiry Date (optional)<input class="form-control" type="datetime-local" name="expires_at" value="<?= e($datetimeValue($entry['expires_at'] ?? '')) ?>"></label>
<label>Priority (0&ndash;100)<input class="form-control" type="number" min="0" max="100" name="priority" required value="<?= e(is_scalar($entry['priority'] ?? null) ? $entry['priority'] : 0) ?>"></label>
</fieldset>
<div class="d-flex flex-wrap gap-2"><button class="btn-navy" type="submit">Save Announcement</button> <a href="<?= base_url('admin/announcements.php') ?>" class="btn-outline">New Announcement</a></div>
</form></div>
</div></div></div>
