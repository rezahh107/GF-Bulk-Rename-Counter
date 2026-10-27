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
      // Assert final placement after the real native Entry List action toolbar.
      const placement=await box.evaluate(el=>{
        const apply=document.getElementById('doaction');
        const anchor=apply&&(apply.closest('.tablenav')||apply);
        const workspace=document.getElementById('gfbrc-entries-workspace');
        return {present:!!anchor,adjacent:!!anchor&&!!workspace&&
            anchor.nextElementSibling===workspace&&workspace.firstElementChild===el,
          visible:el.getClientRects().length>0};
      });
      if(placement.present&&!placement.adjacent)
        throw Error('Guide not adjacent to native Entries actions: '+JSON.stringify(placement));
      if(!placement.visible)throw Error('Guide hidden when native anchor absent');
      if(!placement.present)console.log('OBSERVED_ENTRY_ANCHOR_ABSENT_GUIDE_REMAINS_VISIBLE');
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
    async function polishGeometry(url,width) {
      await page.setViewportSize({width,height:900});
      await open(url);
      const state=await page.evaluate(()=>{
        const ids=['gfbrc-entries-guide','gfbrc-new-panel','gfbrc-all-panel'];
        return ids.map(id=>{
          const el=document.getElementById(id);
          if(!el)return {id,missing:true};
          const r=el.getBoundingClientRect(),h=el.querySelector('.gfbrc-panel-header');
          return {id,left:r.left,right:r.right,top:r.top,bottom:r.bottom,
            width:r.width,heading:h?!!h.querySelector('h2'):true,
            direction:getComputedStyle(el).direction};
        });
      });
      if(state.some(x=>x.missing||x.left< -5||x.right>width+5||x.width<=0))
        throw Error('GFBRC responsive geometry overflow '+width+' '+JSON.stringify(state));
      if(state.slice(1).some(x=>!x.heading))
        throw Error('NEW/ALL heading semantics missing');
      if(width>=1024&&Math.abs(state[1].top-state[2].top)>4)
        throw Error('NEW/ALL top edges diverge: '+JSON.stringify(state));
      return state;
    }
    function captureEnabled(){return !!process.env.BRC_LAB_DIR;}
    async function capture(name){
      if(!captureEnabled())return;
      const dir=path.join(process.env.BRC_LAB_DIR,'reports','ui-polish');
      fs.mkdirSync(dir,{recursive:true});
      await page.screenshot({path:path.join(dir,name+'.png'),fullPage:true});
    }
    if(phase==='initial'){
      await open(base+'/wp-admin/plugins.php');
      if(!await page.locator('a[href*="options-general.php?page=gf-bulk-rename-settings"]').count())
        throw Error('Native Plugins settings shortcut missing');
      await open(settings(data.a.form_id));
      if(!await page.locator('form[action="options.php"]').count()||
         !(await page.locator('.gfbrc-rule-status').innerText()).includes('Legacy'))
        throw Error('Initial settings orientation absent');
      if(!await page.locator('summary').filter({hasText:'Show Global Legacy field labels and IDs for'}).count())
        throw Error('Global Legacy preview form identity missing');
      // Real Settings API mutation: Global Legacy targets A, but navigation starts at B.
      await page.locator('#gf_bulk_rename_form_id').selectOption(String(data.a.form_id));
      await Promise.all([
        page.waitForURL(/settings-updated=true/,{timeout:20000}),
        page.locator('form[action="options.php"]').first().locator('input[type="submit"]').click()
      ]);
      await open(entries(data.b.form_id));
      const toolbar=page.getByRole('link',{name:'File naming settings'});
      if(await toolbar.count()!==1||!await toolbar.isVisible())
        throw Error('Native GF form toolbar naming link not visibly rendered');
      if(new URL(await toolbar.getAttribute('href'),base).searchParams.get('gfbrc_form')!==
          String(data.b.form_id))
        throw Error('Unreachable toolbar form target');
      await Promise.all([
        page.waitForURL(url=>url.pathname.endsWith('/options-general.php')&&
          url.searchParams.get('gfbrc_form')===String(data.b.form_id),{timeout:20000}),
        toolbar.click()
      ]);
      if(await page.locator('#gfbrc-form-select').inputValue()!==String(data.b.form_id))
        throw Error('Toolbar did not open independent configuration for B');
      const preview=page.locator('summary').filter({hasText:'Show Global Legacy field labels and IDs for'});
      const actualTitle=(await page.locator('#gf_bulk_rename_form_id option:checked').textContent()).trim();
      const savedForm=await page.locator('#gf_bulk_rename_form_id').inputValue();
      const previewText=await preview.count()?await preview.first().innerText():'[missing]';
      const expectedId='form ID '+data.a.form_id;
      if(savedForm!==String(data.a.form_id)||
        !previewText.includes(expectedId)||
        !previewText.includes(actualTitle.replace(/ \(ID: .*\)$/,'')))
        throw Error('Legacy A fields misattributed: '+JSON.stringify({
          savedForm,expectedForm:data.a.form_id,actualTitle,previewText,expectedId
        }));
      if(await page.locator('#gf_bulk_rename_counter_field_id').inputValue()!=='5')
        throw Error('Legacy Counter unexpectedly changed while navigating B');
      console.log('PASS_BROWSER_GLOBAL_LEGACY_A_VIA_B_TOOLBAR_FORM_ID_AND_SCOPE');
      // Restore the exact baseline before inherited Legacy and ZIP checks.
      await page.locator('#gf_bulk_rename_form_id').selectOption('0');
      await Promise.all([
        page.waitForURL(/settings-updated=true/,{timeout:20000}),
        page.locator('form[action="options.php"]').first().locator('input[type="submit"]').click()
      ]);
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
      if(await opPage.getByRole('link',{name:'File naming settings'}).count())
        throw Error('Operator sees forbidden GF toolbar navigation');
      if(await opPage.locator('#gfbrc-entries-guide a[href*="gf-bulk-rename-settings"]').count())
        throw Error('Operator has forbidden configuration link');
      await opPage.goto(settings(data.a.form_id),{waitUntil:'domcontentloaded'});
      if(await opPage.locator('#gf_bulk_rename_counter_field_id').count())
        throw Error('Operator can edit protected settings');
      await operator.close();
      await polishGeometry(entries(data.a.form_id),375);
      await polishGeometry(entries(data.a.form_id),1440);
      console.log('PASS_BROWSER_UI_INITIAL_LEGACY_CONFIG_OPERATOR_NAV');
    } else if(phase==='fa_ir'){
      await open(entries(data.a.form_id));
      const locale=await page.evaluate(()=>({
        lang:document.documentElement.lang,dir:document.documentElement.dir,
        bodyDirection:getComputedStyle(document.body).direction,
        guideDirection:getComputedStyle(document.getElementById('gfbrc-entries-guide')).direction
      }));
      if(locale.lang.toLowerCase()!=='fa-ir'||locale.dir!=='rtl'||
        locale.guideDirection!=='rtl')
        throw Error('Actual WordPress fa_IR or GFBRC guide RTL not active: '+JSON.stringify(locale));
      // The host may assign its own body direction; the GFBRC panel itself
      // must remain RTL. Do not impose styling on WordPress/Gravity Forms.
      console.log('OBSERVED_REAL_FA_IR_HOST_AND_GUIDE_DIRECTIONS',JSON.stringify(locale));
      await page.setViewportSize({width:375,height:800});
      const guide=page.locator('#gfbrc-entries-guide');
      const pos=await guide.evaluate(el=>{const b=el.getBoundingClientRect();
        return {left:b.left,right:b.right,viewport:innerWidth};});
      if(pos.left < -5||pos.right>pos.viewport+5)
        throw Error('Real fa_IR narrow admin guide clipped: '+JSON.stringify(pos));
      const summary=guide.locator('details summary');
      await summary.focus();
      if(!await summary.evaluate(el=>document.activeElement===el))
        throw Error('fa_IR guide summary not keyboard focusable');
      await summary.press('Enter');
      if(!await guide.locator('details').evaluate(el=>el.open))
        throw Error('fa_IR guide cannot open with Enter key');
      await page.locator('#gfbrc-new-prepare').focus();
      if(!await page.locator('#gfbrc-new-prepare').evaluate(el=>document.activeElement===el))
        throw Error('fa_IR NEW control not keyboard focusable');
      console.log('PASS_REAL_FA_IR_WORDPRESS_RTL_NARROW_GUIDE_AND_KEYBOARD');
      for(const width of [320,375,390,412,768,1024,1440]){
        await polishGeometry(entries(data.a.form_id),width);
        if(width===375||width===1440)await capture('fa-entries-'+width);
      }
      // Actual installed gettext output, not simulated RTL or a POT-only fallback.
      const heading=await page.locator('#gfbrc-guide-title').innerText();
      if(heading!=='دانلود فایل‌ها و نام‌گذاری')
        throw Error('Installed fa_IR Entries heading missing: '+heading);
      const guideText=await guide.innerText();
      if(!guideText.includes('از کدام روش دانلود استفاده کنم؟')||
         !guideText.includes('ورودی‌های فعال'))
        throw Error('Persian Entries guide and ALL scope missing');
      const newText=JSON.parse(await page.locator('#gfbrc-new-config').textContent()).text;
      const allText=JSON.parse(await page.locator('#gfbrc-all-config').textContent()).text;
      if(newText.ack!=='تأیید دریافت و ثبت در سابقهٔ NEW'||
         !newText.guide.includes('ورودی‌های این نوبت در دانلودهای بعدی NEW دوباره نمایش داده نمی‌شوند')||
         !newText.guide.includes('این اقدام برگشت‌ناپذیر است')||
         !newText.guide.includes('ذخیره‌شدن ZIP روی دستگاه خود')||
         !newText.guide.includes('ورودی‌های بدون فایل را صریحاً بازبینی کنید')||
         !newText.zip_ok.includes('ZIP ذخیره‌شده'))
        throw Error('Persian NEW receipt acknowledgment semantics missing: '+JSON.stringify(newText));
      if(allText.check!=='بررسی ALL'||!allText.guide.includes('سابقهٔ پوشش NEW را تغییر نمی‌دهد')||
         !allText.confirm.includes('تأیید دریافت NEW تغییر نمی‌کند'))
        throw Error('Persian ALL independence semantics missing: '+JSON.stringify(allText));
      const renderedAck=await page.locator('#gfbrc-new-ack').textContent();
      const renderedWarning=await page.locator('#gfbrc-new-guide').innerText();
      if(renderedAck!=='تأیید دریافت و ثبت در سابقهٔ NEW'||
         !renderedWarning.includes('با تأیید این عملیات')||
         !renderedWarning.includes('این اقدام برگشت‌ناپذیر است')||
         !(await page.locator('#gfbrc-new-review-wrap').textContent()).includes('من ZIP را ذخیره'))
        throw Error('Rendered fa_IR ACK button, warning or receipt attestation missing');
      const warningPrecedesAck=await page.locator('#gfbrc-new-guide').evaluate(el=>
        !!(el.compareDocumentPosition(document.getElementById('gfbrc-new-ack'))&
           Node.DOCUMENT_POSITION_FOLLOWING));
      if(!warningPrecedesAck) throw Error('NEW receipt warning is not before ACK control');
      if(!guideText.includes('ZIP بدون پوشه‌بندی'))
        throw Error('Owner-approved flat ZIP terminology missing from Entries guide');
      await open(settings(data.a.form_id));
      if(!(await page.locator('.wrap h1').innerText()).includes('تنظیمات Gravity Forms Bulk Rename')||
         !(await page.locator('.wrap').innerText()).includes('شناسهٔ فیلد شمارنده')||
         !(await page.locator('.wrap').innerText()).includes('d[COUNTER].extension'))
        throw Error('Persian Settings labels or literal file naming pattern missing');
      const bdi=await page.locator('details bdi[dir="ltr"]').first().textContent();
      if(!bdi.includes('ID:')||!/[0-9]/.test(bdi))
        throw Error('Mixed RTL / technical field ID isolation missing: '+bdi);
      const naming=page.locator('#gfbrc-method');
      const original=await naming.inputValue();
      await naming.selectOption('single');
      if(!await page.locator('[data-gfbrc-for="single"]').first().isVisible() ||
        await page.locator('[data-gfbrc-for="composite"]').first().isVisible())
        throw Error('Single method UI visibility invalid');
      await naming.selectOption('composite');
      if(!await page.locator('[data-gfbrc-for="composite"]').first().isVisible() ||
        await page.locator('[data-gfbrc-for="single"]').first().isVisible())
        throw Error('Composite method UI visibility invalid');
      if(await page.locator('#gfbrc-single').isDisabled()||
         await page.locator('#gfbrc-separator').isDisabled())
        throw Error('Method presentation disabled Settings API controls');
      for(const width of [320,375,390,412,768,1024,1440]){
        await page.setViewportSize({width,height:900});
        const a=await page.locator('.gfbrc-settings-card').evaluateAll(nodes=>
          nodes.map(x=>{const r=x.getBoundingClientRect();
            return {left:r.left,right:r.right,width:r.width,viewport:innerWidth};}));
        if(a.some(x=>x.left< -5||x.right>x.viewport+5))
          throw Error('Settings cards clipped at '+width+': '+JSON.stringify(a));
        if(width===375||width===1440)await capture('fa-settings-composite-'+width);
      }
      await naming.selectOption(original);
      await open(settings(data.a.form_id));
      if(await page.locator('#gfbrc-method').inputValue()!==original)
        throw Error('Switching methods without saving persisted changes');
      console.log('PASS_REAL_FA_IR_SETTINGS_ENTRIES_NEW_ACK_ALL_MIXED_BIDI');
      console.log('PASS_REAL_FA_IR_POLISH_RESPONSIVE_AND_METHOD_VISIBILITY');
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
