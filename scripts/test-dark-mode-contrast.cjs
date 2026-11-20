/* Dark-mode contrast test.
 *
 * Renders every staff, administrator and Solo Parent screen (read-only CLI fixtures against the ISOLATED test database), forces the
 * dark theme in a real browser and measures every visible piece of text (and every inline icon, 3:1) against the surface behind it. Fails if any text is below
 * 4.5:1 (3:1 for large text). This is what catches "text or logo became transparent" in dark mode: a component stylesheet that
 * hard-codes a light-theme colour (dark text on a dark card, light text on a white panel).
 *
 * Needs Playwright (and Google Chrome, or Playwright's own browser) and the same environment as the other fixture-based tests
 * (APP_ENV=testing, the isolated test database, TEST_STORAGE_ROOT). No web server and no login: stylesheets, scripts and images are
 * served straight from this project folder, and every other request is blocked.
 *
 *   NODE_PATH=scripts/node_modules node scripts/test-dark-mode-contrast.cjs
 */
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fs = require('node:fs');
const { probe } = require('./lib/dark-contrast-probe.cjs');

const root = path.resolve(__dirname, '..');
const origin = 'http://localhost/virac-soloparent';
const types = { css: 'text/css', js: 'application/javascript', png: 'image/png', jpg: 'image/jpeg', svg: 'image/svg+xml' };
const preview = (file, ...args) => {
  const html = execFileSync('php', [path.join(__dirname, 'fixtures', file), ...args], { encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 });
  if (/Warning:|Fatal error:|Uncaught/.test(html)) throw new Error(`${file} ${args.join(' ')} did not render cleanly`);
  return html;
};

const pages = new Map();
for (const p of ['dashboard', 'notifications', 'index', 'applicants', 'appointments', 'reports']) pages.set(`staff/${p}`, () => preview('staff-workspace.php', `${p}.php`));
for (const p of ['notifications', 'applicants', 'appointments', 'reports', 'eligibility_logs', 'applications', 'users', 'sms', 'settings', 'audit_log', 'account']) pages.set(`admin/${p}`, () => preview('admin-workspace.php', `${p}.php`));
pages.set('staff/application', () => preview('offline-layout.php', 'staff'));
for (const s of ['dashboard', 'application', 'apply', 'documents', 'appointment', 'renewal', 'myid', 'notifications', 'help', 'requirements']) pages.set(`solo-parent/${s}`, () => preview('applicant-section.php', s));

(async () => {
  let browser;
  try { browser = await chromium.launch({ channel: 'chrome', headless: true }); } catch (e) { browser = await chromium.launch({ headless: true }); }
  const failures = [];
  for (const [name, render] of pages) {
    const html = render();
    for (const width of [1280, 390]) {
      const ctx = await browser.newContext({ viewport: { width, height: 900 } });
      await ctx.addInitScript(() => { try { localStorage.setItem('virac.theme', 'dark'); localStorage.setItem('virac.language', 'en'); } catch (e) { /* optional */ } });
      await ctx.route('**/*', route => {
        const url = new URL(route.request().url());
        if (url.pathname === '/virac-soloparent/__fx__/page') return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html });
        const m = url.pathname.match(/^\/virac-soloparent\/((?:public|assets)\/.*)$/);
        const file = m && path.join(root, decodeURIComponent(m[1]));
        if (file && file.startsWith(root) && fs.existsSync(file)) return route.fulfill({ status: 200, contentType: types[file.split('.').pop()] || 'application/octet-stream', body: fs.readFileSync(file) });
        return route.abort();
      });
      const page = await ctx.newPage();
      await page.goto(`${origin}/__fx__/page`, { waitUntil: 'load', timeout: 30000 });
      await page.evaluate(() => document.querySelectorAll('details').forEach(d => { d.open = true; }));
      await page.waitForTimeout(250);
      const res = await page.evaluate(probe);
      if (res.theme !== 'dark') failures.push(`${name} @${width}: the dark theme was not applied`);
      for (const i of res.iconFindings || []) failures.push(`${name} @${width}: icon in ${i.parent} is ${i.fg} on ${i.bg} = ${i.ratio}:1, needs 3:1`);
      for (const t of res.textFindings) failures.push(`${name} @${width}: "${t.text}" (${t.el}) is ${t.fg} on ${t.bg} = ${t.ratio}:1, needs ${t.need}:1`);
      await ctx.close();
    }
  }
  await browser.close();
  if (failures.length) { console.error('FAIL: dark-mode contrast problems:\n  ' + failures.join('\n  ')); process.exit(1); }
  console.log(`PASS: dark-mode contrast on ${pages.size} screens at desktop and phone widths: every text reaches its required contrast.`);
})().catch(e => { console.error(e); process.exit(1); });
