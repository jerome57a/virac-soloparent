/* Synthetic UI only: no authenticated sessions, database writes or SMS. */
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const assert = require('node:assert/strict');
const html = state => execFileSync('php',[path.join(__dirname,'fixtures/appointment-scheduling.php'),state],{encoding:'utf8'});
(async()=>{
  const browser = await chromium.launch({channel:'chrome',headless:true});
  try {
    const page = await browser.newPage();
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.setContent(html('available'));
    await page.locator('#interview-slot').selectOption('10');
    assert.equal(await page.locator('#interview-time').inputValue(),'09:00');
    assert.match(await page.locator('#automatic-sms-preview').textContent(),/9:00 AM/);
    await page.locator('#sms-message-type').selectOption('custom');
    await page.locator('#custom-sms-message').fill('My custom interview message.');
    await page.locator('#interview-slot').selectOption('11');
    assert.equal(await page.locator('#interview-time').inputValue(),'10:00');
    assert.equal(await page.locator('#custom-sms-message').inputValue(),'My custom interview message.');
    await page.locator('#sms-message-type').selectOption('automatic');
    for(const width of [1440,768,390,320]){
      await page.setViewportSize({width,height:1000});
      assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
    }
    await page.setViewportSize({width:1100,height:900});
    await page.screenshot({path:path.join(__dirname,'../storage/appointment-scheduling-demo.png'),fullPage:true});
    await page.setContent(html('error'));
    assert.equal(await page.locator('#sms-message-type').inputValue(),'custom');
    await page.locator('#interview-slot').selectOption('10');
    assert.equal(await page.locator('#custom-sms-message').inputValue(),'Preserve this draft after validation.');
    await page.setContent(html('empty'));
    assert(await page.locator('button[type=submit]').isDisabled());
    assert(await page.locator('.info-box').filter({hasText:'No interview slots are available.'}).isVisible());
    assert.match(await page.getByRole('link',{name:'Manage Slots'}).getAttribute('href'),/application_id=1/);
    assert.deepEqual(errors,[]);
    console.log('PASS: slot selection, derived SMS preview, custom-draft retention, no-slots guidance and 320–1440px layouts. No form submitted.');
  } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1});
