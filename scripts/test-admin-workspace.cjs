/* Requires Playwright + Chrome. CLI previews use read-only SQL; browser requests are GET-only fixtures.
 * Requires VIRAC_TEST_URL (explicit test-only origin with a port); the ordinary application URL is refused. */
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
const { screenshotPath, screenshotDir } = require('./lib/test-artifacts.cjs');
const { parseTestBase, newGuardedContext } = require('./lib/test-origin.cjs');
const root = path.resolve(__dirname, '..');
const routes = ['notifications.php', 'applicants.php', 'appointments.php', 'reports.php', 'eligibility_logs.php', 'applications.php', 'users.php', 'expiry_sms.php', 'sms.php', 'settings.php', 'audit_log.php'];
(async () => {
  // No default: VIRAC_TEST_URL must be an explicit test-only origin with a port (see scripts/lib/test-origin.cjs).
  const base = parseTestBase(process.env.VIRAC_TEST_URL);
  screenshotDir(); // validates TEST_STORAGE_ROOT before any browser starts
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const previews = new Map();
    for (const route of routes) {
      const html = execFileSync('php', [path.join(__dirname, 'fixtures/admin-workspace.php'), route], { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 });
      assert(!/Fatal error:|Warning:|Stack trace:/.test(html), route + ' renders without PHP errors');
      assert(html.includes('admin-workspace-content'), route + ' uses workspace');
      previews.set(route, html);
    }
    // Every browser request is decided by the guard: fixtures and static assets only, GET only, no redirects, and
    // nothing outside the explicit test origin. The ordinary application is never contacted.
    const { context, guard } = await newGuardedContext(browser, base, {}, {
      stubPages: true,
      fixture: relative => relative.startsWith('admin/') ? previews.get(relative.slice(6)) : undefined,
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    for (const route of routes) {
      await page.setViewportSize({ width: 1500, height: 1000 });
      await page.goto(base + 'admin/' + route);
      await page.waitForFunction(() => !!window.ViracI18n);
      assert.equal(await page.locator('.admin-page-heading h1').count(), 1, route + ' has one page title');
      assert.equal(await page.locator('.admin-workspace-sidebar, .admin-mobile-nav').count(), 0, route + ' preserves original navigation');
      const missingTargets = await page.locator('.admin-page-sections a').evaluateAll(links => links.filter(link => !document.getElementById(link.hash.slice(1))).map(link => link.hash));
      assert.deepEqual(missingTargets, [], route + ' section links have targets');
      const unlabeledFilters = await page.locator('.record-filters, .filter-bar, .analytics-filters').evaluateAll(forms => forms.flatMap(form => [...form.querySelectorAll('input:not([type="hidden"]), select')].filter(field => !field.labels?.length).map(field => field.name)));
      assert.deepEqual(unlabeledFilters, [], route + ' has visible filter labels');
      const oversizedFields = await page.locator('.record-filters input:not([type="hidden"]), .record-filters select, .filter-bar input:not([type="hidden"]), .filter-bar select, .analytics-filters input:not([type="hidden"]), .analytics-filters select').evaluateAll(fields => fields.filter(field => field.getBoundingClientRect().height > 60).map(field => field.name));
      assert.deepEqual(oversizedFields, [], route + ' filter fields have a normal control height');
      for (const width of [1500, 1100, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 900 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, route + '/' + width + ' no horizontal page overflow');
      }
      const originalTitle = await page.locator('.admin-page-heading h1').textContent();
      const valuesBefore = await page.locator('.admin-workspace-content input, .admin-workspace-content select, .admin-workspace-content textarea').evaluateAll(fields => fields.map(field => [field.name, field.value, field.checked]));
      await page.locator('[data-language-select]').selectOption('tl');
      const expectedTitle = await page.evaluate(title => window.ViracTranslations[title], originalTitle);
      assert(expectedTitle, route + ' title has a Tagalog translation');
      assert.equal(await page.locator('.admin-page-heading h1').textContent(), expectedTitle);
      await page.locator('[data-theme-toggle]').click();
      await page.setViewportSize({ width: 390, height: 844 });
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true, route + ' Tagalog/dark mobile fits');
      await page.locator('[data-language-select]').selectOption('en');
      await page.locator('[data-theme-toggle]').click();
      const valuesAfter = await page.locator('.admin-workspace-content input, .admin-workspace-content select, .admin-workspace-content textarea').evaluateAll(fields => fields.map(field => [field.name, field.value, field.checked]));
      assert.deepEqual(valuesAfter, valuesBefore, route + ' preserves form values while changing language/theme');
      await page.setViewportSize({ width: 1500, height: 1000 });
      await page.screenshot({ path: screenshotPath('admin-' + route.replace('.php', '') + '-desktop.png') });
      console.log('PASS: ' + route + ' heading, navigation, labels, responsive layouts, dark mode and Tagalog.');
    }
    await page.goto(base + 'admin/notifications.php');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator('#nav-menu-btn').click();
    assert.equal(await page.locator('#nav-menu-btn').getAttribute('aria-expanded'), 'true');
    await page.locator('#nav-drawer a[href$="admin/settings.php"]').click();
    await page.waitForURL('**/admin/settings.php');
    const settingsLink = page.locator('.record-nav a').filter({ hasText: 'Document Limits' });
    if (await settingsLink.count()) {
      await settingsLink.click();
      assert.equal(await page.locator('details#documents').getAttribute('open'), '');
    }
    assert.deepEqual(errors, [], 'no browser errors');
    guard.assertClean(assert);
    assert.deepEqual(guard.external, [], 'no cross-origin requests');
    console.log('PASS: original mobile menu navigation, and opening linked settings sections. No writes or messages sent.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
