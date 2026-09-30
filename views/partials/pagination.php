<?php
/**
 * Previous / "Page X of Y" / Next links that keep the current filters.
 *
 * @var array $pager  from paginate(): page, totalPages, total
 */
?>
  <?php if ($pager['totalPages'] > 1): ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
      <a href="<?= e(query_with(['page' => max(1, $pager['page'] - 1)])) ?>" class="btn-outline text-decoration-none" style="<?= $pager['page'] <= 1 ? 'pointer-events:none;opacity:.4' : '' ?>">&larr; Previous</a>
      <span style="font-size:13px;color:var(--muted)">Page <?= $pager['page'] ?> of <?= $pager['totalPages'] ?> (<?= $pager['total'] ?> total)</span>
      <a href="<?= e(query_with(['page' => min($pager['totalPages'], $pager['page'] + 1)])) ?>" class="btn-outline text-decoration-none" style="<?= $pager['page'] >= $pager['totalPages'] ? 'pointer-events:none;opacity:.4' : '' ?>">Next &rarr;</a>
    </div>
  <?php endif; ?>
