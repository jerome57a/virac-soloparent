<?php
/** @var PDO $pdo */
$applyUrl = base_url('portal.php?tab=apply');
$eligUrl = '#elig-checker';
?>
<h2 class="font-heading" style="font-size:26px;margin:0 0 6px;color:var(--ink)">A Guide to the Solo Parent ID</h2>
<div style="font-size:15px;color:var(--muted);margin-bottom:28px;max-width:780px">Everything you need to know before applying: eligibility, benefits, requirements, and how to apply online through e-SoloParentID.</div>

<div class="section-title">What is the Solo Parent ID?</div>
<div class="body-copy">The Solo Parent Identification Card (SPIC) is an official government-issued ID issued by the Municipal Social Welfare and Development Office (MSWDO) to qualified solo parents in the Municipality of Virac, Catanduanes, pursuant to Republic Act No. 8972 (Solo Parents' Welfare Act of 2000), as expanded by Republic Act No. 11861 (Expanded Solo Parents Welfare Act of 2022).</div>
<div class="body-copy">Holding a Solo Parent ID grants access to a range of government benefits and privileges designed to assist solo parents in raising their children. The ID is valid for one year and must be renewed annually at the MSWDO.</div>
<div class="info-box">📱 New: Through the e-SoloParentID system, qualified solo parents in Virac can now apply, submit documents, and track their application entirely online, without visiting the MSWDO office.</div>


<div style="font-size:12px;color:var(--faint);margin-bottom:36px">Sample Solo Parent ID card design for the Municipality of Virac (for illustration only).</div>

<div class="section-title" style="margin-bottom:4px">Benefits of Having a Solo Parent ID</div>
<div class="section-sub">Having a Solo Parent ID provides access to various benefits and support, including:</div>
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

<div class="section-title" style="margin-bottom:4px">Required Documents</div>
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
  <a href="#elig-checker" id="open-elig-btn" class="btn-navy text-decoration-none">✦ Check Your Eligibility</a>
  <a href="<?= e($applyUrl) ?>" class="btn-red text-decoration-none">✦ Apply Online Now</a>
</div>

<div id="elig-checker" class="elig-panel" data-apply-url="<?= e($applyUrl) ?>" style="display:none">
  <div class="d-flex justify-content-between align-items-start gap-3 mb-1">
    <div class="font-heading" style="font-size:22px">Eligibility Checker</div>
    <button type="button" class="elig-close" id="elig-close-btn" aria-label="Close">×</button>
  </div>
  <div style="font-size:14px;color:var(--muted);margin-bottom:22px">Use the Eligibility Checker to confirm you qualify. Answer three quick questions.</div>

  <div style="font-weight:700;font-size:15px;color:var(--ink);margin-bottom:10px">1. Are you a resident of Virac, Catanduanes?</div>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach (['Yes', 'No'] as $opt): ?>
      <button type="button" class="option-btn" style="flex:1 1 120px" data-elig-group="resident" data-elig-value="<?= e($opt) ?>"><?= e($opt) ?></button>
    <?php endforeach; ?>
  </div>

  <div style="font-weight:700;font-size:15px;color:var(--ink);margin-bottom:10px">2. Which best describes your situation?</div>
  <div class="d-flex flex-column gap-2 mb-3">
    <?php foreach (eligibility_list() as $el): ?>
      <button type="button" class="option-btn wide" data-elig-group="circ" data-elig-value="<?= e($el['title']) ?>"><?= e($el['title']) ?></button>
    <?php endforeach; ?>
    <button type="button" class="option-btn wide" data-elig-group="circ" data-elig-value="None of these apply to me">None of these apply to me</button>
  </div>

  <div style="font-weight:700;font-size:15px;color:var(--ink);margin-bottom:10px">3. Are you solely caring for a child below 22 years old, or a child of any age with a disability?</div>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach (['Yes', 'No'] as $opt): ?>
      <button type="button" class="option-btn" style="flex:1 1 120px" data-elig-group="child" data-elig-value="<?= e($opt) ?>"><?= e($opt) ?></button>
    <?php endforeach; ?>
  </div>

  <div id="elig-result"></div>
  <div style="margin-top:16px">
    <button type="button" id="elig-reset" class="btn-outline" style="padding:8px 16px;font-size:13px">Start over</button>
  </div>
</div>

<script>
  document.getElementById('open-elig-btn').addEventListener('click', function (e) {
    e.preventDefault();
    var panel = document.getElementById('elig-checker');
    panel.style.display = 'block';
    var y = panel.getBoundingClientRect().top + window.scrollY - 20;
    window.scrollTo({ top: y, behavior: 'smooth' });
  });
  document.getElementById('elig-close-btn').addEventListener('click', function () {
    document.getElementById('elig-checker').style.display = 'none';
  });
</script>
