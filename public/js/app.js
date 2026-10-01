(function () {
  'use strict';

  // ---- Nav header slide-out drawer ----
  var navMenuBtn = document.getElementById('nav-menu-btn');
  var navDrawer = document.getElementById('nav-drawer');
  var navOverlay = document.getElementById('nav-drawer-overlay');
  var navDrawerClose = document.getElementById('nav-drawer-close');

  function openNavDrawer() {
    navOverlay.hidden = false;
    requestAnimationFrame(function () {
      navDrawer.classList.add('open');
      navOverlay.classList.add('open');
    });
    document.body.classList.add('nav-drawer-open');
    navMenuBtn.setAttribute('aria-expanded', 'true');
    navDrawer.setAttribute('aria-hidden', 'false');
  }
  function closeNavDrawer() {
    navDrawer.classList.remove('open');
    navOverlay.classList.remove('open');
    document.body.classList.remove('nav-drawer-open');
    navMenuBtn.setAttribute('aria-expanded', 'false');
    navDrawer.setAttribute('aria-hidden', 'true');
    window.setTimeout(function () {
      if (!navOverlay.classList.contains('open')) navOverlay.hidden = true;
    }, 300);
  }

  if (navMenuBtn && navDrawer && navOverlay) {
    navMenuBtn.addEventListener('click', function () {
      if (navDrawer.classList.contains('open')) {
        closeNavDrawer();
      } else {
        openNavDrawer();
      }
    });
    navOverlay.addEventListener('click', closeNavDrawer);
    if (navDrawerClose) navDrawerClose.addEventListener('click', closeNavDrawer);
    navDrawer.querySelectorAll('.nav-drawer-link').forEach(function (link) {
      link.addEventListener('click', closeNavDrawer);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && navDrawer.classList.contains('open')) closeNavDrawer();
    });
  }

  // ---- Nav drawer: expandable submenus (My Application, ID Renewal, ...) ----
  document.querySelectorAll('.nav-drawer-toggle').forEach(function (toggle) {
    var submenu = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!submenu) return;
    toggle.addEventListener('click', function () {
      var willOpen = !submenu.classList.contains('open');
      submenu.classList.toggle('open', willOpen);
      toggle.classList.toggle('open', willOpen);
      toggle.setAttribute('aria-expanded', String(willOpen));
    });
  });

  // ---- Category -> required documents preview (Apply Step 1) ----
  var categorySelect = document.getElementById('circumstance-select');
  var categoryBox = document.getElementById('category-doc-box');
  if (categorySelect && categoryBox && window.CATEGORY_DOCS) {
    function renderCategoryDocs() {
      var docs = window.CATEGORY_DOCS[categorySelect.value];
      if (!docs || !docs.length) {
        categoryBox.style.display = 'none';
        categoryBox.innerHTML = '';
        return;
      }
      var items = docs.map(function (d) { return '<div>· ' + d + '</div>'; }).join('');
      categoryBox.innerHTML =
        '<div style="font-weight:700;margin-bottom:4px">Documents required for this category:</div>' +
        items +
        '<div style="color:#5a6472;margin-top:4px">Plus the standard requirements: Barangay Certificate, children\'s birth certificates, valid ID, and 2x2 photo.</div>';
      categoryBox.style.display = 'block';
    }
    categorySelect.addEventListener('change', renderCategoryDocs);
    renderCategoryDocs();
  }

  // ---- File input: show chosen filename immediately, basic client check ----
  document.querySelectorAll('input[type=file][data-doc-input]').forEach(function (input) {
    input.addEventListener('change', function () {
      var row = input.closest('.doc-row');
      if (!row) return;
      var nameEl = row.querySelector('.doc-filename');
      var file = input.files && input.files[0];
      if (!file) return;
      var okType = /\.(jpe?g|png|pdf)$/i.test(file.name);
      var okSize = file.size <= 5 * 1024 * 1024;
      if (!okType || !okSize) {
        nameEl.textContent = !okType ? 'File must be JPG, PNG, or PDF.' : 'File is larger than 5MB.';
        nameEl.classList.remove('attached');
        return;
      }
      nameEl.textContent = file.name + ' (not yet uploaded – click "Continue" to upload)';
      nameEl.classList.add('attached');
    });
  });

  // ---- Consent checkbox toggling the submit button (extra client guard) ----
  var consentBox = document.getElementById('consent-checkbox');
  var submitBtn = document.getElementById('submit-application-btn');
  if (consentBox && submitBtn) {
    var syncSubmit = function () { submitBtn.disabled = !consentBox.checked; };
    consentBox.addEventListener('change', syncSubmit);
    syncSubmit();
  }

  // ---- Loading state for every POST form ----
  // Once a form is really submitting (it passed validation and any confirm()),
  // its button shows "Please wait…" and further submits are ignored, so a
  // double click can't send an application, upload or SMS twice. The button
  // is not disabled: a disabled button would drop its name/value (e.g.
  // action=...) from the submitted data. Forms opting out: data-no-loading.
  var busyForms = [];
  function resetBusy() {
    busyForms.forEach(function (entry) {
      entry.form.removeAttribute('aria-busy');
      delete entry.form.dataset.submitting;
      if (entry.button) {
        entry.button.classList.remove('is-loading');
        entry.button.removeAttribute('aria-disabled');
        if (entry.label !== null) entry.button.innerHTML = entry.label;
      }
      if (entry.textNode) {
        entry.textNode.nodeValue = entry.text;
        entry.textNode.parentNode.classList.remove('is-loading');
      }
    });
    busyForms = [];
  }
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement) || (form.method || '').toLowerCase() !== 'post' || form.hasAttribute('data-no-loading') || form.target === '_blank') return;
    if (form.dataset.submitting) { e.preventDefault(); return; }
    if (e.defaultPrevented) return; // cancelled by a confirm() or other handler
    form.dataset.submitting = '1';
    form.setAttribute('aria-busy', 'true');
    var button = e.submitter || form.querySelector('button[type=submit], button:not([type]), input[type=submit]');
    var entry = { form: form, button: button, label: null, textNode: null, text: null };
    // Upload forms that send as soon as a file is picked have no button: their "Choose file" label says "Uploading…".
    var picker = !button && form.querySelector('label > input[type=file]');
    if (picker) {
      var label = picker.parentNode;
      for (var n = label.firstChild; n; n = n.nextSibling) {
        if (n.nodeType === 3 && n.nodeValue.trim() !== '') { entry.textNode = n; entry.text = n.nodeValue; n.nodeValue = 'Uploading…'; label.classList.add('is-loading'); break; }
      }
    }
    if (button) {
      button.classList.add('is-loading');
      button.setAttribute('aria-disabled', 'true');
      if (button.tagName === 'BUTTON' && button.textContent.trim() !== '') {
        entry.label = button.innerHTML;
        button.style.minWidth = button.offsetWidth + 'px';
        button.textContent = 'Please wait…';
      }
    }
    busyForms.push(entry);
    // A response that doesn't leave the page (e.g. a file download) mustn't leave the form stuck.
    setTimeout(resetBusy, 10000);
  });
  // Coming back with the browser's Back button restores the page as it was left.
  window.addEventListener('pageshow', function (e) { if (e.persisted) resetBusy(); });
})();
