<?php
/**
 * Preliminary Eligibility Result. Standalone on eligibility.php; also
 * embedded in the applicant dashboard with $standalone = false. This is a
 * guide only -- never presented as MSWD's decision.
 *
 * @var array  $answers
 * @var array  $result     ['eligible' => bool, 'reason' => ?string]
 * @var string $checkedAt
 * @var bool   $loggedIn   an applicant is signed in (offer "Start application" instead of login/register)
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
    <div style="font-size:12px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--muted)">Preliminary Eligibility Result</div>
    <div style="font-size:12px;color:var(--faint);margin-bottom:14px">Checked <?= e(date('F j, Y g:i A', strtotime($checkedAt))) ?></div>

    <div class="bullet-list" style="margin-bottom:18px;font-size:14px">
      <div class="bullet-row"><div class="bullet-dot"></div><div>Resident of Virac: <b><?= e($answers['resident']) ?></b></div></div>
      <div class="bullet-row"><div class="bullet-dot"></div><div>Situation: <b><?= e($answers['circ']) ?></b></div></div>
      <div class="bullet-row"><div class="bullet-dot"></div><div>Solely caring for a qualifying child: <b><?= e($answers['child']) ?></b></div></div>
    </div>

    <?php if ($result['eligible']): ?>
      <div class="result-box success" role="status">
        <div style="flex:1 1 260px">
          <div class="title">You may be eligible to apply.</div>
          <div class="desc">Based on your answers, you appear to meet the preliminary criteria for a Solo Parent ID. <?= $loggedIn ? 'You can start your application now.' : 'Log in or create an account to start your application.' ?></div>
        </div>
        <?php if ($loggedIn): ?>
          <a href="<?= e($applyUrl) ?>" class="btn-red" style="padding:12px 22px;font-size:14px;text-decoration:none;display:inline-block">Start Your Application</a>
        <?php else: ?>
          <div class="d-flex flex-column gap-2" style="flex:0 0 auto">
            <a href="<?= base_url('login.php') ?>" class="btn-red text-center" style="padding:12px 22px;font-size:14px;text-decoration:none;display:inline-block">Proceed to Login / Register</a>
            <a href="<?= base_url('register.php') ?>" style="font-size:13px;text-align:center">New here? Create an account</a>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="result-box warning" role="status">
        <div class="title">Based on your answers, you may not currently meet the preliminary criteria.</div>
        <div class="desc"><?= e($result['reason']) ?></div>
        <div class="desc" style="margin-top:8px">If your situation is different from what the questions describe, or it changes, MSWDO can still assess you in person: MSWDO Office, Municipal Building, Salvacion, Virac (Mon–Fri, 8:00 AM – 5:00 PM). You can also <a href="<?= e($restartUrl) ?>">check again</a>.</div>
      </div>
    <?php endif; ?>

    <div style="font-size:13px;color:var(--ink);background:var(--surface-alt, #f3f5f8);border-radius:6px;padding:10px 14px;margin-top:14px">
      ℹ️ This is a <b>preliminary</b> result only, not an official decision. Final eligibility is subject to MSWD verification and assessment of your documents and circumstances.
    </div>
    <div style="margin-top:16px">
      <a href="<?= e($restartUrl) ?>" class="btn-outline text-decoration-none" style="padding:8px 16px;font-size:13px">Check again</a>
    </div>
  </div>
<?php if ($standalone): ?>
</div>
<?php endif; ?>
