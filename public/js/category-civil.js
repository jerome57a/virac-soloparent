/* New application, step 1: the civil status filters the Solo Parent category.
 * - Before a civil status is chosen (and no category is chosen yet) the category list is disabled: "Choose your civil status first."
 * - With a civil status chosen the list shows only the categories that fit it; the categories for every civil status come last, under
 *   "For any civil status"; a pill says "Showing categories for <civil status>".
 * - Changing the civil status after a category was chosen that no longer fits clears the category and says so.
 * The rules come from the server (window.CATEGORY_CIVIL: id, name, statuses or null = every civil status); the server checks the pair
 * again when the step is saved, so this only helps the Solo Parent choose. Without JavaScript every category stays listed. */
(function () {
  'use strict';
  var rules = window.CATEGORY_CIVIL;
  var civil = document.querySelector('select[name="civilStatus"]');
  var cat = document.getElementById('circumstance-select');
  if (!Array.isArray(rules) || !civil || !cat) { return; }
  var byId = {};
  rules.forEach(function (c) { byId[String(c.id)] = c; });
  var firstHint = document.getElementById('category-civil-hint');
  var pill = document.getElementById('category-filter-pill');
  var msg = document.getElementById('category-filter-msg');
  var msgText = document.getElementById('category-filter-msg-text');

  function fits(c, status) { return !c.statuses || !status || c.statuses.indexOf(status) !== -1; }
  function option(value, text, selected) {
    var o = document.createElement('option');
    o.value = value;
    o.setAttribute('data-i18n', '');
    o.textContent = text;
    if (selected) { o.selected = true; }
    return o;
  }
  function translateAll() { if (window.ViracI18n && typeof window.ViracI18n.refresh === 'function') { window.ViracI18n.refresh(); } }
  function span(text, i18n) {
    var s = document.createElement('span');
    if (i18n) { s.setAttribute('data-i18n', ''); }
    s.textContent = text;
    return s;
  }

  function build(status, keep) {
    var fitting = rules.filter(function (c) { return fits(c, status); });
    var own = fitting.filter(function (c) { return c.statuses; });
    var any = fitting.filter(function (c) { return !c.statuses; });
    cat.textContent = '';
    cat.appendChild(option('', 'Select your category', !keep));
    own.forEach(function (c) { cat.appendChild(option(String(c.id), c.name, String(c.id) === keep)); });
    if (any.length) {
      var group = document.createElement('optgroup');
      group.setAttribute('label', 'For any civil status');
      group.setAttribute('data-i18n-attrs', 'label');
      any.forEach(function (c) { group.appendChild(option(String(c.id), c.name, String(c.id) === keep)); });
      cat.appendChild(group);
    }
    return fitting;
  }

  function apply(initial) {
    var status = civil.value;
    var previous = cat.value;
    msg.hidden = true;
    if (!status) {
      if (previous) { return; }            // a category is already chosen without a civil status: leave it alone
      build('', '');
      cat.disabled = true;
      if (firstHint) { firstHint.hidden = false; }
      pill.hidden = true;
      return;
    }
    cat.disabled = false;
    if (firstHint) { firstHint.hidden = true; }
    var fitting = build(status, previous && byId[previous] && fits(byId[previous], status) ? previous : '');
    pill.textContent = '';
    pill.appendChild(span('Showing categories for', true));
    pill.appendChild(document.createTextNode(' '));
    pill.appendChild(span(status, true));
    pill.hidden = false;
    if (!initial && previous && byId[previous] && !fits(byId[previous], status)) {
      msgText.textContent = '';
      var b = span('Your category was cleared.', true);
      b.style.fontWeight = '700';
      msgText.appendChild(b);
      msgText.appendChild(document.createTextNode(' "'));
      msgText.appendChild(span(byId[previous].name, true));
      msgText.appendChild(document.createTextNode('" '));
      msgText.appendChild(span('does not apply to', true));
      msgText.appendChild(document.createTextNode(' '));
      msgText.appendChild(span(status, true));
      msgText.appendChild(document.createTextNode('. '));
      msgText.appendChild(span('Choose a category again.', true));
      msg.className = 'cf-msg cf-warn';
      msg.hidden = false;
    }
    cat.dispatchEvent(new Event('change', { bubbles: true }));   // the documents preview follows the category
    void fitting;
    translateAll();
  }

  civil.addEventListener('change', function () { apply(false); });
  // The server may already have shown a note about a saved draft (category asked for again): keep it on the first run.
  var serverNote = !msg.hidden;
  apply(true);
  if (serverNote) { msg.hidden = false; }
})();
