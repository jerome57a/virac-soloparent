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
    assert((await page.locator('.ot-row', { hasText: 'Ana Reyes' }).locator('a').getAttribute('href')).includes('checkin=VIRAC-SP-2026-0071'), 'Check in on an expected row opens its card');
    assert((await page.locator('.ot-row', { hasText: 'Edwin' }).locator('a').getAttribute('href')).endsWith('#decision'), 'Open interview goes to the interview result');
    assert.equal(await page.locator('.ot-confirm').count(), 0, 'no card before a search');

    // Booked today: the card, the ID reminder, and Arrived posts to staff/checkin.php.
    await open('today');
    const card = page.locator('.ot-confirm');
    assert(/Booked for today/.test(await card.innerText()) && /Edwin R\. Molina/.test(await card.innerText()) && /September 9, 1982/.test(await card.innerText()), 'name, birthdate and status to compare');
    assert(/Check the valid ID/.test(await page.locator('.ot-idcheck').innerText()), 'the valid-ID reminder');
    const form = card.locator('form');
    assert((await form.getAttribute('action')).endsWith('staff/checkin.php') && (await form.getAttribute('method')) === 'post', 'Arrived posts to staff/checkin.php');
    assert.equal(await form.locator('button[name=action]').getAttribute('value'), 'arrive');
    assert.equal(await form.locator('input[name=csrf_token]').count(), 1, 'with the CSRF token');
    assert(/Arrived · open the interview/.test(await form.locator('button').innerText()));

    // Every other case says what is wrong and offers the right next step.
    const expect = {
      already: [/Already checked in/, /Number 1 in the waiting list/, 'Open interview'],
      other_day: [/Booked for/, /not today/, 'Schedule today and check in'],
      no_date: [/No interview date yet/, /usual interview text/, 'Schedule today and check in'],
      docs: [/Documents not verified yet/, /2 required documents still need checking/, 'Open the documents'],
      closed: [/Nothing to do today/, /Rejected/, 'Open the application'],
    };
    for (const [c, [badge, text, button]] of Object.entries(expect)) {
      await open(c);
      const t = await page.locator('.ot-confirm').innerText();
      assert(badge.test(t) && text.test(t), `${c}: ${t.slice(0, 160)}`);
      assert.equal((await page.locator('.ot-confirm-a :is(a.btn-navy, button.ot-arrive)').innerText()).trim(), button, `${c}: the next step`);
    }
    await open('other_day');
    assert.equal(await page.locator('.ot-confirm button[name=action]').getAttribute('value'), 'move_arrive', 'another day moves the interview to today');
    await open('no_date');
    assert.equal(await page.locator('.ot-confirm button[name=action]').getAttribute('value'), 'schedule_arrive', 'no date schedules today');
    await open('many');
    assert.equal(await page.locator('.ot-pick li').count(), 2, 'several matches: choose one');
    await open('none');
    assert(/Not found/.test(await page.locator('.ot-case').innerText()), 'not found says so');

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

    // ID release: an approved application searched at the counter leads to the ID release card.
    const release = {
      release_today: [/ID release today/, /Release today, 9:00 AM/],
      release_overdue: [/Release date passed/, /Not collected yet/],
      release_later: [/Release on/, /came early/],
      release_unscheduled: [/Approved · no release date/, /has not been scheduled/],
    };
    for (const [c, [badge, text]] of Object.entries(release)) {
      await open(c);
      const t = await page.locator('.ot-confirm').innerText();
      assert(badge.test(t) && text.test(t), `${c}: ${t.slice(0, 200)}`);
      const go = page.locator('.ot-confirm-a a.btn-navy');
      assert.equal((await go.innerText()).trim(), 'Open ID release', `${c}: the next step`);
      assert((await go.getAttribute('href')).endsWith('#id-release'), `${c}: opens the ID release card`);
      assert.equal(await page.locator('.ot-confirm button[name=action]').count(), 0, `${c}: no Arrived button for a release`);
    }
    await open('release_today');
    assert(/valid ID/.test(await page.locator('.ot-idcheck').innerText()) && /in person/.test(await page.locator('.ot-idcheck').innerText()), 'the reminder says the ID goes to the Solo Parent in person');
    await open('release_unscheduled');
    assert.equal(await page.locator('.ot-confirm .ot-idcheck').count(), 0, 'no valid-ID reminder before a release is scheduled');

    // The dashboard strip: the box, one line of counts and the next person; no lists.
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(base + 'staff/strip.php');
    assert(await page.locator('#ot-ref').isVisible(), 'the strip keeps the check-in box');
    assert.equal(await page.locator('.ot-lh, .ot-counts').count(), 0, 'the strip has no lists');
    assert.deepEqual((await page.locator('.ot-line-c a').allInnerTexts()).map(t => t.replace(/\s+/g, ' ').trim()), ['2 waiting', '2 expected', '1 done today'], 'one line of counts');
    const hrefs = await page.locator('.ot-line-c a').evaluateAll(a => a.map(x => x.getAttribute('href')));
    assert(hrefs[0].endsWith('staff/appointments.php#ot-waiting') && hrefs[1].endsWith('#ot-expected') && hrefs[2].endsWith('#ot-done'), 'each count opens its list on Appointment Management');
    assert((await page.locator('.ot-all').getAttribute('href')).endsWith('staff/appointments.php#office-today'), 'See today\'s list');
    assert(/VIRAC-SP-2026-0062/.test(await page.locator('.ot-next').innerText()) && (await page.locator('.ot-next a').getAttribute('href')).endsWith('#decision'), 'Next is the first person waiting, with Open interview');
    await page.goto(base + 'staff/strip-nobody.php');
    assert.equal(await page.locator('.ot-next').count(), 0, 'no Next line when nobody is waiting');
    await page.goto(base + 'staff/strip-today.php');
    assert(await page.locator('.ot-confirm').isVisible() && await page.locator('.ot-line').isVisible(), 'after Find the card opens in the strip');
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
    console.log('PASS: office check-in: the dashboard strip (box, counts, next, no lists), box and lists in arrival order, the confirm card for every case with the right next step, Arrived posts with CSRF, light/dark, 320-1280px, Tagalog, and the Solo Parent counter card.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
