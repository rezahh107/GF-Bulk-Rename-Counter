/* Authentic server / GFAPI race falsification, two stages, no production source edits. */
'use strict';
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const {chromium}=require('playwright-core');
(async()=>{
  const lab=process.env.BRC_LAB_DIR, wp=process.env.BRC_WP_PATH,
    cli=process.env.BRC_WP_CLI,base=process.env.BRC_BASE_URL,
    gate=path.join(lab,'all-existing-race');
  function worker(action,kind='reference'){
    const stdout=cp.execFileSync('php',[cli,'--path='+wp,'--user=bootstrap_admin',
      'eval-file','tests/qualification/all-entry-file-race.php'],{
      encoding:'utf8',env:{...process.env,BRC_ALL_RACE_ACTION:action,BRC_ALL_RACE_KIND:kind},
      timeout:45000});
    const line=stdout.split(/\r?\n/).find(x=>x.startsWith('GFBRC_ALL_RACE '));
    if(!line)throw Error('Authentic WP worker result unavailable '+stdout.slice(-1000));
    return JSON.parse(line.slice('GFBRC_ALL_RACE '.length));
  }
  const fixture=worker('setup').fixture;
  const cookieLines=fs.readFileSync(path.join(lab,'auth.cookies'),'utf8').split(/\r?\n/);
  const cookies=cookieLines.filter(x=>x&&!x.startsWith('#')).map(line=>{
    const p=line.split(/\s+/);return {
      domain:p[0],path:p[2],name:p[5],value:p[6],
      secure:p[3]==='TRUE',expires:Number(p[4]),httpOnly:true};
  });
  const browser=await chromium.launch({executablePath:process.env.BRC_CHROME,headless:true,
    args:['--no-sandbox','--disable-dev-shm-usage']});
  const result={fixture,cases:{}};
  function cleanGate(){for(const s of ['.arm','.ready','.release','.zip-path'])
    fs.rmSync(gate+s,{force:true});}
  try {
    const context=await browser.newContext({acceptDownloads:true});
    await context.addCookies(cookies);
    const page=await context.newPage();
    const url=base+'/wp-admin/admin-post.php',ajax=base+'/wp-admin/admin-ajax.php';
    async function preview(form,count){
      await page.goto(base+'/wp-admin/admin.php?page=gf_entries&id='+form,
        {waitUntil:'domcontentloaded',timeout:25000});
      await page.locator('#gfbrc-all-prepare').waitFor();
      const cfg=await page.locator('#gfbrc-all-config').evaluate(
        el=>JSON.parse(el.textContent));
      const res=await page.request.post(ajax,{form:{
        action:'gfbrc_all_prepare',form_id:String(form),_wpnonce:cfg.nonce
      },timeout:60000});
      const data=await res.json();
      if(res.status()!==200||!data.success||data.data.entries!==1||
        data.data.files!==count||data.data.zero_entries!==0)
        throw Error('Unexpected real Check ALL result '+JSON.stringify(data));
      return {cfg, data:data.data};
    }
    async function awaitBarrier(stage){
      const until=Date.now()+25000;
      while(!fs.existsSync(gate+'.ready')){
        if(Date.now()>until)throw Error('Never reached '+stage+' barrier');
        await new Promise(resolve=>setTimeout(resolve,25));
      }
      if(fs.readFileSync(gate+'.ready','utf8')!==stage)
        throw Error('Wrong barrier '+fs.readFileSync(gate+'.ready','utf8'));
    }
    async function runCase(kind,stage,mutate=true){
      const cfg=fixture.cases[kind],before=worker('inspect',kind),
        oldCount=kind==='reference'?1:kind==='deletion'?2:4,
        newCount=kind==='deletion'?1:oldCount;
      if(before.files!==oldCount||before.entry!==cfg.entry)
        throw Error('Invalid initial GF file state '+JSON.stringify(before));
      const initial=await preview(cfg.form,oldCount);
      const post={action:'gfbrc_flat_zip_download',form_id:String(cfg.form),
        _wpnonce:initial.cfg.nonce,selection_mode:'all_form',
        all_token:initial.data.token,all_seal:initial.data.seal};
      cleanGate();fs.writeFileSync(gate+'.arm',stage);
      const pending=page.request.post(url,{form:post,timeout:90000});
      let finalResult;
      try{
        await awaitBarrier(stage);
        const zipPath=stage==='postwrite'?fs.readFileSync(gate+'.zip-path','utf8'):null;
        if(stage==='postwrite'&&(!zipPath||!fs.existsSync(zipPath)))
          throw Error('Verified ZIP is absent at intended postwrite barrier');
        const after=mutate?worker('mutate',kind):worker('inspect',kind);
        if(after.files!==newCount||after.entry!==cfg.entry||
          !after.a_readable||!after.b_readable||
          (mutate&&after.plan_sha256[0]!==fixture.b.sha256))
          throw Error('Mutation/control invalid '+JSON.stringify(after));
        fs.writeFileSync(gate+'.release','go');
        const res=await pending,body=await res.body();
        const failed=mutate;
        if(failed){
          if(res.status()!==422||
             (res.headers()['content-type']||'').includes('application/zip')||
             res.headers()['x-gfbrc-all']||
             res.headers()['content-disposition']||
             !body.toString('utf8').includes('ALL Entries or eligible source files changed before ZIP delivery'))
            throw Error('Stale download NOT rejected before headers: '+res.status()+
              ' '+JSON.stringify(res.headers())+' '+body.toString('utf8').slice(-650));
          if(zipPath&&fs.existsSync(zipPath))
            throw Error('Temporary verified ZIP not removed after stale-state rejection');
        }else{
          if(res.status()!==200||
             !(res.headers()['content-type']||'').includes('application/zip')||
             res.headers()['x-gfbrc-all']!=='1')
            throw Error('Unmodified ALL unexpectedly failed '+res.status());
          fs.writeFileSync(path.join(lab,'race-positive.zip'),body);
          if(zipPath&&fs.existsSync(zipPath))
            throw Error('Temporary ZIP not cleaned after normal delivery');
        }
        const label=kind+'-'+stage+(mutate?'-changed':'-unchanged');
        finalResult={before,after,stage,status:res.status(),zipPath,
          preheaders:failed ? 'no ZIP headers': 'verified ZIP',mutate};
        result.cases[label]=finalResult;
        console.log(failed?'PASS_STALE_MANIFEST_REJECTED_PREHEADERS':'PASS_UNCHANGED_ALL_ZIP',
          kind,stage,'HTTP',res.status(),'entry',cfg.entry,'before',oldCount,
          'current',after.files,'temporary_removed',!zipPath||!fs.existsSync(zipPath));
      }finally{
        fs.writeFileSync(gate+'.release','emergency release');
        cleanGate();
      }
      if(mutate){
        // A fresh uncontended ALL must now succeed and contain authoritative B.
        const p=await preview(cfg.form,newCount);
        const res=await page.request.post(url,{form:{
          ...post,_wpnonce:p.cfg.nonce,all_token:p.data.token,all_seal:p.data.seal
        },timeout:60000});
        const body=await res.body();
        if(res.status()!==200||
           !(res.headers()['content-type']||'').includes('application/zip'))
          throw Error('Updated state did not download normally '+kind+' '+res.status());
        const filename='race-'+kind+'-'+stage+'-fresh.zip';
        fs.writeFileSync(path.join(lab,filename),body);
        finalResult.fresh={status:res.status(),filename,
          expectedNewCount:newCount};
        console.log('PASS_FRESH_AUTHORITATIVE_ZIP',kind,stage,'HTTP',res.status());
      }
    }
    // Each mutation is independent; run postwrite first only after new case
    // fixtures exist. Recreate fresh entry state between stages with separate
    // GF forms: the original A->B mutation is not reversible without explicit
    // GFAPI write-back. Use independent setup cases from the fixture.
    for(const [kind,stage] of [['reference','prewrite'],
                               ['deletion','postwrite'],
                               ['repeated','postwrite']]){
      await runCase(kind,stage,true);
    }
    // Positive control on a separately initialized unchanged form state:
    await runCase('reference','postwrite',false);
    fs.writeFileSync(path.join(lab,'all-race-repair-results.json'),
      JSON.stringify(result,null,2));
    cp.execFileSync('python3',
      [path.join(__dirname,'all-race-repair-assert.py'),lab],
      {stdio:'inherit',timeout:15000});
  }finally{await browser.close();}
})().catch(e=>{console.error('ALL_RACE_REPAIR_FAILED',e.stack||String(e));process.exit(1);});
