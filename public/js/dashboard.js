(function () {
  'use strict';

  var toggleBtn = document.getElementById('dash-sidebar-toggle');
  var closeBtn = document.getElementById('dash-sidebar-close');
  var sidebar = document.getElementById('dash-sidebar');
  var overlay = document.getElementById('dash-sidebar-overlay');

  function openSidebar() {
    overlay.hidden = false;
    requestAnimationFrame(function () {
      sidebar.classList.add('open');
      overlay.classList.add('open');
    });
    document.body.classList.add('dash-sidebar-open');
  }
  function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
    document.body.classList.remove('dash-sidebar-open');
    window.setTimeout(function () {
      if (!overlay.classList.contains('open')) overlay.hidden = true;
    }, 300);
  }

  if (toggleBtn && sidebar && overlay) {
    toggleBtn.addEventListener('click', openSidebar);
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    overlay.addEventListener('click', closeSidebar);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && sidebar.classList.contains('open')) closeSidebar();
    });
  }
})();
