/* Next-step popup test (staff application page): drives public/js/staff-next-step.js in a real browser with a minimal page that has the same
 * element ids the real page has. Checks: which popup opens after Save Verification (data-auto=1), that the page's own form is moved into the
 * popup and put back on close, that the correction step's "Next step" banner reopens it (the schedule step has only the button on its own card), that nothing opens when nothing is waiting or on a plain page view,
 * and that a scheduling error reopens the schedule popup. No server, no database, no network.
 *
 *   NODE_PATH=scripts/node_modules node scripts/test-next-step-popup.cjs
 */
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const bootstrapJs = fs.readFileSync(path.join(root, 'public/lib/bootstrap/bootstrap.bundle.min.js'), 'utf8');
const bootstrapCss = fs.readFileSync(path.join(root, 'public/lib/bootstrap/bootstrap.min.css'), 'utf8');
const stepJs = fs.readFileSync(path.join(root, 'public/js/staff-next-step.js'), 'utf8');

function page({ correction, schedule, scheduleError, auto }) {
  return `<!doctype html><html><head><meta charset="utf-8"><style>${bootstrapCss}</style></head><body>
  <div class="review-card" id="checklist"><div class="review-card-head"><div class="title">Documents</div></div>
    <form id="verification-form"><button type="submit">Save Verification</button></form>
    ${correction ? `<div id="correction-sms"><button type="button" data-next-step-open="correction">Review and send correction SMS</button><div id="correction-review-store" hidden><div id="correction-review"><form id="corr-form"><button type="submit" class="js-correction-confirm" data-blocked="0">Confirm and Send Correction SMS</button></form></div></div></div>` : ''}
  </div>
  <div id="schedule-card">${schedule ? `<button type="button" data-next-step-open="schedule">Schedule the interview</button><div id="schedule-store" hidden><div id="schedule-modal-source" data-open="${scheduleError ? 1 : 0}"><form id="sched-form"><input type="date" name="interview_date"><input type="time" name="interview_time"><button type="submit">Schedule Date and Send SMS</button></form></div></div>` : ''}</div>
  <script>${bootstrapJs}</script>
  <script id="step">${stepJs}</script>
  </body></html>`.replace('<script id="step">', `<script id="step" data-auto="${auto ? 1 : 0}">`);
}

let failures = 0;
const check = (ok, msg) => { if (!ok) { failures++; console.log('FAIL: ' + msg); } };

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const run = async (opts, fn) => {
    const ctx = await browser.newContext({ viewport: { width: 1000, height: 800 } });
    const p = await ctx.newPage();
    // document.currentScript is the inline script itself, so its data-auto attribute is what the real page's script tag carries.
    await p.goto('about:blank' + (opts.hash || ''));
    await p.setContent(page(opts));
    await p.waitForTimeout(700);
    await fn(p);
    await ctx.close();
  };
  const open = (p) => p.evaluate(() => { const m = document.getElementById('next-step-modal'); return !!m && m.classList.contains('show'); });
  const inModal = (p, sel) => p.evaluate((s) => { const m = document.getElementById('next-step-body'); return !!m && !!m.querySelector(s); }, sel);

  await run({ correction: true, schedule: false, auto: true }, async (p) => {
    check(await open(p), 'correction popup should open after Save Verification');
    check(await inModal(p, '#corr-form'), 'the correction form should be inside the popup');
    await p.click('#next-step-modal .btn-secondary');
    await p.waitForTimeout(500);
    check(!(await open(p)), 'Later should close the popup');
    check(!(await inModal(p, '#corr-form')) && (await p.$('#correction-sms #corr-form')) !== null, 'the form should return to the page when the popup closes');
    check((await p.$('#checklist .info-box button')) !== null, 'the Next step banner should be on the document card');
    await p.click('#checklist .info-box button');
    await p.waitForTimeout(500);
    check(await open(p) && await inModal(p, '#corr-form'), 'the banner button should reopen the popup');
  });

  await run({ correction: false, schedule: true, auto: true }, async (p) => {
    check(await open(p) && await inModal(p, '#sched-form'), 'schedule popup should open when every document is verified');
    check((await p.$('#next-step-body input[name="interview_time"]')) !== null, 'the popup should have the time field');
  });

  await run({ correction: false, schedule: true, auto: false }, async (p) => {
    check(!(await open(p)), 'a plain page view must not open a popup');
    check(!(await p.isVisible('#sched-form')), 'the form must not be visible on the page itself (only in the popup)');
    await p.click('button[data-next-step-open="schedule"]');
    await p.waitForTimeout(500);
    check(await open(p) && await inModal(p, '#sched-form'), 'the page button should open the schedule popup at any time');
    check((await p.$('#checklist .info-box button')) === null, 'the schedule step has no banner: its own card holds the single button');
    check((await p.$$('[data-next-step-open="schedule"]')).length === 1, 'exactly one Schedule the interview button');
  });

  await run({ correction: false, schedule: true, scheduleError: true, auto: false }, async (p) => {
    check(await open(p) && await inModal(p, '#sched-form'), 'a scheduling error should reopen the schedule popup');
  });

  await run({ correction: true, schedule: false, auto: true }, async (p) => {
    // mixed outcome is never both at once in the real page; correction wins if both are present
  });
  await run({ correction: true, schedule: true, auto: true }, async (p) => {
    check(await inModal(p, '#corr-form') && !(await inModal(p, '#sched-form')), 'correction SMS comes first when both are present');
  });

  await run({ correction: false, schedule: false, auto: true }, async (p) => {
    check(!(await open(p)) && (await p.$('#next-step-modal')) === null && (await p.$('#checklist .info-box')) === null, 'nothing should appear when nothing is waiting');
  });

  await run({ correction: false, schedule: true, auto: false, hash: '#schedule-interview' }, async (p) => {
    check(await open(p) && await inModal(p, '#sched-form'), 'a link to #schedule-interview should open the schedule popup');
  });

  await browser.close();
  if (failures) { console.log(failures + ' check(s) failed'); process.exit(1); }
  console.log('PASS: next-step popup: correction SMS first, schedule when all verified, banner reopens, nothing when idle, error reopens the schedule.');
})();
