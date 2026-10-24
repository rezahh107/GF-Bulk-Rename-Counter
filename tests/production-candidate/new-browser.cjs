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
  const form = meta.cases.global154.form_id;
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
    async function pending(expectEntries,expectFiles) {
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
      if(data.entries!==expectEntries||data.files!==expectFiles)throw Error(
        'Wrong NEW count '+JSON.stringify(data)+' expected '+expectEntries+'/'+expectFiles);
      await page.locator('#gfbrc-new-result').filter({
        hasText:data.op ? String(expectEntries)+' Entries / '+expectFiles+' eligible files'
          : 'Nothing to acknowledge'
      }).waitFor();
      return data;
    }
    async function receive(label) {
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
    const initial=await pending(154,155);
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
    const inc=await pending(2,1);
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
    const noNew = await pending(0,0);
    if (noNew.op || await page.locator('#gfbrc-new-ack').isVisible()) {
      throw Error('Empty NEW batch demanded unnecessary reapproval');
    }
    if(mutate('read_coverage').state.rev!==2)throw Error('Empty check changed coverage');
    mutate('add_zero');
    await open();
    await pending(1,0);
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

    fs.writeFileSync(path.join(lab,'new-browser-summary.json'),JSON.stringify({
      initial:{entries:154,files:155,sha:firstZip.sha,bytes:firstZip.bytes},
      incremental:{entries:2,files:1,sha:incZip.sha,bytes:incZip.bytes},
      backdated_new_entry:fresh.id,old_reactivated_entry:old.id,zero_file_entry:zero.id,
      acknowledgment:'real authenticated browser requests; synthetic operator clicked receipt checkbox'
    },null,2));
    cp.execFileSync('python3',[path.join(__dirname,'new-assert.py'),lab],
      {stdio:'inherit',timeout:30000});
  } finally { await browser.close(); }
})().catch(e=>{console.error('NEW_BROWSER_FAILURE',e.stack||String(e));process.exit(1);});
