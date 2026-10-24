/* Authentic Chromium NEW workflow: Entry List → AJAX snapshot → ZIP →
 * browser save → human-style separate confirmation → next incremental.
 * Browser saving is automated, not evidence that an actual human kept a file.
 */
'use strict';
const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process');
const crypto = require('node:crypto');
const {chromium} = require('playwright-core');
(async function () {
  const lab = process.env.BRC_LAB_DIR, base = process.env.BRC_BASE_URL;
  const wpcli = process.env.BRC_WP_CLI, wp = process.env.BRC_WP_PATH;
  const meta = JSON.parse(fs.readFileSync(path.join(lab,'independent.json'),'utf8'));
  let form = 0;
  const cookies = fs.readFileSync(path.join(lab,'auth.cookies'),'utf8').split(/\r?\n/)
    .filter(s=>s && !s.startsWith('#')).map(line=>{
      const t=line.split(/\s+/);
      return {domain:t[0],path:t[2],name:t[5],value:t[6],
        secure:t[3]==='TRUE',expires:Number(t[4]),httpOnly:true};
    });
  function mutate(action) {
    const res=cp.execFileSync('php',[wpcli,'--path='+wp,'--user=bootstrap_admin',
      'eval-file','tests/production-candidate/new-mutate.php'], {
      env:{...process.env,BRC_NEW_ACTION:action},encoding:'utf8',timeout:30000});
    const marker=res.split(/\r?\n/).find(s=>s.startsWith('GFBRC_NEW_MUTATION '));
    if(!marker)throw Error('Missing GF mutation: '+res.slice(-400));
    return JSON.parse(marker.replace('GFBRC_NEW_MUTATION ',''));
  }
  form=mutate('setup').form;
  if(!Number.isInteger(form)||form<=0)throw Error('Invalid isolated NEW form');
  const browser=await chromium.launch({executablePath:process.env.BRC_CHROME,headless:true,
    args:['--no-sandbox','--disable-dev-shm-usage']});
  try {
    const context=await browser.newContext({acceptDownloads:true});
    await context.addCookies(cookies);
    const page=await context.newPage();
    page.on('pageerror',e=>console.error('NEW_BROWSER_PAGEERROR',e.message));
    async function open() {
      await page.goto(base+'/wp-admin/admin.php?page=gf_entries&id='+form,{
        waitUntil:'domcontentloaded',timeout:25000});
      await page.locator('#gfbrc-new-prepare').waitFor({timeout:15000});
    }
    const config=async()=>page.locator('#gfbrc-new-config').evaluate(el=>JSON.parse(el.textContent));
    async function pending(expectEntries,expectFiles,expectZeroEntries) {
      const response=page.waitForResponse(r=>r.url().includes('admin-ajax.php') &&
        r.request().postData()?.includes('action=gfbrc_new_prepare'),{timeout:30000});
      await page.locator('#gfbrc-new-prepare').click();
      const result=await response;
      const raw=await result.text();
      let answer;
      try { answer=JSON.parse(raw); }
      catch(e) {
        throw Error('NEW prepare returned HTTP '+result.status()+
          ' type='+result.headers()['content-type']+
          ' unexpected='+raw.slice(0,900).replace(/\\s+/g,' '));
      }
      if(!answer.success)throw Error('Prepare rejected: '+JSON.stringify(answer));
      const data=answer.data;
      if(data.entries!==expectEntries||data.files!==expectFiles||
         (Number.isInteger(expectZeroEntries) && data.zero_entries!==expectZeroEntries))
        throw Error('Wrong NEW counts '+JSON.stringify(data)+' expected '+
          expectEntries+'/'+expectFiles+'/'+expectZeroEntries);
      await page.locator('#gfbrc-new-result').filter({
        hasText:data.op ? String(expectEntries)+' Entries / '+expectFiles+' eligible files'
          : 'Nothing to acknowledge'
      }).waitFor();
      return data;
    }
    async function receive(label) {
      const candidate=page.locator('#gfbrc-new-download');
      await candidate.scrollIntoViewIfNeeded();
      const hit=await candidate.evaluate(el=>{
        const r=el.getBoundingClientRect();
        const x=r.left+r.width/2,y=r.top+r.height/2;
        const above=document.elementFromPoint(x,y);
        const info=node=>node?{tag:node.tagName,id:node.id,cls:String(node.className).slice(0,100)}:null;
        return {button:info(el),atCenter:info(above),
          selfHit:above===el||el.contains(above),
          rect:{x:r.x,y:r.y,width:r.width,height:r.height},
          hidden:el.hidden,disabled:el.disabled,
          parent:info(el.parentElement),
          ancestors:[el.parentElement,el.parentElement?.parentElement].map(info)};
      });
      console.log('NEW_BROWSER_DOWNLOAD_HITTEST',JSON.stringify(hit));
      if(!hit.selfHit)throw Error('NEW download is obscured by native GF content: '+JSON.stringify(hit));
      const response=page.waitForResponse(r=>r.url().includes('admin-ajax.php') &&
        r.request().postData()?.includes('action=gfbrc_new_download'),{timeout:45000});
      const [download]=await Promise.all([
        page.waitForEvent('download',{timeout:45000}),
        page.locator('#gfbrc-new-download').click()
      ]);
      const received=await response;
      if(received.status()!==200)throw Error('ZIP response HTTP '+received.status());
      const dest=path.join(lab,'new-'+label+'.zip');
      await download.saveAs(dest);
      const body=fs.readFileSync(dest);
      const sha=crypto.createHash('sha256').update(body).digest('hex');
      const header=received.headers()['x-gfbrc-zip-sha256'];
      if(sha!==header||body[0]!==0x50||body[1]!==0x4b)throw Error('Browser downloaded mismatching ZIP');
      await page.locator('#gfbrc-new-result').filter({hasText:'ZIP received and SHA-256 matched'}).waitFor();
      return {dest,sha,bytes:body.length};
    }
    async function confirm() {
      await page.locator('#gfbrc-new-review').check();
      const response=page.waitForResponse(r=>r.url().includes('admin-ajax.php') &&
        r.request().postData()?.includes('action=gfbrc_new_ack'),{timeout:20000});
      await page.locator('#gfbrc-new-ack').click();
      const answer=await (await response).json();
      if(!answer.success||answer.data.result!=='acknowledged')
        throw Error('Operator acknowledgment rejected: '+JSON.stringify(answer));
      return answer.data;
    }
    const old=mutate('mark_old_inactive');
    await open();
    const initial=await pending(154,155,1);
    if(!initial.first)throw Error('First-use baseline advanced automatically');
    const baseline=mutate('read_coverage').state;
    if(!baseline||baseline.rev!==0||baseline.known.length!==0)
      throw Error('NEW state advanced before human confirmation');
    const firstZip=await receive('initial');
    if(mutate('read_coverage').state.rev!==0)throw Error('ZIP download automatically advanced NEW');
    await confirm();
    if(mutate('read_coverage').state.rev!==1)throw Error('Initial acknowledgment not durable');
    console.log('PASS_NEW_BROWSER_INITIAL_PENDING_EXPLICIT_ACK entries=154 files=155 sha='+firstZip.sha);

    mutate('restore_old_with_upload');
    const fresh=mutate('add_backdated'), zero=mutate('add_zero');
    if(!fresh.backdated||fresh.id<=old.id||zero.id<=fresh.id)throw Error('GF chronology fixture invalid');
    await open();
    const inc=await pending(2,1,1);
    const incZip=await receive('incremental');
    await confirm();
    if(mutate('read_coverage').state.rev!==2)throw Error('Second acknowledgment missing');
    console.log('PASS_NEW_BROWSER_BACKDATED_AND_OLD_REACTIVATION_DISCRIMINATED ids='+
      fresh.id+','+zero.id+' files=1');

    // Duplicate confirmation is genuinely HTTP-bound to the previously
    // acknowledged operation; it must not alter the revision.
    const cfg=await config(), nonce=cfg.nonce;
    const adminAjax=new URL('admin-ajax.php',page.url()).href;
    const dupe=await page.request.post(adminAjax,{
      form:{action:'gfbrc_new_ack',form_id:String(form),_wpnonce:nonce,
        operation:inc.op,confirmed:'yes',sha256:incZip.sha,zero_reviewed:'no'}});
    const duplicate=await dupe.json();
    if(!duplicate.success||duplicate.data.result!=='duplicate'||
       mutate('read_coverage').state.rev!==2)throw Error('Duplicate ACK modified coverage');
    console.log('PASS_NEW_BROWSER_DUPLICATE_OPERATION_ACK_IDEMPOTENT');

    await open();
    const noNew = await pending(0,0,0);
    if (noNew.op || await page.locator('#gfbrc-new-ack').isVisible()) {
      throw Error('Empty NEW batch demanded unnecessary reapproval');
    }
    if(mutate('read_coverage').state.rev!==2)throw Error('Empty check changed coverage');
    mutate('add_zero');
    await open();
    await pending(1,0,1);
    if(!await page.locator('#gfbrc-new-ack').isVisible()) {
      throw Error('File-free review control not visible');
    }
    // A file-free Entry is explicitly reviewed, NEVER a fictitious empty ZIP.
    await confirm();
    if(mutate('read_coverage').state.rev!==3)throw Error('File-free confirmation not persisted');
    console.log('PASS_NEW_BROWSER_ZERO_FILE_EXPLICIT_REVIEW_NO_FAKE_ZIP');

    // A new Entry remains pending despite browser ZIP receipt without ACK.
    mutate('add_backdated');
    await open();
    await pending(1,1);
    await receive('unacknowledged');
    const rev=mutate('read_coverage').state.rev;
    await open();
    await pending(1,1); // no confirmation => safe retry
    if(mutate('read_coverage').state.rev!==rev)throw Error('Unacknowledged ZIP changed coverage');
    await receive('retry');
    await confirm();
    console.log('PASS_NEW_BROWSER_UNACKNOWLEDGED_RETRY_NO_SILENT_SKIP');

    // Genuine late-commit counterexample: the lower allocated GF Entry ID
    // remains uncommitted while a higher Entry is downloaded and acknowledged.
    // Exact known identities, unlike max-ID frontiers, must recover it.
    const barrier=path.join(lab,'new-delayed-commit');
    for(const suffix of ['.ready','.go']) {
      try {fs.unlinkSync(barrier+suffix);} catch(e) {if(e.code!=='ENOENT')throw e;}
    }
    const child=cp.spawn('php',[wpcli,'--path='+wp,'--user=bootstrap_admin',
      'eval-file','tests/production-candidate/new-delayed-worker.php'],{
      env:{...process.env,BRC_NEW_BARRIER:barrier},stdio:['ignore','pipe','pipe']});
    let output='',errors='';
    child.stdout.on('data',b=>{output+=String(b);});
    child.stderr.on('data',b=>{errors+=String(b);});
    let lower;
    try {
      const deadline=Date.now()+30000;
      while(!fs.existsSync(barrier+'.ready')) {
        if(Date.now()>deadline||child.exitCode!==null)throw Error('Delayed GF writer failed: '+errors);
        await new Promise(resolve=>setTimeout(resolve,75));
      }
      lower=Number(fs.readFileSync(barrier+'.ready','utf8'));
      const higher=mutate('add_backdated').id;
      if(!(higher>lower))throw Error('Real delayed GF IDs not ordered as intended '+lower+'/'+higher);
      await open(); await pending(1,1);
      await receive('high-before-low'); await confirm();
      fs.writeFileSync(barrier+'.go','commit');
      await new Promise((resolve,reject)=>{
        if(child.exitCode!==null) return child.exitCode===0?resolve():reject(Error(errors));
        const timer=setTimeout(()=>{child.kill();reject(Error('Delayed writer exit timeout'));},15000);
        child.once('close',code=>{clearTimeout(timer);code===0?resolve():reject(Error(errors));});
      });
      if(!output.includes('PASS_REAL_GF_DELAYED_NEW_COMMIT'))throw Error('No real delayed commit oracle: '+output);
      await open(); await pending(1,1);
      await receive('late-lower-id'); await confirm();
      console.log('PASS_NEW_BROWSER_DELAYED_LOWER_ID_RESCUED lower='+lower+' higher='+higher);
    } finally {
      fs.writeFileSync(barrier+'.go','release');
      if(child.exitCode===null)child.kill();
    }

    // A separate new pending operation in another real browser tab invalidates
    // the original tab token; no stale confirmation may advance coverage.
    mutate('add_backdated');
    await open();
    await pending(1,1); await receive('stale-tab');
    const beforeRace=mutate('read_coverage').state.rev;
    const secondTab=await context.newPage();
    try {
      await secondTab.goto(base+'/wp-admin/admin.php?page=gf_entries&id='+form,
        {waitUntil:'domcontentloaded',timeout:25000});
      await secondTab.locator('#gfbrc-new-prepare').click();
      await secondTab.locator('#gfbrc-new-result').filter({hasText:'1 Entries / 1 eligible files'}).waitFor();
      await page.locator('#gfbrc-new-review').check();
      await page.locator('#gfbrc-new-ack').click();
      await page.locator('#gfbrc-new-result').filter({hasText:'expired or superseded'}).waitFor();
      if(mutate('read_coverage').state.rev!==beforeRace)throw Error('Stale tab advanced coverage');
      const [tabSaved]=await Promise.all([
        secondTab.waitForEvent('download',{timeout:45000}),
        secondTab.locator('#gfbrc-new-download').click()
      ]);
      await tabSaved.saveAs(path.join(lab,'new-race-winner.zip'));
      await secondTab.locator('#gfbrc-new-result').filter({hasText:'SHA-256 matched'}).waitFor();
      await secondTab.locator('#gfbrc-new-review').check();
      await secondTab.locator('#gfbrc-new-ack').click();
      await secondTab.locator('#gfbrc-new-result').filter({hasText:'NEW acknowledged'}).waitFor();
      if(mutate('read_coverage').state.rev!==beforeRace+1)throw Error('Fresh second tab failed to persist');
      console.log('PASS_NEW_BROWSER_TWO_TABS_STALE_REJECTED_FRESH_CONFIRMED');
    } finally {await secondTab.close();}

    // File-free eligibility may change between preview and confirmation.
    // An explicit zero-file acknowledgment must not permanently skip a newly
    // attached file that existed before the operator actually confirmed.
    mutate('add_zero');
    await open(); await pending(1,0,1);
    const preUploadRev=mutate('read_coverage').state.rev;
    mutate('attach_latest_zero');
    await page.locator('#gfbrc-new-review').check();
    await page.locator('#gfbrc-new-ack').click();
    await page.locator('#gfbrc-new-result').filter({hasText:'uploads changed before acknowledgment'}).waitFor();
    if(mutate('read_coverage').state.rev!==preUploadRev)throw Error('Mutated zero-file ACK skipped new upload');
    await open(); await pending(1,1);
    await receive('former-zero-with-file'); await confirm();
    console.log('PASS_NEW_BROWSER_ZERO_FILE_CHANGED_BEFORE_ACK_REJECTED_AND_RECOVERED');

    // Missing upload must fail visibly before ZIP and before any checkpoint.
    mutate('add_missing');
    await open();
    const revBeforeBad=mutate('read_coverage').state.rev;
    const failure=page.waitForResponse(r=>r.url().includes('admin-ajax.php') &&
      r.request().postData()?.includes('action=gfbrc_new_prepare'),{timeout:30000});
    await page.locator('#gfbrc-new-prepare').click();
    const bad=await failure;
    const badJson=await bad.json();
    if(bad.status()===200||badJson.success)throw Error('Missing upload silently admitted');
    if(mutate('read_coverage').state.rev!==revBeforeBad)throw Error('Missing upload advanced coverage');
    console.log('PASS_NEW_BROWSER_MISSING_SOURCE_REJECTED_NO_CHECKPOINT');

    fs.writeFileSync(path.join(lab,'new-browser-summary.json'),JSON.stringify({
      initial:{entries:154,files:155,sha:firstZip.sha,bytes:firstZip.bytes},
      incremental:{entries:2,files:1,sha:incZip.sha,bytes:incZip.bytes},
      backdated_new_entry:fresh.id,old_reactivated_entry:old.id,zero_file_entry:zero.id,
      acknowledgment:'real authenticated browser requests; synthetic operator clicked receipt checkbox'
    },null,2));
    cp.execFileSync('python3',[path.join(__dirname,'new-assert.py'),lab],
      {stdio:'inherit',timeout:30000});
    // Independent WP-CLI/PHP processes on the SAME authentic MariaDB exercise
    // precise stale-ACK, stale-ZIP, stale-prepare and overlapping ACK barriers.
    cp.execFileSync('python3',[path.join(__dirname,'new-generation-race.py')],
      {stdio:'inherit',env:process.env,timeout:240000});
  } finally { await browser.close(); }
})().catch(e=>{console.error('NEW_BROWSER_FAILURE',e.stack||String(e));process.exit(1);});
