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

  // ---- Eligibility checker (client-side only, no data is stored) ----
  var elig = document.getElementById('elig-checker');
  if (elig) {
    var state = { resident: '', circ: '', child: '' };
    var NONE = 'None of these apply to me';

    function paintGroup(groupName) {
      var buttons = elig.querySelectorAll('[data-elig-group="' + groupName + '"]');
      buttons.forEach(function (btn) {
        var on = state[groupName] === btn.dataset.eligValue;
        btn.classList.toggle('selected', on);
      });
    }

    function renderResult() {
      var box = document.getElementById('elig-result');
      if (!box) return;
      var done = state.resident && state.circ && state.child;
      if (!done) {
        box.innerHTML = '<div style="font-size:13px;color:#8a93a3">Answer all three questions to see your result.</div>';
        return;
      }
      var yes = state.resident === 'Yes' && state.circ !== NONE && state.child === 'Yes';
      if (yes) {
        box.innerHTML =
          '<div class="result-box success">' +
            '<div style="flex:1 1 260px">' +
              '<div class="title">You likely qualify for a Solo Parent ID.</div>' +
              '<div class="desc">Prepare the required documents above, then start your application. Final approval is subject to MSWDO review.</div>' +
            '</div>' +
            '<a href="' + elig.dataset.applyUrl + '" class="btn-red" style="padding:12px 22px;font-size:14px;text-decoration:none;display:inline-block">Apply Online Now</a>' +
          '</div>';
        return;
      }
      var reason = state.resident === 'No'
        ? 'The Solo Parent ID from MSWDO Virac is for residents of Virac; apply at the MSWDO of your own municipality.'
        : (state.circ === NONE
          ? 'Your situation does not match the circumstances listed under RA 8972 / RA 11861.'
          : 'The Solo Parent ID requires that you solely care for a qualifying child.');
      box.innerHTML =
        '<div class="result-box warning">' +
          '<div class="title">You may not qualify at this time.</div>' +
          '<div class="desc">' + reason + ' Visit the MSWDO Office, Municipal Building, Salvacion, Virac (Mon–Fri, 8:00 AM – 5:00 PM) for a personal assessment.</div>' +
        '</div>';
    }

    elig.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-elig-group]');
      if (btn) {
        state[btn.dataset.eligGroup] = btn.dataset.eligValue;
        paintGroup(btn.dataset.eligGroup);
        renderResult();
        return;
      }
      if (e.target.closest('#elig-reset')) {
        state = { resident: '', circ: '', child: '' };
        ['resident', 'circ', 'child'].forEach(paintGroup);
        renderResult();
      }
    });

    renderResult();
  }

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
