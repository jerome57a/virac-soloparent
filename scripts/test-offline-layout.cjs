/* Requires Playwright and VIRAC_TEST_URL (explicit test-only origin with a port, served by a separately configured
 * test instance). No logins or form submissions.
 * Every preview uses read-only SQL. Internet is blocked in a fresh context per page.
 */
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');
const { parseTestBase, newGuardedContext } = require('./lib/test-origin.cjs');
const { screenshotDir } = require('./lib/test-artifacts.cjs');
const root = path.resolve(__dirname, '..');
// No default: VIRAC_TEST_URL must be an explicit test-only origin with a port (see scripts/lib/test-origin.cjs).
// The ordinary http://localhost/virac-soloparent/ is refused. The server behind it must itself run with
// APP_ENV=testing, the isolated test database and TEST_STORAGE_ROOT.
const base = parseTestBase(process.env.VIRAC_TEST_URL);
// Validate TEST_STORAGE_ROOT too, before any PHP fixture (read-only SQL), screenshot or browser activity below.
screenshotDir();
const preview = (file, ...args) => {
  const html = execFileSync('php', [path.join(__dirname, 'fixtures', file), ...args], { encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 });
  assert(!/Warning:|Fatal error:/.test(html), `${file}/${args} renders cleanly`);
  return html;
};
const fixtures = new Map();
for (const page of ['dashboard', 'notifications', 'index', 'applicants', 'appointments', 'reports']) {
  fixtures.set(`staff/${page}.php`, preview('staff-workspace.php', `${page}.php`));
}
for (const page of ['notifications', 'applicants', 'appointments', 'reports', 'eligibility_logs', 'applications', 'users', 'sms', 'settings', 'audit_log', 'account']) {
  fixtures.set(`admin/${page}.php`, preview('admin-workspace.php', `${page}.php`));
}
fixtures.set('staff/application.php', preview('offline-layout.php', 'staff'));
fixtures.set('applicant/dashboard.php', preview('offline-layout.php', 'applicant'));
const pages = [...fixtures.keys(), 'portal.php', 'portal.php?tab=help', 'portal.php?tab=status', 'eligibility.php', 'login.php', 'mswd-login.php'];
(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    for (const route of pages) {
      // Every request is decided by the guard: fixtures, static assets and the four listed public pages (GET only,
      // redirects refused); cross-origin, non-GET and anything else is aborted and recorded.
      const { context, guard } = await newGuardedContext(browser, base, { viewport: { width: 1440, height: 1000 } }, {
        fixture: relative => fixtures.get(relative),
        livePages: ['portal.php', 'eligibility.php', 'login.php', 'mswd-login.php'],
      });
      const page = await context.newPage();
      // Every MSWDO staff page polls staff/doc_alerts.php for the live alert: answer it with an empty, read-only stub.
      await page.route('**/staff/doc_alerts.php**', r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ waiting: 0, resent: 0, latest: '', first: null, unread: 0, latest_id: 0, items: [] }) }));
      const errors = [], badAssets = [];
      const external = guard.external;
      page.on('pageerror', error => errors.push(error.message));
      page.on('response', response => {
        if (response.status() >= 400 && ['stylesheet', 'script', 'image', 'font'].includes(response.request().resourceType())) badAssets.push(`${response.status()} ${response.url()}`);
      });
      await page.goto(base + route, { waitUntil: 'networkidle' });
      assert(await page.evaluate(() => !!getComputedStyle(document.documentElement).getPropertyValue('--bs-breakpoint-lg')), `${route}: local Bootstrap CSS loaded`);
      if (!route.startsWith('applicant/')) assert(await page.evaluate(() => !!window.bootstrap?.Modal), `${route}: local Bootstrap JS loaded`);
      for (const theme of ['light', 'dark']) {
        await page.evaluate(theme => { document.documentElement.dataset.theme = theme; document.documentElement.dataset.bsTheme = theme; }, theme);
        for (const width of [1440, 390]) {
          await page.setViewportSize({ width, height: 1000 });
          assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${route}/${theme}/${width}: no horizontal overflow`);
          if (route === 'staff/application.php') {
            const columns = await page.locator('.staff-application-review > .row > [class*=col-]').evaluateAll(elements => elements.map(element => ({ x: Math.round(element.getBoundingClientRect().x), y: Math.round(element.getBoundingClientRect().y) })));
            assert.equal(columns.length, 2);
            assert.equal(columns[0].y === columns[1].y, width === 1440, 'Review columns sit side by side on desktop and stack on mobile');
          }
        }
      }
      const brokenImages = await page.locator('img').evaluateAll(images => images.filter(image => !image.complete || image.naturalWidth === 0).map(image => image.getAttribute('src')));
      assert.deepEqual(brokenImages, [], `${route}: images load offline`);
      assert.deepEqual(external, [], `${route}: no internet requests`);
      guard.assertClean(assert, `${route}: browser requests`);
      assert.deepEqual(badAssets, [], `${route}: no missing assets`);
      assert.deepEqual(errors, [], `${route}: no browser errors`);
      if (route === 'staff/application.php') {
        await page.locator('#nav-menu-btn').click();
        assert.equal(await page.locator('#nav-menu-btn').getAttribute('aria-expanded'), 'true', 'Navigation works offline');
        await page.locator('#nav-drawer-close').click();
        await page.locator('[data-language-select]').selectOption('tl');
        assert.equal(await page.evaluate(() => document.documentElement.lang), 'tl');
      }
      console.log(`PASS offline: ${route} (desktop/mobile, light/dark, assets, JavaScript)`);
      await context.close();
    }
    console.log(`PASS: ${pages.length} pages with empty caches and internet blocked; no application changes or messages.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
