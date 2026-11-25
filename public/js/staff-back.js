/* Staff application page: the Back link returns to the page the application was opened from (the dashboard list, the Applications
 * list, a search...), not always to the Applications list. The origin is remembered for this tab, so it survives saving a form on
 * the application page (which reloads it). Without JavaScript or without a known origin the link keeps its normal address. */
(function () {
  var link = document.querySelector('.staff-back-link');
  if (!link) { return; }
  var KEY = 'virac.staff.backto';
  var stored = null;
  try { stored = sessionStorage.getItem(KEY); } catch (e) { stored = null; }
  if (document.referrer) {
    try {
      var from = new URL(document.referrer);
      // A staff page of this site other than an application itself (or the check-in post).
      if (from.origin === window.location.origin && /\/staff\/[a-z_]+\.php$/.test(from.pathname) && !/\/staff\/(application|checkin)\.php$/.test(from.pathname)) {
        stored = from.pathname + from.search;
        try { sessionStorage.setItem(KEY, stored); } catch (e) { /* remembered for this visit only */ }
      }
    } catch (e) { /* keep what is stored */ }
  }
  if (stored && /^\/.*\/staff\/[a-z_]+\.php(\?.*)?$/.test(stored)) { link.setAttribute('href', stored); }
})();