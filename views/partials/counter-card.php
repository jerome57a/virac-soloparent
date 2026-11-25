<?php
/**
 * Solo Parent, on the interview day: "Show this at the MSWDO counter" with the reference number in large type, and what to bring.
 * MSWDO staff type the number to check them in (the number only finds the record; staff also check the valid ID).
 *
 * @var string $counterRef      the reference number of the application being interviewed
 * @var bool   $counterRenewal  a renewal (then the current Solo Parent ID is also brought)
 * @var ?array $counterRelease  set on the ID release day: ['scheduled_at', 'location']; the card then says the ID is ready, and only a valid ID is brought
 */
$counterRelease = $counterRelease ?? null;
$relTs = $counterRelease ? strtotime((string) $counterRelease['scheduled_at']) : null;
require_once APP_ROOT . '/includes/icons.php';
?>
<link rel="stylesheet" href="<?= public_asset('css/office-today.css') ?>">
<section class="ot-counter" aria-labelledby="ot-counter-title">
  <?php if ($counterRelease): ?>
  <div class="ot-when"><span class="ot-when-d"><small><?= e(date('M', $relTs)) ?></small><b><?= e(date('j', $relTs)) ?></b></span><div><b id="ot-counter-title" data-i18n>Your ID is ready for release today</b><span><?= e(date('g:i A', $relTs)) ?> · <?= e((string) $counterRelease['location']) ?></span></div></div>
  <div class="ot-ref"><small data-i18n>Show this at the MSWDO counter</small><strong translate="no"><?= e($counterRef) ?></strong><span data-i18n>Only you can receive the ID, in person</span></div>
  <b class="ot-bring-h" data-i18n>Bring</b>
  <ul class="ot-bring"><li><?= ui_icon('circle-check', 16) ?><span data-i18n>A valid government ID</span></li></ul>
  <?php else: ?>
  <h3 id="ot-counter-title" data-i18n>Your interview is today</h3>
  <div class="ot-ref"><small data-i18n>Show this at the MSWDO counter</small><strong translate="no"><?= e($counterRef) ?></strong><span data-i18n>MSWDO Office, Virac · any time 7:00 AM to 5:00 PM</span></div>
  <b class="ot-bring-h" data-i18n>Bring</b>
  <ul class="ot-bring">
    <li><?= ui_icon('circle-check', 16) ?><span data-i18n>A valid government ID</span></li>
    <li><?= ui_icon('circle-check', 16) ?><span data-i18n>Two 1x1 ID pictures</span></li>
    <li><?= ui_icon('circle-check', 16) ?><span data-i18n>The original documents you uploaded</span></li>
    <?php if (!empty($counterRenewal)): ?><li><?= ui_icon('circle-check', 16) ?><span data-i18n>Your current Solo Parent ID</span></li><?php endif; ?>
  </ul>
  <?php endif; ?>
</section>
