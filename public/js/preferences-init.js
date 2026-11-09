/* Apply saved appearance before CSS paints. Storage may be disabled. */
(function () {
  'use strict';
  var theme = 'light';
  var language = 'en';
  try {
    theme = localStorage.getItem('virac.theme') === 'dark' ? 'dark' : 'light';
    language = localStorage.getItem('virac.language') === 'tl' ? 'tl' : 'en';
  } catch (error) { /* The controls still work without persistent storage. */ }
  document.documentElement.dataset.theme = theme;
  document.documentElement.dataset.bsTheme = theme;
  document.documentElement.lang = language;
})();
