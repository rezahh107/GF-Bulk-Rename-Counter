/* Exact-head authenticated WordPress/Gravity Forms NEW acknowledgment localization.
 * Duplicate presentation is fed by two REAL ACK POSTs on the same operation;
 * an unrelated synthetic unknown response is used only for fail-closed UI testing.
 */
'use strict';
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const crypto=require('node:crypto');
const {chromium}=require('playwright-core');
(async()=>{
  const lab=process.env.BRC_LAB_DIR,base=process.env.BRC_BASE_URL;
  const wp=process.env.BRC_WP_PATH,wpcli=process.env.BRC_WP_CLI;
  const locale=process.env.BRC_EXPECT_LOCALE;
  if(!['fa_IR','en_US'].includes(locale))throw Error('Missing expected real WordPress locale');
  const cookies=fs.readFileSync(path.join(lab,'auth.cookies'),'utf8').split(/\r?\n/)
    .filter(s=>s&&!s.startsWith('#')).map(s=>{
      const t=s.split(/\s+/);return {domain:t[0],path:t[2],name:t[5],value:t[6],
        secure:t[3]==='TRUE',expires:Number(t[4]),httpOnly:true};
    });
  function mutate(action){
    const stdout=cp.execFileSync('php',[wpcli,'--path='+wp,'--user=bootstrap_admin',
      'eval-file','tests/production-candidate/new-mutate.php'],{
        env:{...process.env,BRC_NEW_ACTION:action},encoding:'utf8',timeout:45000});
    const line=stdout.split(/\r?\n/).find(x=>x.startsWith('GFBRC_NEW_MUTATION '));
    if(!line)throw Error('GF fixture command failed: '+stdout.slice(-400));
    return JSON.parse(line.slice('GFBRC_NEW_MUTATION '.length));
  }
  const form=mutate('setup').form;
  mutate('mark_old_inactive');
  if(!Number.isInteger(form)||form<1)throw Error('NEW GF form not created');
  const browser=await chromium.launch({executablePath:process.env.BRC_CHROME,headless:true,
    args:['--no-sandbox','--disable-dev-shm-usage']});
  try{
    const context=await browser.newContext({acceptDownloads:true,viewport:{width:375,height:800}});
    await context.addCookies(cookies);
    const page=await context.newPage();
    const actionUrl=new URL('admin-ajax.php',base+'/wp-admin/admin.php').href;
    async function open(){
      await page.goto(base+'/wp-admin/admin.php?page=gf_entries&id='+form,
        {waitUntil:'domcontentloaded',timeout:25000});
      await page.locator('#gfbrc-new-prepare').waitFor();
      const lang=await page.evaluate(()=>document.documentElement.lang);
      if(!lang.toLowerCase().startsWith(locale==='fa_IR'?'fa':'en'))
        throw Error('Actual WordPress page locale mismatch '+lang);
    }
    async function prepare(){
      const promise=page.waitForResponse(r=>r.url().includes('admin-ajax.php')&&
        r.request().postData()?.includes('action=gfbrc_new_prepare'));
      await page.locator('#gfbrc-new-prepare').click();
      const json=await (await promise).json();
      if(!json.success||!json.data.op)throw Error('Actual GF NEW prepare failed '+JSON.stringify(json));
      return json.data;
    }
    async function receive(){
      const promise=page.waitForResponse(r=>r.url().includes('admin-ajax.php')&&
        r.request().postData()?.includes('action=gfbrc_new_download'));
      const [download]=await Promise.all([page.waitForEvent('download'),
        page.locator('#gfbrc-new-download').click()]);
      const response=await promise,save=path.join(lab,'new-locale-'+locale+'.zip');
      await download.saveAs(save);
      const bytes=fs.readFileSync(save);
      const hash=crypto.createHash('sha256').update(bytes).digest('hex');
      if(response.status()!==200||response.headers()['x-gfbrc-zip-sha256']!==hash)
        throw Error('Downloaded actual ZIP integrity mismatch');
      return hash;
    }
    const config=async()=>page.locator('#gfbrc-new-config').evaluate(el=>JSON.parse(el.textContent));
    const rendered=()=>page.locator('#gfbrc-new-result').innerText();
    function expected(text,count){return text.replace('%d',String(count));}
    async function confirm(expectedResult,count){
      await page.locator('#gfbrc-new-review').check();
      const wait=page.waitForResponse(r=>r.url().includes('admin-ajax.php')&&
        r.request().postData()?.includes('action=gfbrc_new_ack'));
      await page.locator('#gfbrc-new-ack').click();
      const json=await (await wait).json();
      if(!json.success||json.data.result!==expectedResult||json.data.covered!==count)
        throw Error('Actual ACK protocol mismatch '+JSON.stringify(json));
      return json.data;
    }
    await open();
    const configInitial=await config();
    if(locale==='fa_IR'&&(!configInitial.text.ack_recorded.includes('سابقهٔ پوشش NEW')||
       !configInitial.text.ack_duplicate.includes('دوباره تغییر نکرد')))
      throw Error('Installed Persian gettext messages missing');
    const initial=await prepare();
    if(initial.files<1||initial.entries<1)throw Error('No real initial GF file workload');
    await receive();
    const before=mutate('read_coverage').state.rev;
    await confirm('acknowledged',initial.entries);
    const after=mutate('read_coverage').state.rev;
    const firstText=await rendered();
    if(after!==before+1||firstText!==expected(configInitial.text.ack_recorded,initial.entries)||
       /\b(?:acknowledged|duplicate)\b/.test(firstText)||/%(?:\d+\$)?[ds]/.test(firstText))
      throw Error('First browser ACK localized status / revision wrong '+JSON.stringify({firstText,before,after}));
    console.log('PASS_REAL_'+locale+'_FIRST_ACK_ARIA_LIVE',JSON.stringify({text:firstText,before,after,entries:initial.entries}));
    // File-free ACK has no fabricated ZIP and must use the same localized message.
    mutate('add_zero');await open();
    const zero=await prepare();
    if(!zero.zero||zero.files!==0||zero.entries!==1||await page.locator('#gfbrc-new-download').isVisible())
      throw Error('File-free batch incorrectly offered ZIP');
    const zBefore=mutate('read_coverage').state.rev;
    await confirm('acknowledged',1);
    const zText=await rendered();
    if(mutate('read_coverage').state.rev!==zBefore+1||
       zText!==expected((await config()).text.ack_recorded,1))
      throw Error('Real zero-file ACK text or coverage failed '+zText);
    console.log('PASS_REAL_'+locale+'_ZERO_FILE_ACK_ARIA_LIVE',JSON.stringify({text:zText,from:zBefore,to:zBefore+1}));
    // Keep server protocol unchanged: deliver the second REAL server response
    // to the browser's first client-visible request, after a committed first ACK.
    mutate('add_zero');await open();const dupeOp=await prepare();
    if(!dupeOp.zero||dupeOp.entries!==1)throw Error('Duplicate zero-file operation missing');
    const dBefore=mutate('read_coverage').state.rev;
    let responses=[],postRevision=[];
    const handler=async route=>{
      if(!route.request().postData()?.includes('action=gfbrc_new_ack')){
        await route.continue();return;
      }
      for(let i=0;i<2;i++){
        const response=await route.fetch();
        const json=await response.json();
        responses.push(json);
        postRevision.push(mutate('read_coverage').state.rev);
        if(i===1)await route.fulfill({response});
      }
    };
    await page.route('**/admin-ajax.php',handler);
    await page.locator('#gfbrc-new-review').check();
    await page.locator('#gfbrc-new-ack').click();
    await page.locator('#gfbrc-new-result').filter({hasText:expected(configInitial.text.ack_duplicate,1)}).waitFor();
    await page.unroute('**/admin-ajax.php',handler);
    const dText=await rendered(),dAfter=mutate('read_coverage').state.rev;
    if(responses.length!==2||responses[0]?.data?.result!=='acknowledged'||
       responses[1]?.data?.result!=='duplicate'||postRevision[0]!==dBefore+1||
       postRevision[1]!==dBefore+1||dAfter!==dBefore+1||
       dText!==expected(configInitial.text.ack_duplicate,1)||
       /\b(?:acknowledged|duplicate)\b/.test(dText)||/%(?:\d+\$)?[ds]/.test(dText))
      throw Error('Real duplicate HTTP ACK/status/revision failure '+JSON.stringify({responses,postRevision,dText,dBefore,dAfter}));
    console.log('PASS_REAL_'+locale+'_DUPLICATE_ACK_ARIA_LIVE',JSON.stringify({text:dText,before:dBefore,revisions:postRevision,after:dAfter}));
    // Negative presentation-only mutation: no server ACK request goes through.
    // This does NOT stand in for the real duplicate test above.
    mutate('add_zero');await open();await prepare();
    const badBefore=mutate('read_coverage').state.rev;
    const unknown=async route=>{
      if(!route.request().postData()?.includes('action=gfbrc_new_ack')){
        await route.continue();return;
      }
      await route.fulfill({status:200,contentType:'application/json',
        body:JSON.stringify({success:true,data:{result:'unrecognized',covered:1}})});
    };
    await page.route('**/admin-ajax.php',unknown);
    await page.locator('#gfbrc-new-review').check();
    await page.locator('#gfbrc-new-ack').click();
    await page.locator('#gfbrc-new-result').filter({hasText:configInitial.text.invalid}).waitFor();
    await page.unroute('**/admin-ajax.php',unknown);
    if(mutate('read_coverage').state.rev!==badBefore||
       !(await page.locator('#gfbrc-new-ack').isVisible())||
       (await rendered()).includes(expected(configInitial.text.ack_recorded,1)))
      throw Error('Unknown protocol result displayed false success or mutated coverage');
    console.log('PASS_REAL_'+locale+'_UNKNOWN_RESULT_FAILS_CLOSED_NO_SERVER_ACK');
  } finally{await browser.close()}
})().catch(e=>{console.error('NEW_ACK_LOCALE_FAILURE',e.stack||e);process.exit(1)});
