/* The Children block of the new application (RA 11861): the Yes/No question about a child with a disability, the live line under each child,
 * the red box when no child qualifies, and the locked Continue button. Read-only: fixture pages, GET only.
 * Requires VIRAC_TEST_URL (explicit test-only origin with a port).
 *
 *   VIRAC_TEST_URL=http://127.0.0.1:8099/virac-soloparent/ NODE_PATH=scripts/node_modules node scripts/test-child-pwd.cjs */
const { chromium } = require('playwright');
const { parseTestBase, newGuardedContext } = require('./lib/test-origin.cjs');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
  const base = parseTestBase(process.env.VIRAC_TEST_URL);
  const states = ['young', 'pwd', 'none', 'docs'];
  const html = Object.fromEntries(states.map(s => [s, execFileSync('php', [path.join(__dirname, 'fixtures/children-step.php'), s], { encoding: 'utf8' })]));
  for (const s of states) assert(!/Warning:|Fatal error:|Notice:/.test(html[s]), s + ' renders cleanly');
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const { context } = await newGuardedContext(browser, base, {}, { stubPages: true, fixture: relative => { const m = /^applicant\/children-(\w+)\.php$/.exec(relative); return m ? html[m[1]] : undefined; } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    const open = async state => { await page.goto(base + 'applicant/children-' + state + '.php'); if (state !== 'docs') await page.waitForFunction(() => !!window.ChildRules); };
    const live = () => page.locator('.js-child-live').first().innerText();
    const continueBtn = () => page.locator('.js-child-submit');
    const none = () => page.locator('.js-child-none');

    // A child of 10, the answer No: the tick is hidden, green line, Continue open.
    await open('young');
    assert(await page.locator('.js-pwd-answer[value="0"]').isChecked(), 'the answer is No');
    assert(!(await page.locator('.js-child-pwd').first().isVisible()), 'the tick is hidden while the answer is No');
    assert(/Age 10 — qualifies \(22 or younger\)/.test(await live()), 'green line for a child of 10');
    assert(await continueBtn().isEnabled() && !(await none().isVisible()), 'Continue is open and no red box');
    // Answering Yes shows the tick for each child.
    await page.locator('.js-pwd-answer[value="1"]').check();
    assert(await page.locator('.js-child-pwd').first().isVisible(), 'the tick shows when the answer is Yes');

    // A child over 22 with the tick: amber line, Continue open.
    await open('pwd');
    assert(await page.locator('.js-child-pwd').first().isChecked(), 'the child is ticked');
    assert(/qualifies with a disability/.test(await live()) && /PWD ID or medical certificate/.test(await live()), 'amber line says a document will be asked');
    assert(await page.locator('.js-child-live').first().evaluate(el => el.classList.contains('pwd')), 'amber state');
    assert(await continueBtn().isEnabled(), 'Continue is open');

    // A child over 22 without the tick: red line, red box, Continue locked; ticking unlocks it; the answer No locks it again.
    await open('none');
    assert(/over 22 without a disability: does not qualify/.test(await live()), 'red line');
    assert(await none().isVisible(), 'the red box shows');
    assert(await continueBtn().isDisabled(), 'Continue is locked');
    assert(await page.locator('.js-child-why').isVisible(), 'the reason for the lock is shown');
    assert.equal(await continueBtn().evaluate(el => getComputedStyle(el).borderTopStyle), 'dashed', 'a locked Continue looks locked');
    await page.locator('.js-child-pwd').first().check();
    assert(await continueBtn().isEnabled() && !(await none().isVisible()), 'ticking the child unlocks Continue');
    assert(/qualifies with a disability/.test(await live()), 'the line turns amber');
    await page.locator('.js-pwd-answer[value="0"]').check();
    assert(await continueBtn().isDisabled() && await none().isVisible(), 'answering No hides the tick and locks Continue again');
    assert(/If this child has one, answer Yes above/.test(await live()), 'the hint tells the person to answer Yes');
    await page.locator('.js-child-birth').first().fill(await page.evaluate(() => { const d = new Date(); d.setFullYear(d.getFullYear() - 12); return d.toISOString().slice(0, 10); }));
    assert(await continueBtn().isEnabled() && !(await none().isVisible()), 'a child of 12 makes Continue open');
    // Exactly 22 still qualifies.
    await page.locator('.js-child-birth').first().fill(await page.evaluate(() => { const d = new Date(); d.setFullYear(d.getFullYear() - 22); return d.toISOString().slice(0, 10); }));
    assert(/qualifies \(22 or younger\)/.test(await live()), 'a child who is exactly 22 qualifies');

    // The extra required document in the Documents step.
    await open('docs');
    assert(await page.getByText('For your child with a disability', { exact: true }).isVisible(), 'the proof document has its own group');
    assert(await page.getByText('PWD ID or medical certificate of the child with a disability', { exact: true }).isVisible(), 'the proof document is listed');
    assert(/Needed because Juan Santos \(age 30\) is over 22 and has a disability/.test(await page.locator('.doc-proof-reason').innerText()), 'the reason names the child');
    assert(/Required/.test(await page.locator('.doc-upload-card', { hasText: 'PWD ID or medical certificate' }).locator('.checklist-requirement').innerText()), 'it is marked Required');

    // Tagalog and dark mode, down to a 320px phone.
    await open('none');
    await page.evaluate(() => { document.documentElement.lang = 'tl'; document.dispatchEvent(new CustomEvent('virac:languagechange', { detail: 'tl' })); if (window.ViracI18n) window.ViracI18n.refresh(); document.documentElement.dataset.theme = 'dark'; });
    await page.waitForTimeout(300);
    assert(/Edad 30 — higit 22 at walang kapansanan: hindi kwalipikado/.test(await live()), 'the live line is in Tagalog: ' + await live());
    assert(/May anak ka bang may kapansanan/.test(await page.locator('.cr-question legend').innerText()), 'the question is in Tagalog');
    for (const width of [1440, 768, 390, 320]) {
      await page.setViewportSize({ width, height: 1000 });
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `the Children step fits ${width}px`);
    }
    assert.equal(await continueBtn().evaluate(el => getComputedStyle(el).borderTopStyle), 'dashed', 'the locked Continue looks locked in dark mode');
    assert.deepEqual(errors, [], 'no script errors');
    console.log('PASS: children block: Yes/No question, live lines (green, amber, red), red box and locked Continue, extra required document, Tagalog, dark mode, 320-1440px.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
