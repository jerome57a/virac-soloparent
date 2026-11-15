/* Local presentation controls only; no records are submitted or changed. */
(() => {
  const root = document.querySelector('.staff-workspace');
  if (!root) return;
  root.querySelectorAll('.report-table').forEach((table, index) => {
    const hint = document.createElement('p');
    hint.className = 'staff-table-hint';
    hint.id = 'staff-table-hint-' + index;
    hint.textContent = 'Swipe or scroll to see all columns.';
    hint.setAttribute('data-i18n', '');
    table.before(hint);
    table.setAttribute('tabindex', '0');
    table.setAttribute('role', 'region');
    table.setAttribute('aria-label', 'Report results');
    table.setAttribute('data-i18n-attrs', 'aria-label');
    table.setAttribute('aria-describedby', hint.id);
    const update = () => { hint.hidden = table.scrollWidth <= table.clientWidth + 1; };
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(update).observe(table);
    update();
  });
})();
