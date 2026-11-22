/* Requires playwright (Chrome) and VIRAC_TEST_URL (explicit test-only origin with a port; the ordinary application URL
 * is refused). Fixtures use no database and perform no account writes. Every browser request is decided by the shared
 * guard (scripts/lib/test-origin.cjs): fixture pages and static assets only, GET only, no redirects, nothing
 * cross-origin; the one expected search navigation (admin/applications.php, GET) is answered with a stub page. */
const assert = require('node:assert/strict');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { chromium } = require('playwright');
const { screenshotPath, screenshotDir } = require('./lib/test-artifacts.cjs');
const { parseTestBase, newGuardedContext } = require('./lib/test-origin.cjs');
const fixture = state => execFileSync('php', [path.join(__dirname, 'fixtures/admin-dashboard.php'), state], { encoding: 'utf8' });
(async () => {
  // Validate everything before any PHP fixture or browser runs. No default URL.
  const base = parseTestBase(process.env.VIRAC_TEST_URL);
  screenshotDir(); // validates TEST_STORAGE_ROOT
  const states = ['populated', 'empty', 'clear', 'large'];
  const fixtures = new Map(states.map(state => [state, fixture(state)]));
  for (const html of fixtures.values()) assert(!/Warning:|Fatal error:/.test(html), 'PHP fixture renders cleanly');
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const { context, guard } = await newGuardedContext(browser, base, {}, {
      stubPages: true, // the expected GET search navigation to admin/applications.php gets a placeholder, never the server
      fixture: (relative, url) => relative === 'admin/dashboard.php' ? fixtures.get(url.searchParams.get('state') || 'populated') : undefined,
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    for (const state of states) {
      await page.goto(base + 'admin/dashboard.php?state=' + state);
      assert.equal(await page.locator('.ad-metric').count(), 4);
      assert.equal(await page.locator('.ad-status-row').count(), 8);
      assert.equal(await page.locator('.ad-heading.personnel-page-heading').count(), 1, 'shared staff heading panel');
      assert.equal(await page.locator('.ad-metric.stat-tile').count(), 4, 'shared staff statistic tiles');
      assert.equal(await page.locator('.ad-metric').first().evaluate(el => getComputedStyle(el).borderTopWidth), '3px', 'staff tile accent');
      assert.equal(await page.locator('#admin-dashboard > .dash-two-col').count(), 1, 'one row of paired panels');
      assert.equal(await page.locator('.ad-attention-item').count(), 5, 'five Needs attention items');
      assert.equal(await page.locator('.ad-attention-item[open]').count(), 0, 'Needs attention details are hidden until clicked');
      assert.equal(await page.locator('.ad-details').getAttribute('open'), null, 'system details are hidden');
      assert.equal(await page.locator('#admin-more').getAttribute('open'), null, 'recent activity and quick access are hidden');
      await page.setViewportSize({ width: 1440, height: 1100 });
      const rows = await page.locator('#admin-dashboard > .dash-two-col').evaluateAll(rows => rows.map(row => [...row.children].map(card => { const r = card.getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width }; })));
      for (const pair of rows) {
        assert.equal(pair.length, 2);
        assert(Math.abs(pair[0].y - pair[1].y) < 1, 'desktop panels share a row');
        assert(Math.abs(pair[0].width - pair[1].width) < 1, 'desktop columns have equal width');
      }
      assert(await page.locator('#admin-tools').evaluate(el => !!el.closest('#admin-more')), 'quick access is folded under the hidden section');
      const reviewUrl = new URL(await page.locator('.ad-metric').nth(1).getAttribute('href'));
      assert.equal(reviewUrl.searchParams.get('status'), 'Pending Review');
      assert.equal(reviewUrl.searchParams.has('type'), false, 'pending card includes both types');
      await page.locator('#admin-attention-locked > summary').click();
      assert.equal(await page.locator('#admin-attention-locked').getAttribute('open'), '', 'a row opens its details');
      const lockedUrl = new URL(await page.locator('#admin-attention-locked .ad-text-link').getAttribute('href'));
      assert.equal(lockedUrl.searchParams.get('status'), 'locked');
      await page.locator('#admin-attention-failed-sms > summary').click();
      assert.equal(await page.locator('#admin-attention-locked').getAttribute('open'), null, 'one row open at a time');
      await page.locator('#admin-attention-failed-sms > summary').click();
      if (state === 'populated') {
        assert.equal(await page.locator('.ad-metric > strong').nth(1).textContent(), '31');
        assert.equal(await page.locator('.ad-attention-count b').textContent(), '5');
        assert.equal(await page.locator('.ad-signed-in strong').textContent(), 'Test <Administrator>');
      }
      if (state === 'empty') {
        assert.equal(await page.locator('.ad-empty').count(), 1);
        assert.match(await page.locator('.ad-trend-summary').textContent(), /No decided applications yet/);
      }
      if (state === 'clear') {
        assert.equal(await page.locator('.ad-attention-count b').textContent(), '0');
        assert.equal(await page.locator('.ad-clear-message').count(), 1);
      }
      for (const language of ['en', 'tl']) {
        await page.locator('[data-language-select]').selectOption(language);
        for (const width of [1440, 768, 390, 320]) {
          await page.setViewportSize({ width, height: 1000 });
          if (width <= 768) {
            const stacked = await page.locator('#admin-dashboard > .dash-two-col').evaluateAll(rows => rows.every(row => { const [a, b] = [...row.children].map(el => el.getBoundingClientRect()); return Math.abs(a.x - b.x) < 1 && b.y >= a.bottom; }));
            assert(stacked, 'paired panels stack in reading order on mobile');
          }
          for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
            assert.equal(await page.locator('.ad-heading').evaluate(el => getComputedStyle(el).backgroundColor), theme === 'dark' ? 'rgb(28, 40, 57)' : 'rgb(255, 255, 255)', 'heading surface follows theme');
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${state}/${language}/${width}/${theme} fits viewport`);
          }
        }
      }
      await page.locator('.ad-details summary').click();
      assert.equal(await page.locator('.ad-details').getAttribute('open'), '');
      assert.equal(await page.locator('.ad-details-grid').isVisible(), true);
    }
    await page.goto(base + 'admin/dashboard.php?state=populated');
    await page.setViewportSize({ width: 1440, height: 1100 });
    await page.screenshot({ path: screenshotPath('admin-dashboard-desktop.png'), fullPage: true });
    await page.locator('[data-language-select]').selectOption('tl');
    assert.equal(await page.locator('h1').textContent(), 'Dashboard ng MSWDO Admin');
    const missing = await page.locator('#admin-dashboard [data-i18n]').evaluateAll(elements => elements.flatMap(element => [...element.childNodes].filter(node => node.nodeType === 3).map(node => node.nodeValue.trim()).filter(text => /[a-z]/i.test(text) && !Object.values(window.ViracTranslations).includes(text))));
    assert.deepEqual(missing, [], 'all dashboard labels have Tagalog translations');
    await page.locator('[data-theme-toggle]').click();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: screenshotPath('admin-dashboard-dark-mobile.png'), fullPage: true });
    const target = page.waitForRequest(request => new URL(request.url()).pathname.endsWith('/admin/applications.php'));
    await page.locator('#admin-application-search').fill('Test reference');
    await page.locator('.ad-search button').click();
    const searched = new URL((await target).url());
    assert.equal(searched.searchParams.get('q'), 'Test reference');
    assert.equal(searched.origin, new URL(base).origin, 'search stays on the test origin');
    await page.waitForLoadState();
    assert.deepEqual(errors, []);
    guard.assertClean(assert);
    assert.deepEqual(guard.external, [], 'no cross-origin requests');
    console.log('PASS: dashboard counts, scoped filters, escaped names, empty states, expandable details, search, both languages/themes, and 320–1440px layouts. No database changes.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
