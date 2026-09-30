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

<?php if ($submitError): ?><div class="step-alert"><?= e($submitError) ?></div><?php endif; ?>
<?php if ($stepError): ?><div class="step-alert"><?= e($stepError) ?></div><?php endif; ?>

<?php require __DIR__ . '/' . $stepViews[$step]; ?>

</div>
