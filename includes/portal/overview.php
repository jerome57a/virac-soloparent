<?php
/** @var PDO $pdo */
$applyUrl = base_url(!empty($user) ? 'applicant/dashboard.php?section=apply' : 'portal.php?tab=apply');
$eligUrl = base_url('eligibility.php');
?>
<nav class="home-actions" aria-label="Solo Parent services">
  <a class="btn-navy text-decoration-none" href="<?= e($eligUrl) ?>" data-i18n>Check Eligibility</a>
  <a class="btn-red text-decoration-none" data-i18n href="<?= e($applyUrl) ?>"><?= !empty($user) ? 'Continue Application' : 'Apply for Solo Parent ID' ?></a>
  <a class="btn-outline text-decoration-none" data-i18n href="<?= base_url(!empty($user) ? 'applicant/dashboard.php?section=application' : 'portal.php?tab=status') ?>"><?= !empty($user) ? 'My Applications' : 'Track Application' ?></a>
</nav>
<nav class="home-jump-links" aria-label="Guide sections">
  <a href="#benefits" data-i18n>Benefits</a><a href="#eligibility" data-i18n>Eligibility</a><a href="#requirements" data-i18n>Requirements</a><a href="#how-to-apply" data-i18n>How to Apply</a><a href="<?= base_url('portal.php?tab=help') ?>" data-i18n>Contact &amp; Help</a>
</nav>
<div style="font-size:15px;color:var(--muted);margin-bottom:28px" data-i18n>Everything you need to know before applying: eligibility, benefits, requirements, and how to apply online through e-SoloParentID.</div>

<section class="home-intro" aria-labelledby="home-intro-heading">
  <div class="home-intro-copy">
    <h2 id="home-intro-heading" class="section-title" data-i18n>What is the Solo Parent ID?</h2>
<div class="body-copy" data-i18n>The Solo Parent Identification Card (SPIC) is an official government-issued ID issued by the Municipal Social Welfare and Development Office (MSWDO) to qualified solo parents in the Municipality of Virac, Catanduanes, pursuant to Republic Act No. 8972 (Solo Parents' Welfare Act of 2000), as expanded by Republic Act No. 11861 (Expanded Solo Parents Welfare Act of 2022).</div>
<div class="body-copy" data-i18n>Holding a Solo Parent ID grants access to a range of government benefits and privileges designed to assist solo parents in raising their children. The ID is valid for one year and must be renewed annually at the MSWDO.</div>
  </div>
  <div class="home-intro-illustration">
    <img src="<?= asset('images/solo-parent-benefits-hero.png') ?>"
         alt="A solo parent and child, surrounded by Solo Parent ID benefit icons and a sample ID card">
  </div>
</section>

<div class="info-box" data-i18n>📱 New: Through the e-SoloParentID system, solo parents in Virac can complete the application form, upload the required supporting documents, and track their application online. Follow MSWDO instructions for document review, interviews, and ID claiming.</div>

<section class="home-benefits" aria-labelledby="benefits">
  <h2 id="benefits" class="section-title" data-i18n>Benefits of Having a Solo Parent ID</h2>
  <p class="section-sub" data-i18n>Having a Solo Parent ID provides access to various benefits and support, including:</p>
  <div class="benefit-grid">
    <?php foreach (benefit_cards() as $b): ?>
      <article class="benefit-card">
        <div class="benefit-icon"><?= benefit_icon_svg($b['icon']) ?></div>
        <h3 class="title" data-i18n><?= e($b['title']) ?></h3>
        <p class="desc" data-i18n><?= e($b['desc']) ?></p>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<div id="eligibility" class="section-title" style="margin-bottom:4px" data-i18n>Who is Eligible? (Section 3, RA 8972 / RA 11861)</div>
<div class="section-sub" data-i18n>You may qualify as a solo parent under any of the following circumstances:</div>
<div class="bullet-list" style="margin-bottom:16px">
  <?php foreach (eligibility_list() as $el): ?>
    <div class="bullet-row"><div class="bullet-dot"></div><div data-i18n><b data-i18n><?= e($el['title']) ?></b>: <span data-i18n><?= e($el['desc']) ?></span></div></div>
  <?php endforeach; ?>
</div>
<div class="warn-box" data-i18n>💡 Not sure if you qualify? Use the Eligibility Checker below, or visit the MSWDO Office for a personal assessment.</div>

<div id="requirements" class="section-title" style="margin-bottom:4px" data-i18n>Required Documents</div>
<div class="section-sub" data-i18n>Open your category below to see the configured requirements for that category. Upload a file for each required document after you submit your application; an uploaded file is not verified until MSWDO staff review it, and you may be asked to replace it. Bring originals and photocopies as instructed for document review and your interview.</div>
<div class="doc-groups home-requirements">
  <?php foreach (required_doc_groups($pdo) as $g): ?>
    <details class="doc-group-card">
      <summary class="title">
        <span data-i18n><?= e($g['title']) ?></span>
        <svg class="requirement-chevron" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
      </summary>
      <?php if ($g['items']): ?>
        <ul class="home-requirement-list">
          <?php foreach ($g['items'] as $it): ?>
            <li>
              <span class="home-requirement-label" data-i18n><?= e($it['label']) ?></span>
              <span data-i18n class="home-requirement-badge<?= !empty($it['is_required']) ? ' is-required' : '' ?>"><?= !empty($it['is_required']) ? 'Required' : 'Optional' ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="item" data-i18n>Contact MSWDO for the requirements for this category.</div>
      <?php endif; ?>
    </details>
  <?php endforeach; ?>
</div>

<div id="how-to-apply" class="section-title" style="margin-bottom:16px" data-i18n>How to Apply via e-SoloParentID</div>
<div class="section-sub" style="margin-bottom:20px" data-i18n>Follow these steps to apply for your Solo Parent ID online through the MSWDO of Virac's e-SoloParentID system:</div>
<ol class="how-steps home-application-steps" aria-label="Application steps">
  <?php foreach (apply_steps_overview() as $st): ?>
    <li class="home-application-step">
      <div class="apply-step-circle" aria-hidden="true"><?= (int) $st['n'] ?></div>
      <div class="home-application-step-copy">
        <h3 data-i18n><?= e($st['title']) ?></h3>
        <p data-i18n><?= e($st['desc']) ?></p>
      </div>
    </li>
  <?php endforeach; ?>
</ol>
<div style="font-size:13px;color:var(--muted);margin-bottom:36px">You may also apply in person at the <?= e(setting('office_name')) ?> office, <?= e(setting('office_address')) ?>, during office hours (<?= e(setting('office_hours')) ?>). Bring original and photocopies of all required documents.</div>

<section class="home-reminders" aria-labelledby="home-reminders-heading">
  <h2 id="home-reminders-heading" class="section-title" data-i18n>Important Reminders</h2>
  <ul class="home-reminders-list">
    <?php foreach (important_reminders() as $r): ?>
      <li data-i18n><?= e($r) ?></li>
    <?php endforeach; ?>
  </ul>
</section>
