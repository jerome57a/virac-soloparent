<?php
/** @var array $faqs */
?>
      <div style="font-size:14px;color:var(--muted);margin-bottom:20px;max-width:560px">Answers to common questions about applying, requirements, and renewal. Can't find what you need? Contact MSWDO directly using the details below.</div>

      <input type="text" id="faq-search" placeholder="Search questions..." class="form-control mb-3" style="max-width:420px;padding:10px;border:1px solid var(--input-border);border-radius:6px">

      <div id="faq-list">
        <?php foreach ($faqs as $f): ?>
          <div class="review-card faq-item" data-faq-q="<?= e(mb_strtolower($f['question'] . ' ' . $f['answer'])) ?>">
            <div style="font-weight:700;font-size:14px;color:var(--ink);margin-bottom:6px">❓ <?= e($f['question']) ?></div>
            <div style="font-size:13px;color:var(--muted);line-height:1.6"><?= nl2br(e($f['answer'])) ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (!$faqs): ?><div style="font-size:14px;color:var(--muted)">No FAQ entries yet.</div><?php endif; ?>
      </div>

      <div class="dash-card" style="margin-top:8px">
        <div class="dash-card-label">Still need help?</div>
        <div style="font-size:14px;color:var(--ink);margin:8px 0 4px">📍 MSWDO Office, Municipal Building, Salvacion, Virac, Catanduanes</div>
        <div style="font-size:14px;color:var(--ink)">🕘 Monday–Friday, 8:00 AM – 5:00 PM</div>
      </div>

      <script>
      (function () {
        var search = document.getElementById('faq-search');
        if (!search) return;
        search.addEventListener('input', function () {
          var q = search.value.trim().toLowerCase();
          document.querySelectorAll('.faq-item').forEach(function (item) {
            item.style.display = (!q || item.dataset.faqQ.indexOf(q) !== -1) ? '' : 'none';
          });
        });
      })();
      </script>
