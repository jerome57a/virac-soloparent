/* Read-only real GET previews. Browser navigation stays within fixture HTML.
 * Requires VIRAC_TEST_URL (explicit test-only origin with a port); the ordinary application URL is refused. */
const { chromium } = require('playwright');
const { screenshotPath, screenshotDir } = require('./lib/test-artifacts.cjs');
const { parseTestBase, newGuardedContext } = require('./lib/test-origin.cjs');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');
const routes = ['dashboard.php', 'notifications.php', 'index.php', 'applicants.php', 'appointments.php', 'reports.php'];
(async () => {
  // No default: an explicit test-only origin is required (see scripts/lib/test-origin.cjs). Every browser request is
  // decided by the guard: fixtures and static assets only, GET only, no redirects, nothing cross-origin.
  // Both the URL and TEST_STORAGE_ROOT are validated before any PHP fixture or browser runs.
  const base = parseTestBase(process.env.VIRAC_TEST_URL);
  screenshotDir();
  const previews = new Map(routes.map(route => [route, execFileSync('php', [path.join(__dirname, 'fixtures/staff-workspace.php'), route], { encoding: 'utf8' })]));
  for (const [route, html] of previews) assert(!/Warning:|Fatal error:/.test(html), route + ' renders cleanly');
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const { context, guard } = await newGuardedContext(browser, base, {}, {
      stubPages: true,
      fixture: relative => relative.startsWith('staff/') ? previews.get(relative.slice(6)) : undefined,
    });
    const page = await context.newPage();
    // The staff pages poll staff/doc_alerts.php for the "documents to verify" alert: answer it with an empty, read-only stub.
    await page.route('**/staff/doc_alerts.php', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ waiting: 0, resent: 0, latest: '', first: null }) }));
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    for (const route of routes) {
      await page.goto(base + 'staff/' + route);
      await page.waitForFunction(() => !!window.ViracI18n);
      assert.equal(await page.locator('main.staff-workspace').count(), 1);
      assert.equal(await page.locator('main h1').count(), 1, route + ' has one main title');
      if (route !== 'dashboard.php') {
        assert.equal(await page.locator('.staff-back-link').getAttribute('href'), base + 'staff/dashboard.php');
      }
      const missingLabels = await page.locator('.staff-workspace .filter-bar, .staff-workspace .record-filters, .staff-workspace .analytics-filters').evaluateAll(forms => forms.flatMap(form => [...form.querySelectorAll('input:not([type=hidden]),select')].filter(el => !el.labels.length).map(el => el.name)));
      assert.deepEqual(missingLabels, [], route + ' visible search labels');
      const missingTargets = await page.locator('.staff-section-links a').evaluateAll(links => links.filter(a => !document.getElementById(a.hash.slice(1))).map(a => a.hash));
      assert.deepEqual(missingTargets, []);
      const oversizedFields = await page.locator('.staff-workspace .filter-bar input, .staff-workspace .record-filters input, .staff-workspace .analytics-filters input:not([type=hidden]), .staff-code-field input').evaluateAll(fields => fields.filter(el => el.getBoundingClientRect().height > 60).map(el => el.name));
      assert.deepEqual(oversizedFields, [], route + ' normal input heights');
      for (const width of [1440, 1100, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 1000 });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${route}/${width} fits viewport`);
      }
      const values = () => page.locator('.staff-workspace input,.staff-workspace select,.staff-workspace textarea').evaluateAll(fields => fields.map(el => [el.name, el.value, el.checked]));
      const originalValues = await values();
      await page.locator('[data-language-select]').selectOption('tl');
      await page.locator('[data-theme-toggle]').click();
      if (route === 'reports.php') assert.equal(await page.locator('#admin-report-types a:not([aria-current])').first().evaluate(el => getComputedStyle(el).backgroundColor), 'rgb(28, 40, 57)', 'report choices follow dark theme');
      assert.deepEqual(await values(), originalValues, route + ' preferences preserve form data');
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), route + ' dark Tagalog mobile fits');
      if (route === 'reports.php') await page.screenshot({ path: screenshotPath('staff-' + route.replace('.php', '') + '-dark-mobile.png'), fullPage: true });
      await page.locator('[data-language-select]').selectOption('en');
      await page.locator('[data-theme-toggle]').click();
      await page.setViewportSize({ width: 1440, height: 1000 });
      await page.screenshot({ path: screenshotPath('staff-' + route.replace('.php', '') + '-desktop.png'), fullPage: true });
      console.log('PASS: ' + route + ' heading, search labels, section links, responsive layout, theme and language.');
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator('#nav-menu-btn').click();
    assert.equal(await page.locator('#nav-menu-btn').getAttribute('aria-expanded'), 'true');
    await page.locator('#nav-drawer a[href$="staff/reports.php"]').click();
    await page.waitForURL('**/staff/reports.php');
    assert.deepEqual(errors, []);
    guard.assertClean(assert);
    assert.deepEqual(guard.external, [], 'no cross-origin requests');
    console.log('PASS: staff workspace pages and mobile sidebar navigation.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
