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
      // The approved design: four numbers, Needs attention, Recent activity; nothing else on the dashboard.
      assert.equal(await page.locator('.adm-metric').count(), 4, 'four numbers');
      assert.equal(await page.locator('.adm-metric .adm-chip svg.ui-icon').count(), 4, 'each number has its icon');
      assert.equal(await page.locator('#admin-attention .adm-item').count(), 5, 'five Needs attention items');
      const attentionLinks = await page.locator('#admin-attention .adm-item').evaluateAll(a => a.map(x => new URL(x.href).pathname.split('/').pop() + new URL(x.href).search));
      assert.deepEqual(attentionLinks, ['sms.php?status=failed', 'users.php?status=locked', 'expiry_sms.php', 'applications.php?status=Approved', 'applications.php?waiting=7'], 'each Needs attention item goes straight to its page');
      assert.equal(await page.locator('.ad-status-row, .ad-details, #admin-more, #admin-tools, .ad-section-nav, .ad-heading').count(), 0, 'the status breakdown, details, quick access, section links and heading are gone');
      assert.equal(await page.locator('#admin-setup').count(), state === 'clear' ? 0 : 1, 'the setup checklist shows until every step is done');
      if (state !== 'clear') assert.equal(await page.locator('#admin-setup .gi-check').count(), 5, 'five setup steps');
      const reviewUrl = new URL(await page.locator('.adm-metric').nth(1).getAttribute('href'));
      assert.equal(reviewUrl.searchParams.get('status'), 'Pending Review');
      assert.equal(reviewUrl.searchParams.has('type'), false, 'pending card includes both types');
      assert((await page.locator('#admin-activity .adm-more').getAttribute('href')).endsWith('admin/audit_log.php'), 'Recent activity links to the audit log');
      if (state === 'populated') {
        assert.equal(await page.locator('.adm-metric strong').nth(1).textContent(), '31');
        assert.equal(await page.locator('#admin-activity .adm-act').count(), 3, 'three recent actions');
        assert.equal(await page.locator('#admin-activity .adm-avatar').first().textContent(), 'EA', 'initials of who did it');
      }
      if (state === 'empty') {
        assert.equal(await page.locator('#admin-activity .adm-empty').count(), 1, 'no activity says so');
        assert.equal(await page.locator('#admin-attention .adm-count.is-zero').count(), 5, 'nothing waiting: every count is grey');
      }
      if (state === 'clear') {
        assert.equal(await page.locator('#admin-attention .adm-count:not(.is-zero)').count(), 0, 'nothing needs attention');
      }
      for (const language of ['en', 'tl']) {
        await page.locator('[data-language-select]').selectOption(language);
        for (const width of [1440, 768, 390, 320]) {
          await page.setViewportSize({ width, height: 1000 });
          if (width >= 1440) {
            const [a, b] = await page.locator('.adm-cols > .adm-panel').evaluateAll(p => p.map(el => el.getBoundingClientRect().y));
            assert(Math.abs(a - b) < 1, 'Needs attention and Recent activity share a row on a wide screen');
          } else if (width <= 768) {
            const [a, b] = await page.locator('.adm-cols > .adm-panel').evaluateAll(p => p.map(el => el.getBoundingClientRect()));
            assert(b.y >= a.bottom, 'the two panels stack on a phone');
          }
          for (const theme of ['light', 'dark']) {
            await page.evaluate(theme => document.documentElement.dataset.theme = theme, theme);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `${state}/${language}/${width}/${theme} fits viewport`);
          }
        }
      }
    }
    await page.goto(base + 'admin/dashboard.php?state=populated');
    await page.setViewportSize({ width: 1440, height: 1100 });
    await page.screenshot({ path: screenshotPath('admin-dashboard-desktop.png'), fullPage: true });
    await page.locator('[data-language-select]').selectOption('tl');
    assert.equal(await page.locator('h1').textContent(), 'Dashboard ng MSWDO Admin');
    await page.locator('[data-theme-toggle]').click();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: screenshotPath('admin-dashboard-dark-mobile.png'), fullPage: true });
    await page.waitForLoadState();
    assert.deepEqual(errors, []);
    guard.assertClean(assert);
    assert.deepEqual(guard.external, [], 'no cross-origin requests');
    console.log('PASS: admin dashboard (four numbers, Needs attention, Recent activity, setup checklist until done), both languages/themes, and 320–1440px layouts. No database changes.');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
