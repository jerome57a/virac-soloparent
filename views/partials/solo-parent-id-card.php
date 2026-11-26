<?php
/**
 * Shared front/back ID, rendered only after the caller checks access.
 * $verificationSample = true: a SAMPLE of the card for the Solo Parent (the blank card with SAMPLE across it, none of their details on it).
 */
$sample = !empty($verificationSample);
$card = $sample ? null : ($verificationCard ?? null);
$cardChildren = $card['children'] ?? [];
$a = $sample ? ['reference_no' => '', 'full_name' => '', 'category_name' => '', 'id_expires_at' => null] : $a;
?>
<button type="button" class="verification-id<?= $sample ? ' is-sample' : '' ?>" id="verification-id" aria-label="<?= $sample ? 'Sample of the Solo Parent ID. Show back of ID.' : 'Solo Parent ID for ' . e($a['full_name']) . ', reference ' . e($a['reference_no']) . '. Show back of ID.' ?>" aria-pressed="false" title="Click to flip the ID">
  <span class="verification-id-inner">
    <span class="verification-id-face verification-id-front" aria-hidden="false">
      <img src="<?= asset('images/solo-parent-id/front.png') ?>" alt="Solo Parent Identification Card — front">
      <?php if ($sample): ?><span class="verification-id-sample" aria-hidden="true">SAMPLE</span><?php endif; ?>
      <span class="verification-id-field verification-id-number"><?= e($a['reference_no']) ?></span>
      <span class="verification-id-field verification-id-name"><?= e($a['full_name']) ?></span>
      <span class="verification-id-field verification-id-birth"><?= $card ? e(format_date_long($card['birthdate'])) : '' ?></span>
      <span class="verification-id-field verification-id-address"><?= $card ? e($card['address'] . ', ' . $card['barangay'] . ', Virac') : '' ?></span>
      <span class="verification-id-field verification-id-category"><?= e($a['category_name']) ?></span>
      <span class="verification-id-field verification-id-expiry"><?= $a['id_expires_at'] ? e(format_date_long($a['id_expires_at'])) : '' ?></span>
    </span>
    <span class="verification-id-face verification-id-back" aria-hidden="true" inert>
      <img src="<?= asset('images/solo-parent-id/back.png') ?>" alt="Solo Parent Identification Card — back">
      <?php if ($sample): ?><span class="verification-id-sample" aria-hidden="true">SAMPLE</span><?php endif; ?>
      <span class="verification-id-children">
        <?php for ($i = 0; $i < 6; $i++): $child = $cardChildren[$i] ?? null; ?>
          <span class="verification-id-child"><span><?= $child ? e($child['full_name']) : '' ?></span><span><?= $child ? e(format_date_long($child['birthdate'])) : '' ?></span><span><?= $child ? 'Child' : '' ?></span></span>
        <?php endfor; ?>
      </span>
      <?php if (count($cardChildren) > 6): ?><span class="verification-id-field verification-id-more"><?= count($cardChildren) - 6 ?> more; see full record</span><?php endif; ?>
      <span class="verification-id-field verification-id-emergency-name"><?= e($card['emergency_contact_name'] ?? '') ?></span>
      <span class="verification-id-field verification-id-emergency-relation"><?= e($card['emergency_contact_relation'] ?? '') ?></span>
      <span class="verification-id-field verification-id-emergency-phone"><?= e($card['emergency_contact_phone'] ?? '') ?></span>
      <span class="verification-id-field verification-id-emergency-address"><?= e($card['emergency_contact_address'] ?? '') ?></span>
    </span>
  </span>
</button>
