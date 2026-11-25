/* Read-only real GET previews. Browser navigation stays within fixture HTML.
 * Requires VIRAC_TEST_URL (explicit test-only origin with a port); the ordinary application URL is refused. */
const { chromium } = require('playwright');
const { screenshotPath, screenshotDir } = require('./lib/test-artifacts.cjs');
const { parseTestBase, newGuardedContext } = require('./lib/test-origin.cjs');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');
const routes = ['dashboard.php', 'notifications.php', 'index.php', 'applicants.php', 'appointments.php', 'reports.php', 'verification.php', 'follow_up.php', 'work.php'];
(async () => {
  // No default: an explicit test-only origin is required (see scripts/lib/test-origin.cjs). Every browser request is
  // decided by the guard: fixtures and static assets only, GET only, no redirects, nothing cross-origin.
  // Both the URL and TEST_STORAGE_ROOT are validated before any PHP fixture or browser runs.
  const base = parseTestBase(process.env.VIRAC_TEST_URL);
  screenshotDir();
  const previews = new Map(routes.map(route => [route, execFileSync('php', [path.join(__dirname, 'fixtures/staff-workspace.php'), route, route === 'work.php' ? 'new_forms' : ''], { encoding: 'utf8' })]));
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
      // Section links on the dashboard go to the page that does the work; a link that stays on this page must find its target.
      const missingTargets = await page.locator('.staff-section-links a').evaluateAll(links => links.filter(a => a.pathname === location.pathname && a.hash && !document.getElementById(a.hash.slice(1))).map(a => a.hash));
      assert.deepEqual(missingTargets, []);
      if (route === 'dashboard.php') {
        // The approved design: Today at the office (search, counts, See today's work), four work tiles, four shortcut buttons.
        assert.equal(await page.locator('.stat-tile').count(), 0, 'no old stat tiles');
        assert(await page.locator('#office-today #ot-ref').isVisible(), 'the search box is in Today at the office');
        assert.equal(await page.locator('#office-today .td-pill').count(), 2, 'two counts: expected and done today (no "waiting")');
        assert((await page.locator('#office-today .td-more').getAttribute('href')).endsWith('staff/appointments.php'), "See today's work opens Appointment Management");
        assert.deepEqual((await page.locator('.wt-tile .wt-t').allInnerTexts()).map(t => t.toLowerCase()), ['new forms', 're-uploaded documents', 'set the interview date', 'set the id release date'], 'four work tiles, in work order');
        assert.equal(await page.locator('.wt-tile .wt-n').count(), 4, 'each tile has its number');
        assert.equal(await page.locator('.wt-tile .wt-chip svg.ui-icon').count(), 4, 'each tile has its icon');
        assert.equal(await page.locator('.sq-list, .sq-tag').count(), 0, 'the Solo Parents are not listed on the dashboard: staff open the list');
        const cardLinks = await page.locator('a.wt-tile').evaluateAll(a => a.map(x => x.getAttribute('href')));
        assert.deepEqual(cardLinks.map(h => h.split('/').pop()), ['work.php?list=new_forms', 'work.php?list=resent', 'work.php?list=interview', 'work.php?list=release_date'], 'each tile opens its own list');
        assert.equal(await page.locator('.wt-shortcuts a').count(), 4, 'the four shortcut buttons');
        // The side menu works like the Solo Parent's: closed until the menu button opens it, also on a wide screen.
        await page.setViewportSize({ width: 1440, height: 1000 });
        assert(await page.locator('#nav-menu-btn').isVisible(), 'the menu button is there on a wide screen');
        assert.equal(await page.locator('#nav-drawer').getAttribute('aria-hidden'), 'true', 'the menu starts closed');
        await page.locator('#nav-menu-btn').click();
        await page.waitForTimeout(350);
        assert.equal(await page.locator('#nav-drawer').getAttribute('aria-hidden'), 'false', 'the menu button opens it');
        assert.equal((await page.locator('#nav-drawer .dash-brand-sub').innerText()).trim(), 'MSWDO Staff Portal', 'the menu names the portal, like the Solo Parent menu');
        await page.locator('#nav-drawer-close').click();
        await page.waitForTimeout(350);
        assert.equal(await page.locator('#nav-drawer').getAttribute('aria-hidden'), 'true', 'the close button closes it');
        assert.equal(await page.locator('.gi-start').count(), 0, 'the Start here list is gone from the dashboard');
      }
      if (route === 'work.php') {
        assert.equal((await page.locator('#staff-work-list h1').innerText()).trim(), 'New forms', 'the list page is titled with its list');
        assert((await page.locator('#staff-work-list .sq-list a').evaluateAll(a => a.map(x => x.getAttribute('href')))).every(h => /staff\/application\.php\?id=\d+$/.test(h)), 'each Solo Parent opens its application');
      }
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
