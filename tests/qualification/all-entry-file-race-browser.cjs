/* Deterministic authenticated HTTP race against exact PR #28 runtime semantics.
 * No source/plugin/persistent production mutation outside disposable GF fixtures.
 */
'use strict';
const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process');
const {chromium} = require('playwright-core');

(async()=>{
  const lab=process.env.BRC_LAB_DIR, wp=process.env.BRC_WP_PATH,
        cli=process.env.BRC_WP_CLI, base=process.env.BRC_BASE_URL;
  const gate=path.join(lab,'all-existing-race');
  function worker(action, kind='reference') {
    const txt=cp.execFileSync('php',[cli,'--path='+wp,'--user=bootstrap_admin',
      'eval-file','tests/qualification/all-entry-file-race.php'],{
      encoding:'utf8',env:{...process.env,BRC_ALL_RACE_ACTION:action,
        BRC_ALL_RACE_KIND:kind},timeout:45000
    });
    const line=txt.split(/\r?\n/).find(x=>x.startsWith('GFBRC_ALL_RACE '));
    if(!line)throw Error('Missing authentic WP probe result: '+txt.slice(-1000));
    return JSON.parse(line.substring('GFBRC_ALL_RACE '.length));
  }
  const initial=worker('setup');
  const manifest=initial.fixture;
  const cookies=fs.readFileSync(path.join(lab,'auth.cookies'),'utf8').split(/\r?\n/)
    .filter(s=>s&&!s.startsWith('#')).map(line=>{
      const t=line.split(/\s+/);
      return {domain:t[0],path:t[2],name:t[5],value:t[6],
        secure:t[3]==='TRUE',expires:Number(t[4]),httpOnly:true};
    });
  const browser=await chromium.launch({
    executablePath:process.env.BRC_CHROME,headless:true,
    args:['--no-sandbox','--disable-dev-shm-usage']});
  const record={fixture:manifest, cases:{}};
  try {
    const context=await browser.newContext({acceptDownloads:true});
    await context.addCookies(cookies);
    const page=await context.newPage();
    const url=base+'/wp-admin/admin-post.php';
    const ajax=base+'/wp-admin/admin-ajax.php';
    async function preview(form, expectedFiles) {
      await page.goto(base+'/wp-admin/admin.php?page=gf_entries&id='+form,
        {waitUntil:'domcontentloaded',timeout:25000});
      await page.locator('#gfbrc-all-prepare').waitFor();
      const cfg=await page.locator('#gfbrc-all-config').evaluate(
        e=>JSON.parse(e.textContent));
      const r=await page.request.post(ajax,{form:{
        action:'gfbrc_all_prepare',form_id:String(form),_wpnonce:cfg.nonce
      },timeout:60000});
      const payload=await r.json();
      if(r.status()!==200||!payload.success||
         payload.data.entries!==1||payload.data.files!==expectedFiles||
         payload.data.zero_entries!==0) {
        throw Error('Unexpected ALL manifest preview '+JSON.stringify(payload));
      }
      return {cfg, data:payload.data};
    }
    async function awaitBarrier() {
      const deadline=Date.now()+25000;
      while(!fs.existsSync(gate+'.ready')) {
        if(Date.now()>deadline)throw Error('HTTP request never crossed validated_download boundary');
        await new Promise(resolve=>setTimeout(resolve,35));
      }
      const marker=fs.readFileSync(gate+'.ready','utf8');
      if(marker!=='after_validated_manifest_before_writer')
        throw Error('Unexpected barrier stage '+marker);
    }
    for(const kind of ['reference','deletion']) {
      const cfg=manifest.cases[kind];
      const before=worker('inspect',kind);
      const expectedOld=kind==='reference'?1:2;
      const expectedNew=1;
      if(before.files!==expectedOld||before.entry!==cfg.entry)
        throw Error('Invalid pre-mutation source plan '+JSON.stringify(before));
      const first=await preview(cfg.form,expectedOld);
      const payload={action:'gfbrc_flat_zip_download',form_id:String(cfg.form),
        _wpnonce:first.cfg.nonce,selection_mode:'all_form',
        all_token:first.data.token,all_seal:first.data.seal};
      for(const suffix of ['.arm','.ready','.release']) {
        try {fs.unlinkSync(gate+suffix);}catch(e){if(e.code!=='ENOENT')throw e;}
      }
      fs.writeFileSync(gate+'.arm',kind);
      const pending=page.request.post(url,{form:payload,timeout:90000});
      let response;
      try {
        await awaitBarrier();
        const after=worker('mutate',kind);
        if(after.files!==expectedNew||after.entry!==cfg.entry||
          !after.a_readable||!after.b_readable ||
          after.plan_sha256[0]!==manifest.b.sha256)
          throw Error('Mutation not authoritative or sources lost: '+JSON.stringify(after));
        // The actual server is paused after the validated source plan; the
        // source A remains readable while GF now authoritatively points to B.
        fs.writeFileSync(gate+'.release','continue');
        response=await pending;
        const zip=path.join(lab,'race-'+kind+'-old-plan.zip');
        fs.writeFileSync(zip,await response.body());
        if(response.status()!==200||
           !(response.headers()['content-type']||'').includes('application/zip')||
           response.headers()['x-gfbrc-all']!=='1') {
          throw Error('Race did not deliver ordinary successful ALL ZIP: '+
            response.status()+' '+JSON.stringify(response.headers()));
        }
        // Control: a fresh preview after the mutation must use B and respect
        // the updated deletion metadata, demonstrating current authority.
        for(const suffix of ['.arm','.ready','.release'])fs.rmSync(gate+suffix,{force:true});
        const fresh=await preview(cfg.form,expectedNew);
        const clean=await page.request.post(url,{form:{
          ...payload, _wpnonce:fresh.cfg.nonce,
          all_token:fresh.data.token,all_seal:fresh.data.seal
        },timeout:60000});
        const controlFile=path.join(lab,'race-'+kind+'-fresh.zip');
        fs.writeFileSync(controlFile,await clean.body());
        if(clean.status()!==200 ||
           !(clean.headers()['content-type']||'').includes('application/zip'))
          throw Error('Fresh current-state ALL failed '+kind+': HTTP '+clean.status());
        record.cases[kind]={before,after,stale:{
            status:response.status(),headers:response.headers(),
            filename:path.basename(zip)},
          fresh:{status:clean.status(),headers:clean.headers(),filename:path.basename(controlFile)},
          note:'Exact same active Entry ID; changed file/deletion metadata after validated manifest',
        };
        console.log('REAL_ALL_RACE_HTTP_SUCCESS_AFTER_SOURCE_MUTATION',
          kind,'expected_old_files',expectedOld,
          'current_files',after.files,'HTTP',response.status());
      } finally {
        fs.writeFileSync(gate+'.release','unblock');
        for(const suffix of ['.arm','.ready','.release'])fs.rmSync(gate+suffix,{force:true});
      }
    }
    fs.writeFileSync(path.join(lab,'all-existing-race-result.json'),
      JSON.stringify(record,null,2));
    cp.execFileSync('python3',
      [path.join(__dirname,'all-entry-file-race-assert.py'),lab],
      {stdio:'inherit',timeout:15000});
  } finally {await browser.close();}
})().catch(e=>{console.error('ALL_ENTRY_FILE_RACE_FAILURE',e.stack||String(e));process.exit(1);});
