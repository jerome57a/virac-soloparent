<?php
/**
 * Apply Online wizard shell: step bar, step heading, alerts, then the current step's view.
 *
 * @var array   $draft
 * @var array   $errors
 * @var ?string $submitError
 * @var int     $step
 * @var array   $stepMeta
 * @var string  $stepError
 * @var ?string $draftSavedAt  when the saved draft was last updated (null = nothing saved yet)
 * @var ?string $flashMessage
 */
$stepViews = [1 => 'step-personal.php', 2 => 'step-children.php', 3 => 'step-documents.php', 4 => 'review.php'];
?>
<div style="max-width:720px">

<div class="step-bar">
  <?php for ($n = 1; $n <= 4; $n++): $isActive = $step === $n; ?>
    <?php if ($n < $step): ?>
      <form method="post" class="step-item done">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="goto">
        <input type="hidden" name="n" value="<?= $n ?>">
        <button type="submit" style="border:none;background:none;padding:0;width:100%;font-family:inherit;cursor:pointer">
          <div class="step-circle">✓</div>
          <div class="step-label"><?= e($stepMeta[$n][0]) ?></div>
          <div class="step-progress"></div>
        </button>
      </form>
    <?php else: ?>
      <div class="step-item <?= $isActive ? 'active' : '' ?>">
        <div class="step-circle"><?= $n ?></div>
        <div class="step-label"><?= e($stepMeta[$n][0]) ?></div>
        <div class="step-progress"></div>
      </div>
    <?php endif; ?>
  <?php endfor; ?>
</div>

<div class="step-title"><?= e($stepMeta[$step][0]) ?></div>
<div class="step-desc"><?= e($stepMeta[$step][1]) ?></div>

<?php if ($flashMessage): ?><div class="info-box" style="max-width:none" role="status"><?= e($flashMessage) ?></div><?php endif; ?>

<div class="draft-meta">
  <span><?= $draftSavedAt ? "Draft saved to your account · last saved " . e(date("M j, Y g:i A", strtotime($draftSavedAt))) : "Your progress is saved to your account as you go." ?></span>
  <?php if ($draftSavedAt): ?>
    <form method="post" class="m-0" onsubmit="return confirm('Discard this draft? Everything you entered and uploaded will be deleted.')">
      <?= csrf_field() ?>
      <button type="submit" name="action" value="discard_draft" formnovalidate class="draft-discard">Discard draft</button>
    </form>
  <?php endif; ?>
</div>

<?php if ($submitError): ?><div class="step-alert"><?= e($submitError) ?></div><?php endif; ?>
<?php if ($stepError): ?><div class="step-alert"><?= e($stepError) ?></div><?php endif; ?>

<?php require __DIR__ . '/' . $stepViews[$step]; ?>

</div>
