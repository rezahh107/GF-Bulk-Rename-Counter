/* Native Chromium checks: wp-admin discovery, read-only guidance and roles. */
'use strict';
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright-core');
(async()=>{
  const lab=process.env.BRC_LAB_DIR,base=process.env.BRC_BASE_URL;
  const data=JSON.parse(fs.readFileSync(path.join(lab,'per-form.json'),'utf8'));
  const phase=process.env.BRC_UI_PHASE||'initial';
  function cookieJar(file) {
    return fs.readFileSync(path.join(lab,file),'utf8').split(/\r?\n/)
      .filter(line=>line&&!line.startsWith('#')).map(line=>{
        const p=line.split(/\s+/);return {name:p[5],value:p[6],domain:p[0],
          path:p[2],secure:p[3]==='TRUE',expires:Number(p[4]),httpOnly:true};
      });
  }
  const browser=await chromium.launch({executablePath:process.env.BRC_CHROME,headless:true,
    args:['--no-sandbox','--disable-dev-shm-usage']});
  try {
    const admin=await browser.newContext({acceptDownloads:true,viewport:{width:1440,height:900}});
    await admin.addCookies(cookieJar('auth.cookies'));
    const page=await admin.newPage();
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    const settings=id=>base+'/wp-admin/options-general.php?page=gf-bulk-rename-settings&gfbrc_form='+id;
    const entries=id=>base+'/wp-admin/admin.php?page=gf_entries&id='+id;
    async function open(url) {
      const resp=await page.goto(url,{waitUntil:'domcontentloaded'});
      if(resp.status()!==200)throw Error('Expected wp-admin HTTP 200: '+url+' got '+resp.status());
    }
    async function state(formId,expected) {
      await open(entries(formId));
      const box=page.locator('#gfbrc-entries-guide');
      if(await box.count()!==1) throw Error('Entries guide missing');
      if(await box.locator('.gfbrc-rule-status').getAttribute('data-gfbrc-status')!==expected)
        throw Error('Wrong effective naming status, expected '+expected);
      if(!await page.locator('#gfbrc-new-prepare').count()||
         !await page.locator('#gfbrc-all-prepare').count()||
         !await page.locator('select[name="action"] option[value="gfbrc_independent_flat_zip"]').count())
        throw Error('Native Selected/NEW/ALL controls missing');
      if(!await box.locator('details summary').count())throw Error('Choice guide missing');
      await box.locator('summary').click();
      for(const phrase of ['Selected:', 'All matching:', 'NEW:', 'ALL:']) {
        if(!(await box.textContent()).includes(phrase))throw Error('Workflow explanation missing '+phrase);
      }
      const adminLink=box.locator('a[href*="gf-bulk-rename-settings"]');
      if(await adminLink.count()!==1)throw Error('Admin Entries correction link missing');
    }
    if(phase==='initial'){
      await open(base+'/wp-admin/plugins.php');
      if(!await page.locator('a[href*="options-general.php?page=gf-bulk-rename-settings"]').count())
        throw Error('Native Plugins settings shortcut missing');
      await open(settings(data.a.form_id));
      if(!await page.locator('form[action="options.php"]').count()||
         !(await page.locator('.gfbrc-rule-status').innerText()).includes('Legacy'))
        throw Error('Initial settings orientation absent');
      if(!await page.getByText('Show field labels and IDs for this form').count())
        throw Error('Global counter field labels missing');
      await state(data.a.form_id,'ready');
      // Real options.php save: empty Legacy Counter deliberately preserves original names.
      await open(settings(data.a.form_id));
      const counter=page.locator('#gf_bulk_rename_counter_field_id');
      await counter.fill('0');
      await Promise.all([
        page.waitForURL(/settings-updated=true/,{timeout:20000}),
        page.locator('form[action="options.php"]').first().locator('input[type="submit"]').click()
      ]);
      await state(data.a.form_id,'unchanged');
      if(!(await page.locator('#gfbrc-entries-guide').innerText()).includes('Original filenames'))
        throw Error('Intentionally unchanged naming is not explained');
      // Restore the exact Legacy fixture before any ZIP qualification.
      await open(settings(data.a.form_id));
      await page.locator('#gf_bulk_rename_counter_field_id').fill('5');
      await Promise.all([
        page.waitForURL(/settings-updated=true/,{timeout:20000}),
        page.locator('form[action="options.php"]').first().locator('input[type="submit"]').click()
      ]);
      await state(data.a.form_id,'ready');
      // Real GF operator: read-only guidance is present, admin settings inaccessible.
      const operator=await browser.newContext({viewport:{width:1280,height:800}});
      await operator.addCookies(cookieJar('operator.cookies'));
      const opPage=await operator.newPage();
      const result=await opPage.goto(entries(data.a.form_id),{waitUntil:'domcontentloaded'});
      if(result.status()!==200 || !await opPage.locator('#gfbrc-entries-guide').count())
        throw Error('Operator cannot reach read-only download help');
      if(await opPage.locator('#gfbrc-entries-guide a[href*="gf-bulk-rename-settings"]').count())
        throw Error('Operator has forbidden configuration link');
      await opPage.goto(settings(data.a.form_id),{waitUntil:'domcontentloaded'});
      if(await opPage.locator('#gf_bulk_rename_counter_field_id').count())
        throw Error('Operator can edit protected settings');
      await operator.close();
      console.log('PASS_BROWSER_UI_INITIAL_LEGACY_CONFIG_OPERATOR_NAV');
    } else {
      await state(data.b.form_id,'ready');
      if(!(await page.locator('#gfbrc-entries-guide').innerText()).includes('Single Field'))
        throw Error('Single field effective status absent');
      await state(data.c.form_id,'ready');
      if(!(await page.locator('#gfbrc-entries-guide').innerText()).includes('Composite'))
        throw Error('Composite effective status absent');
      await open(settings(data.c.form_id));
      if(!(await page.locator('.gfbrc-rule-status').innerText()).includes('Number'))
        throw Error('Selected Composite field labels absent');
      await state(data.c.form_id,'ready');
      await page.setViewportSize({width:375,height:800});
      for(const direction of ['ltr','rtl']){
        await page.evaluate(dir=>{document.documentElement.dir=dir;},direction);
        const measure=await page.locator('#gfbrc-entries-guide').evaluate(el=>{
          const box=el.getBoundingClientRect();
          return {left:box.left,right:box.right,width:window.innerWidth};
        });
        if(measure.left < -5 || measure.right > measure.width + 5)
          throw Error('Narrow '+direction+' guide clipped '+JSON.stringify(measure));
      }
      await page.locator('#gfbrc-new-prepare').focus();
      if(!await page.locator('#gfbrc-new-prepare').evaluate(el=>document.activeElement===el))
        throw Error('Keyboard focus cannot reach NEW control');
      console.log('PASS_BROWSER_UI_CONFIGURED_SINGLE_COMPOSITE_NARROW_RTL_FOCUS');
    }
    if(errors.length)throw Error('Admin page JS errors: '+errors.join('; '));
    await admin.close();
  } finally {await browser.close();}
})().catch(e=>{console.error('GFBRC_UI_BROWSING_FAILED',e.stack||e);process.exit(1)});
