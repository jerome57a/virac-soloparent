/* Alerts MSWDO staff while they are signed in: every 30 seconds it asks staff/doc_alerts.php how many Solo Parents are waiting
 * for document verification. When a newer document has arrived since the last check, a toast names the Solo Parent first in line
 * and stays until it is dismissed. The menu badge is updated as well. It reads only; it never changes anything. */
(function () {
  var tag = document.currentScript;
  if (!tag) { return; }
  var url = tag.getAttribute('data-url');
  var queueUrl = tag.getAttribute('data-queue');
  var KEY = 'virac.docAlert.latest';
  var last = null;
  try { last = sessionStorage.getItem(KEY); } catch (e) { last = null; }

  function badge(data) {
    var link = document.getElementById('nav-docs-to-verify');
    if (!link) { return; }
    var el = link.querySelector('[data-doc-badge]');
    if (data.waiting < 1) { if (el) { el.remove(); } return; }
    if (!el) {
      el = document.createElement('span');
      el.setAttribute('data-doc-badge', '');
      el.style.cssText = 'margin-left:8px;color:#fff;border-radius:999px;padding:1px 8px;font-size:12px;font-weight:700';
      link.appendChild(el);
    }
    el.textContent = String(data.waiting);
    el.style.background = data.resent > 0 ? '#b45309' : '#1b3a6b';
  }

  function toast(data) {
    var old = document.getElementById('doc-alert-toast');
    if (old) { old.remove(); }
    var box = document.createElement('div');
    box.id = 'doc-alert-toast';
    box.setAttribute('role', 'alert');
    box.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:2000;max-width:340px;background:#1b3a6b;color:#fff;border-left:6px solid #f59e0b;border-radius:10px;padding:12px 14px;box-shadow:0 8px 24px rgba(0,0,0,.35);font-size:14px';
    var title = document.createElement('div');
    title.style.fontWeight = '700';
    title.textContent = data.resent > 0 ? 'Documents re-uploaded for verification' : 'New documents for verification';
    var text = document.createElement('div');
    text.style.margin = '4px 0 8px';
    text.textContent = data.waiting + ' Solo Parent' + (data.waiting === 1 ? ' is' : 's are') + ' waiting.' +
      (data.first ? ' Top of the queue: ' + data.first.name + ' (' + data.first.ref + ').' : '');
    var open = document.createElement('a');
    open.href = queueUrl;
    open.textContent = 'Open the queue';
    open.style.cssText = 'color:#fff;font-weight:700;text-decoration:underline;margin-right:14px';
    var close = document.createElement('button');
    close.type = 'button';
    close.textContent = 'Dismiss';
    close.style.cssText = 'background:transparent;border:1px solid #fff;color:#fff;border-radius:6px;padding:2px 10px';
    close.addEventListener('click', function () { box.remove(); });
    box.appendChild(title); box.appendChild(text); box.appendChild(open); box.appendChild(close);
    document.body.appendChild(box);
  }

  function check() {
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || typeof data.waiting !== 'number') { return; }
        badge(data);
        if (data.latest && last !== null && data.latest > last) { toast(data); }
        if (data.latest) {
          last = data.latest;
          try { sessionStorage.setItem(KEY, last); } catch (e) { /* storage unavailable: alerts still work for this page */ }
        } else if (last === null) {
          last = '';
        }
      })
      .catch(function () { /* offline or signed out: try again at the next check */ });
  }

  check();
  setInterval(check, 30000);
})();
