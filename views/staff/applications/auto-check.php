<?php
/**
 * Automated verification result for the staff review page (AutoVerificationService). A recommendation only.
 * Colours come from the shared .ac-* styles in style.css, so they follow the light and dark themes.
 *
 * @var array $autoCheck verdict, checks, counts
 */
$verdicts = [
    'ready'  => ['Ready for review', 'Every automated check passed. MSWDO staff still verify each document.'],
    'review' => ['Needs a closer look', 'The system flagged items below for MSWDO staff to confirm.'],
    'fix'    => ['Needs the Solo Parent to fix', 'The system found problems the Solo Parent must correct.'],
];
[$title, $summary] = $verdicts[$autoCheck['verdict']] ?? $verdicts['review'];
$marks = ['pass' => ['✓', 'Passed'], 'flag' => ['!', 'Flagged'], 'fail' => ['✕', 'Failed']];
$shown = array_values(array_filter($autoCheck['checks'], fn($c) => $c['result'] !== 'pass'));
$item = function (array $c) use ($marks): void {
    [$mark, $word] = $marks[$c['result']];
    ?><li><span class="ac-mark is-<?= e($c['result']) ?>" aria-hidden="true"><?= e($mark) ?></span><span><span class="visually-hidden"><?= e($word) ?>: </span><strong><?= e($c['label']) ?>:</strong> <?= e($c['message']) ?></span></li><?php
};
?>
<div class="review-card" id="auto-check" role="region" aria-label="Automated verification">
  <div class="review-card-head"><div class="title" data-i18n>Automated verification</div></div>
  <div class="ac-head">
    <span class="ac-pill is-<?= e($autoCheck['verdict']) ?>"><?= e($title) ?></span>
    <span class="ac-counts"><?= (int) $autoCheck['counts']['pass'] ?> passed · <?= (int) $autoCheck['counts']['flag'] ?> flagged · <?= (int) $autoCheck['counts']['fail'] ?> failed</span>
  </div>
  <p class="small text-muted mb-2"><?= e($summary) ?> This is a recommendation; the final decision is the social worker's.</p>
  <?php if ($shown): ?>
    <ul class="ac-list"><?php foreach ($shown as $c) { $item($c); } ?></ul>
  <?php endif; ?>
  <?php if (!AutoVerificationService::ocrAvailable()): ?>
    <p class="small text-muted mb-2">Matching names against the text on each document is not enabled on this server (Tesseract OCR is not installed).</p>
  <?php endif; ?>
  <details class="small">
    <summary>All checks (<?= count($autoCheck['checks']) ?>)</summary>
    <ul class="ac-list mt-2 mb-0"><?php foreach ($autoCheck['checks'] as $c) { $item($c); } ?></ul>
  </details>
</div>
