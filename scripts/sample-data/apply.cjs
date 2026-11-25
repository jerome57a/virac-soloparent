/* Sample Solo Parents (database/sample_data/solo-parents.csv): signs in as each one and uses the real web pages, exactly as a person
 * would, so every upload goes through the system's own checks.
 *   phase "apply":    the new application (personal details, children, documents with pictures, declaration, submit)
 *   phase "followup": B-rows at "reuploaded" send the corrected document again; A-rows at "released_renewal_filed" file the renewal
 * Run database/sample_data.php accounts first, and `staff` between the two phases.
 *
 *   SAMPLE_PASSWORD=... SAMPLE_BASE=http://localhost/virac-soloparent/ SAMPLE_PICTURES=C:/dev/sample-data/pictures \
 *     NODE_PATH=scripts/node_modules node scripts/sample-data/apply.cjs apply
 * Optional: SAMPLE_ONLY=A1,B4 to run some rows only. The password is never printed. */
const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');

const phase = process.argv[2] || 'apply';
const base = (process.env.SAMPLE_BASE || '').replace(/\/?$/, '/');
const password = process.env.SAMPLE_PASSWORD || '';
const picturesDir = process.env.SAMPLE_PICTURES || '';
const only = (process.env.SAMPLE_ONLY || '').split(',').map(s => s.trim()).filter(Boolean);
if (!/^https?:\/\//.test(base) || !password || !fs.existsSync(picturesDir)) {
  console.error('Set SAMPLE_BASE, SAMPLE_PASSWORD and SAMPLE_PICTURES.');
  process.exit(2);
}

function readCsv(file) {
  const [head, ...lines] = fs.readFileSync(file, 'utf8').trim().split(/\r?\n/);
  const cols = head.split(',');
  return lines.map(line => {
    const cells = line.split(',');
    const row = Object.fromEntries(cols.map((c, i) => [c, (cells[i] || '').trim()]));
    row.kids = row.children.split(';').filter(Boolean).map(c => { const [name, birthdate, pwd] = c.split(':'); return { name, birthdate, pwd: pwd === 'pwd' }; });
    return row;
  });
}
const rows = readCsv(path.join(__dirname, '..', '..', 'database', 'sample_data', 'solo-parents.csv')).filter(r => !only.length || only.includes(r.key));
const pictures = fs.readdirSync(picturesDir).filter(f => /\.jpe?g$/i.test(f)).sort().map(f => path.join(picturesDir, f));
let next = 0;
const picture = () => pictures[next++ % pictures.length];

async function signIn(page, row) {
  await page.goto(base + 'login.php');
  await page.fill('input[name=username]', row.username);
  await page.fill('input[name=password]', password);
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.locator('form:has(input[name=password]) [type=submit]').first().click()]);
  if (/login\.php/.test(page.url())) throw new Error(row.key + ': sign-in failed');
}
async function signOut(context) { await context.clearCookies(); }
const click = async (page, value) => {
  // Wait for the new page itself (the form posts and the server redirects), not just the current one.
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.locator(`button[name=action][value=${value}]`).first().click()]);
};
const stepOf = async page => Number(await page.locator('input[name=wizard_step]').first().getAttribute('value').catch(() => '0')) || 0;
async function errorsOn(page) { return (await page.locator('.field-error:visible, .auth-error:visible').allInnerTexts()).join(' | '); }

