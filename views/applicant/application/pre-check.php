<?php
/**
 * Automatic check shown on the review step, before the applicant submits (AutoVerificationService::forDraft).
 * Advisory only: it never blocks submitting, and MSWDO staff still review everything.
 * Colours come from the shared .ac-* styles in style.css, so they follow the light and dark themes.
 *
 * @var array $preCheck verdict, checks, counts
 */
// Missing required documents already have their own alert above; only the rest is listed here.
$items = array_values(array_filter($preCheck['checks'], fn($c) => $c['result'] !== 'pass' && $c['key'] !== 'required_documents'));
if (!$items) {
    ?>
    <div class="info-box" style="max-width:none" role="status"><strong data-i18n>Automatic check:</strong> <span data-i18n>no problems found with your details and files. MSWDO staff will still review them.</span></div>
    <?php
    return;
}
?>
<div class="review-card" id="pre-check" role="region" aria-label="Automatic check">
  <div class="review-card-head"><div class="title" data-i18n>Automatic check before you submit</div></div>
  <p class="small text-muted mb-2" data-i18n>We checked your details and files. You can still submit, but fixing these now helps MSWDO approve your application sooner.</p>
  <ul class="ac-list mb-0">
    <?php foreach ($items as $c): ?>
      <li>
        <span class="ac-mark is-<?= $c['result'] === 'fail' ? 'fail' : 'flag' ?>" aria-hidden="true"><?= $c['result'] === 'fail' ? '✕' : '!' ?></span>
        <span><span class="visually-hidden"><?= $c['result'] === 'fail' ? 'Problem' : 'Please check' ?>: </span><strong><?= e($c['label']) ?>:</strong> <?= e($c['message']) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
