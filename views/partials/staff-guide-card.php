<?php
require_once __DIR__ . '/../../includes/icons.php';
/**
 * The step tracker and the "Next step" card of one application (StaffGuideService::applicationGuide).
 *
 * @var array  $guide      steps, next, closed
 * @var ?array $queueNext  the oldest other application whose documents wait for verification (id, reference_no) or null
 */
$guide = $guide ?? ['steps' => [], 'next' => null, 'closed' => null];
$queueNext = $queueNext ?? null;
?>
<?php if ($guide['closed']): ?>
  <div class="gi-next gi-tone-amber" role="status"><span class="gi-chip gi-red"><?= ui_icon('circle-x', 22) ?></span><div class="gi-next-text"><strong data-i18n>Application rejected</strong><span data-i18n><?= e($guide['closed']) ?></span></div></div>
<?php else: ?>
  <?php if ($guide['steps']): ?>
  <ol class="gi-tracker" aria-label="Progress of this application" data-i18n-attrs="aria-label">
    <?php foreach ($guide['steps'] as $step): ?>
      <li class="gi-step is-<?= e($step['state']) ?>"<?= $step['state'] === 'now' ? ' aria-current="step"' : '' ?>>
        <span class="gi-dot"><?= ui_icon($step['state'] === 'done' ? 'circle-check' : $step['icon'], 18) ?></span>
        <span data-i18n><?= e($step['label']) ?></span>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>
  <?php if ($guide['next']): $gn = $guide['next']; ?>
  <div class="gi-next gi-tone-<?= e($gn['tone']) ?>" id="next-step-card" role="status">
    <span class="gi-chip gi-<?= $gn['tone'] === 'navy' ? 'blue' : e($gn['tone']) ?>"><?= ui_icon($gn['icon'], 22) ?></span>
    <div class="gi-next-text"><strong data-i18n><?= e($gn['title']) ?></strong><span><span data-i18n><?= e(preg_replace('/: \d+$/', '', $gn['text'])) ?></span><?= preg_match('/: (\d+)$/', $gn['text'], $count) ? ': ' . (int) $count[1] : '' ?></span></div>
    <div class="gi-actions">
      <?php if ($gn['label'] !== ''): ?><a class="gi-btn" href="<?= e($gn['href']) ?>"><?= ui_icon($gn['icon'], 17) ?><span data-i18n><?= e($gn['label']) ?></span></a><?php endif; ?>
      <?php if ($queueNext): ?><a class="gi-btn is-outline" href="<?= e(base_url('staff/application.php?id=' . (int) $queueNext['id'])) ?>#checklist"><?= ui_icon('arrow-right', 17) ?><span><span data-i18n>Next waiting:</span> <?= e($queueNext['reference_no']) ?></span></a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>
<script>(function(){var t=document.querySelector('.gi-tracker'),s=t&&t.querySelector('.is-now');if(t&&s){t.scrollLeft=s.offsetLeft-(t.clientWidth-s.offsetWidth)/2;}})();</script>
