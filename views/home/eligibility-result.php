<?php
/**
 * Eligibility result. Standalone on eligibility.php; also embedded in the
 * applicant dashboard with $standalone = false.
 *
 * @var array  $answers
 * @var array  $result   ['eligible' => bool, 'reason' => ?string]
 * @var string $applyUrl
 * @var string $restartUrl
 * @var bool   $standalone
 */
$standalone = $standalone ?? true;
?>
<?php if ($standalone): ?>
<div class="portal-container">
  <a href="<?= base_url('portal.php') ?>" style="font-size:13px">&larr; Back to the Solo Parent ID guide</a>
<?php endif; ?>
  <div class="elig-panel">
    <div class="font-heading mb-3" style="font-size:22px">Your Eligibility Result</div>

    <div class="bullet-list" style="margin-bottom:18px;font-size:14px">
      <div class="bullet-row"><div class="bullet-dot"></div><div>Resident of Virac: <b><?= e($answers['resident']) ?></b></div></div>
      <div class="bullet-row"><div class="bullet-dot"></div><div>Situation: <b><?= e($answers['circ']) ?></b></div></div>
      <div class="bullet-row"><div class="bullet-dot"></div><div>Solely caring for a qualifying child: <b><?= e($answers['child']) ?></b></div></div>
    </div>

    <?php if ($result['eligible']): ?>
      <div class="result-box success">
        <div style="flex:1 1 260px">
          <div class="title">You likely qualify for a Solo Parent ID.</div>
          <div class="desc">Prepare the required documents, then start your application. Final approval is subject to MSWDO review.</div>
        </div>
        <a href="<?= e($applyUrl) ?>" class="btn-red" style="padding:12px 22px;font-size:14px;text-decoration:none;display:inline-block">Apply Online Now</a>
      </div>
    <?php else: ?>
      <div class="result-box warning">
        <div class="title">You may not qualify at this time.</div>
        <div class="desc"><?= e($result['reason']) ?> Visit the MSWDO Office, Municipal Building, Salvacion, Virac (Mon–Fri, 8:00 AM – 5:00 PM) for a personal assessment.</div>
      </div>
    <?php endif; ?>

    <div style="font-size:12px;color:var(--faint);margin-top:14px">This result is a guide only. The final assessment remains with the MSWD Social Worker.</div>
    <div style="margin-top:16px">
      <a href="<?= e($restartUrl) ?>" class="btn-outline text-decoration-none" style="padding:8px 16px;font-size:13px">Start over</a>
    </div>
  </div>
<?php if ($standalone): ?>
</div>
<?php endif; ?>
