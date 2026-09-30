<?php
/** @var PDO $pdo */
$applyUrl = base_url('portal.php?tab=apply');
$eligUrl = base_url('eligibility.php');
?>
<div style="font-size:15px;color:var(--muted);margin-bottom:28px;max-width:780px">Everything you need to know before applying: eligibility, benefits, requirements, and how to apply online through e-SoloParentID.</div>

<h2 class="section-title" style="font-family:var(--font-body);margin-top:0">What is the Solo Parent ID?</h2>

<div class="text-center" style="margin:0 0 24px">
<img 
    src="<?= asset('images/solo-parent-benefits-hero.png') ?>" 
    alt="A solo parent and child, surrounded by Solo Parent ID benefit icons and a sample ID card"
    style="
        width: 100%;
        max-width: 480px;
        height: auto;
        border-radius: 12px;
        display: block;
        margin: 15px 0 20px 0;
    "
>
</div>

<div class="body-copy">The Solo Parent Identification Card (SPIC) is an official government-issued ID issued by the Municipal Social Welfare and Development Office (MSWDO) to qualified solo parents in the Municipality of Virac, Catanduanes, pursuant to Republic Act No. 8972 (Solo Parents' Welfare Act of 2000), as expanded by Republic Act No. 11861 (Expanded Solo Parents Welfare Act of 2022).</div>
<div class="body-copy">Holding a Solo Parent ID grants access to a range of government benefits and privileges designed to assist solo parents in raising their children. The ID is valid for one year and must be renewed annually at the MSWDO.</div>
<div class="info-box">📱 New: Through the e-SoloParentID system, qualified solo parents in Virac can now apply, submit documents, and track their application entirely online, without visiting the MSWDO office.</div>

<div id="benefits" class="section-title" style="margin-bottom:4px">Benefits of Having a Solo Parent ID</div>
<div class="section-sub">Having a Solo Parent ID provides access to various benefits and support, including:</div>

<img
    src="<?= asset('images/solo-parent01.png') ?>"
    alt="Benefits of Having a Solo Parent ID"
    class="img-fluid"
    style="
        width: 100%;
        max-width: 480px;
        height: auto;
        border-radius: 12px;
        display: block;
        margin: 15px 0 20px 0;
    "
>

<div class="benefit-grid">
  <?php foreach (benefit_cards() as $b): ?>
      <div class="benefit-card">
        <div class="icon"><?= $b['icon'] ?></div>
        <div class="title"><?= e($b['title']) ?></div>
        <div class="desc"><?= e($b['desc']) ?></div>
      </div>
  <?php endforeach; ?>
</div>

<div id="eligibility" class="section-title" style="margin-bottom:4px">Who is Eligible? (Section 3, RA 8972 / RA 11861)</div>
<div class="section-sub">You may qualify as a solo parent under any of the following circumstances:</div>
<div class="bullet-list" style="margin-bottom:16px">
  <?php foreach (eligibility_list() as $el): ?>
    <div class="bullet-row"><div class="bullet-dot"></div><div><b><?= e($el['title']) ?></b>: <?= e($el['desc']) ?></div></div>
  <?php endforeach; ?>
</div>
<div class="warn-box">💡 Not sure if you qualify? Use the Eligibility Checker below, or visit the MSWDO Office for a personal assessment.</div>

<div id="requirements" class="section-title" style="margin-bottom:4px">Required Documents</div>
<div class="section-sub" style="max-width:780px">Prepare the following documents before applying. Bring both the original and photocopies when submitting. When applying through e-SoloParentID, upload clear digital copies (JPG, PNG, or PDF; max 5MB each).</div>
<div class="doc-groups">
  <?php foreach (required_doc_groups() as $g): ?>
    <div class="doc-group-card">
      <div class="title"><?= e($g['title']) ?></div>
      <?php foreach ($g['items'] as $it): ?><div class="item">· <?= e($it) ?></div><?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="section-title" style="margin-bottom:16px">How to Apply via e-SoloParentID</div>
<div class="section-sub" style="max-width:780px;margin-bottom:20px">Follow these steps to apply for your Solo Parent ID online through the MSWDO of Virac's e-SoloParentID system:</div>
<div class="how-steps">
  <?php foreach (apply_steps_overview() as $st): ?>
    <div class="text-center">
      <div class="apply-step-circle"><?= (int) $st['n'] ?></div>
      <div style="font-weight:700;font-size:14px;color:var(--ink);margin-bottom:4px"><?= e($st['title']) ?></div>
      <div style="font-size:13px;color:var(--muted);line-height:1.5"><?= e($st['desc']) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<div style="font-size:13px;color:var(--muted);max-width:780px;margin-bottom:36px">You may also apply in person at the MSWDO Office, Municipal Building, Salvacion, Virac, Catanduanes during office hours (Mon–Fri, 8:00 AM – 5:00 PM). Bring original and photocopies of all required documents.</div>

<div class="section-title" style="margin-bottom:14px">Important Reminders</div>
<div class="bullet-list" style="margin-bottom:36px">
  <?php foreach (important_reminders() as $r): ?>
    <div class="bullet-row"><div class="bullet-dot red"></div><div><?= e($r) ?></div></div>
  <?php endforeach; ?>
</div>

<div class="cta-row">
  <a href="<?= e($eligUrl) ?>" class="btn-navy text-decoration-none">✦ Check Your Eligibility</a>
  <a href="<?= e($applyUrl) ?>" class="btn-red text-decoration-none">✦ Apply Online Now</a>
</div>
