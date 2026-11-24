/* Live alert for MSWDO staff while they are signed in. Every 60 seconds it asks staff/doc_alerts.php what is new:
 * - each new unread notification (a Solo Parent submitted, re-uploaded, sent corrections, booked/changed/cancelled an interview)
 *   pops up in the corner with a button that opens the exact place (the document's row, Supporting Documents, the Interview card);
 * - the bell count and the "Documents to Verify" menu badge follow;
 * - when in-app alerts for staff are switched off in Settings, a newly arrived document still pops up the queue alert.
 * A freshly opened page never pops up old news. It reads only; opening a pop-up marks that notification read. */
(function () {
  'use strict';
  var tag = document.currentScript;
  if (!tag) { return; }
  var url = tag.getAttribute('data-url');
  var queueUrl = tag.getAttribute('data-queue');
  var EVERY = 60000;
  var MAX_SHOWN = 3;
  var KEY_DOC = 'virac.docAlert.latest';
  var KEY_ID = 'virac.alert.lastId';
  var lastDoc = null, lastId = null;
  try { lastDoc = sessionStorage.getItem(KEY_DOC); lastId = sessionStorage.getItem(KEY_ID); } catch (e) { /* private window: alerts still work for this page */ }
  lastId = lastId !== null && /^\d+$/.test(lastId) ? parseInt(lastId, 10) : null;

  function tr(text) { return window.ViracI18n && typeof window.ViracI18n.translate === 'function' ? window.ViracI18n.translate(text) : text; }
  function save(key, value) { try { sessionStorage.setItem(key, String(value)); } catch (e) { /* ignore */ } }
  var ICON = {
    bell: '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
    file: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m9 15 2 2 4-4"/>',
    x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'
  };
  function svg(name, size) { return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICON[name] + '</svg>'; }

  function stack() {
    var el = document.getElementById('staff-alert-stack');
    if (!el) {
      el = document.createElement('div');
      el.id = 'staff-alert-stack';
      el.className = 'staff-alert-stack';
      el.setAttribute('aria-live', 'polite');
      document.body.appendChild(el);
    }
    return el;
  }
  function ago(created) {
    var t = Date.parse(String(created || '').replace(' ', 'T'));
    if (isNaN(t)) { return ''; }
    var m = Math.round((Date.now() - t) / 60000);
    return m < 1 ? tr('just now') : (m < 60 ? m + ' ' + tr('min ago') : '');
  }
  function toast(opts) {
    var box = document.createElement('div');
    box.className = 'staff-alert' + (opts.action ? ' is-action' : '');
    box.setAttribute('role', 'status');
    var head = document.createElement('div');
    head.className = 'staff-alert-head';
    var title = document.createElement('span');
    title.innerHTML = svg(opts.icon || 'bell', 15);
    title.appendChild(document.createTextNode(' ' + opts.title));
    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'staff-alert-close';
    close.setAttribute('aria-label', tr('Close'));
    close.innerHTML = svg('x', 16);
    close.addEventListener('click', function () { box.remove(); });
    head.appendChild(title); head.appendChild(close);
    var text = document.createElement('p');
    text.textContent = opts.text;
    var foot = document.createElement('div');
    foot.className = 'staff-alert-foot';
    var open = document.createElement('a');
    open.className = 'btn-navy staff-alert-open';
    open.href = opts.href;
    open.textContent = opts.button;
    foot.appendChild(open);
    if (opts.when) { var w = document.createElement('small'); w.textContent = opts.when; foot.appendChild(w); }
    box.appendChild(head); box.appendChild(text); box.appendChild(foot);
    var s = stack();
    s.appendChild(box);
    while (s.children.length > MAX_SHOWN) { s.removeChild(s.firstChild); }
  }

  function docBadge(data) {
    var link = document.getElementById('nav-docs-to-verify');
    if (!link) { return; }
    var el = link.querySelector('[data-doc-badge]');
    if (data.waiting < 1) { if (el) { el.remove(); } return; }
    if (!el) {
      el = document.createElement('span');
      el.setAttribute('data-doc-badge', '');
      el.className = 'staff-live-badge';
      link.appendChild(el);
    }
    el.textContent = String(data.waiting);
    el.classList.toggle('is-resent', data.resent > 0);
  }
  function bell(unread) {
    if (typeof unread !== 'number') { return; }
    var link = document.querySelector('.nav-bell');
    if (!link) { return; }
    var el = link.querySelector('.nav-bell-count');
    if (unread < 1) { if (el) { el.remove(); } return; }
    if (!el) { el = document.createElement('span'); el.className = 'nav-bell-count'; link.appendChild(el); }
    el.textContent = unread > 99 ? '99+' : String(unread);
    link.setAttribute('aria-label', tr('Notifications') + ' (' + unread + ' ' + tr('unread') + ')');
  }
  function buttonFor(item) {
    if (/re-?upload|Uploaded:/i.test(item.message)) { return tr('Open the document'); }
    if (/interview|appointment/i.test(item.message)) { return tr('Open the interview'); }
    return tr('Open the application');
  }

  function check() {
    var q = lastId !== null ? url + (url.indexOf('?') === -1 ? '?' : '&') + 'since=' + lastId : url;
    fetch(q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || typeof data.waiting !== 'number') { return; }
        docBadge(data);
        bell(data.unread);
        var items = Array.isArray(data.items) ? data.items : [];
        items.forEach(function (item) {
          toast({ action: item.action, icon: item.action ? 'file' : 'bell', title: tr(item.action ? 'New · needs your action' : item.label),
            text: item.message, href: item.open, button: buttonFor(item), when: ago(item.created_at) });
        });
        if (items.length > MAX_SHOWN && data.notifications_url) {
          toast({ title: tr('More updates'), text: (items.length - MAX_SHOWN) + ' ' + tr('more on the Notifications page.'), href: data.notifications_url, button: tr('Open Notifications') });
        }
        // No notification came (in-app alerts for staff may be off): still tell staff about newly arrived documents.
        if (!items.length && data.latest && lastDoc !== null && data.latest > lastDoc && data.waiting > 0) {
          toast({ action: true, icon: 'file', title: tr(data.resent > 0 ? 'Documents re-uploaded for verification' : 'New documents for verification'),
            text: data.waiting + ' ' + tr(data.waiting === 1 ? 'Solo Parent is waiting.' : 'Solo Parents are waiting.') + (data.first ? ' ' + tr('Top of the queue:') + ' ' + data.first.name + ' (' + data.first.ref + ').' : ''),
            href: queueUrl, button: tr('Open the queue') });
        }
        if (data.latest) { lastDoc = data.latest; save(KEY_DOC, lastDoc); } else if (lastDoc === null) { lastDoc = ''; }
        if (typeof data.latest_id === 'number') {
          var top = items.reduce(function (m, i) { return Math.max(m, i.id); }, data.latest_id);
          lastId = lastId === null ? top : Math.max(lastId, top);
          save(KEY_ID, lastId);
        }
      })
      .catch(function () { /* offline or signed out: try again at the next check */ });
  }

  check();
  setInterval(check, EVERY);
})();