async function apply(page, row) {
  await page.goto(base + 'applicant/dashboard.php?section=apply');
  if ((await stepOf(page)) === 0) return null;   // no wizard: this Solo Parent already has an application
  for (let guard = 0; guard < 12; guard++) {
    const step = await stepOf(page);
    if (step === 0) break;
    if (step === 1) {
      await page.fill('input[name=fullName]', row.full_name);
      await page.fill('input[name=barangay]', row.barangay);
      await page.fill('input[name=address]', row.address);
      await page.fill('input[name=birthdate]', row.birthdate);
      await page.selectOption('select[name=sex]', row.sex);
      await page.selectOption('select[name=civilStatus]', row.civil_status);
      await page.selectOption('select[name=categoryId]', row.category_id);
      await click(page, 'continue');
    } else if (step === 2) {
      while ((await page.locator('input[name="child_name[]"]').count()) < row.kids.length) await click(page, 'add_child');
      // A draft left by an earlier try may hold extra (empty) child rows: remove them.
      while ((await page.locator('input[name="child_name[]"]').count()) > row.kids.length) {
        await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.locator('button[name=action][value=remove_child]').last().click()]);
      }
      const pwd = row.kids.some(k => k.pwd);
      await page.locator(`input[name=has_pwd_child][value="${pwd ? 1 : 0}"]`).check({ force: true });
      for (let i = 0; i < row.kids.length; i++) {
        await page.locator('input[name="child_name[]"]').nth(i).fill(row.kids[i].name);
        await page.locator('input[name="child_birthdate[]"]').nth(i).fill(row.kids[i].birthdate);
        if (row.kids[i].pwd) await page.locator(`input[name="child_disability[${i}]"]`).check({ force: true });
      }
      await click(page, 'continue');
    } else if (step === 3) {
      const inputs = page.locator('input[type=file][name^=application_file]');
      for (let i = 0; i < await inputs.count(); i++) await inputs.nth(i).setInputFiles(picture());
      await click(page, 'continue');
    } else if (step === 4) {
      await page.locator('input[name=consent]').check({ force: true });
      await click(page, 'submit');
    }
    const after = await stepOf(page);
    if (after === step && step !== 0) throw new Error(`${row.key}: stuck on step ${step}: ${await errorsOn(page)}`);
  }
  const text = await page.locator('body').innerText();
  if (!/VIRAC-SP-\d{4}-\d{4}/.test(text)) throw new Error(row.key + ': no reference number after submitting');
  return text.match(/VIRAC-SP-\d{4}-\d{4}/)[0];
}

async function reupload(page, row) {
  await page.goto(base + 'applicant/dashboard.php?section=documents');
  const input = page.locator('input.docs-input').first();
  if (!(await input.count())) throw new Error(row.key + ': no document to replace');
  await input.setInputFiles(picture());
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.locator('form:has(input.docs-input) [type=submit]').first().click()]);
  return 'document sent again';
}

async function renew(page, row) {
  await page.goto(base + 'applicant/dashboard.php?section=renewal&type=new');
  for (let guard = 0; guard < 10; guard++) {
    if (await page.locator('button[name=action][value=submit]').count()) {
      const pwd = row.kids.some(k => k.pwd);
      const answer = page.locator(`input[name=has_pwd_child][value="${pwd ? 1 : 0}"]`);
      if (await answer.count()) await answer.check({ force: true });
      const files = page.locator('input[type=file][name^=renewal_file]');
      // Only the documents shown are asked (a hidden box, like the PWD proof when no child needs it, is not part of this renewal).
      for (let i = 0; i < await files.count(); i++) if (await files.nth(i).isVisible()) await files.nth(i).setInputFiles(picture());
      await page.locator('input[name=consent]').check({ force: true });
      await click(page, 'submit');
      break;
    }
    if (await page.locator('button[name=action][value=continue]').count()) { await click(page, 'continue'); continue; }
    const shown = (await page.locator('main').innerText()).slice(0, 300);
    if (/being processed|already|in progress/i.test(shown)) return 'a renewal is already filed (skipped)';
    throw new Error(row.key + ': the renewal form did not open: ' + shown.slice(0, 200));
  }
  const errors = await errorsOn(page);
  if (errors) throw new Error(row.key + ': renewal not submitted: ' + errors);
  return 'renewal filed';
}

(async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  let failed = 0;
  try {
    for (const row of rows) {
      const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
      const page = await context.newPage();
      try {
        if (phase === 'apply') {
          await signIn(page, row);
          const ref = await apply(page, row);
          console.log(`${row.key} ${row.username}: ` + (ref ? `submitted ${ref}` : 'already has an application (skipped)'));
        } else if (phase === 'followup' && row.stage === 'reuploaded') {
          await signIn(page, row);
          console.log(`${row.key} ${row.username}: ${await reupload(page, row)}`);
        } else if (phase === 'followup' && (row.stage === 'released_renewal_filed' || row.stage.startsWith('renewal_'))) {
          await signIn(page, row);
          console.log(`${row.key} ${row.username}: ${await renew(page, row)}`);
        }
      } catch (e) {
        failed++;
        console.log(`${row.key}: FAILED ${e.message}`);
      } finally {
        await signOut(context);
        await context.close();
      }
    }
  } finally {
    await browser.close();
  }
  process.exit(failed ? 1 : 0);
})();
