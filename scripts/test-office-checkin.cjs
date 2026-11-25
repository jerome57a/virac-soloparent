/* Office check-in (browser): "Today at the office" with the check-in box, the confirm card for every case, today's lists, and the
 * Solo Parent's counter card. Read-only: fixture pages (scripts/fixtures/batch-ui.php), GET only; nothing is submitted.
 * Requires VIRAC_TEST_URL (explicit test-only origin with a port).
 *
 *   VIRAC_TEST_URL=http://127.0.0.1:8099/virac-soloparent/ NODE_PATH=scripts/node_modules node scripts/test-office-checkin.cjs */
const { chromium } = require('playwright');
const { parseTestBase, newGuardedContext } = require('./lib/test-origin.cjs');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
  const base = parseTestBase(process.env.VIRAC_TEST_URL);
  const php = (...args) => execFileSync('php', [path.join(__dirname, 'fixtures', 'batch-ui.php'), ...args], { encoding: 'utf8' });
  const cases = ['list', 'today', 'already', 'other_day', 'no_date', 'docs', 'closed', 'many', 'none', 'release_today', 'release_overdue', 'release_later', 'release_unscheduled'];
  const pages = {};
  for (const c of cases) pages[`staff/office-${c}.php`] = php('office', c);
  pages['applicant/counter.php'] = php('counter', 'renewal');
  pages['applicant/counter-release.php'] = php('counter', 'release');
  pages['applicant/myid-renewal.php'] = php('myid', 'renewal');
  pages['applicant/myid.php'] = php('myid');
  pages['staff/strip.php'] = php('office', 'list', 'compact');
  pages['staff/strip-nobody.php'] = php('office', 'list', 'nobody');
  pages['staff/strip-today.php'] = php('office', 'today', 'compact');
  for (const [name, html] of Object.entries(pages)) assert(!/Warning:|Fatal error:|Notice:|Deprecated:/.test(html), name + ' renders cleanly');
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const { context } = await newGuardedContext(browser, base, {}, { stubPages: true, fixture: relative => pages[relative] });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    const open = c => page.goto(base + `staff/office-${c}.php`);

    // The panel: box, counts, lists in arrival order.
    await open('list');
    assert(await page.locator('#ot-ref').isVisible(), 'the check-in box is there');
    assert.equal(await page.locator('#ot-ref').getAttribute('name'), 'checkin', 'the box searches with ?checkin=');
    assert.deepEqual(await page.locator('.ot-counts strong').allInnerTexts(), ['2', '2', '1'], 'waiting, expected, done');
    assert.deepEqual(await page.locator('.ot-qn:not(.is-dim)').allInnerTexts(), ['1', '2'], 'the waiting list is numbered');
    assert(/VIRAC-SP-2026-0062/.test(await page.locator('.ot-row').first().innerText()), 'first to arrive is first');
    assert((await page.locator('.ot-row', { hasText: 'Ana Reyes' }).locator('a').getAttribute('href')).includes('application.php?id='), 'an expected row opens its application');
    assert((await page.locator('.ot-row', { hasText: 'Edwin' }).locator('a').getAttribute('href')).endsWith('#decision'), 'Open interview goes to the interview result');
    
    // One match opens the application straight away (the controller redirects), so the page never shows a confirm card; several matches
    // link each application directly, and a miss says so.
    for (const c of ['today', 'already', 'other_day', 'no_date', 'docs', 'closed', 'release_today', 'release_overdue', 'release_later', 'release_unscheduled']) {
      await open(c);
      assert.equal(await page.locator('.ot-confirm').count(), 0, c + ': no confirm card');
    }
    await open('many');
    assert.equal(await page.locator('.ot-pick li').count(), 2, 'several matches: choose one');
    assert((await page.locator('.ot-pick a').first().getAttribute('href')).includes('application.php?id='), 'each match opens its application directly');
    await open('none');
    assert(/Not found/.test(await page.locator('.ot-case').innerText()), 'not found says so');
    assert.equal(await page.locator('label[for=ot-ref]').innerText(), "Open a Solo Parent's application", 'the box says what it does');
    assert.equal((await page.locator('.ot-find').innerText()).trim(), 'Open', 'the button is Open');

    // Light, dark, phone.
    await open('today');
    for (const theme of ['light', 'dark']) {
      await page.evaluate(t => { document.documentElement.dataset.theme = t; }, theme);
      for (const width of [1280, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 900 });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `the panel fits ${width}px (${theme})`);
      }
    }
    // Tagalog.
    await page.evaluate(() => { document.documentElement.lang = 'tl'; document.dispatchEvent(new CustomEvent('virac:languagechange', { detail: 'tl' })); if (window.ViracI18n) window.ViracI18n.refresh(); });
    await page.waitForTimeout(200);
    assert(/Ngayong araw sa opisina/.test(await page.locator('#ot-title').innerText()), 'the title is in Tagalog');

    // The dashboard strip: the box, one line of counts and the next person; no lists.
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(base + 'staff/strip.php');
    assert(await page.locator('#ot-ref').isVisible(), 'the strip keeps the check-in box');
    assert.equal(await page.locator('.ot-lh, .ot-counts').count(), 0, 'the strip has no lists');
    assert.deepEqual((await page.locator('.ot-line-c a').allInnerTexts()).map(t => t.replace(/\s+/g, ' ').trim()), ['2 waiting', '2 expected', '1 done today'], 'one line of counts');
    const hrefs = await page.locator('.ot-line-c a').evaluateAll(a => a.map(x => x.getAttribute('href')));
    assert(hrefs.every(h => h.endsWith('staff/appointments.php#staff-today-card')), 'each count opens Today\'s list on Appointment Management');
    assert((await page.locator('.ot-all').getAttribute('href')).endsWith('staff/appointments.php'), 'See today\'s work opens Appointment Management');
    assert(/VIRAC-SP-2026-0062/.test(await page.locator('.ot-next').innerText()) && (await page.locator('.ot-next a').getAttribute('href')).endsWith('#decision'), 'Next is the first person waiting, with Open interview');
    await page.goto(base + 'staff/strip-nobody.php');
    assert.equal(await page.locator('.ot-next').count(), 0, 'no Next line when nobody is waiting');
    await page.goto(base + 'staff/strip-today.php');
    assert.equal(await page.locator('.ot-confirm').count(), 0, 'the strip has no confirm card');
    for (const width of [390, 320]) {
      await page.setViewportSize({ width, height: 900 });
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `the strip fits ${width}px`);
    }
    // The full lists keep their anchors for the count links.
    await open('list');
    assert.deepEqual(await page.locator('.ot-lh').evaluateAll(h => h.map(x => x.id)), ['ot-waiting', 'ot-expected', 'ot-done'], 'the list headings have the anchors');

    // The Solo Parent's release-day card and the My ID wording.
    await page.setViewportSize({ width: 390, height: 800 });
    await page.goto(base + 'applicant/counter-release.php');
    assert(/Your ID is ready for release today/.test(await page.locator('.ot-when').innerText()) && /MSWDO Office, Virac/.test(await page.locator('.ot-when').innerText()), 'the release day card says when and where');
    assert.equal(await page.locator('.ot-bring li').count(), 1, 'only a valid ID is brought for a release');
    assert(/valid government ID/i.test(await page.locator('.ot-bring').innerText()) && !/1x1/.test(await page.locator('.ot-counter').innerText()), 'no pictures or documents on the release day');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'the release day card fits a phone');
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(base + 'applicant/myid-renewal.php');
    assert.equal((await page.locator('.applicant-id-status').innerText()).trim(), 'Renewal in progress', 'My ID says Renewal in progress');
    const renewalNote = await page.locator('.applicant-id-renewal-note').innerText();
    assert(/VIRAC-SP-2026-0068/.test(renewalNote) && /Keep this ID/.test(renewalNote), 'the note names the renewal');
    assert((await page.locator('a.applicant-id-link').first().getAttribute('href')).includes('section=renewal&type=status'), 'View Renewal Status');
    assert(/November 14, 2026/.test(await page.locator('.applicant-id-details').innerText()), 'the dates stay as they are');
    await page.goto(base + 'applicant/myid.php');
    assert.equal((await page.locator('.applicant-id-status').innerText()).trim(), 'Expired', 'without a renewal it still says Expired');
    assert.equal(await page.locator('.applicant-id-renewal-note').count(), 0, 'no renewal note');

    // The Solo Parent's counter card.
    await page.setViewportSize({ width: 390, height: 800 });
    await page.goto(base + 'applicant/counter.php');
    assert.equal(await page.locator('.ot-ref strong').innerText(), 'VIRAC-SP-2026-0062', 'the reference number in large type');
    assert.equal(await page.locator('.ot-bring li').count(), 4, 'what to bring (renewal: with the current Solo Parent ID)');
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'the counter card fits a phone');

    assert.deepEqual(errors, [], 'no script errors');
    console.log('PASS: office check-in: the dashboard strip (search box, counts, next, no lists), no confirm card, several matches open their application directly, light/dark, 320-1280px, Tagalog, and the Solo Parent counter card.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
