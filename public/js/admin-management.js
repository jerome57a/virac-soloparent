/* Open a collapsed editor when a settings link points to it. */
(() => {
  document.querySelectorAll('[data-role-account-form]').forEach(form => {
    const role = form.elements.role;
    const updateRoleFields = () => {
      const applicant = role.value === 'applicant';
      const personnel = ['admin', 'mswd_social_worker'].includes(role.value);
      form.querySelector('[data-applicant-fields]').hidden = !applicant;
      form.elements.mobile.disabled = !applicant;
      form.elements.mobile.required = applicant;
      form.querySelector('[data-personnel-fields]').hidden = !personnel;
      form.elements.mustChange.disabled = !personnel;
      form.elements.username.pattern = applicant ? '(?=.*[A-Za-z])[A-Za-z0-9._]{4,30}' : '[A-Za-z0-9._\\x2D]{3,50}';
      form.elements.username.maxLength = applicant ? 30 : 50;
      form.querySelector('[data-applicant-help]').hidden = !applicant;
      form.querySelector('[data-personnel-help]').hidden = applicant;
    };
    role.addEventListener('change', updateRoleFields);
    updateRoleFields();
  });
  document.querySelectorAll('.admin-workspace-content textarea[data-admin-count][maxlength]').forEach((field, index) => {
    const counter = document.createElement('small');
    counter.className = 'admin-character-count';
    counter.id = 'admin-character-count-' + index;
    const number = document.createElement('span');
    const label = document.createElement('span');
    label.setAttribute('data-i18n', '');
    label.textContent = 'characters';
    counter.append(number, document.createTextNode(' '), label);
    field.after(counter);
    field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), counter.id].filter(Boolean).join(' '));
    const update = () => { number.textContent = Array.from(field.value).length + ' / ' + field.maxLength; };
    field.addEventListener('input', update);
    update();
  });
  const faqSearch = document.querySelector('[data-admin-faq-search]');
  if (faqSearch) {
    const entries = [...document.querySelectorAll('#admin-faq-list details.admin-editor')];
    const empty = document.querySelector('[data-admin-faq-empty]');
    faqSearch.addEventListener('input', () => {
      const query = faqSearch.value.trim().toLocaleLowerCase();
      let matches = 0;
      entries.forEach(entry => {
        const text = [...entry.querySelectorAll('[name="question"], [name="answer"]')].map(field => field.value).join(' ').toLocaleLowerCase();
        entry.hidden = !!query && !text.includes(query);
        if (!entry.hidden) matches++;
      });
      empty.hidden = !query || matches > 0;
    });
  }
  // A short hint appears only when a record table actually needs horizontal scrolling.
  if (typeof ResizeObserver !== 'undefined') {
    const observer = new ResizeObserver(entries => entries.forEach(({ target }) => {
      const hint = target.previousElementSibling;
      if (hint?.classList.contains('admin-table-hint')) hint.hidden = target.scrollWidth <= target.clientWidth + 1;
    }));
    document.querySelectorAll('.admin-workspace .table-responsive').forEach((region, index) => {
      region.setAttribute('tabindex', '0');
      if (!region.hasAttribute('role')) region.setAttribute('role', 'region');
      if (!region.hasAttribute('aria-label')) region.setAttribute('aria-label', 'Records');
      const hint = document.createElement('p');
      hint.className = 'admin-table-hint';
      hint.id = 'admin-table-hint-' + index;
      hint.setAttribute('data-i18n', '');
      hint.textContent = 'Swipe or scroll to see all columns.';
      hint.hidden = true;
      region.before(hint);
      region.setAttribute('aria-describedby', [region.getAttribute('aria-describedby'), hint.id].filter(Boolean).join(' '));
      observer.observe(region);
    });
  }
  const revealSection = () => {
    let id;
    try { id = decodeURIComponent(window.location.hash.slice(1)); } catch { return; }
    const target = id && document.getElementById(id);
    if (!target) return;
    if (target instanceof HTMLDetailsElement) target.open = true;
    for (let parent = target.parentElement; parent; parent = parent.parentElement) {
      if (parent instanceof HTMLDetailsElement) parent.open = true;
    }
    target.scrollIntoView({ block: 'start' });
  };
  revealSection();
  window.addEventListener('hashchange', revealSection);
  document.addEventListener('click', event => {
    const link = event.target.closest('a[href^="#"]');
    if (link && link.hash === window.location.hash) revealSection();
  });
})();
