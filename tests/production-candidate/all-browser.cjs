/* Authentic Entries → ALL scope preview → server ZIP → ZIP hashes.
 * Independent of current filters, row checks and NEW history.
 */
'use strict';
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const {chromium}=require('playwright-core');
(async()=>{
  const lab=process.env.BRC_LAB_DIR, base=process.env.BRC_BASE_URL;
  const wp=process.env.BRC_WP_PATH, cli=process.env.BRC_WP_CLI;
  function mutate(action) {
    const out=cp.execFileSync('php',[cli,'--path='+wp,'--user=bootstrap_admin',
      'eval-file','tests/production-candidate/all-fixture.php'],{
        env:{...process.env,BRC_ALL_ACTION:action},encoding:'utf8',timeout:90000
      });
    const line=out.split(/\r?\n/).find(x=>x.startsWith('GFBRC_ALL_FIXTURE '));
    if(!line)throw Error('ALL fixture setup failed '+out);
    return JSON.parse(line.slice('GFBRC_ALL_FIXTURE '.length));
  }
  const fixture=mutate('setup');
  const form=fixture.form;
  if(fixture.entries!==503||fixture.files!==504)throw Error('Wrong large-form fixture');
  const cookies=fs.readFileSync(path.join(lab,'auth.cookies'),'utf8').split(/\r?\n/)
    .filter(x=>x && !x.startsWith('#')).map(x=>{
      const t=x.split(/\s+/);
      return {domain:t[0],path:t[2],name:t[5],value:t[6],
        secure:t[3]==='TRUE',expires:Number(t[4]),httpOnly:true};
    });
  const browser=await chromium.launch({executablePath:process.env.BRC_CHROME,
    headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
  try {
    const ctx=await browser.newContext({acceptDownloads:true});
    await ctx.addCookies(cookies);
    const page=await ctx.newPage();
    page.on('dialog',d=>d.accept());
    page.on('pageerror',e=>console.error('ALL_BROWSER_PAGEERROR',e.message));
    const adminPost=base+'/wp-admin/admin-post.php';
    const ajax=base+'/wp-admin/admin-ajax.php';
    async function open(id,suffix='') {
      await page.goto(base+'/wp-admin/admin.php?page=gf_entries&id='+id+suffix,{
        waitUntil:'domcontentloaded',timeout:25000});
      await page.locator('#gfbrc-all-prepare').waitFor();
    }
    async function check(entries,files,zero) {
      const response=page.waitForResponse(r=>r.url().includes('admin-ajax.php') &&
        r.request().postData()?.includes('action=gfbrc_all_prepare'),{timeout:60000});
      await page.locator('#gfbrc-all-prepare').click();
      const r=await response, raw=await r.text();
      let obj;try{obj=JSON.parse(raw);}catch(_){throw Error('ALL preview non-JSON '+raw.slice(0,500));}
      if(!r.ok()||!obj.success)throw Error('ALL preview failed '+JSON.stringify(obj));
      if(obj.data.entries!==entries||obj.data.files!==files||obj.data.zero_entries!==zero) {
        throw Error('Wrong ALL preview '+JSON.stringify(obj.data));
      }
      await page.locator('#gfbrc-all-result').filter({
        hasText:entries+' active Entries / '+files+' eligible files / '+zero+' Entries without files'
      }).waitFor();
      return obj.data;
    }
    async function receive(label,entries,files,zero) {
      const [download]=await Promise.all([
        page.waitForEvent('download',{timeout:90000}),
        page.locator('#gfbrc-all-download').click()
      ]);
      if(download.suggestedFilename()!=='gfbrc-all-'+currentForm+'-'+entries+
        '-entries-'+files+'-files.zip')throw Error('Unexpected ALL ZIP name');
      const dest=path.join(lab,'all-'+label+'.zip');
      await download.saveAs(dest);
      const bytes=fs.readFileSync(dest);
      if(bytes[0]!==0x50||bytes[1]!==0x4b)throw Error('Invalid downloaded ZIP');
      console.log('PASS_REAL_ALL_BROWSER_ZIP',label,entries,files,zero,bytes.length);
      return dest;
    }
    let currentForm=form;
    // True GF native filtered Entry List and checkbox state must be irrelevant.
    await open(form,'&field_id=5&s=group-a&operator=is');
    const visible=page.locator('input[type="checkbox"][name="entry[]"]');
    const shown=await visible.count();
    if(shown<1||shown>=250)throw Error('Expected native filtered pagination');
    await visible.first().check();
    const preview=await check(503,504,1);
    if(!preview.token||!preview.seal)throw Error('Signed ALL preview missing');
    await receive('large-filtered',503,504,1);
    // Same ALL may be repeated without persistent history or state.
    await check(503,504,1);
    await receive('large-repeat',503,504,1);
    console.log('PASS_ALL_IGNORES_FILTER_SELECTION_PAGINATION_AND_REPEAT');

    // A preview is NOT authority for a stale membership. Membership is
    // recomputed from the form, and old token cannot download a partial ZIP.
    mutate('add_member');
    const stale=await page.request.post(adminPost,{form:{
      action:'gfbrc_flat_zip_download',form_id:String(form),
      _wpnonce:(await page.locator('#gfbrc-all-config')
        .evaluate(el=>JSON.parse(el.textContent))).nonce,
      selection_mode:'all_form',all_token:preview.token,all_seal:preview.seal
    }});
    if(stale.status()!==422 || (stale.headers()['content-type']||'').includes('application/zip') ||
      !(await stale.text()).includes('changed')) {
      throw Error('Stale membership preview yielded ZIP/status '+stale.status());
    }
    await check(504,505,1);
    console.log('PASS_ALL_CHANGED_MEMBERSHIP_REJECTS_STALE_PREVIEW');

    // No user-supplied IDs or filtered mode may narrow an ALL request.
    const cfg=await page.locator('#gfbrc-all-config').evaluate(el=>JSON.parse(el.textContent));
    const fresh=await check(504,505,1);
    const basePost={action:'gfbrc_flat_zip_download',form_id:String(form),
      _wpnonce:cfg.nonce,selection_mode:'all_form',
      all_token:fresh.token,all_seal:fresh.seal};
    for(const extra of [
      {'entry_ids[]':'1'}, {selection_scope:'{}'}, {_wpnonce:'forged'},
      {all_seal:'forged'}
    ]) {
      const bad=await page.request.post(adminPost,{form:{...basePost,...extra}});
      if(bad.status()<400 || (bad.headers()['content-type']||'').includes('application/zip')) {
        throw Error('Invalid ALL form scope admitted '+JSON.stringify(extra));
      }
    }
    const anonymous=await browser.newContext();
    const denied=await anonymous.request.post(ajax,{form:{
      action:'gfbrc_all_prepare',form_id:String(form),_wpnonce:cfg.nonce
    }});
    if(denied.status()===200)throw Error('Anonymous ALL preview admitted');
    await anonymous.close();
    console.log('PASS_ALL_FORGED_NONCE_SCOPE_ID_AND_ANONYMOUS_REJECTED');

    // No eligible files must not produce a misleading ZIP.
    const original=JSON.parse(fs.readFileSync(path.join(lab,'independent.json'),'utf8'));
    const empty=original.cases.global_empty.form_id;
    currentForm=empty;
    await open(empty);
    await check(3,0,3);
    if(await page.locator('#gfbrc-all-download').isVisible())throw Error('Empty ZIP offered');
    console.log('PASS_ALL_ZERO_FILE_ENTRIES_WITHOUT_EMPTY_ZIP');

    currentForm=form;
    mutate('add_missing');
    await open(form);
    const failedResponse=page.waitForResponse(r=>r.url().includes('admin-ajax.php') &&
      r.request().postData()?.includes('action=gfbrc_all_prepare'),{timeout:90000});
    await page.locator('#gfbrc-all-prepare').click();
    const failure=await failedResponse;
    const error=await failure.json();
    if(failure.status()!==422||error.success||
      await page.locator('#gfbrc-all-download').isVisible()) {
      throw Error('Missing source not rejected on whole-form preparation');
    }
    console.log('PASS_ALL_MISSING_SOURCE_FAILS_BEFORE_DOWNLOAD');

    cp.execFileSync('python3',[path.join(__dirname,'all-assert.py'),lab],{
      stdio:'inherit',timeout:90000});
  } finally {await browser.close();}
})().catch(e=>{console.error('ALL_BROWSER_FAILURE',e.stack||e);process.exit(1);});
