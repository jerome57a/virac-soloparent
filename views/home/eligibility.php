<?php
/**
 * Eligibility questions. Standalone on eligibility.php; also embedded in
 * the applicant dashboard with $standalone = false.
 *
 * @var array  $answers
 * @var string $error
 * @var array  $circumstances
 * @var string $noneOption
 * @var string $formAction
 * @var bool   $standalone
 */
$standalone = $standalone ?? true;
$yesNo = ['Yes', 'No'];
?>
<?php if ($standalone): ?>
<div class="portal-container">
  <a href="<?= base_url('portal.php') ?>" style="font-size:13px">&larr; Back to the Solo Parent ID guide</a>
<?php endif; ?>
  <div class="elig-panel">
    <div class="font-heading mb-1" style="font-size:22px">Eligibility Checker</div>
    <div style="font-size:14px;color:var(--muted);margin-bottom:22px">Answer three quick questions to see whether you likely qualify for a Solo Parent ID. This is a guide only &mdash; final eligibility is confirmed by the MSWD Social Worker. Nothing you enter here is saved.</div>

    <?php if ($error): ?><div class="auth-error mb-3"><?= e($error) ?></div><?php endif; ?>

    <form method="post" action="<?= e($formAction) ?>">
      <?= csrf_field() ?>

      <fieldset class="mb-3">
        <legend style="font-weight:700;font-size:15px;color:var(--ink);margin-bottom:10px">1. Are you a resident of Virac, Catanduanes?</legend>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($yesNo as $opt): ?>
            <label class="option-btn option-radio" style="flex:1 1 120px">
              <input type="radio" name="resident" value="<?= e($opt) ?>" <?= $answers['resident'] === $opt ? 'checked' : '' ?> required> <?= e($opt) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <fieldset class="mb-3">
        <legend style="font-weight:700;font-size:15px;color:var(--ink);margin-bottom:10px">2. Which best describes your situation?</legend>
        <div class="d-flex flex-column gap-2">
          <?php foreach (array_merge(array_column($circumstances, 'title'), [$noneOption]) as $opt): ?>
            <label class="option-btn wide option-radio">
              <input type="radio" name="circ" value="<?= e($opt) ?>" <?= $answers['circ'] === $opt ? 'checked' : '' ?> required> <?= e($opt) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <fieldset class="mb-3">
        <legend style="font-weight:700;font-size:15px;color:var(--ink);margin-bottom:10px">3. Are you solely caring for a child below 22 years old, or a child of any age with a disability?</legend>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($yesNo as $opt): ?>
            <label class="option-btn option-radio" style="flex:1 1 120px">
              <input type="radio" name="child" value="<?= e($opt) ?>" <?= $answers['child'] === $opt ? 'checked' : '' ?> required> <?= e($opt) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <button type="submit" class="btn-navy">See my result</button>
    </form>
  </div>
<?php if ($standalone): ?>
</div>
<?php endif; ?>
