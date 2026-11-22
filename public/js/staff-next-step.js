/* Schedule form: ask before scheduling on a weekend, a holiday or closure, or outside office hours. It only asks; it never blocks.
 * The holidays and the office hours come from the form's data-warn attribute. */
window.virScheduleConfirm = function (form) {
  var notes = [];
  try {
    var warn = JSON.parse(form.getAttribute('data-warn') || '{}');
    var d = form.elements['interview_date'] ? form.elements['interview_date'].value : '';
    var t = form.elements['interview_time'] ? form.elements['interview_time'].value : '';
    if (d) {
      var day = new Date(d + 'T00:00:00').getDay();
      if (day === 0 || day === 6) { notes.push('it is a weekend'); }
      else if (warn.closed && warn.closed[d]) { notes.push(warn.closed[d].charAt(0).toLowerCase() + warn.closed[d].slice(1)); }
    }
    if (t && warn.opens && (t < warn.opens || t >= warn.closes)) { notes.push('the time is outside office hours (' + warn.label + ')'); }
  } catch (e) { notes = []; }
  var message = 'Schedule this interview and text the Solo Parent?';
  if (notes.length) { message = 'Warning: ' + notes.join('; ') + '.\n\n' + message; }
  return confirm(message);
};
/* Staff application page: after Save Verification the next step opens as a popup, like the document viewer.
 *   - a document marked Needs Correction  -> the correction SMS review (Confirm and Send);
 *   - every document verified             -> the "Schedule the interview" form (date, time, SMS).
 * The forms live in hidden holders on the page (#correction-review, #schedule-modal-source) and are moved into the popup and put back
 * when it closes, so nothing is duplicated and closing the popup loses nothing. The buttons marked data-next-step-open and the "Next step"
 * banner open it. It opens by itself when the page was reached from Save Verification (data-auto="1"), after a scheduling error, or
 * from a link to #schedule-interview (the dashboard's Ready to Schedule list). */
(function () {
  var tag = document.currentScript;
  var auto = !!tag && tag.getAttribute('data-auto') === '1';

  function init() {
    if (!window.bootstrap || !window.bootstrap.Modal) { return; }
    var steps = [];
    var correction = document.getElementById('correction-review');
    if (correction && correction.querySelector('.js-correction-confirm')) {
      steps.push({
        key: 'correction', el: correction, open: false,
        title: 'Next step: send the correction SMS',
        hint: 'Some documents need correction. Review the message and send it to the Solo Parent.',
        button: 'Review correction SMS'
      });
    }
    var schedule = document.getElementById('schedule-modal-source');
    if (schedule && schedule.querySelector('form')) {
      steps.push({
        key: 'schedule', el: schedule, open: schedule.getAttribute('data-open') === '1',
        title: 'Next step: schedule the interview',
        hint: 'All documents are verified. Choose the interview date and time; the Solo Parent is texted.',
        button: 'Schedule the interview'
      });
    }
    if (!steps.length) { return; }

    var shell = document.createElement('div');
    shell.className = 'modal fade';
    shell.id = 'next-step-modal';
    shell.tabIndex = -1;
    shell.setAttribute('aria-hidden', 'true');
    shell.innerHTML = '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">' +
      '<div class="modal-header py-2"><h2 class="modal-title fs-6" id="next-step-title"></h2>' +
      '<button type="button" class="btn btn-sm btn-secondary ms-auto" data-bs-dismiss="modal">Later</button></div>' +
      '<div class="modal-body" id="next-step-body"></div></div></div>';
    shell.setAttribute('aria-labelledby', 'next-step-title');
    document.body.appendChild(shell);
    var modal = new window.bootstrap.Modal(shell);
    var body = shell.querySelector('#next-step-body');
    var title = shell.querySelector('#next-step-title');
    var current = null;

    steps.forEach(function (s) {
      s.home = document.createElement('div');
      s.home.hidden = true;
      s.el.parentNode.insertBefore(s.home, s.el);
    });

    function put(s) {
      if (current && current !== s) { giveBack(current); }
      current = s;
      title.textContent = s.title;
      body.appendChild(s.el);
      modal.show();
    }
    function giveBack(s) {
      if (s && s.home.parentNode) { s.home.parentNode.insertBefore(s.el, s.home.nextSibling); }
    }
    shell.addEventListener('hidden.bs.modal', function () {
      if (current) { giveBack(current); current = null; }
    });

    // The "Next step" banner on the document card (always there while a step is waiting).
    var card = document.getElementById('checklist');
    if (card) {
      var banner = document.createElement('div');
      banner.className = 'info-box';
      banner.style.maxWidth = 'none';
      banner.setAttribute('role', 'status');
      var first = steps[0];
      banner.innerHTML = '<strong></strong> <span></span> ';
      banner.querySelector('strong').textContent = first.title + '.';
      banner.querySelector('span').textContent = first.hint;
      var go = document.createElement('button');
      go.type = 'button';
      go.className = 'btn-navy ms-2';
      go.textContent = first.button;
      go.addEventListener('click', function () { put(first); });
      banner.appendChild(go);
      var anchor = card.querySelector('.review-card-head');
      if (anchor && anchor.nextSibling) { card.insertBefore(banner, anchor.nextSibling); } else { card.insertBefore(banner, card.firstChild); }
    }

    // The buttons on the page.
    steps.forEach(function (s) {
      Array.prototype.forEach.call(document.querySelectorAll('[data-next-step-open="' + s.key + '"]'), function (b) {
        b.addEventListener('click', function () { put(s); });
      });
    });

    // Open by itself: after Save Verification, or when a scheduling attempt just failed (so the message is seen with the form).
    var toOpen = null;
    steps.forEach(function (s) { if (s.open) { toOpen = s; } });
    if (!toOpen && auto) { toOpen = steps[0]; }
    if (!toOpen && window.location.hash === '#schedule-interview') {
      steps.forEach(function (s) { if (s.key === 'schedule') { toOpen = s; } });
    }
    if (toOpen) { put(toOpen); }
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
