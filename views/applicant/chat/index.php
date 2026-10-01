<?php
/**
 * @var array $thread        chat_messages rows, oldest first (meta decoded)
 * @var bool $awaitingStaff  the applicant asked for staff and no one has replied yet
 * @var ?string $chatError
 * @var string $typed        what they typed, redisplayed after an error
 * @var array $faqs
 */
$lastAssistant = null;
foreach ($thread as $m) {
    if ($m['sender'] === 'ASSISTANT') {
        $lastAssistant = $m;
    }
}
$suggestions = $lastAssistant['meta']['suggestions'] ?? array_values(HelpAssistantService::SUGGESTIONS);
?>
      <div class="chat-disclaimer" role="note">
        <b>ℹ️ Guidance only.</b> <?= e(HelpAssistantService::DISCLAIMER) ?>
      </div>

      <div class="chat-box">
        <div class="chat-thread" id="chat-thread" aria-live="polite">
          <?php foreach ($thread as $i => $m): ?>
            <?php
              $mine = $m['sender'] === 'APPLICANT';
              $who = $mine ? 'You' : ($m['sender'] === 'STAFF' ? 'MSWDO staff' . ($m['staff_name'] ? ' · ' . $m['staff_name'] : '') : 'Help Assistant');
            ?>
            <div class="chat-msg <?= $mine ? 'chat-msg-mine' : ($m['sender'] === 'STAFF' ? 'chat-msg-staff' : 'chat-msg-bot') ?>"<?= $i === count($thread) - 1 ? ' id="chat-latest"' : '' ?>>
              <div class="chat-meta"><?= e($who) ?> · <?= e(date('M j, g:i A', strtotime($m['created_at']))) ?><?= $m['handoff'] ? ' · <span class="chat-tag">Sent to MSWDO staff</span>' : '' ?></div>
              <div class="chat-bubble"><?= nl2br(e($m['message'])) ?></div>
              <?php $links = array_filter(array_map([HelpAssistantService::class, 'resolveLink'], array_map('strval', $m['meta']['links'] ?? []))); ?>
              <?php if ($links): ?>
                <div class="chat-links">
                  <?php foreach ($links as [$label, $url]): ?>
                    <a href="<?= e($url) ?>" class="chat-link"><?= e($label) ?> &rarr;</a>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($awaitingStaff): ?>
          <div class="chat-waiting">⏳ Your question is with MSWDO staff. A social worker will reply here during office hours (<?= e(setting('office_hours')) ?>).</div>
        <?php endif; ?>

        <?php if ($chatError): ?>
          <div class="auth-error mb-2" role="alert"><?= e($chatError) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(base_url('applicant/dashboard.php?section=chat')) ?>" class="chat-suggestions" aria-label="Suggested questions">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="ask">
          <?php foreach ($suggestions as $s): ?>
            <button type="submit" name="message" value="<?= e($s) ?>" class="chat-chip"><?= e($s) ?></button>
          <?php endforeach; ?>
        </form>

        <form method="post" action="<?= e(base_url('applicant/dashboard.php?section=chat')) ?>" class="chat-input">
          <?= csrf_field() ?>
          <label for="chat-message" class="visually-hidden">Your question</label>
          <textarea id="chat-message" name="message" rows="2" maxlength="<?= HelpAssistantService::MAX_LENGTH ?>" placeholder="Type your question (English or Filipino)…"><?= e($typed) ?></textarea>
          <div class="chat-actions">
            <button type="submit" name="action" value="ask" class="btn-navy">Ask assistant</button>
            <button type="submit" name="action" value="handoff" class="btn-outline" title="Send this message to a MSWDO social worker">Ask MSWDO staff</button>
          </div>
        </form>
        <div class="chat-hint">"Ask MSWDO staff" sends your message to a social worker (you can leave it empty). Please don't share passwords or codes in the chat.</div>
      </div>

      <h2 class="font-heading" style="font-size:18px;margin:28px 0 12px">Frequently asked questions</h2>
      <input type="text" id="faq-search" placeholder="Search questions..." class="form-control mb-3" style="max-width:420px;padding:10px;border:1px solid var(--input-border);border-radius:6px" aria-label="Search frequently asked questions">

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
        <div class="dash-card-label">Visit or contact <?= e(setting('office_name')) ?></div>
        <div style="font-size:14px;color:var(--ink);margin:8px 0 4px">📍 <?= e(setting('office_address')) ?></div>
        <div style="font-size:14px;color:var(--ink)">🕘 <?= e(setting('office_hours')) ?></div>
        <div style="font-size:14px;color:var(--ink);margin-top:4px">✉️ <?= e(setting('office_email')) ?> · ☎️ <?= e(setting('office_phone')) ?></div>
      </div>

      <script>
      (function () {
        var thread = document.getElementById('chat-thread');
        if (thread) thread.scrollTop = thread.scrollHeight;
        var box = document.getElementById('chat-message');
        if (box) box.addEventListener('keydown', function (e) {
          // Enter sends to the assistant; Shift+Enter adds a new line.
          if (e.key === 'Enter' && !e.shiftKey && box.value.trim() !== '') {
            e.preventDefault();
            box.form.querySelector('button[value="ask"]').click();
          }
        });
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
