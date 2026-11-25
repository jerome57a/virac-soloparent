/* Staff document remarks, the live staff alert, Appointment Management, staff Notifications and the Solo Parent dashboard ID card.
 * Read-only: fixture pages, GET only; the alert endpoint is answered by the test, nothing is sent.
 * Requires VIRAC_TEST_URL (explicit test-only origin with a port) and the isolated test database (for the application page fixture).
 *
 *   VIRAC_TEST_URL=http://127.0.0.1:8099/virac-soloparent/ NODE_PATH=scripts/node_modules node scripts/test-staff-alerts-ui.cjs */
const { chromium } = require('playwright');
const { parseTestBase, newGuardedContext } = require('./lib/test-origin.cjs');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
  const base = parseTestBase(process.env.VIRAC_TEST_URL);
  const php = (file, arg) => execFileSync('php', [path.join(__dirname, 'fixtures', file), arg], { encoding: 'utf8' });
  const pages = {
    'staff/application.php': php('offline-layout.php', 'staff'),
    'staff/appointments.php': php('batch-ui.php', 'appointments'),
    'staff/notifications.php': php('batch-ui.php', 'notifications'),
    'applicant/dashboard.php': php('batch-ui.php', 'idcard'),
  };
  for (const [name, html] of Object.entries(pages)) assert(!/Warning:|Fatal error:|Notice:|Deprecated:/.test(html), name + ' renders cleanly');
  assert(pages['staff/application.php'].includes('staff-doc-alerts.js'), 'the live alert runs on the application page too');
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const { context } = await newGuardedContext(browser, base, {}, { stubPages: true, fixture: relative => pages[relative] });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));

    // The live alert endpoint: the first check only learns where to start; a later one brings two new alerts.
    const calls = [];
    let reply = { waiting: 2, resent: 1, latest: '2026-11-24 10:00:00', first: null, unread: 1, latest_id: 10, items: [] };
    await page.route('**/staff/doc_alerts.php**', route => { calls.push(route.request().url()); route.fulfill({ contentType: 'application/json', body: JSON.stringify(reply) }); });
    await page.clock.install();
    await page.goto(base + 'staff/application.php');
    await page.waitForFunction(() => !!window.ViracI18n);
    await page.waitForTimeout(300);
    assert(calls.length >= 1 && !/since=\d/.test(calls[0]), 'the first check asks no history: ' + calls[0]);
    assert.equal(await page.locator('.staff-alert').count(), 0, 'a freshly opened page pops up nothing');
    assert.equal(await page.locator('.nav-bell .nav-bell-count').innerText(), '1', 'the bell shows the unread count');

    // ---- Remarks: Needs Correction asks for a remark, Save goes to the missing one. ----
    const row = page.locator('#verification-form .doc-item').first();
    const label = await row.getAttribute('data-doc-label');
    assert(await row.locator('.doc-remark-req').isHidden(), 'no "* Required" before choosing Needs Correction');
    await row.locator('.js-doc-status').selectOption('NEEDS_CORRECTION');
    assert(await row.locator('.doc-remark-req').isVisible(), '"* Required" appears with Needs Correction');
    assert(await row.locator('.js-doc-remark').evaluate(el => el === document.activeElement), 'the cursor goes to the remark box');
    await page.locator('#verification-form button[type=submit]').scrollIntoViewIfNeeded();
    const before = page.url();
    await page.locator('#verification-form button[type=submit]').click();
    await page.waitForTimeout(400);
    assert.equal(page.url(), before, 'nothing is sent while a remark is missing');
    assert(await row.evaluate(el => el.classList.contains('remark-missing')), 'the document is outlined');
    assert(await row.locator('.doc-remark-error').isVisible(), 'the row says what to do');
    assert(/Write what the Solo Parent must fix/.test(await row.locator('.doc-remark-error').innerText()), 'the message names the fix');
    assert(await row.locator('.js-doc-remark').evaluate(el => el === document.activeElement), 'the cursor is in the missing remark');
    const hint = await page.locator('.doc-save-hint').innerText();
    assert(hint.includes('1 remark missing') && hint.includes(label), 'the line beside Save names the document: ' + hint);
    assert(await row.evaluate(el => { const r = el.getBoundingClientRect(); return r.top >= 0 && r.top < innerHeight; }), 'the page scrolled to the document');
    await row.locator('.js-doc-remark').fill('The photo is blurry. Take it again in good light.');
    assert(!(await row.evaluate(el => el.classList.contains('remark-missing'))) && await page.locator('.doc-save-hint').isHidden(), 'writing the remark clears the warning');
    await row.locator('.js-doc-status').selectOption('VERIFIED');
    assert(await row.locator('.doc-remark-req').isHidden(), 'Verified needs no remark');
    assert.equal(await page.locator('.gi-bring').count(), 0, 'the Interview card no longer carries the bring line');

    // ---- Live alert: two new alerts pop up, each opens its place; the bell follows. ----
    reply = { ...reply, unread: 3, items: [
      { id: 11, label: 'Document re-uploaded', message: 'RE-UPLOADED: Maria Santos sent "Birth certificate" again for VSP-1 — needs your verification.', action: true, open: base + 'staff/notifications.php?open=11', created_at: '' },
      { id: 12, label: 'Appointment update', message: 'VSP-2 (Jose Reyes) booked an interview date: Nov 28, 2026.', action: true, open: base + 'staff/notifications.php?open=12', created_at: '' },
    ] };
    await page.clock.fastForward(61000);
    await page.waitForTimeout(400);
    assert(/since=10/.test(calls[calls.length - 1]), 'the next check asks only for newer alerts: ' + calls[calls.length - 1]);
    assert.equal(await page.locator('.staff-alert').count(), 2, 'one pop-up per new alert');
    assert.equal(await page.locator('.staff-alert .staff-alert-open').first().innerText(), 'Open the document', 'a re-upload opens the document');
    assert.equal(await page.locator('.staff-alert .staff-alert-open').nth(1).innerText(), 'Open the interview', 'a booking opens the interview');
    assert((await page.locator('.staff-alert .staff-alert-open').first().getAttribute('href')).endsWith('open=11'), 'the button opens that notification');
    assert.equal(await page.locator('.nav-bell .nav-bell-count').innerText(), '3', 'the bell count follows');
    reply = { ...reply, items: [] };
    await page.clock.fastForward(61000);
    await page.waitForTimeout(300);
    assert(/since=12/.test(calls[calls.length - 1]), 'seen alerts are not asked again');
    assert.equal(await page.locator('.staff-alert').count(), 2, 'no repeat pop-up');
    await page.locator('.staff-alert .staff-alert-close').first().click();
    assert.equal(await page.locator('.staff-alert').count(), 1, 'a pop-up can be closed');
    await page.evaluate(() => { document.documentElement.dataset.theme = 'dark'; });
    const alertBg = await page.locator('.staff-alert').first().evaluate(el => getComputedStyle(el).backgroundColor);
    assert.notEqual(alertBg, 'rgb(255, 255, 255)', 'the pop-up follows dark mode');
    await page.setViewportSize({ width: 360, height: 800 });
    assert(await page.locator('.staff-alert').first().evaluate(el => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; }), 'the pop-up fits a phone');

    // ---- Appointment Management. ----
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(base + 'staff/appointments.php');
    await page.waitForTimeout(200);
    assert.equal(await page.locator('.ap-next, .ap-chips, #office-today').count(), 0, 'only the lists: no Next step banner, no count links, no office panel');
    const titles = await page.locator('.ap-sec-h h2 > span').allInnerTexts();
    assert.deepEqual(titles, ['Needs a result', 'Today', 'Needs an interview date', 'Waiting for the Solo Parent to pick a date', 'Upcoming', 'ID release date passed', 'Interview days'], 'sections in work order: ' + titles.join(' | '));
    assert.deepEqual(await page.locator('.ap-sec-h .ap-cnt').allInnerTexts(), ['2', '3', '1', '0', '2', '1'], 'the counts sit on the list headings');
    const outcomeRow = page.locator('#staff-needs-outcome .ap-row').first();
    assert.equal(await outcomeRow.locator('.btn-navy').innerText(), 'Open', 'one main button per row');
    assert.equal(await page.locator('.ap-more, .ap-more-btn, .ap-more-panel').count(), 0, 'no More button');
    assert(/Renewal/.test(await outcomeRow.innerText()), 'a renewal is tagged');
    assert((await page.locator('#staff-needs-schedule .ap-row a.btn-navy').getAttribute('href')).endsWith('#schedule-interview'), 'Schedule date opens the schedule card');

    // ---- Staff Notifications. ----
    await page.goto(base + 'staff/notifications.php');
    const groups = await page.locator('.notif-group-title > span[data-i18n]').allInnerTexts();
    assert.deepEqual(groups, ['Needs your action', 'For your information'], 'two groups');
    assert.equal(await page.locator('.notif-group-title.is-act .notif-group-count').innerText(), '2', 'unread action items first');

    // ---- Solo Parent dashboard ID card. ----
    await page.goto(base + 'applicant/dashboard.php');
    const card = c => page.locator(`[data-case="${c}"]`);
    assert(/VSP-2026-000123/.test(await card('valid').innerText()) && !/Maria Santos/.test(await card('valid').innerText()), 'ID number shown, name never');
    assert.equal(await card('valid').locator('.idc-badge').innerText(), 'Active');
    assert.equal(await card('due').locator('.idc-badge').innerText(), 'Expires today');
    assert.equal(await card('expired').locator('.idc-badge').innerText(), 'Expired');
    assert.equal(await card('in_progress').locator('.idc-badge').innerText(), 'Renewal in progress');
    assert.equal(await card('pending').locator('.idc-badge').innerText(), 'Being prepared');
    assert((await card('valid').locator('a.idc-sample').getAttribute('href')).includes('section=myid'), 'tapping the card opens My ID');
    for (const theme of ['light', 'dark']) {
      await page.evaluate(t => { document.documentElement.dataset.theme = t; }, theme);
      for (const width of [1280, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 900 });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `the ID cards fit ${width}px (${theme})`);
        const fits = await card('valid').locator('.idc-no').evaluate(el => el.scrollWidth <= el.clientWidth + 1);
        assert(fits, `the ID number fits on the card at ${width}px`);
      }
    }
    // Tagalog.
    await page.evaluate(() => { document.documentElement.lang = 'tl'; document.dispatchEvent(new CustomEvent('virac:languagechange', { detail: 'tl' })); if (window.ViracI18n) window.ViracI18n.refresh(); });
    await page.waitForTimeout(300);
    assert(/Aktibo|Active/.test(await card('valid').locator('.idc-badge').innerText()), 'the badge is still there in Tagalog');
    assert(/Preview lamang/.test(await card('valid').locator('.idc-fine').innerText()), 'the preview line is in Tagalog: ' + await card('valid').locator('.idc-fine').innerText());

    assert.deepEqual(errors, [], 'no script errors');
    console.log('PASS: remark check (Required, scroll, cursor, hint), live staff alert (no old news, one pop-up each, exact place, bell, dark, phone), Appointment Management (only the lists, in work order, counts, no More button), Notifications groups, dashboard ID card (no name, badges, light/dark, 320-1280px, Tagalog).');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
