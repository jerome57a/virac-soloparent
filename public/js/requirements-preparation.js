/* Update the preparation list locally; checkbox edits never send an SMS. */
document.querySelectorAll('[data-preparation-form]').forEach(form => {
  const summary = form.querySelector('[data-preparation-summary]');
  if (!summary) return;
  const update = () => {
    const inputs = [...form.querySelectorAll('input[data-required-document]')];
    const missing = inputs.filter(input => !input.checked && input.dataset.staffAccepted !== '1');
    inputs.forEach(input => {
      const note = input.closest('.document-checklist-item')?.querySelector('[data-preparation-item-note]');
      if (note) note.hidden = input.checked || input.dataset.staffAccepted === '1';
    });
    summary.querySelector('[data-preparation-heading]').textContent = missing.length
      ? 'Documents You Still Need to Prepare' : 'No unchecked required documents to prepare';
    const list = summary.querySelector('[data-preparation-list]');
    list.replaceChildren(...missing.map(input => {
      const item = document.createElement('li');
      const link = document.createElement('a');
      link.setAttribute('data-i18n', '');
      link.textContent = input.dataset.requiredDocument;
      link.href = input.dataset.requirementGuide;
      item.append(link);
      return item;
    }));
    list.hidden = !missing.length;
  };
  form.addEventListener('change', update);
  update();
});
