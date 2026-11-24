/* Staff document verification: a document marked Needs Correction (or the older Rejected) must say what the Solo Parent has to fix.
 * - Choosing that status shows "* Required" on the remark box at once.
 * - Save Verification with a remark missing does not send: the page goes to the first such document, outlines it, puts the cursor in
 *   the box, and the line beside the button names every document still missing a remark.
 * - When the server refused the save (for example with scripts off before), the page opens on the first document with an error.
 * The server keeps its own check; this only helps staff find the box. */
(function () {
  'use strict';
  var NEEDS = ['NEEDS_CORRECTION', 'REJECTED'];
  var form = document.getElementById('verification-form');

  function tr(text) { return window.ViracI18n && typeof window.ViracI18n.translate === 'function' ? window.ViracI18n.translate(text) : text; }
  function rowOf(el) { return el.closest('.doc-item'); }
  function parts(row) {
    return {
      select: row.querySelector('.js-doc-status'),
      input: row.querySelector('.js-doc-remark'),
      req: row.querySelector('.doc-remark-req'),
      error: row.querySelector('.doc-remark-error')
    };
  }
  function needsRemark(p) { return !!(p.select && NEEDS.indexOf(p.select.value) !== -1); }
  function missing(p) { return needsRemark(p) && p.input && p.input.value.trim() === ''; }
  function mark(row, on) {
    var p = parts(row);
    row.classList.toggle('remark-missing', on);
    if (p.input) { p.input.classList.toggle('is-missing', on); if (on) p.input.setAttribute('aria-invalid', 'true'); else p.input.removeAttribute('aria-invalid'); }
    if (p.error) p.error.hidden = !on;
  }
  function go(row, input) {
    row.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
    if (input) setTimeout(function () { input.focus({ preventScroll: true }); }, 250);
  }

  if (form) {
    var hint = form.querySelector('.doc-save-hint');
    form.noValidate = true; // our own message replaces the browser bubble, so the page can scroll to the right row
    Array.prototype.forEach.call(form.querySelectorAll('.doc-item'), function (row) {
      var p = parts(row);
      if (!p.select || !p.input) return;
      var sync = function () {
        var need = needsRemark(p);
        if (p.req) p.req.hidden = !need;
        p.input.required = need;
        if (!missing(p)) mark(row, false);
        if (hint && !form.querySelector('.doc-item.remark-missing')) hint.hidden = true;
      };
      p.select.addEventListener('change', function () { sync(); if (needsRemark(p) && p.input.value.trim() === '') p.input.focus(); });
      p.input.addEventListener('input', sync);
      sync();
    });

    form.addEventListener('submit', function (event) {
      var rows = Array.prototype.filter.call(form.querySelectorAll('.doc-item'), function (row) { return parts(row).select && missing(parts(row)); });
      Array.prototype.forEach.call(form.querySelectorAll('.doc-item'), function (row) { mark(row, rows.indexOf(row) !== -1); });
      if (!rows.length) { if (hint) hint.hidden = true; return; }
      event.preventDefault();
      if (hint) {
        var names = rows.map(function (row) { return row.getAttribute('data-doc-label') || ''; }).filter(Boolean);
        hint.textContent = (rows.length === 1 ? tr('1 remark missing') : rows.length + ' ' + tr('remarks missing')) + (names.length ? ': ' + names.join(', ') : '');
        hint.hidden = false;
      }
      go(rows[0], parts(rows[0]).input);
    });
  }

  // The server refused the save: open the page on the first document with an error.
  var first = document.querySelector('.doc-item.has-error');
  if (first) {
    // The server's own message is already in the row, so only the cursor and the scroll are added here.
    go(first, first.querySelector('.js-doc-remark.is-missing') || first.querySelector('.js-doc-remark'));
  }
})();
