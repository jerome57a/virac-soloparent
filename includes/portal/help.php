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
  <h2 class="section-title" data-i18n>Common Questions</h2>
  <details class="doc-group-card"><summary data-i18n>How do I apply?</summary><p data-i18n>Check your preliminary eligibility, create an account, complete the application and document checkboxes, then submit. Follow MSWDO instructions for document review, interviews, and ID claiming.</p><a href="<?= base_url('portal.php?tab=apply') ?>" data-i18n>Apply for Solo Parent ID</a></details>
  <details class="doc-group-card"><summary data-i18n>Do I upload my required documents?</summary><p data-i18n>Use the checkbox checklist to indicate the documents you have available. Staff verify documents separately. Bring originals and photocopies as instructed by MSWDO.</p><a href="<?= base_url('portal.php#requirements') ?>" data-i18n>View requirements by category</a></details>
  <details class="doc-group-card"><summary data-i18n>Where can I find my application reference number?</summary><p data-i18n>Your submitted application and application slip show the reference number. Log in to My Applications to find it. Public tracking only requires your application reference number.</p><a href="<?= base_url('login.php?next=applications') ?>" data-i18n>Log In to My Applications</a></details>
  <details class="doc-group-card"><summary data-i18n>Does an eligibility result mean my application is approved?</summary><p data-i18n>The checker gives a preliminary result. Final eligibility and approval depend on MSWDO assessment and document review. Monitor your application for appointment and claiming instructions.</p></details>
  <?php foreach (get_faqs($pdo) as $faq): ?>
    <details class="doc-group-card"><summary data-i18n><?= e($faq['question']) ?></summary><p data-i18n style="white-space:pre-line"><?= e($faq['answer']) ?></p></details>
  <?php endforeach; ?>
</div>
