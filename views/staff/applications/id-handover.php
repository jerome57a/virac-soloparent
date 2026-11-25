<?php
/**
 * Confirm handover (ID release): the start and expiration dates printed on the card, the in-person check, and — only when a required
 * document expired while the ID waited — the reason for releasing it anyway. Included by views/staff/applications/show.php.
 *
 * @var array $app
 * @var array $outstandingRequirements  required documents that are no longer verified (expired) — empty normally
 * @var array $idDefaults               ['from' => Y-m-d, 'to' => Y-m-d, 'years' => int] pre-filled values
 */
$handoverOverride = !empty($outstandingRequirements);
$defaultFrom = $idDefaults['from'] ?? date('Y-m-d');
$defaultTo = $idDefaults['to'] ?? date('Y-m-d', strtotime('+1 year'));
?>
<div class="id-handover" id="id-handover">
  <p class="small text-muted mb-3">Give the ID <b>only to the Solo Parent, in person</b> — nobody else may claim it. Type the start date and the expiration date exactly as printed on the card. The system counts from these dates, and the Solo Parent gets one SMS on the expiration date.</p>
  <form method="post" id="id-handover-form" class="d-flex flex-column gap-3" data-name="<?= e($app['full_name']) ?>">
    <?= csrf_field() ?><input type="hidden" name="form_action" value="confirm_id_release"><input type="hidden" name="confirm_handover" value="1">
    <div class="row g-2">
      <label class="col-12 col-sm-6" style="font-size:13px;font-weight:600;color:var(--ink)">ID start date <span class="fw-normal text-muted">(on the card)</span>
        <input type="date" name="id_valid_from" id="handover-from" class="form-control mt-1" value="<?= e($defaultFrom) ?>" max="<?= e(date('Y-m-d')) ?>" required>
      </label>
      <label class="col-12 col-sm-6" style="font-size:13px;font-weight:600;color:var(--ink)">Expiration date <span class="fw-normal text-muted">(on the card)</span>
        <input type="date" name="id_expires_at" id="handover-to" class="form-control mt-1" value="<?= e($defaultTo) ?>" min="<?= e(date('Y-m-d', strtotime('+1 day'))) ?>" required>
      </label>
    </div>
    <div class="small" id="handover-validity" role="status" style="color:var(--muted)"></div>
    <?php if ($handoverOverride): ?>
      <div class="auth-error" role="alert" style="max-width:none;margin:0">
        <b>A required document expired while the ID was waiting:</b> <?= e(implode(', ', array_map(fn($item) => (string) $item['label'], $outstandingRequirements))) ?>.
        You may still release the ID — give the reason below (it is recorded in the audit log).
      </div>
      <label style="font-size:13px;font-weight:600;color:var(--ink)">Reason for releasing the ID anyway
        <textarea name="override_reason" rows="2" maxlength="500" class="form-control mt-1" required placeholder="For example: the Solo Parent showed the renewed certificate at the counter."></textarea>
      </label>
    <?php endif; ?>
    <label class="d-flex align-items-start gap-2" style="font-size:13px;color:var(--ink);cursor:pointer">
      <input type="checkbox" name="identity_checked" value="1" required style="margin-top:3px;width:18px;height:18px;flex:0 0 auto">
      <span>I checked the Solo Parent's valid ID and handed the Solo Parent ID to <b><?= e($app['full_name']) ?></b> in person.</span>
    </label>
    <button type="submit" class="btn-navy review-button-success is-selected review-release-action">Confirm ID Released</button>
    <p class="small text-muted mb-0">Press this only after the ID is in the Solo Parent's hands. It records the ID as released and starts its validity.</p>
  </form>
  <script>
    (() => {
      const form = document.getElementById('id-handover-form'), from = document.getElementById('handover-from'), to = document.getElementById('handover-to');
      const out = document.getElementById('handover-validity');
      const months = () => {
        if (!from.value || !to.value) return null;
        const [y1, m1, d1] = from.value.split('-').map(Number), [y2, m2, d2] = to.value.split('-').map(Number);
        return (y2 - y1) * 12 + (m2 - m1) + (d2 >= d1 ? 0 : -1);
      };
      const show = () => {
        const m = months();
        if (m === null) { out.textContent = ''; return; }
        if (to.value <= from.value) { out.textContent = 'The expiration date must be after the start date.'; out.style.color = 'var(--red, #b3261e)'; return; }
        const odd = m < 11 || m > 13;
        out.textContent = 'Valid for about ' + (m === 12 ? '1 year' : m + ' month' + (m === 1 ? '' : 's')) + (odd ? ' — this is not about one year. Check the dates on the card.' : '.');
        out.style.color = odd ? 'var(--red, #b3261e)' : 'var(--muted)';
      };
      [from, to].forEach(el => el.addEventListener('input', show)); show();
      form.addEventListener('submit', event => {
        const m = months();
        if (m !== null && (m < 11 || m > 13) && !confirm('The ID would be valid for about ' + m + ' months, not one year. Is the date on the card correct?')) { event.preventDefault(); return; }
        if (!confirm('Confirm that you handed the Solo Parent ID to ' + form.dataset.name + ' in person? This records ID Released.')) event.preventDefault();
      });
    })();
  </script>
</div>
