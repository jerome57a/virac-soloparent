<?php /** @var PDO $pdo */ ?>
<div style="max-width:780px">
  <p data-i18n>Contact MSWDO for help with requirements, application corrections, interviews, and ID claiming.</p>
  <div class="info-box">
    <strong><?= e(setting('office_name')) ?></strong>
    <div><?= e(setting('office_address')) ?></div>
    <div><span data-i18n>Office hours:</span> <span data-i18n><?= e(setting('office_hours')) ?></span></div>
    <?php if (setting('office_phone')): ?><div data-i18n>Phone: <a href="tel:<?= e(preg_replace('/[^+0-9]/', '', setting('office_phone'))) ?>"><?= e(setting('office_phone')) ?></a></div><?php endif; ?>
    <?php if (setting('office_email')): ?><div data-i18n>Email: <a href="mailto:<?= e(setting('office_email')) ?>"><?= e(setting('office_email')) ?></a></div><?php endif; ?>
  </div>
  <h2 class="section-title" data-i18n>Supporting documents</h2>
  <details class="doc-group-card"><summary data-i18n>How do I upload or replace a supporting document?</summary><p data-i18n>Upload the file for each required document in the Documents step of the application form; you cannot submit until every required file is uploaded. After you submit, open Supporting Documents to read staff remarks. If MSWDO requests a correction, upload a corrected replacement there.</p><a href="<?= base_url('portal.php?tab=apply') ?>" data-i18n>Start an application</a></details>
  <h2 class="section-title" data-i18n>Frequently asked questions</h2>
  <?php foreach (get_faqs($pdo) as $faq): ?>
    <details class="doc-group-card"><summary data-i18n><?= e($faq['question']) ?></summary><p data-i18n style="white-space:pre-line"><?= e($faq['answer']) ?></p></details>
  <?php endforeach; ?>
</div>
