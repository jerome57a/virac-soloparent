/* The staff application (verification) page, cleaner: the three decision buttons are always there (locked ones say why), the explanations
 * sit in Notes boxes with a Show all notes switch, and the applicant details are one folded row. Read-only: a fixture page, GET only.
 * Requires VIRAC_TEST_URL (explicit test-only origin with a port) and the isolated test database.
 *
 *   VIRAC_TEST_URL=http://127.0.0.1:8099/virac-soloparent/ NODE_PATH=scripts/node_modules node scripts/test-application-clean.cjs */
const { chromium } = require('playwright');
const { parseTestBase, newGuardedContext } = require('./lib/test-origin.cjs');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
  const base = parseTestBase(process.env.VIRAC_TEST_URL);
  const html = execFileSync('php', [path.join(__dirname, 'fixtures/offline-layout.php'), 'staff'], { encoding: 'utf8' });
  assert(!/Warning:|Fatal error:|Notice:/.test(html), 'the application page renders cleanly');
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const { context } = await newGuardedContext(browser, base, {}, { stubPages: true, fixture: relative => relative === 'staff/application.php' ? html : undefined });
    const page = await context.newPage();
    await page.route('**/staff/doc_alerts.php', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ waiting: 0, resent: 0, latest: '', first: null }) }));
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(base + 'staff/application.php');
    await page.waitForFunction(() => !!window.ViracI18n);
    // Opening the application goes straight to its next step (here: verify the documents).
    await page.waitForFunction(() => !!document.querySelector('#checklist.gi-target'), null, { timeout: 5000 });

    // Back returns to the page the application was opened from (the opening page's address is remembered for this tab).
    const backHref = () => page.locator('.staff-back-link').getAttribute('href');
    assert(/staff\/index\.php$/.test(await backHref()), 'Back goes to the Applications list when the origin is unknown');
    await page.goto(base + 'staff/application.php', { referer: base + 'staff/work.php?list=interview' });
    await page.waitForFunction(() => !!window.ViracI18n);
    assert(/staff\/work\.php\?list=interview$/.test(await backHref()), 'Back returns to the list the application was opened from');
    await page.goto(base + 'staff/application.php');   // reloaded (as after saving a form): no referrer, the origin is still remembered
    await page.waitForFunction(() => !!window.ViracI18n);
    assert(/staff\/work\.php\?list=interview$/.test(await backHref()), 'Back still returns there after the page reloads');
    await page.evaluate(() => sessionStorage.removeItem('virac.staff.backto'));
    await page.goto(base + 'staff/application.php');
    await page.waitForFunction(() => !!window.ViracI18n);

    // The decision: no button before the interview has taken place (not even Reject); one plain line says when it opens.
    assert.equal(await page.locator('.gi-decide button').count(), 0, 'no Approve or Reject before the interview');
    assert(/Approve or reject appears after the interview has taken place/.test(await page.locator('#decision .gi-lockline').innerText()), 'a line says when the decision opens');
    assert.equal(await page.locator('#decision button[value="Rejected"]').count(), 0, 'Reject is not always available');
    // Notes: closed until opened; each opens on its own.
    const notes = page.locator('details.gi-notes');
    assert(await notes.count() >= 2, 'Supporting Documents and Decision have Notes');
    assert.deepEqual(await notes.evaluateAll(n => n.map(x => x.open)), await notes.evaluateAll(n => n.map(() => false)), 'notes are closed by default');
    assert(!(await page.locator('.gi-notes ul').first().isVisible()), 'the note text is hidden');
    await notes.first().locator('summary').click();
    assert(await page.locator('.gi-notes ul').first().isVisible(), 'a single Notes box opens on click');
    assert.equal(await page.locator('#gi-notes-all').count(), 0, 'there is no Show all notes switch');
    assert.equal(await page.locator('#next-step-card').count(), 0, 'there is no Next step card');
    assert.equal(await page.getByText('Next waiting').count(), 0, 'there is no Next waiting button');

    // Automated verification: the details can be hidden or shown; the verdict line stays and the choice is remembered.
    if (await page.locator('#auto-check').count()) {
      const body = page.locator('#auto-check-body'), toggle = page.locator('#auto-check-toggle');
      assert(await body.isVisible(), 'the automated verification details are shown by default');
      await toggle.click();
      assert(!(await body.isVisible()) && (await toggle.getAttribute('aria-expanded')) === 'false' && await page.locator('#auto-check .ac-pill').isVisible(), 'Hide hides the details but keeps the verdict');
      await page.reload();
      await page.waitForFunction(() => !!window.ViracI18n);
      assert(!(await page.locator('#auto-check-body').isVisible()), 'the choice is remembered');
      await page.locator('#auto-check-toggle').click();
      assert(await page.locator('#auto-check-body').isVisible(), 'Show brings the details back');
    }
    // Applicant details: never folded; Personal Info, Children and the Declaration are always shown.
    assert.equal(await page.locator('#applicant-details').count(), 1, 'one Applicant details section');
    assert.equal(await page.locator('details#applicant-details').count(), 0, 'Applicant details is not folded');
    for (const title of ['Personal Info', 'Children', 'Solo Parent Declaration']) assert(await page.getByText(title, { exact: true }).first().isVisible(), title + ' is always shown');

    // Both themes and languages, down to a 320px phone: nothing wider than the screen, locked buttons still look locked.
    for (const width of [1440, 768, 390, 320]) {
      await page.setViewportSize({ width, height: 1000 });
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `application page fits ${width}px`);
    }
    await page.locator('[data-language-select]').selectOption('tl');
    await page.locator('[data-theme-toggle]').click();
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'dark Tagalog 320px page fits');
    assert.deepEqual(errors, [], 'no script errors');
    console.log('PASS: application page: no Approve or Reject before the interview, Notes boxes, no Show all notes or Next step card, Applicant details always shown, light/dark, Tagalog, 320-1440px.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
