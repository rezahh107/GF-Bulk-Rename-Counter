/* Qualification-only authentic Chromium display-to-independent-ZIP probe. */
'use strict';
const fs=require('node:fs'), path=require('node:path'), {chromium}=require('playwright-core');
(async()=>{
 const lab=process.env.BRC_LAB_DIR, base=process.env.BRC_BASE_URL;
 const m=JSON.parse(fs.readFileSync(path.join(lab,'hook-fixture.json'),'utf8'));
 const cookieLines=fs.readFileSync(path.join(lab,'auth.cookies'),'utf8')
   .split(/\r?\n/).filter(x=>x && !x.startsWith('#'));
 const cookies=cookieLines.map(x=>{
   const a=x.split(/\s+/);return {name:a[5],value:a[6],domain:a[0],path:a[2],
     secure:a[3]==='TRUE',expires:Number(a[4]),httpOnly:true};
 });
 const browser=await chromium.launch({executablePath:process.env.BRC_CHROME,
   headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try {
  const ctx=await browser.newContext({acceptDownloads:true});
  await ctx.addCookies(cookies);
  const page=await ctx.newPage();
  const dialogs=[],posts=[],errors=[];
  page.on('pageerror',e=>errors.push(String(e.message)));
  page.on('dialog', async d=>{
    dialogs.push({type:d.type(),text:d.message()});
    if(d.type()==='confirm') await d.accept(); else await d.dismiss();
  });
  page.on('request', req=>{
    if(!req.url().includes('/wp-admin/admin-post.php'))return;
    const b=new URLSearchParams(req.postData()||'');
    posts.push({method:req.method(),mode:b.get('selection_mode'),
      expected:b.get('expected_entry_count'),ids:b.getAll('entry_ids[]')});
  });
  await page.goto(base+'/wp-admin/admin.php?page=gf_entries&id='+m.form_id,
    {waitUntil:'domcontentloaded',timeout:30000});
  const displayed=await page.locator('input[type="checkbox"][name="entry[]"]')
    .evaluateAll(nodes=>nodes.map(n=>Number(n.value)).sort((a,b)=>a-b));
  const expected=m.included.slice().sort((a,b)=>a-b);
  if(JSON.stringify(displayed)!==JSON.stringify(expected))
    throw Error('Hooked native Entry List membership mismatch: '+JSON.stringify({displayed,expected}));
  const nativeCountText=await page.locator('.displaying-num').allTextContents();
  const config=await page.locator('#gfbrc-independent-flat-zip')
    .evaluate(e=>JSON.parse(e.dataset.config));
  if(!config || !config.scopeSeal)throw Error('GFBRC count/scope config not rendered');
  const beforeNative=await page.locator('#all_entries').inputValue();
  const header=page.locator('thead input[type="checkbox"]').first();
  let banner='not_reachable';
  if(await header.count()) {
    await header.check();
    const links=page.locator('a').filter({hasText:/select all\s+\d+|select all entries/i});
    if(await links.count()) {
      await links.first().click();
      banner=await page.locator('#all_entries').inputValue();
    } else {
      banner='not_reachable_no_native_banner_link';
    }
  } else {banner='not_reachable_no_header';}
  // Reset native page selections; the independent ALL toggle alone triggers global scope.
  if(await header.count()) await header.uncheck();
  const toggle=page.locator('#gfbrc-all-matching');
  await toggle.check();
  await page.locator('select[name="action"]').selectOption('gfbrc_independent_flat_zip');
  const [download]=await Promise.all([
    page.waitForEvent('download',{timeout:35000}),page.locator('#doaction').click(),
  ]);
  await download.saveAs(path.join(lab,'hook-browser.zip'));
  // WordPress/GF can emit unrelated page-script errors in the CI lab.
  // Record them without substituting their absence for download correctness.
  if(posts.length!==1 || posts[0].mode!=='all_matching' || posts[0].ids.length)
    throw Error('Not the genuine independent all-matching POST: '+JSON.stringify(posts));
  if(dialogs.length!==1 || dialogs[0].type!=='confirm')
    throw Error('Missing independent all-matching operator confirmation');
  const report={form_id:m.form_id,displayed_ids:displayed,native_count_text:nativeCountText,
    native_all_marker_initial:beforeNative,native_all_marker_after_banner_attempt:banner,
    rendered_matching_count:config.matchingCount,post:posts[0],
    confirmation:dialogs[0].text,download_name:download.suggestedFilename(),
    scope:config.scope,scope_seal:config.scopeSeal,nonce:config.nonce,
    page_error_count:errors.length,page_errors_sample:errors.slice(0,5)};
  fs.writeFileSync(path.join(lab,'hook-browser.json'),JSON.stringify(report,null,2));
  console.log('HOOK_BROWSER_DISPLAY_AND_DOWNLOAD '+JSON.stringify({
    displayed_ids:displayed,native_count_text:nativeCountText,
    native_all:banner,matchingCount:config.matchingCount,post:posts[0],
    confirmation:dialogs[0].text,filename:download.suggestedFilename()
  }));
  await ctx.close();
 } finally {await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exitCode=1;});
