// Test-only: real public form submission using Playwright.
const {chromium} = require('playwright-core');
const fs = require('node:fs');
const path = require('node:path');
async function run() {
  const lab = process.env.BRC_LAB_DIR;
  const data = JSON.parse(fs.readFileSync(path.join(lab, 'entry-order-qualifier.json'), 'utf8'));
  const browser = await chromium.launch({executablePath: process.env.BRC_CHROME, headless:true,
    args:['--no-sandbox', '--disable-dev-shm-usage']});
  try {
    const page = await browser.newPage();
    await page.goto(data.page_url, {waitUntil:'domcontentloaded'});
    const form = page.locator('#gform_' + data.form_id);
    await form.locator('input[name="input_1"]').fill('genuine-public-browser');
    await form.locator('#gform_submit_button_' + data.form_id).click();
    await page.locator('#gform_confirmation_message_' + data.form_id +
      ', #gform_confirmation_wrapper_' + data.form_id).first()
      .waitFor({state:'visible',timeout:30000});
    process.stdout.write('PASS_REAL_PUBLIC_BROWSER_FORM_SUBMISSION\n');
  } finally {
    await browser.close();
  }
}
run().catch(e=>{console.error('PUBLIC_FORM_ORDER_BROWSER_FAILURE',e);process.exitCode=1;});
