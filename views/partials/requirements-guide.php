<?php
/**
 * Shared Requirements Guide ("How to get your requirements"): used by the applicant dashboard and the public Home Screen.
 * Guidance comes from RequirementGuideService; the page only supplies its own links.
 *
 * @var array   $requirementGuides  RequirementGuideService::catalog()
 * @var ?string $selectedGuideKey   a catalog key to show on its own, or null for every card
 * @var bool    $guideNotFound      a requested key is no longer in the catalog
 * @var string  $guideContactUrl    where "Contact MSWDO for help" goes
 * @var string  $guideBrowseUrl     where "Browse all requirements" goes
 */
$visibleGuides = $selectedGuideKey !== null ? [$selectedGuideKey => $requirementGuides[$selectedGuideKey]] : $requirementGuides;
?>
<div class="requirements-guide">
  <div class="requirements-guide-intro">
    <h2 data-i18n>How to get your requirements</h2>
    <p data-i18n>A document-by-document guide for applicants in Virac, Catanduanes. Start with MSWDO to confirm the documents for your category, then use the office routes and request steps below.</p>
    <p class="requirements-guide-reviewed">Sources reviewed <?= e(format_date_long(RequirementGuideService::REVIEWED_ON)) ?>. Each guide identifies confirmed national procedures and local arrangements you need to check. Follow your application checklist; these instructions do not add requirements.</p>
    <div class="requirements-guide-advisory"><strong data-i18n>Before visiting PSA Virac</strong><p data-i18n>From April 6, 2026, an online CRS appointment is required, including for National ID holders. The appointment must be under the actual requester's name. <a href="https://appointment.psa.gov.ph/" target="_blank" rel="noopener" data-i18n>Book through PSA</a> or read the <a href="https://rsso05.psa.gov.ph/content/full-implementation-civil-registration-service-appointment-system-crs-outlet-virac" target="_blank" rel="noopener" data-i18n>official Virac advisory</a>.</p></div>
    <a href="<?= e($guideContactUrl) ?>" class="requirements-guide-contact" data-i18n>Contact MSWDO for help &rarr;</a>
  </div>
  <details class="requirement-guide-card requirements-office-directory">
    <summary><span data-i18n>Offices to contact in Virac and the Bicol region</span><span class="requirement-guide-toggle" aria-hidden="true">+</span></summary>
    <div class="requirement-guide-body">
      <p data-i18n>Contact the issuing office before travelling. Older directory entries are marked; fees, appointment slots and release dates must be confirmed with the office.</p>
      <ul class="requirements-office-list">
        <?php foreach (RequirementGuideService::offices() as $office): ?>
          <li><h3><?= e($office['name']) ?></h3><p data-i18n><?= e($office['purpose']) ?></p><p><?= e($office['location']) ?><?php if ($office['contact']): ?><br><?= e($office['contact']) ?><?php endif; ?></p><a href="<?= e($office['url']) ?>" target="_blank" rel="noopener"><?= e($office['source']) ?> &rarr;</a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </details>
  <?php if ($guideNotFound): ?><div class="info-box" role="status" data-i18n>That requirement is no longer in the current checklist. Browse the available guides below or contact MSWDO.</div><?php endif; ?>
  <?php if ($selectedGuideKey !== null): ?><a href="<?= e($guideBrowseUrl) ?>" class="requirements-guide-back" data-i18n>&larr; Browse all requirements</a><?php endif; ?>
  <?php if (!$visibleGuides): ?><div class="empty-state" data-i18n><div class="empty-state-title" data-i18n>No requirements configured yet</div>Contact MSWDO for the current documentary requirements.</div><?php endif; ?>
  <div class="requirements-guide-list">
    <?php foreach ($visibleGuides as $key => $guide): ?>
      <details class="requirement-guide-card" id="guide-<?= e($key) ?>"<?= $selectedGuideKey !== null && (string) $selectedGuideKey === (string) $key ? ' open' : '' ?>>
        <?php $requiredFlags = array_values(array_unique(array_map(fn($a) => (bool) $a['required'], $guide['applies']))); ?>
        <summary><span class="requirement-guide-title"><span data-i18n><?= e($guide['label']) ?></span><?php if (count($requiredFlags) === 1): ?><span class="requirement-guide-status<?= $requiredFlags[0] ? ' is-required' : '' ?>" data-i18n><?= $requiredFlags[0] ? 'Required' : 'Optional' ?></span><?php endif; ?></span><span class="requirement-guide-toggle" aria-hidden="true">+</span></summary>
        <div class="requirement-guide-body">
          <h3 data-i18n>Applies to</h3>
          <ul class="requirement-guide-applies">
            <?php foreach ($guide['applies'] as $applies): ?>
              <li><span<?= $applies['category'] === null ? ' data-i18n' : '' ?>><?= e($applies['category'] ?? 'Shared / legacy standard requirement') ?></span> <span class="requirement-guide-status<?= $applies['required'] ? ' is-required' : '' ?>" data-i18n><?= $applies['required'] ? 'Required' : 'Optional' ?></span></li>
            <?php endforeach; ?>
          </ul>
          <?php if (count($guide['labels']) > 1): ?><p class="requirement-guide-variants">Also listed as: <?= e(implode(' · ', array_slice($guide['labels'], 1))) ?></p><?php endif; ?>
          <p class="requirement-guide-basis" data-i18n><?= e($guide['basis']) ?></p>
          <h3 data-i18n>Where to get it</h3><p data-i18n><?= e($guide['where']) ?></p>
          <h3 data-i18n>What to prepare</h3><ul data-i18n><?php foreach ($guide['prepare'] as $item): ?><li data-i18n><?= e($item) ?></li><?php endforeach; ?></ul>
          <h3 data-i18n>How to request it</h3><ol data-i18n><?php foreach ($guide['steps'] as $step): ?><li data-i18n><?= e($step) ?></li><?php endforeach; ?></ol>
          <?php if ($guide['notes']): ?><h3 data-i18n>Before you request or pay</h3><ul data-i18n><?php foreach ($guide['notes'] as $note): ?><li data-i18n><?= e($note) ?></li><?php endforeach; ?></ul><?php endif; ?>
          <?php if ($guide['sources']): ?>
            <div class="requirement-guide-sources" data-i18n><h3 data-i18n>Official reference</h3><?php foreach ($guide['sources'] as $label => $url): ?><a href="<?= e($url) ?>" target="_blank" rel="noopener" data-i18n><?= e($label) ?> &rarr;</a><?php endforeach; ?></div>
          <?php else: ?><p class="requirement-guide-local" data-i18n>Ask MSWDO or the issuing office for the local request procedure.</p><?php endif; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </div>
</div>
