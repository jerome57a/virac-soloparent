/* Children block of the new application and the renewal (RA 11861): one Yes/No question about a child with a disability, a live line for
 * each child, a red box when no child qualifies, and the submit button locked until a child qualifies. The server checks the same rules
 * again on submit; this only makes them visible while typing. Markup hooks (inside an element with [data-child-rules]):
 *   .js-pwd-answer (radios, value 1/0)   .js-child (a child)   .js-child-birth (date)   .js-child-pwd (the tick)
 *   .js-child-live (the line)   .js-child-none (the red box)   .js-child-submit / .js-child-why (in the same form)   data-proof="#id"
 */
(function () {
  var ICONS = {
    ok: '<svg class="ic" aria-hidden="true" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
    pwd: '<svg class="ic" aria-hidden="true" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>',
    bad: '<svg class="ic" aria-hidden="true" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>'
  };
  function t(text) { return window.ViracI18n && window.ViracI18n.translate ? window.ViracI18n.translate(text) : text; }
  function esc(text) { var d = document.createElement('div'); d.textContent = text; return d.innerHTML; }
  function ageOn(birthdate, onDate) {
    if (!birthdate || birthdate > onDate) { return null; }
    var b = birthdate.split('-').map(Number), o = onDate.split('-').map(Number);
    if (b.length !== 3 || b.some(isNaN)) { return null; }
    return o[0] - b[0] - ((o[1] < b[1] || (o[1] === b[1] && o[2] < b[2])) ? 1 : 0);
  }

  function refresh(box) {
    var onDate = box.getAttribute('data-on-date') || new Date().toISOString().slice(0, 10);
    var max = parseInt(box.getAttribute('data-max') || '22', 10);
    var checked = box.querySelector('.js-pwd-answer:checked');
    var yes = !!checked && checked.value === '1';
    box.setAttribute('data-pwd', yes ? '1' : '0');
    var anyQualifies = false, needsProof = false, entered = false;
    Array.prototype.forEach.call(box.querySelectorAll('.js-child'), function (row) {
      var birth = row.querySelector('.js-child-birth'), tick = row.querySelector('.js-child-pwd'), live = row.querySelector('.js-child-live');
      if (!birth || !live) { return; }
      var a = ageOn(birth.value, onDate), ticked = !!tick && tick.checked && yes, cls, text, sub = '';
      if (!birth.value) { live.className = 'cr-live js-child-live'; live.innerHTML = ''; return; }
      entered = true;
      if (a === null) { cls = 'bad'; text = t('Check the birthdate'); }
      else if (a <= max) { cls = 'ok'; text = t('Age ' + a + ' — qualifies (22 or younger)'); anyQualifies = true; }
      else if (ticked) { cls = 'pwd'; text = t('Age ' + a + ' — qualifies with a disability'); sub = t('You will be asked to upload a PWD ID or medical certificate.'); anyQualifies = true; needsProof = true; }
      else {
        cls = 'bad'; text = t('Age ' + a + ' — over 22 without a disability: does not qualify');
        sub = t(yes ? 'A child over 22 qualifies only with a disability. Tick the box above if this applies.' : 'A child over 22 qualifies only with a disability. If this child has one, answer Yes above.');
      }
      live.className = 'cr-live js-child-live show ' + cls;
      live.innerHTML = ICONS[cls] + '<div>' + esc(text) + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</div>';
    });
    var none = entered && !anyQualifies;
    var noneBox = box.querySelector('.js-child-none');
    if (noneBox) { noneBox.hidden = !none; }
    var form = box.closest('form');
    if (form) {
      Array.prototype.forEach.call(form.querySelectorAll('.js-child-submit'), function (button) {
        if (none) { button.setAttribute('data-child-locked', '1'); button.disabled = true; button.setAttribute('aria-disabled', 'true'); }
        else if (button.getAttribute('data-child-locked') === '1') { button.removeAttribute('data-child-locked'); button.disabled = false; button.removeAttribute('aria-disabled'); }
      });
      Array.prototype.forEach.call(form.querySelectorAll('.js-child-why'), function (why) { why.hidden = !none; });
    }
    var proofSelector = box.getAttribute('data-proof');
    if (proofSelector) { var proof = document.querySelector(proofSelector); if (proof) { proof.classList.toggle('d-none', !needsProof); } }
  }

  function boxes() { return document.querySelectorAll('[data-child-rules]'); }
  function all() { Array.prototype.forEach.call(boxes(), refresh); }
  document.addEventListener('input', function (e) { var b = e.target.closest && e.target.closest('[data-child-rules]'); if (b) { refresh(b); } });
  document.addEventListener('change', function (e) { var b = e.target.closest && e.target.closest('[data-child-rules]'); if (b) { refresh(b); } });
  document.addEventListener('virac:languagechange', all);
  window.ChildRules = { refresh: refresh, all: all };
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', all); } else { all(); }
})();
