/* Requires jsdom and playwright; run with NODE_PATH pointing to their installation.
 * Exercises display preferences without creating accounts or changing application data.
 *
 * The browser half needs VIRAC_TEST_URL: an explicit test-only origin with a port, e.g. http://localhost:8081/virac-soloparent/
 * (the ordinary http://localhost/virac-soloparent/ and other default-port, https, credentialed or external URLs are
 * refused). A localhost URL is NOT evidence of isolation: the server behind it must itself be started with
 * APP_ENV=testing, the isolated test database and TEST_STORAGE_ROOT. See docs/database-tooling-safety.md.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const { chromium } = require('playwright');
const { screenshotPath, screenshotDir } = require('./lib/test-artifacts.cjs');
const { parseTestBase, newGuardedContext } = require('./lib/test-origin.cjs');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));

async function testEngine() {
  const dom = new JSDOM(`<!doctype html><html lang="en"><body>
    <select data-language-select><option value="en">English</option><option value="tl">Tagalog</option></select>
    <button data-theme-toggle><span data-theme-icon></span><span data-theme-label data-i18n>Dark mode</span></button>
    <p id="copy" data-i18n>Continue to Children <span aria-hidden="true">→</span></p>
    <p id="wrapped" data-i18n>Enter your\n full name.</p>
    <p id="count" data-i18n>Child 2</p>
    <p id="private">Pending Review</p>
    <p translate="no" data-i18n>Home</p>
    <input id="name" value="Home" placeholder="Child's full name" data-i18n-attrs="placeholder">
    <select id="sex"><option value="Female" data-i18n>Female</option></select>
    <select id="implicit"><option data-i18n>Female</option></select>
  </body></html>`, { url: 'http://localhost', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  const observers = [];
  const Observer = w.MutationObserver;
  w.MutationObserver = class extends Observer { constructor(callback) { super(callback); observers.push(this); } };
  for (const file of ['translations', 'preferences-init', 'preferences']) w.eval(read(`public/js/${file}.js`));
  const language = w.document.querySelector('[data-language-select]');
  const setLanguage = value => { language.value = value; language.dispatchEvent(new w.Event('change')); };
  setLanguage('tl');
  assert.match(w.document.querySelector('#copy').textContent, /Magpatuloy sa mga Anak/);
  assert.equal(w.document.querySelector('#wrapped').textContent, 'Ilagay ang iyong buong pangalan.');
  assert.equal(w.document.querySelector('#count').textContent, 'Anak 2');
  assert.equal(w.document.querySelector('#private').textContent, 'Pending Review');
  assert.equal(w.document.querySelector('[translate="no"]').textContent, 'Home');
  assert.equal(w.document.querySelector('#name').value, 'Home');
  assert.equal(w.document.querySelector('#sex').value, 'Female');
  assert.equal(w.document.querySelector('#sex option').textContent, 'Babae');
  assert.equal(w.document.querySelector('#implicit').value, 'Female');
  const dynamic = w.document.createElement('p');
  dynamic.setAttribute('data-i18n', ''); dynamic.textContent = 'Documents You Still Need to Prepare';
  w.document.body.append(dynamic);
  await pause(70);
  assert.equal(dynamic.textContent, 'Mga Dokumentong Kailangan Mo Pang Ihanda');
  dynamic.textContent = 'No unchecked required documents to prepare';
  w.document.querySelector('#name').placeholder = 'Complete address';
  await pause(70);
  assert.equal(dynamic.textContent, 'Wala nang kinakailangang dokumentong walang tsek');
  assert.equal(w.document.querySelector('#name').placeholder, 'Kumpletong tirahan');
  w.document.querySelector('[data-theme-toggle]').click();
  await pause(70);
  assert.equal(w.document.documentElement.dataset.theme, 'dark');
  assert.equal(w.document.documentElement.dataset.bsTheme, 'dark');
  assert.equal(w.localStorage.getItem('virac.theme'), 'dark');
  assert.equal(w.localStorage.getItem('virac.language'), 'tl');
  setLanguage('en');
  assert.equal(dynamic.textContent, 'No unchecked required documents to prepare');
  assert.equal(w.document.querySelector('#wrapped').textContent, 'Enter your\n full name.');
  assert.equal(w.document.querySelector('#name').placeholder, 'Complete address');
  assert.equal(w.document.querySelector('#copy span').textContent, '→');
  await pause(70); // Let the final observer/animation frame settle before closing jsdom.
  observers.forEach(observer => observer.disconnect());
  w.close();
  console.log('PASS: translation round trips, dynamic copy/attributes, personal data and form values, theme persistence.');
}

async function testPages() {
  // Validate the target and TEST_STORAGE_ROOT before any browser is started.
  const base = parseTestBase(process.env.VIRAC_TEST_URL);
  screenshotDir(); // validates TEST_STORAGE_ROOT (without reserving a screenshot name) before any browser starts
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    // The page talks to the validated test server only; anything cross-origin, non-GET or redirecting is refused.
    const { context, guard } = await newGuardedContext(browser, base, { viewport: { width: 1280, height: 900 } },
      { livePages: ['portal.php', 'eligibility.php', 'login.php'] });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    // No default URL: this part drives a running copy of the application, whose sessions, logs and database are those
    // of whatever server answers. VIRAC_TEST_URL must be an explicit test-only origin with a port (for example
    // http://localhost:8081/virac-soloparent/); the ordinary http://localhost/virac-soloparent/ is rejected. The URL is
    // only validated for shape: it is NOT proof of isolation. The server behind it must independently run with
    // APP_ENV=testing, the isolated test database and TEST_STORAGE_ROOT. See scripts/lib/test-origin.cjs.
    await page.goto(base + 'portal.php');
    await page.locator('[data-language-select]').selectOption('tl');
    await page.locator('[data-theme-toggle]').click();
    for (const route of ['portal.php', 'portal.php?tab=help', 'portal.php?tab=status', 'eligibility.php', 'login.php']) {
      await page.goto(base + route);
      await page.waitForFunction(() => document.documentElement.lang === 'tl' && document.documentElement.dataset.theme === 'dark');
      const styles = await page.locator('.nav-drawer-brand img').evaluate(image => {
        const style = getComputedStyle(image);
        return { loaded: image.complete && image.naturalWidth > 0, background: style.backgroundColor, opacity: style.opacity, blend: style.mixBlendMode };
      });
      assert.equal(styles.loaded, true, route + ' logo loads');
      assert.equal(styles.background, 'rgb(255, 255, 255)', route + ' logo has light backing');
      assert.equal(styles.opacity, '1'); assert.equal(styles.blend, 'normal');
      assert.equal(await page.locator('[data-language-select]').inputValue(), 'tl');
      const untranslated = await page.evaluate(() => {
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        const remaining = new Set();
        let node;
        while ((node = walker.nextNode())) {
          const text = node.nodeValue.trim().replace(/\s+/g, ' ');
          if (text && window.ViracTranslations[text] && window.ViracTranslations[text] !== text &&
              !node.parentElement.closest('script,style,[translate="no"]')) remaining.add(text);
        }
        return [...remaining];
      });
      assert.deepEqual(untranslated, [], route + ' applies known translations');
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(base + 'portal.php?tab=help');
    await page.waitForTimeout(150);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, 'mobile page has no horizontal overflow');
    await page.screenshot({ path: screenshotPath('preferences-dark-mobile.png'), fullPage: true });
    await page.locator('[data-language-select]').selectOption('en');
    await page.locator('[data-theme-toggle]').click();
    await page.reload();
    assert.equal(await page.evaluate(() => document.documentElement.lang), 'en');
    assert.equal(await page.evaluate(() => document.documentElement.dataset.theme), 'light');
    assert.deepEqual(errors, []);
    guard.assertClean(assert);
    await page.setContent(`<html data-theme="dark"><body>
      <div class="verification-id-face"><img></div>
    </body></html>`);
    for (const file of ['style', 'preferences-surfaces', 'preferences', 'verification-id']) {
      await page.addStyleTag({ content: read(`public/css/${file}.css`) });
    }
    for (const selector of ['.verification-id-face']) {
      assert.equal(await page.locator(selector).evaluate(element => getComputedStyle(element).backgroundColor), 'rgb(255, 255, 255)', selector + ' preserves white backing');
      assert.equal(await page.locator(selector + ' img').evaluate(element => getComputedStyle(element).filter), 'none');
    }
    console.log('PASS: saved language/theme across public pages, logos in dark mode, mobile layout, English/light restoration, no browser errors.');
    console.log('PASS: ID artwork preserves its white background and image colors.');
  } finally { await browser.close(); }
}

testEngine().then(testPages).catch(error => { console.error(error); process.exitCode = 1; });
