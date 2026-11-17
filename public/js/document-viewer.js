// Staff in-system document viewer. Fetches the document through the authorised preview endpoint and shows it
// inside a Bootstrap modal. It never changes verification status and offers no download control of its own.
(function () {
  var modalEl = document.getElementById('document-viewer');
  if (!modalEl || !window.bootstrap) return;
  var body = document.getElementById('document-viewer-body');
  var title = document.getElementById('document-viewer-title');
  var zoomBar = document.getElementById('document-viewer-zoom');
  var modal = new bootstrap.Modal(modalEl);
  var objectUrl = null, scale = 1, img = null, token = 0;

  function message(text) {
    body.textContent = '';
    var p = document.createElement('p');
    p.className = 'text-center text-muted p-4 m-0';
    p.textContent = text;
    body.appendChild(p);
  }
  function release() {
    if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
    body.textContent = '';
    img = null; scale = 1;
    zoomBar.classList.add('d-none');
  }
  function applyZoom() {
    if (!img) return;
    img.style.width = (scale * 100) + '%';
    zoomBar.querySelector('[data-zoom="reset"]').textContent = Math.round(scale * 100) + '%';
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.js-view-document');
    if (!btn) return;
    var mine = ++token;
    release();
    title.textContent = btn.getAttribute('data-doc-name') || 'Document';
    message('Loading document…');
    modal.show();
    fetch(btn.getAttribute('data-doc-src'), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) throw new Error('status ' + r.status);
        var type = (r.headers.get('Content-Type') || '').split(';')[0].trim();
        if (['application/pdf', 'image/jpeg', 'image/png'].indexOf(type) === -1) throw new Error('type');
        return r.blob().then(function (b) { return { blob: b, type: type }; });
      })
      .then(function (res) {
        if (mine !== token) return; // viewer was closed or reopened meanwhile
        release();
        objectUrl = URL.createObjectURL(new Blob([res.blob], { type: res.type }));
        if (res.type === 'application/pdf') {
          var frame = document.createElement('iframe');
          frame.src = objectUrl;
          frame.title = title.textContent;
          frame.className = 'w-100 h-100 border-0';
          frame.style.minHeight = '70vh';
          body.appendChild(frame);
        } else {
          img = document.createElement('img');
          img.src = objectUrl;
          img.alt = title.textContent;
          img.style.cssText = 'display:block;margin:0 auto;max-width:none;height:auto;width:100%';
          img.draggable = false;
          img.addEventListener('error', function () { release(); message('This document could not be previewed.'); });
          body.appendChild(img);
          zoomBar.classList.remove('d-none');
          applyZoom();
        }
      })
      .catch(function () {
        if (mine !== token) return;
        release();
        message('This document could not be previewed. It may be missing or in an unsupported format.');
      });
  });

  zoomBar.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-zoom]');
    if (!b || !img) return;
    var a = b.getAttribute('data-zoom');
    scale = a === 'in' ? Math.min(4, scale + 0.25) : a === 'out' ? Math.max(0.25, scale - 0.25) : 1;
    applyZoom();
  });
  modalEl.addEventListener('hidden.bs.modal', function () { token++; release(); });
})();
