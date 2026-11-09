/* Real GET templates with a read-only SQL fixture; no browser form submissions. */
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
  const roles = ['', 'applicant', 'mswd_social_worker', 'admin'];
  const previews = new Map(roles.map(role => [role, execFileSync('php', [path.join(__dirname, 'fixtures/admin-workspace.php'), 'users.php', role], { encoding: 'utf8' })]));
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/admin/users.php*', route => {
      assert.equal(route.request().method(), 'GET', 'Never submit a live account form.');
      return route.fulfill({ contentType: 'text/html', body: previews.get(new URL(route.request().url()).searchParams.get('create_role') || '') });
    });
    await page.goto('http://localhost/virac-soloparent/admin/users.php');
    assert.equal(await page.locator('[data-role-account-form] select[name=role]').count(), 1, 'Original role dropdown is available.');
    assert.equal(await page.locator('.admin-create-roles').count(), 0, 'No added role navigation UI.');
    for (const role of roles.filter(Boolean)) {
      await page.locator('[data-role-account-form] select[name=role]').selectOption(role);
      const form = page.locator('[data-role-account-form]');
      assert.equal(await form.locator('[name=role]').inputValue(), role);
      assert.equal(await form.locator('[name=password_confirm]').count(), 1);
      assert.equal(await form.locator('[name=email]').count(), 0);
      assert.equal(await form.locator('[name=mobile]').evaluate(el => el.required), role === 'applicant');
      assert.equal(await form.locator('[name=mobile]').isVisible(), role === 'applicant');
      assert.equal(await form.locator('[name=mobile]').isDisabled(), role !== 'applicant');
      assert.equal(await form.locator('[name=mustChange]').isDisabled(), role === 'applicant');
      if (role !== 'applicant') assert(await form.locator('[name=mustChange]').isChecked());
      const username = form.locator('[name=username]');
      await username.fill(role === 'applicant' ? '123_45' : 'staff-name');
      assert.equal(await username.evaluate(el => el.validity.valid), role !== 'applicant');
      await username.fill('valid.name');
      assert(await username.evaluate(el => el.validity.valid));
      await form.locator('[name=fullName]').fill('Preview Name');
      await form.locator('[name=password]').fill('PreviewOnly123');
      await page.locator('[data-language-select]').selectOption('tl');
      assert.equal(await form.locator('[name=password]').inputValue(), 'PreviewOnly123');
      assert.equal(await form.locator('[name=username]').inputValue(), 'valid.name');
      await page.locator('[data-theme-toggle]').click();
      for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 1000 });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${role}/${width} fits viewport`);
      }
      await page.locator('[data-language-select]').selectOption('en');
      await page.locator('[data-theme-toggle]').click();
      await form.locator('[name=password]').fill('');
      await page.setViewportSize({ width: 1440, height: 1100 });
      await page.screenshot({ path: path.join(__dirname, `../storage/admin-create-${role}.png`) });
      console.log(`PASS: ${role} form fields, username constraints, password confirmation, language, theme and mobile layouts.`);
    }
    assert.deepEqual(errors, []);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
