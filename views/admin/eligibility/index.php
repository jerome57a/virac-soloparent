<?php /** @var array $logs @var array $pager @var array $filters */ ?>
<div class="portal-container">
  <?php require APP_ROOT . '/views/partials/admin-page-heading.php'; ?>

  <details class="admin-help-panel"><summary data-i18n>How to read these logs</summary><p class="eligibility-help">Each row is one completed preliminary check. Refreshes add views, not checks. Unique viewers identify accounts or browser sessions, not verified individuals. Unclaimed guest checks expire after 30 days. Checks from before this feature have no recorded views.</p></details>
  <?php if ($error): ?><div class="auth-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="get" class="analytics-filters my-3" role="search" aria-label="Filter eligibility checks" id="admin-eligibility-filters">
    <label>From<input type="date" name="from" value="<?= e($filters['from']) ?>" class="form-control"></label>
    <label>To<input type="date" name="to" value="<?= e($filters['to']) ?>" class="form-control"></label>
    <label>Result<select name="result" class="form-select"><option value="">All results</option><?php foreach ($results as $status): ?><option value="<?= e($status) ?>" <?= $filters['result'] === $status ? 'selected' : '' ?>><?= e(EligibilityService::resultLabel($status)) ?></option><?php endforeach; ?></select></label>
    <label>Visitor<select name="audience" class="form-select"><option value="">All visitors</option><option value="registered" <?= $filters['audience'] === 'registered' ? 'selected' : '' ?>>Registered</option><option value="guest" <?= $filters['audience'] === 'guest' ? 'selected' : '' ?>>Guest</option></select></label>
    <label>Situation<select name="circ" class="form-select"><option value="">All situations</option><?php foreach ($situations as $situation): ?><option value="<?= e($situation) ?>" <?= $filters['circ'] === $situation ? 'selected' : '' ?>><?= e($situation) ?></option><?php endforeach; ?></select></label>
    <button type="submit" class="btn-navy" data-i18n>Filter</button><a href="<?= base_url('admin/eligibility_logs.php') ?>" class="btn-outline text-decoration-none" data-i18n>Clear</a>
  </form>
  <div id="admin-eligibility-results"><?php require APP_ROOT . '/views/partials/admin-list-meta.php'; ?></div>
  <?php if (!$logs): ?><div class="info-box">No eligibility checks found.</div><?php endif; ?>
  <?php if ($logs): ?><div class="admin-list-scroll" role="region" aria-label="Eligibility checks" tabindex="0"><?php endif; ?>
  <?php foreach ($logs as $log): $answers = json_decode($log['answers'], true) ?: []; ?>
    <details class="admin-editor">
      <summary class="admin-log-summary"><span class="admin-disclosure-arrow" aria-hidden="true">›</span><strong><?= e($log['full_name'] ?? 'Guest') ?></strong><span class="admin-log-result" data-i18n><?= e(EligibilityService::resultLabel($log['preliminary_result'])) ?></span><time datetime="<?= e(date('c', strtotime($log['created_at']))) ?>"><?= e(date('M j, Y', strtotime($log['created_at']))) ?></time></summary>
      <div class="admin-editor-content">
      <div class="d-flex justify-content-between flex-wrap gap-2">
        <div><strong><?= e($log['full_name'] ?? ('Guest ? ' . substr($log['session_token'] ?? '', 0, 8))) ?></strong><?php if ($log['username']): ?><span class="ms-2">@<?= e($log['username']) ?></span><?php endif; ?><div class="eligibility-help">Check #<?= (int) $log['id'] ?> ? <?= e(date('M j, Y g:i A', strtotime($log['created_at']))) ?></div></div>
        <strong><?= e(EligibilityService::resultLabel($log['preliminary_result'])) ?></strong>
      </div>
      <div class="mt-2" data-i18n>Situation: <?= e($answers['circ'] ?? '') ?></div>
      <div class="eligibility-help">Resident: <?= e($answers['resident'] ?? '') ?> ? Sole care: <?= e($answers['child'] ?? '') ?></div>
      <?php if ($log['result_reason']): ?><p class="mt-2 mb-2"><?= e($log['result_reason']) ?></p><?php endif; ?>
      <div class="eligibility-log-stats">
        <span>Views: <b><?= (int) $log['view_count'] ?></b></span><span>Unique viewers: <b><?= (int) $log['unique_viewers'] ?></b></span>
        <span>First viewed: <?= $log['first_viewed'] ? e(date('M j, Y g:i A', strtotime($log['first_viewed']))) : 'Not recorded' ?></span>
        <span>Last viewed: <?= $log['last_viewed'] ? e(date('M j, Y g:i A', strtotime($log['last_viewed']))) : 'Not recorded' ?></span>
      </div>
      </div>
    </details>
  <?php endforeach; ?>
  <?php if ($logs): ?></div><?php endif; ?>
  <?php require APP_ROOT . '/views/partials/pagination.php'; ?>
</div>
