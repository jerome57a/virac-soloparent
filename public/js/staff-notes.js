/* Staff application page: the "Show all notes" switch opens or closes every Notes box at once and remembers the choice on this
 * device. Warnings and errors are never inside a Notes box, so they are always shown. Storage may be unavailable: it still works. */
(function () {
  var KEY = 'virac.notes.all';
  var sw = document.getElementById('gi-notes-all');
  if (!sw) { return; }
  function setAll(open) {
    Array.prototype.forEach.call(document.querySelectorAll('details.gi-notes'), function (d) { d.open = open; });
  }
  var saved = false;
  try { saved = localStorage.getItem(KEY) === '1'; } catch (e) { saved = false; }
  sw.checked = saved;
  if (saved) { setAll(true); }
  sw.addEventListener('change', function () {
    setAll(sw.checked);
    try { localStorage.setItem(KEY, sw.checked ? '1' : '0'); } catch (e) { /* the switch still works for this visit */ }
  });
})();
