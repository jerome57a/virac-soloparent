/* New application, step 1: the civil status filters the Solo Parent category. Read-only: fixture pages (scripts/fixtures/personal-step.php),
 * GET only. Requires VIRAC_TEST_URL (explicit test-only origin with a port).
 *
 *   VIRAC_TEST_URL=http://127.0.0.1:8099/virac-soloparent/ NODE_PATH=scripts/node_modules node scripts/test-category-civil.cjs */
const { chromium } = require('playwright');
const { parseTestBase, newGuardedContext } = require('./lib/test-origin.cjs');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
  const base = parseTestBase(process.env.VIRAC_TEST_URL);
  const states = ['empty', 'single', 'widowed', 'married', 'refit', 'mismatch', 'nocolumn'];
  const html = Object.fromEntries(states.map(s => [s, execFileSync('php', [path.join(__dirname, 'fixtures/personal-step.php'), s], { encoding: 'utf8' })]));
  for (const s of states) assert(!/Warning:|Fatal error:|Notice:|Deprecated:/.test(html[s]), s + ' renders cleanly');
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const { context } = await newGuardedContext(browser, base, {}, { stubPages: true, fixture: relative => { const m = /^applicant\/personal-(\w+)\.php$/.exec(relative); return m ? html[m[1]] : undefined; } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    const open = async s => { await page.goto(base + 'applicant/personal-' + s + '.php'); await page.waitForFunction(() => !!window.ViracI18n && !!document.getElementById('circumstance-select')); };
    const cat = () => page.locator('#circumstance-select');
    const civil = () => page.locator('select[name=civilStatus]');
    const names = async () => (await cat().locator('option').allInnerTexts()).slice(1);
    const pill = () => page.locator('#category-filter-pill');
    const msg = () => page.locator('#category-filter-msg');
    const general = ['Legal guardian, adoptive parent, or foster parent', 'Child/children as a consequence of rape', 'Spouse or family member of an OFW'];
    const rules = {
      Single: ['Unmarried father or mother who keeps and rears the child/children'],
      Married: ['Abandonment by the spouse', 'Detention or imprisonment of the spouse', 'Legal or de facto separation of spouse for at least six months'],
      Widowed: ['Death of the spouse'],
      Separated: ['Abandonment by the spouse', 'Detention or imprisonment of the spouse', 'Legal or de facto separation of spouse for at least six months'],
      Annulled: ['Declaration of nullity or annulment of marriage'],
    };

    // No civil status yet: the category list waits for it.
    await open('empty');
    assert(await cat().isDisabled(), 'the category is disabled until a civil status is chosen');
    assert(await page.locator('#category-civil-hint').isVisible() && /Choose your civil status first/.test(await page.locator('#category-civil-hint').innerText()), 'the hint says to choose the civil status first');
    assert(await pill().isHidden() && await msg().isHidden(), 'no pill and no message yet');

    // Each civil status lists its categories, then the ones for every civil status under their own heading.
    for (const [status, own] of Object.entries(rules)) {
      await civil().selectOption(status);
      assert(!(await cat().isDisabled()), `${status}: the category is enabled`);
      assert.deepEqual(await names(), [...own, ...general], `${status}: the list`);
      assert.equal(await cat().locator('optgroup').getAttribute('label'), 'For any civil status', `${status}: the heading of the general ones`);
      assert.equal((await cat().locator('optgroup option').allInnerTexts()).length, 3, `${status}: three general categories under the heading`);
      assert(/Showing categories for\s+/.test(await pill().innerText()) && (await pill().innerText()).includes(status), `${status}: the pill`);
      assert(await page.locator('#category-civil-hint').isHidden(), `${status}: the first-hint is gone`);
    }

    // A category chosen, then another civil status: the category is cleared and the Solo Parent is told; the documents preview follows.
    await civil().selectOption('Married');
    await cat().selectOption({ label: 'Abandonment by the spouse' });
    assert(await page.locator('#category-doc-box').isVisible(), 'the documents preview shows for the chosen category');
    await civil().selectOption('Separated');
    assert.equal(await cat().inputValue(), await cat().locator('option', { hasText: 'Abandonment by the spouse' }).getAttribute('value'), 'Abandonment still fits Separated: kept');
    assert(await msg().isHidden(), 'no message when the category still fits');
    await civil().selectOption('Widowed');
    assert.equal(await cat().inputValue(), '', 'the category is cleared');
    assert(await msg().isVisible(), 'the message shows');
    const text = await msg().innerText();
    assert(/Your category was cleared/.test(text) && /Abandonment by the spouse/.test(text) && /does not apply to/.test(text) && /Widowed/.test(text) && /Choose a category again/.test(text), 'the message names the category and the civil status: ' + text);
    assert(await page.locator('#category-doc-box').isHidden(), 'the documents preview hides when the category is cleared');
    await cat().selectOption({ label: 'Death of the spouse' });
    await civil().selectOption('Single');
    assert(await msg().isVisible() && /Death of the spouse/.test(await msg().innerText()), 'changing again names the new category');
    // A general category is never cleared.
    await civil().selectOption('Annulled');
    await cat().selectOption({ label: 'Spouse or family member of an OFW' });
    for (const s of ['Single', 'Married', 'Widowed']) {
      await civil().selectOption(s);
      assert.equal(await cat().locator('option:checked').innerText(), 'Spouse or family member of an OFW', `a general category stays chosen for ${s}`);
      assert(await msg().isHidden(), `no message for ${s}`);
    }

    // A civil status chosen on load: the list is already filtered by the server.
    await open('married');
    assert.deepEqual(await names(), [...rules.Married, ...general], 'on load: Married');
    assert(await pill().isVisible() && !(await cat().isDisabled()), 'on load: enabled with the pill');
    await open('single');
    assert.deepEqual(await names(), [...rules.Single, ...general], 'on load: Single');

    // A saved draft whose category no longer fits: asked again, with a note.
    await open('refit');
    assert.equal(await cat().inputValue(), '', 'the draft category is asked for again');
    assert(await msg().isVisible() && /no longer fits your civil status/.test(await msg().innerText()), 'the note about the saved draft');
    assert.deepEqual(await names(), [...rules.Widowed, ...general], 'the list fits Widowed');

    // The server refused a mismatched pair.
    await open('mismatch');
    assert(/does not apply to the civil status you chose/.test(await page.locator('#personal-error-circumstance').innerText()), 'the server message is shown');
    assert.equal(await cat().getAttribute('aria-invalid'), 'true', 'the category is marked invalid');

    // A database without the setting: nothing is filtered.
    await open('nocolumn');
    assert.equal((await names()).length, 9, 'without the column every category is listed');
    await civil().selectOption('Single');
    assert.equal((await names()).length, 9, 'and stays so when the civil status changes');
    assert.equal(await cat().locator('optgroup').count(), 1, 'all of them fit everyone (listed under the general heading)');

    // Light and dark, down to a 320px phone, and Tagalog.
    await open('widowed');
    for (const theme of ['light', 'dark']) {
      await page.evaluate(t => { document.documentElement.dataset.theme = t; }, theme);
      for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 1000 });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `step 1 fits ${width}px (${theme})`);
      }
    }
    await page.evaluate(() => { document.documentElement.lang = 'tl'; document.dispatchEvent(new CustomEvent('virac:languagechange', { detail: 'tl' })); if (window.ViracI18n) window.ViracI18n.refresh(); });
    await page.waitForTimeout(300);
    assert(/Ipinapakita ang mga kategorya para sa/.test(await pill().innerText()), 'the pill is in Tagalog: ' + await pill().innerText());
    assert.equal(await cat().locator('optgroup').getAttribute('label'), 'Para sa anumang civil status', 'the heading is in Tagalog');
    await civil().selectOption('Married');
    await cat().selectOption({ index: 1 });
    await civil().selectOption('Widowed');
    assert(/Nabura ang iyong kategorya/.test(await msg().innerText()), 'the cleared message is in Tagalog: ' + await msg().innerText());

    assert.deepEqual(errors, [], 'no script errors');
    console.log('PASS: civil status filters the category: each civil status lists its categories plus the ones for everyone, disabled until a civil status is chosen, cleared with a message when it no longer fits (a general one never), saved-draft note, server message, no filtering without the setting, light/dark, 320-1440px, Tagalog.');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
