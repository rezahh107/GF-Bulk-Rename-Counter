/* Real authenticated HTTP + constrained tmpfs: old 422, corrected 200, shortage 422. */
'use strict';
const fs=require('node:fs'), path=require('node:path'), cp=require('node:child_process');
const {chromium}=require('playwright-core');
(async()=>{
  const lab=process.env.BRC_LAB_DIR,base=process.env.BRC_BASE_URL;
  const cli=path.join(__dirname,'all-f02-real-disk.py');
  const fixture=JSON.parse(fs.readFileSync(path.join(lab,'all-race-fixture.json'),'utf8'));
  const benchmark=fixture.cases.benchmark;
  function disk(action, arg=''){
    return cp.execFileSync('python3',[cli,action,...(arg?[arg]:[])],{
      env:process.env,encoding:'utf8',timeout:45000}).trim();
  }
  function snap(){
    const cfg=JSON.parse(fs.readFileSync(path.join(lab,'f02-temp-dir.json'),'utf8'));
    return {folder:cfg.path,probePath:path.join(lab,'f02-verified-zip-state.json')};
  }
  function verifyBuild(label){
    const s=snap();
    if(!fs.existsSync(s.probePath))throw Error(label+': ZIP never reached verified-writer boundary');
    const record=JSON.parse(fs.readFileSync(s.probePath,'utf8'));
    if(!record.path.startsWith(s.folder+'/') || record.temp_dir!==s.folder+'/' ||
       record.size<fixture.benchmark.size || record.free<0)
      throw Error(label+': probe does not prove real tmpfs verified ZIP '+JSON.stringify(record));
    return record;
  }
  const cookieLines=fs.readFileSync(path.join(lab,'auth.cookies'),'utf8').split(/\r?\n/);
  const cookies=cookieLines.filter(x=>x&&!x.startsWith('#')).map(line=>{
    const p=line.split(/\s+/);return {
      domain:p[0],path:p[2],name:p[5],value:p[6],
      secure:p[3]==='TRUE',expires:Number(p[4]),httpOnly:true};
  });
  const browser=await chromium.launch({executablePath:process.env.BRC_CHROME,
    headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
  const evidence={head:process.env.GITHUB_SHA,benchmark_bytes:fixture.benchmark.size};
  try{
    const context=await browser.newContext({acceptDownloads:true});
    await context.addCookies(cookies);
    const page=await context.newPage();
    await page.goto(base+'/wp-admin/admin.php?page=gf_entries&id='+benchmark.form,
      {waitUntil:'domcontentloaded',timeout:25000});
    await page.locator('#gfbrc-all-prepare').waitFor();
    const cfg=await page.locator('#gfbrc-all-config').evaluate(el=>JSON.parse(el.textContent));
    async function preview(expectedStatus){
      const response=await page.request.post(base+'/wp-admin/admin-ajax.php',{form:{
        action:'gfbrc_all_prepare',form_id:String(benchmark.form),_wpnonce:cfg.nonce
      },timeout:90000});
      const body=await response.json();
      if(response.status()!==expectedStatus||body.success!==(expectedStatus===200))
        throw Error('Unexpected check ALL preflight '+response.status()+' '+JSON.stringify(body));
      if(expectedStatus===200&&(body.data.files!==1||body.data.entries!==1))
        throw Error('Unexpected complete form count '+JSON.stringify(body.data));
      return body;
    }
    async function download(token){
      const response=await page.request.post(base+'/wp-admin/admin-post.php',{form:{
        action:'gfbrc_flat_zip_download',form_id:String(benchmark.form),
        _wpnonce:cfg.nonce,selection_mode:'all_form',
        all_token:token.token,all_seal:token.seal
      },timeout:90000});
      return {status:response.status(),headers:response.headers(),body:await response.body()};
    }
    function assertNoZip(r,label,expectedText){
      if(r.status!==422 || /application\/zip/.test(r.headers['content-type']||'') ||
        r.headers['content-disposition'] || r.headers['x-gfbrc-all'] ||
        !r.body.toString('utf8').includes(expectedText))
        throw Error(label+' not a clean pre-header 422 '+r.status+' '+JSON.stringify(r.headers)+' '+
          r.body.toString('utf8').slice(-450));
    }
    disk('fill','28');
    disk('baseline');
    fs.rmSync(snap().probePath,{force:true});
    const oldPreview=await preview(200);
    const old=await download(oldPreview.data);
    console.log('F02_DIAGNOSTIC_BASELINE_REAL_RUNTIME', 'HTTP',old.status,
      'tmpfs_state',disk('status'),'php_source_baseline_installed',
      fs.readFileSync(path.join(process.env.BRC_WP_PATH,
        'wp-content/plugins/gf-bulk-rename-counter/includes/class-all_download.php'),'utf8')
          .includes('$actual = self::manifest($form);'));
    assertNoZip(old,'pre-F02 baseline','Insufficient temporary disk space for complete ALL ZIP');
    const oldProbe=verifyBuild('pre-F02 baseline');
    if(fs.existsSync(oldProbe.path))throw Error('Baseline temporary ZIP leaked after HTTP 422');
    evidence.baseline={status:old.status,zip_bytes:oldProbe.size,
      free_after_zip_bytes:oldProbe.free,error:'second ZIP disk-admission rejected unchanged verified ZIP'};
    console.log('CONFIRMED_F02_REAL_TMPFS_OLD_FALSE_REJECTION_HTTP422',
      'zip_bytes',oldProbe.size,'free_after_zip',oldProbe.free);

    disk('candidate');
    disk('fill','28'); // exact same real admission region
    fs.rmSync(snap().probePath,{force:true});
    const currentPreview=await preview(200);
    const current=await download(currentPreview.data);
    if(current.status!==200 || !/application\/zip/.test(current.headers['content-type']||'') ||
      current.headers['x-gfbrc-all']!=='1')
      throw Error('Corrected unchanged complete ZIP was not delivered '+current.status+
        ' '+JSON.stringify(current.headers)+' '+current.body.toString('utf8').slice(-450));
    const newProbe=verifyBuild('corrected F02 candidate');
    if(fs.existsSync(newProbe.path))throw Error('Candidate temporary ZIP leaked after HTTP 200');
    const archive=path.join(lab,'f02-capacity-accepted.zip');
    fs.writeFileSync(archive,current.body);
    evidence.candidate={status:current.status,zip_bytes:newProbe.size,
      free_after_zip_bytes:newProbe.free,archive:path.basename(archive)};
    console.log('PASS_F02_REAL_TMPFS_UNCHANGED_COMPLETE_ZIP_HTTP200',
      'zip_bytes',newProbe.size,'free_after_zip',newProbe.free);

    disk('fill','21'); // below 8 MiB originals + 16 MiB safety allowance
    fs.rmSync(snap().probePath,{force:true});
    const deniedPreview=await preview(422);
    const denied=await download(currentPreview.data); // signed valid preview, now truly insufficient
    assertNoZip(denied,'genuinely insufficient tmpfs','Insufficient temporary disk space for complete ALL ZIP');
    if(fs.existsSync(snap().probePath))throw Error('Under-capacity request built a ZIP');
    evidence.insufficient={preview_status:422,download_status:denied.status,
      before_verified_zip:true,no_zip_written:true,preview_message:deniedPreview.data.message};
    console.log('PASS_F02_REAL_TMPFS_INSUFFICIENT_REJECTS_PREHEADERS_HTTP422');
    fs.writeFileSync(path.join(lab,'f02-capacity-evidence.json'),JSON.stringify(evidence,null,2));
    cp.execFileSync('python3',[path.join(__dirname,'all-f02-capacity-assert.py'),lab],
      {stdio:'inherit',timeout:20000});
  }finally{await browser.close();}
})().catch(e=>{console.error('F02_REAL_CAPACITY_FAILED',e.stack||String(e));process.exit(1);});
