<?php
/** @var array $office @var bool $hasApplication @var array $faqs */
$links = [
    ['section=requirements', 'documents', 'Requirements guide', 'Find the issuing office, what to bring, and steps to get each document.'],
    [$hasApplication ? 'section=application' : 'section=apply', 'application', $hasApplication ? 'Track my application' : 'Apply online', $hasApplication ? 'See your progress, MSWDO staff remarks, and the next action needed.' : 'Start your Solo Parent ID application and save your progress.'],
];
if ($hasApplication) {
    $links[] = ['section=documents', 'documents', 'Supporting documents', 'Upload required documents and replace files when MSWDO requests corrections.'];
}
$steps = [
    ['Apply online', 'Fill in your details, upload the required documents and submit your application. Save your draft if you need to finish later.'],
    ['Follow the review', 'Check My Application for status and MSWDO staff remarks. If corrections are requested, open Supporting Documents, replace the flagged file, and submit it for review.'],
    ['Attend your interview', 'MSWDO will text your interview date and time and show it under Appointment. Bring the requested original documents and your application reference.'],
    ['Claim your Solo Parent ID', 'After approval, follow your release appointment or MSWDO instructions. Your ID appears in My ID after it is released.'],
];
$helpIcons = [
    'documents' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/>',
    'application' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8m-8 4h5"/>',
];
?>
<link rel="stylesheet" href="<?= public_asset('css/applicant-account.css') ?>">
<div class="applicant-help-page">
  <div class="help-workspace">
    <div class="help-main">
      <section class="help-resource-section" aria-labelledby="help-resources-heading"><div class="help-section-header"><h3 id="help-resources-heading" data-i18n>What do you need help with?</h3><p data-i18n>Choose a topic to go straight to the right place.</p></div>
        <div class="help-resource-grid" data-i18n>
          <?php foreach ($links as [$query, $icon, $label, $text]): ?><a href="?<?= e($query) ?>" class="help-resource-link"><span class="help-resource-symbol" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><?= $helpIcons[$icon] ?></svg></span><span class="help-resource-title" data-i18n><?= e($label) ?><span aria-hidden="true">&rarr;</span></span><span class="help-resource-text" data-i18n><?= e($text) ?></span></a><?php endforeach; ?>
        </div>
      </section>
      <section class="help-process-card" aria-labelledby="help-faq-heading">
        <div class="help-section-header"><h3 id="help-faq-heading" data-i18n>Frequently asked questions</h3><p data-i18n>Answers to common questions about Solo Parent ID services.</p></div>
        <?php if ($faqs): ?>
          <label for="help-faq-search" class="visually-hidden" data-i18n>Search frequently asked questions</label>
          <input type="search" id="help-faq-search" class="form-control mb-3" placeholder="Search questions or answers" data-i18n-attrs="placeholder">
          <div class="help-faq-list">
            <?php foreach ($faqs as $faq): ?>
              <details class="help-faq-item" data-help-faq="<?= e(mb_strtolower($faq['question'] . ' ' . $faq['answer'])) ?>"><summary data-i18n><?= e($faq['question']) ?></summary><div data-i18n><?= nl2br(e($faq['answer'])) ?></div></details>
            <?php endforeach; ?>
          </div>
          <p id="help-faq-empty" role="status" hidden data-i18n>No FAQ entries match your search.</p>
        <?php else: ?>
          <p data-i18n>No FAQ entries are currently available. Contact MSWDO using the details on this page.</p>
        <?php endif; ?>
      </section>
      <section class="help-process-card" aria-labelledby="help-process-heading"><div class="help-section-header"><div class="account-eyebrow" data-i18n>FROM APPLICATION TO RELEASE</div><h3 id="help-process-heading" data-i18n>How it works</h3><p data-i18n>Know what happens next at each stage.</p></div><ol class="help-process-steps" data-i18n>
        <?php foreach ($steps as $i => [$title, $text]): ?><li><span class="help-step-number" aria-hidden="true"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span><div><h4 data-i18n><?= e($title) ?></h4><p data-i18n><?= e($text) ?></p></div></li><?php endforeach; ?>
      </ol></section>
    </div>
    <aside class="help-contact-card" id="help-contact" aria-labelledby="help-contact-heading"><div class="account-eyebrow" data-i18n>WE’RE HERE TO HELP</div><h3 id="help-contact-heading" data-i18n>Contact MSWDO</h3><p class="help-office-name"><?= e($office['name']) ?></p>
      <dl><div><dt data-i18n>Visit us</dt><dd><?= e($office['address']) ?></dd></div><div><dt data-i18n>Office hours</dt><dd><?= e($office['hours']) ?></dd></div>
      <?php if ($office['phone'] !== ''): ?><div><dt data-i18n>Phone</dt><dd><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $office['phone'])) ?>"><?= e($office['phone']) ?></a></dd></div><?php endif; ?>
      <?php if ($office['email'] !== ''): ?><div><dt data-i18n>Email</dt><dd><a href="mailto:<?= e($office['email']) ?>"><?= e($office['email']) ?></a></dd></div><?php endif; ?></dl>
      <div class="help-privacy-note"><strong data-i18n>Keep your account private</strong><p data-i18n>Never share your password or SMS reset code with anyone, including MSWDO staff.</p></div>
    </aside>
  </div>
</div>
<script>
  const faqSearch = document.getElementById('help-faq-search');
  if (faqSearch) faqSearch.addEventListener('input', () => {
    const query = faqSearch.value.trim().toLocaleLowerCase();
    const entries = [...document.querySelectorAll('[data-help-faq]')];
    let visible = 0;
    entries.forEach(entry => {
      entry.hidden = query !== '' && !entry.dataset.helpFaq.includes(query);
      if (!entry.hidden) visible++;
    });
    document.getElementById('help-faq-empty').hidden = visible !== 0 || query === '';
  });
</script>
