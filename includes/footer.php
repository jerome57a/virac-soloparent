<?php
/**
 * Shared page footer. Expects $footerVariant = 'home' | 'services' | 'portal'.
 */
$footerVariant = $footerVariant ?? 'services';

$variants = [
    'home' => [
        'about' => "Virac, officially the Municipality of Virac, is the capital of the province of Catanduanes, Philippines.",
        'col2title' => 'CATEGORIES',
        'col2' => ['News', 'Transparency', 'Resolutions', 'Solo Parent Services'],
    ],
    'services' => [
        'about' => "Browse and access LGU Virac's online services, starting with Solo Parent ID registration.",
        'col2title' => 'ONLINE SERVICES',
        'col2' => ['Solo Parent ID Services', 'Apply Online', 'Check Status'],
    ],
    'portal' => [
        'about' => 'Online Services — Solo Parent ID registration, and status tracking for residents of Virac, Catanduanes.',
        'col2title' => 'ONLINE SERVICES',
        'col2' => ['Apply Online', 'My Applications', 'Check Status'],
    ],
];
$v = $variants[$footerVariant] ?? $variants['services'];
?>
<footer class="site-footer">
  <div>
    <div class="footer-brand">VIRAC<br>CATANDUANES</div>
    <div class="footer-small"><?= e($v['about']) ?></div>
  </div>
  <div>
    <div class="footer-heading"><?= e($v['col2title']) ?></div>
    <div class="footer-small footer-list"><?= implode('<br>', array_map('e', $v['col2'])) ?></div>
  </div>
  <div>
    <div class="footer-heading">DIRECTORY</div>
    <div class="footer-small footer-list">Office of the Municipal Mayor<br>MSWDO-Virac<br>MDRRMO-Virac</div>
  </div>
  <div>
    <div class="footer-heading">CONTACT DETAILS</div>
    <div class="footer-contact"><?= e(setting('office_address')) ?><br><?= e(setting('office_email')) ?><br><?= e(setting('office_phone')) ?></div>
    <div class="footer-logos">
      <img src="<?= asset('images/transparency-seal.png') ?>" alt="Philippine Transparency Seal" class="footer-seal">
      <img src="<?= asset('images/bagong-pilipinas-logo.png') ?>" alt="Bagong Pilipinas" class="footer-bp">
    </div>
  </div>
</footer>

<?php if ($footerVariant === 'home'): ?>
<div class="gov-footer">
  <div></div>
  <div>
    <div class="gov-heading">REPUBLIC OF THE PHILIPPINES</div>
    <div class="gov-small">All content is in the public domain unless otherwise stated.</div>
  </div>
  <div class="gov-cols">
    <div>
      <div class="gov-heading">ABOUT GOVPH</div>
      <div class="gov-small" style="margin-bottom:10px">Learn more about the Philippine government, its structure, how government works and the people behind it.</div>
      <div class="gov-small gov-links">GOV.PH<br>Open Data Portal<br>Official Gazette</div>
    </div>
    <div>
      <div class="gov-heading">GOVERNMENT LINKS</div>
      <div class="gov-small gov-links">Office of the President<br>Office of the Vice President<br>Senate of the Philippines<br>House of Representatives<br>Supreme Court<br>Court of Appeals<br>Sandiganbayan</div>
    </div>
  </div>
</div>
<?php else: ?>
<div class="footer-bottom-bar">All content is in the public domain unless otherwise stated. · Republic of the Philippines</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="<?= public_asset('js/app.js') ?>"></script>
</body>
</html>
