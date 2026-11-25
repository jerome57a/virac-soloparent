(function () {
  'use strict';
  var root = document.documentElement;
  var catalog = window.ViracTranslations || {};
  var originals = new WeakMap();
  var lastWritten = new WeakMap();
  var attributeOriginals = new WeakMap();
  function keyFor(text) { return text.trim().replace(/\s+/g, ' '); }
  function translate(text) {
    var key = keyFor(text);
    if (root.lang !== 'tl') return text;
    if (Object.prototype.hasOwnProperty.call(catalog, key)) return text.replace(text.trim(), catalog[key]);
    // Anchored templates preserve counts/dates in system-generated interface copy.
    var patterns = window.ViracTranslationPatterns || [];
    for (var i = 0; i < patterns.length; i++) {
      if (patterns[i][0].test(key)) return text.replace(text.trim(), key.replace(patterns[i][0], patterns[i][1]));
    }
    return text;
  }
  function save(key, value) {
    try { localStorage.setItem('virac.' + key, value); } catch (error) { /* Optional storage. */ }
  }
  function render(scope) {
    // Only explicitly marked interface copy is translated. User data stays intact.
    scope.querySelectorAll('[data-i18n]').forEach(function (element) {
      if (element.hasAttribute('data-theme-label')) return; // Rendered from current theme below.
      if (element.closest('[translate="no"], [data-i18n-ignore], script, style, textarea, [contenteditable="true"]')) return;
      // Translating an option without an explicit value would change submitted data.
      if (element.tagName === 'OPTION' && !element.hasAttribute('value')) return;
      Array.from(element.childNodes).forEach(function (node) {
        if (node.nodeType !== 3) return;
        var original = originals.get(node);
        // Also supports UI copy changed by the eligibility wizard or other scripts.
        if (original === undefined || (lastWritten.has(node) && node.nodeValue !== lastWritten.get(node))) {
          original = node.nodeValue;
          originals.set(node, original);
        }
        var next = translate(original);
        if (node.nodeValue !== next) node.nodeValue = next;
        lastWritten.set(node, next);
      });
    });
    scope.querySelectorAll('[data-i18n-attrs]').forEach(function (element) {
      if (element.hasAttribute('data-theme-toggle')) return;
      var saved = attributeOriginals.get(element) || {};
      element.dataset.i18nAttrs.split(' ').forEach(function (name) {
        if (!/^(aria-label|title|placeholder|alt|label)$/.test(name)) return;
        var current = element.getAttribute(name);
        if (!saved[name] || current !== saved[name].written) saved[name] = { original: current };
        var next = saved[name].original === null ? null : translate(saved[name].original);
        if (next !== null && current !== next) element.setAttribute(name, next);
        saved[name].written = next;
      });
      attributeOriginals.set(element, saved);
    });
    scope.querySelectorAll('[data-language-select]').forEach(function (select) { select.value = root.lang; });
    scope.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
      var dark = root.dataset.theme === 'dark';
      button.setAttribute('aria-pressed', String(dark));
      var themeLabel = translate(dark ? 'Switch to light mode' : 'Switch to dark mode');
      if (button.getAttribute('aria-label') !== themeLabel) button.setAttribute('aria-label', themeLabel);
      var icon = button.querySelector('[data-theme-icon]');
      var label = button.querySelector('[data-theme-label]');
      var iconText = dark ? '☀' : '☾';
      var labelText = translate(dark ? 'Light mode' : 'Dark mode');
      if (icon.textContent !== iconText) icon.textContent = iconText;
      if (label.textContent !== labelText) label.textContent = labelText;
    });
  }
  function refresh() { render(document); }
  window.ViracI18n = { translate: translate, refresh: refresh };
  document.querySelectorAll('[data-language-select]').forEach(function (select) {
    select.addEventListener('change', function () {
      root.lang = select.value === 'tl' ? 'tl' : 'en';
      save('language', root.lang);
      refresh();
      document.dispatchEvent(new CustomEvent('virac:languagechange', { detail: root.lang }));
    });
  });
  document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
      root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
      root.dataset.bsTheme = root.dataset.theme;
      save('theme', root.dataset.theme);
      refresh();
      document.dispatchEvent(new CustomEvent('virac:themechange', { detail: root.dataset.theme }));
    });
  });
  refresh();
  // Translate marked UI inserted or updated after load, without observing form values.
  var scheduled = false;
  new MutationObserver(function (records) {
    if (scheduled || !records.some(function (record) {
      return record.type === 'childList' || record.type === 'attributes' || (record.target.parentElement && record.target.parentElement.closest('[data-i18n]'));
    })) return;
    scheduled = true;
    requestAnimationFrame(function () { scheduled = false; refresh(); });
  }).observe(document.body, { childList: true, characterData: true, attributes: true, attributeFilter: ['placeholder', 'title', 'alt', 'aria-label'], subtree: true });
  window.addEventListener('storage', function (event) {
    if (event.key === 'virac.language') root.lang = event.newValue === 'tl' ? 'tl' : 'en';
    if (event.key === 'virac.theme') {
      root.dataset.theme = event.newValue === 'dark' ? 'dark' : 'light';
      root.dataset.bsTheme = root.dataset.theme;
    }
    if (event.key === 'virac.language' || event.key === 'virac.theme') refresh();
  });
})();
