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

    // The decision buttons: always the same three, in this order; the two that need a successful interview are locked and say why.
    const names = await page.locator('.gi-decide button').evaluateAll(b => b.map(x => x.textContent.trim().replace(/\s+/g, ' ')));
    assert.deepEqual(names, ['Request Corrections', 'Reject', 'Approve'], 'three decision buttons in a fixed order');
    assert.deepEqual(await page.locator('.gi-decide button').evaluateAll(b => b.map(x => x.disabled)), [true, false, true], 'only Reject is available before a successful interview');
    assert.equal(await page.locator('.gi-decide button svg.ui-icon').count(), 3, 'every decision button has an icon');
    assert.equal(await page.locator('.gi-lockline').count(), 1, 'one line says why the buttons are locked');
    assert(/unlock after a successful interview/.test(await page.locator('.gi-lockline').innerText()), 'the lock line names the reason');
    assert.equal(await page.locator('.info-box', { hasText: 'become available after a successful interview' }).count(), 0, 'the old blue notice is gone');
    assert.equal(await page.locator('.gi-decide button.review-button-locked').first().evaluate(el => getComputedStyle(el).borderTopStyle), 'dashed', 'locked buttons look locked');
    // Reject asks for its reason first (nothing is sent).
    await page.locator('.gi-decide button[value="Rejected"]').click();
    assert(await page.locator('#decision-reason').isVisible(), 'Reject asks for the reason before anything is sent');

    // Notes: closed until opened; the switch opens all of them and is remembered.
    const notes = page.locator('details.gi-notes');
    assert(await notes.count() >= 2, 'Supporting Documents and Decision have Notes');
    assert.deepEqual(await notes.evaluateAll(n => n.map(x => x.open)), await notes.evaluateAll(n => n.map(() => false)), 'notes are closed by default');
    assert(!(await page.locator('.gi-notes ul').first().isVisible()), 'the note text is hidden');
    await notes.first().locator('summary').click();
    assert(await page.locator('.gi-notes ul').first().isVisible(), 'a single Notes box opens on click');
    await page.locator('#gi-notes-all').check();
    assert((await notes.evaluateAll(n => n.every(x => x.open))), 'Show all notes opens every Notes box');
    await page.reload();
    await page.waitForFunction(() => !!window.ViracI18n);
    assert(await page.locator('#gi-notes-all').isChecked() && (await notes.evaluateAll(n => n.every(x => x.open))), 'the choice is remembered on this device');
    await page.locator('#gi-notes-all').uncheck();
    assert((await notes.evaluateAll(n => n.every(x => !x.open))), 'switching it off closes them again');

    // Applicant details: one folded row; opens to Personal Info, Children and the Declaration.
    const fold = page.locator('#applicant-details');
    assert.equal(await fold.count(), 1, 'one Applicant details row');
    assert(!(await fold.evaluate(el => el.open)), 'closed once the documents are being verified');
    assert(/child/.test(await fold.locator('summary').innerText()), 'its summary names the children');
    assert(!(await page.getByText('Personal Info', { exact: true }).first().isVisible()), 'Personal Info is hidden while folded');
    await fold.locator('summary').click();
    for (const title of ['Personal Info', 'Children', 'Solo Parent Declaration']) assert(await page.getByText(title, { exact: true }).first().isVisible(), title + ' shows when opened');

    // Both themes and languages, down to a 320px phone: nothing wider than the screen, locked buttons still look locked.
    for (const width of [1440, 768, 390, 320]) {
      await page.setViewportSize({ width, height: 1000 });
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `application page fits ${width}px`);
    }
    await page.locator('[data-language-select]').selectOption('tl');
    await page.locator('[data-theme-toggle]').click();
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'dark Tagalog 320px page fits');
    assert.equal(await page.locator('.gi-decide button.review-button-locked').first().evaluate(el => getComputedStyle(el).borderTopStyle), 'dashed', 'locked buttons still look locked in dark mode');
    assert(await page.locator('.gi-decide button.review-button-locked').first().evaluate(el => getComputedStyle(el).color !== getComputedStyle(el).backgroundColor), 'locked button text is readable');
    assert.deepEqual(errors, [], 'no script errors');
    console.log('PASS: application page: three decision buttons always shown (locked ones explain), Notes and Show all notes (remembered), folded Applicant details, light/dark, Tagalog, 320-1440px.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
