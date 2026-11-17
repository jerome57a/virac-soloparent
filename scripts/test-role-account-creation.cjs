/* Real GET templates with a read-only SQL fixture; no browser form submissions.
 * Requires VIRAC_TEST_URL (explicit test-only origin with a port); the ordinary application URL is refused. */
const { chromium } = require('playwright');
const { screenshotPath, screenshotDir } = require('./lib/test-artifacts.cjs');
const { parseTestBase, newGuardedContext } = require('./lib/test-origin.cjs');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
  const roles = ['', 'applicant', 'mswd_social_worker', 'admin'];
  // No default: VIRAC_TEST_URL must be an explicit test-only origin with a port (see scripts/lib/test-origin.cjs).
  // Both the URL and TEST_STORAGE_ROOT are validated before any PHP fixture (read-only SQL) or browser runs.
  const base = parseTestBase(process.env.VIRAC_TEST_URL);
  screenshotDir();
  const previews = new Map(roles.map(role => [role, execFileSync('php', [path.join(__dirname, 'fixtures/admin-workspace.php'), 'users.php', role], { encoding: 'utf8' })]));
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    // Only admin/users.php (the fixture) and static assets are answered; any other request, any non-GET request,
    // any redirect and anything outside the test origin is aborted and fails the run.
    const { context, guard } = await newGuardedContext(browser, base, {}, {
      fixture: (relative, url) => relative === 'admin/users.php' ? previews.get(url.searchParams.get('create_role') || '') : undefined,
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(base + 'admin/users.php');
    assert.equal(await page.locator('[data-role-account-form] select[name=role]').count(), 1, 'Original role dropdown is available.');
    assert.equal(await page.locator('.admin-create-roles').count(), 0, 'No added role navigation UI.');
    for (const role of roles.filter(Boolean)) {
      await page.locator('[data-role-account-form] select[name=role]').selectOption(role);
      const form = page.locator('[data-role-account-form]');
      assert.equal(await form.locator('[name=role]').inputValue(), role);
      assert.equal(await form.locator('[name=password_confirm]').count(), 1);
      const gmail = form.locator('[name=email]');
      assert.equal(await gmail.count(), 1);
      assert.equal(await gmail.isVisible(), role === 'applicant');
      assert.equal(await gmail.isDisabled(), role !== 'applicant');
      assert.equal(await gmail.evaluate(el => el.required), false);
      assert.equal(await form.locator('[name=mobile]').evaluate(el => el.required), role === 'applicant');
      assert.equal(await form.locator('[name=mobile]').isVisible(), role === 'applicant');
      assert.equal(await form.locator('[name=mobile]').isDisabled(), role !== 'applicant');
      assert.equal(await form.locator('[name=mustChange]').isDisabled(), role === 'applicant');
      if (role !== 'applicant') assert(await form.locator('[name=mustChange]').isChecked());
      // Every role signs in with a username: visible, enabled and required for all three; applicants get the stricter 3-30 rule.
      const username = form.locator('[name=username]');
      assert.equal(await username.isVisible(), true);
      assert.equal(await username.isDisabled(), false);
      assert.equal(await username.evaluate(el => el.required), true);
      assert.equal(await username.evaluate(el => el.maxLength), role === 'applicant' ? 30 : 50);
      assert.equal(await form.locator('[data-username-help="applicant"]').isVisible(), role === 'applicant');
      assert.equal(await form.locator('[data-username-help="personnel"]').isVisible(), role !== 'applicant');
      await username.fill('ab');
      assert.equal(await username.evaluate(el => el.validity.patternMismatch), true, 'Too-short username refused by the pattern.');
      if (role === 'applicant') {
        await username.fill('12345');
        assert.equal(await username.evaluate(el => el.validity.patternMismatch), true, 'A username needs at least one letter (pattern).');
        await username.fill('ends.with.dot.');
        assert.equal(await username.evaluate(el => el.validity.patternMismatch), true, 'A username cannot end with a symbol (pattern).');
      }
      await username.fill('valid.name');
      assert(await username.evaluate(el => el.validity.valid));
      if (role === 'applicant') {
        await gmail.fill('');
        assert(await gmail.evaluate(el => el.validity.valid), 'Blank Gmail is optional.');
        await gmail.fill('parent@example.com');
        assert.equal(await gmail.evaluate(el => el.validity.patternMismatch), true, 'Gmail domain required (pattern).');
        await gmail.fill('valid.name@gmail.com');
        assert(await gmail.evaluate(el => el.validity.valid));
      }
      await form.locator('[name=fullName]').fill('Preview Name');
      await form.locator('[name=password]').fill('PreviewOnly123');
      await page.locator('[data-language-select]').selectOption('tl');
      assert.equal(await form.locator('[name=password]').inputValue(), 'PreviewOnly123');
      assert.equal(await username.inputValue(), 'valid.name');
      if (role === 'applicant') assert.equal(await gmail.inputValue(), 'valid.name@gmail.com');
      await page.locator('[data-theme-toggle]').click();
      for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 1000 });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${role}/${width} fits viewport`);
      }
      await page.locator('[data-language-select]').selectOption('en');
      await page.locator('[data-theme-toggle]').click();
      await form.locator('[name=password]').fill('');
      await page.setViewportSize({ width: 1440, height: 1100 });
      await page.screenshot({ path: screenshotPath(`admin-create-${role}.png`) });
      console.log(`PASS: ${role} form fields, optional Gmail, required role-specific username constraints, password confirmation, language, theme and mobile layouts.`);
    }
    assert.deepEqual(errors, []);
    guard.assertClean(assert);
    assert.deepEqual(guard.external, [], 'no cross-origin requests');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
