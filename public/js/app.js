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
})();
