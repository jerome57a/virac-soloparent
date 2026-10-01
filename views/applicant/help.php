<?php
/**
 * Applicant Help & Support: who to contact, where to get answers, and how the process works.
 *
 * @var array $office  name, address, hours, email, phone (System Settings)
 * @var bool  $hasApplication
 */
$links = [
    ['section=chat', 'Chat Support', 'Ask the Help Assistant, or send your question to an MSWDO social worker.'],
    ['section=application', $hasApplication ? 'Track my application' : 'Apply online', $hasApplication ? 'See where your application is and what to do next.' : 'Start your Solo Parent ID application.'],
    ['section=news', 'News & Announcements', 'Office schedules, payouts and program updates.'],
];
$steps = [
    ['Apply online', 'Fill in the form and upload your documents (JPG, PNG or PDF). Your draft is saved, so you can finish later.'],
    ['Book an appointment', 'Pick an interview slot. You will get an SMS confirmation and a reminder before the date.'],
    ['Track your status', 'MSWD reviews your documents. If something needs correcting you are notified and can re-upload it here.'],
    ['Claim your ID', 'Once approved, bring your QR application slip to the MSWDO office. Renew online before your ID expires.'],
];
?>
      <div class="dash-card">
        <div class="dash-card-label">Contact the MSWDO</div>
        <div style="font-weight:700;font-size:16px;color:var(--ink)"><?= e($office['name']) ?></div>
        <div class="review-row"><div class="k">Address</div><div class="v"><?= e($office['address']) ?></div></div>
        <div class="review-row"><div class="k">Office hours</div><div class="v"><?= e($office['hours']) ?></div></div>
        <?php if ($office['phone'] !== ''): ?>
          <div class="review-row"><div class="k">Phone</div><div class="v"><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $office['phone'])) ?>"><?= e($office['phone']) ?></a></div></div>
        <?php endif; ?>
        <?php if ($office['email'] !== ''): ?>
          <div class="review-row"><div class="k">Email</div><div class="v"><a href="mailto:<?= e($office['email']) ?>"><?= e($office['email']) ?></a></div></div>
        <?php endif; ?>
        <div style="font-size:13px;color:var(--muted);margin-top:10px">Never share your password or SMS reset code with anyone, including MSWDO staff.</div>
      </div>

      <div class="dash-card">
        <div class="dash-card-label">Get answers</div>
        <div class="help-links">
          <?php foreach ($links as [$query, $label, $text]): ?>
            <a href="?<?= e($query) ?>" class="help-link">
              <span class="help-link-title"><?= e($label) ?> &rarr;</span>
              <span class="help-link-text"><?= e($text) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="dash-card">
        <div class="dash-card-label">How it works</div>
        <ol class="help-steps">
          <?php foreach ($steps as [$title, $text]): ?>
            <li><b><?= e($title) ?>.</b> <?= e($text) ?></li>
          <?php endforeach; ?>
        </ol>
      </div>
