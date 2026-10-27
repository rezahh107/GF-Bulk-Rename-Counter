/* Genuine WP/GF/Chromium error-presentation regression.
 * Transport faults are browser-injected; ACK post-commit loss forwards the
 * actual request to the server before withholding its response.
 */
'use strict';
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const crypto=require('node:crypto');
const {chromium}=require('playwright-core');
(async()=>{
  const lab=process.env.BRC_LAB_DIR,base=process.env.BRC_BASE_URL;
  const wp=process.env.BRC_WP_PATH,wpcli=process.env.BRC_WP_CLI,locale=process.env.BRC_EXPECT_LOCALE;
  if(!['fa_IR','en_US'].includes(locale))throw Error('Expected locale missing');
  const cookies=fs.readFileSync(path.join(lab,'auth.cookies'),'utf8').split(/\r?\n/)
    .filter(s=>s&&!s.startsWith('#')).map(s=>{
      const t=s.split(/\s+/);
      return {domain:t[0],path:t[2],name:t[5],value:t[6],
        secure:t[3]==='TRUE',expires:Number(t[4]),httpOnly:true};
    });
  function mutate(action){
    const raw=cp.execFileSync('php',[wpcli,'--path='+wp,'--user=bootstrap_admin',
      'eval-file','tests/production-candidate/new-mutate.php'],{
      env:{...process.env,BRC_NEW_ACTION:action},encoding:'utf8',timeout:45000});
    const line=raw.split(/\r?\n/).find(s=>s.startsWith('GFBRC_NEW_MUTATION '));
    if(!line)throw Error('Missing GF mutation '+raw.slice(-400));
    return JSON.parse(line.slice('GFBRC_NEW_MUTATION '.length));
  }
  const form=mutate('setup').form;
  mutate('mark_old_inactive');
  const browser=await chromium.launch({executablePath:process.env.BRC_CHROME,headless:true,
    args:['--no-sandbox','--disable-dev-shm-usage']});
  try {
    const ctx=await browser.newContext({acceptDownloads:true});
    await ctx.addCookies(cookies);
    const page=await ctx.newPage();
    async function open(){
      await page.goto(base+'/wp-admin/admin.php?page=gf_entries&id='+form,
        {waitUntil:'domcontentloaded',timeout:25000});
      await page.locator('#gfbrc-new-prepare').waitFor();
      const lang=await page.evaluate(()=>document.documentElement.lang);
      if(!lang.toLowerCase().startsWith(locale==='fa_IR'?'fa':'en'))
        throw Error('Real WP locale mismatch '+lang);
    }
    const cfg=async(name)=>page.locator('#gfbrc-'+name+'-config')
      .evaluate(el=>JSON.parse(el.textContent));
    const txt=async(name)=>page.locator('#gfbrc-'+name+'-result').innerText();
    async function captureError(name){
      if(locale!=='fa_IR')return;
      const dir=path.join(lab,'reports','ui-polish');
      fs.mkdirSync(dir,{recursive:true});
      try{
        await page.locator('#gfbrc-'+name+'-panel').screenshot({
          path:path.join(dir,'fa-'+name+'-error-375.png'),animations:'disabled',timeout:12000
        });
      }catch(error){console.warn('GFBRC_VISUAL_CAPTURE_NOT_PROVEN',name,String(error.message).slice(0,200));}
    }
    async function equals(name,expected){
      await page.locator('#gfbrc-'+name+'-result').filter({hasText:expected}).waitFor({timeout:12000});
      const actual=await txt(name);
      if(actual!==expected||/Failed to fetch|Unexpected token|TypeError|SyntaxError|DOMException/.test(actual)||
        /%(?:\d+\$)?[sd]/.test(actual))throw Error('Raw exception or mismatch '+JSON.stringify({name,actual,expected}));
      return actual;
    }
    async function intercept(action,kind,mutator){
      let matched=0,payload=null;
      const handler=async route=>{
        const s=route.request().postData()||'';
        if(!s.includes('action='+action)){await route.continue();return;}
        matched++;
        if(kind==='abort'){await route.abort('failed');return;}
        if(kind==='html'){await route.fulfill({status:200,contentType:'text/html',
          body:'<html>Unexpected token x</html>'});return;}
        if(kind==='nonce'){
          const body=new URLSearchParams(s);body.set('_wpnonce','bad-nonce');
          const response=await route.fetch({postData:body.toString()});
          payload=await response.json();
          await route.fulfill({response});return;
        }
        if(kind==='commit-lost'){
          const response=await route.fetch();
          payload=await response.json();
          await route.abort('failed');return;
        }
        throw Error('Unknown interception');
      };
      await page.route('**/admin-ajax.php',handler);
      try {
        await mutator();
        // The previous ACK failure can leave the same status text on screen.
        // Keep the route alive until the genuine server request finishes and
        // the browser has handled the withheld response.
        if(kind==='commit-lost') {
          await page.waitForFunction(()=>
            !document.getElementById('gfbrc-new-ack').disabled, null, {timeout:20000});
        }
      } finally {await page.unroute('**/admin-ajax.php',handler);}
      if(matched!==1)throw Error('Expected one actual request '+action+'/'+kind+' got '+matched);
      return payload;
    }
    const checkNoState=(before,reason)=>{
      const actual=mutate('read_coverage').state.rev;
      if(actual!==before)throw Error(reason+' mutated coverage: '+before+' -> '+actual);
    };
    await open();
    const ncfg=await cfg('new'),acfg=await cfg('all');
    if(!ncfg.text.unexpected||!ncfg.text.ack_uncertain||!acfg.text.unexpected)
      throw Error('Installed gettext failure messages missing');
    // Seed coverage with a genuine read-only NEW check before fault injection.
    const seedWait=page.waitForResponse(r=>r.url().includes('admin-ajax.php')&&
      r.request().postData()?.includes('action=gfbrc_new_prepare'));
    await page.locator('#gfbrc-new-prepare').click();
    const seed=(await (await seedWait).json()).data;
    if(!seed.op||seed.files<1)throw Error('Coverage baseline not seeded by genuine NEW check');
    const start=mutate('read_coverage').state.rev;
    if(start!==0)throw Error('NEW check unexpectedly advanced initial revision');
    await intercept('gfbrc_new_prepare','abort',async()=>{
      await page.locator('#gfbrc-new-prepare').click();
      await equals('new',ncfg.text.unexpected);
    });checkNoState(start,'NEW prepare network');
    console.log('PASS_REAL_'+locale+'_NEW_PREPARE_NETWORK',JSON.stringify(await txt('new')));
    await intercept('gfbrc_new_prepare','html',async()=>{
      await page.locator('#gfbrc-new-prepare').click();
      await equals('new',ncfg.text.failure.replace('%s',ncfg.text.invalid));
    });checkNoState(start,'NEW malformed JSON');
    const nerr=await intercept('gfbrc_new_prepare','nonce',async()=>{
      await page.locator('#gfbrc-new-prepare').click();
      await page.locator('#gfbrc-new-result').filter({hasText:'NEW'}).waitFor();
      // Wait for request-driven result, not the initial "checking" state.
      await page.waitForFunction(()=>{
        const cfg=JSON.parse(document.getElementById('gfbrc-new-config').textContent);
        return document.getElementById('gfbrc-new-result').textContent!==cfg.text.checking;
      });
    });
    if(nerr?.success!==false||typeof nerr.data?.message!=='string')throw Error('Actual NEW server error missing');
    await equals('new',ncfg.text.failure.replace('%s',nerr.data.message));
    checkNoState(start,'NEW valid server error');
    await captureError('new');
    console.log('PASS_REAL_'+locale+'_NEW_VALID_SERVER_ERROR',JSON.stringify(await txt('new')));
    const arun=async()=>{await page.locator('#gfbrc-all-prepare').click();};
    await intercept('gfbrc_all_prepare','abort',async()=>{
      await arun();await equals('all',acfg.text.unexpected);
    });
    console.log('PASS_REAL_'+locale+'_ALL_PREPARE_NETWORK',JSON.stringify(await txt('all')));
    await intercept('gfbrc_all_prepare','html',async()=>{
      await arun();await equals('all',acfg.text.unexpected);
    });
    const aerr=await intercept('gfbrc_all_prepare','nonce',async()=>{
      await arun();
      await page.waitForFunction(()=>{
        const cfg=JSON.parse(document.getElementById('gfbrc-all-config').textContent);
        return document.getElementById('gfbrc-all-result').textContent!==cfg.text.checking;
      });
    });
    if(aerr?.success!==false||typeof aerr.data?.message!=='string')throw Error('Actual ALL server error missing');
    await equals('all',acfg.text.failed.replace('%s',aerr.data.message));
    checkNoState(start,'ALL errors');
    await captureError('all');
    console.log('PASS_REAL_'+locale+'_ALL_NETWORK_MALFORMED_AND_VALID_SERVER_ERROR',
      JSON.stringify({network:acfg.text.unexpected,server:await txt('all')}));
    // Prepare authentic batch; bad network on download must NOT manufacture ZIP/ACK.
    const prepResponse=page.waitForResponse(r=>r.url().includes('admin-ajax.php')&&
      r.request().postData()?.includes('action=gfbrc_new_prepare'));
    await page.locator('#gfbrc-new-prepare').click();
    const prepared=(await (await prepResponse).json()).data;
    if(!prepared.op||prepared.files<1)throw Error('Genuine NEW upload batch unavailable');
    await intercept('gfbrc_new_download','abort',async()=>{
      await page.locator('#gfbrc-new-download').click();
      await equals('new',ncfg.text.unexpected);
    });
    checkNoState(start,'NEW ZIP transport');
    if(await page.locator('#gfbrc-new-ack').isVisible())throw Error('ACK visible after failed download');
    console.log('PASS_REAL_'+locale+'_NEW_DOWNLOAD_NETWORK',JSON.stringify(await txt('new')));
    const rDownload=page.waitForResponse(r=>r.url().includes('admin-ajax.php')&&
      r.request().postData()?.includes('action=gfbrc_new_download'));
    const [dl]=await Promise.all([page.waitForEvent('download'),
      page.locator('#gfbrc-new-download').click()]);
    const received=await rDownload;
    const dest=path.join(lab,'error-presentation-'+locale+'.zip');
    await dl.saveAs(dest);
    const sha=crypto.createHash('sha256').update(fs.readFileSync(dest)).digest('hex');
    if(received.headers()['x-gfbrc-zip-sha256']!==sha)throw Error('Downloaded ZIP hash mismatch');
    await page.locator('#gfbrc-new-result').filter({hasText:ncfg.text.zip_ok.split('%1$s')[0]}).waitFor();
    // Loss before server: no transaction, but browser must NOT claim unchanged Coverage.
    await page.locator('#gfbrc-new-review').check();
    await intercept('gfbrc_new_ack','abort',async()=>{
      await page.locator('#gfbrc-new-ack').click();
      await equals('new',ncfg.text.ack_uncertain);
    });
    checkNoState(start,'ACK precommit lost');
    console.log('PASS_REAL_'+locale+'_ACK_PRECOMMIT_RESPONSE_LOST',JSON.stringify({text:await txt('new'),from:start,to:mutate('read_coverage').state.rev}));
    // Loss after genuine server COMMIT: only response is dropped.
    const committed=await intercept('gfbrc_new_ack','commit-lost',async()=>{
      await page.locator('#gfbrc-new-ack').click();
      await equals('new',ncfg.text.ack_uncertain);
    });
    const after=mutate('read_coverage').state.rev;
    if(committed?.success!==true||committed.data?.result!=='acknowledged'||after!==start+1)
      throw Error('Genuine postcommit ACK state not durable '+JSON.stringify({committed,start,after}));
    console.log('PASS_REAL_'+locale+'_ACK_POSTCOMMIT_RESPONSE_LOST',
      JSON.stringify({text:await txt('new'),from:start,to:after,serverResult:committed.data.result}));
    await open();
    const retryPromise=page.waitForResponse(r=>r.url().includes('admin-ajax.php')&&
      r.request().postData()?.includes('action=gfbrc_new_prepare'));
    await page.locator('#gfbrc-new-prepare').click();
    const next=(await (await retryPromise).json()).data;
    if(next.op||next.entries!==0||mutate('read_coverage').state.rev!==after)
      throw Error('Reload/check NEW did not reflect committed ACK');
    console.log('PASS_REAL_'+locale+'_ACK_UNCERTAIN_RELOAD_READ_ONLY');
  } finally {await browser.close();}
})().catch(e=>{console.error('GFBRC_ERROR_PRESENTATION_FAILURE',e.stack||e);process.exit(1)});
