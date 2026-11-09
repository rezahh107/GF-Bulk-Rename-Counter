/* P0: real anonymous Gravity Forms HTTP multipart submit -> persisted Entry ->
 * authentic NEW browser ZIP -> independent source-byte oracle -> explicit ACK.
 * The late GFAPI mutation is labelled fixture-only and proves creation-only scope.
 */
'use strict';
const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process');
const crypto = require('node:crypto');
const {chromium} = require('playwright-core');

(async () => {
  const lab = process.env.BRC_LAB_DIR, wp = process.env.BRC_WP_PATH;
  const cli = process.env.BRC_WP_CLI, base = process.env.BRC_BASE_URL;
  const input = Buffer.from('GFBRC P0 real form upload fixture\nUnique source occurrence\n', 'utf8');
  const digest = crypto.createHash('sha256').update(input).digest('hex');
  function probe(action) {
    const raw = cp.execFileSync('php', [cli,'--path='+wp,'--user=bootstrap_admin',
      'eval-file','tests/qualification/new-form-upload.php'], {
      encoding:'utf8',env:{...process.env,BRC_FORM_UPLOAD_ACTION:action},timeout:30000
    });
    try { return JSON.parse(raw.trim()); }
    catch(e) { throw Error('GF oracle JSON unavailable: '+action+' output='+raw.slice(-600)); }
  }
  const spec = probe('setup');
  // The lab records an independent GF eligibility population before each
  // prepare. It is not derived from NEW's frozen pending IDs or ZIP plan.
  const baselines={};
  function beforePrepare(name) {
    const raw=cp.execFileSync('php',[cli,'--path='+wp,'--user=bootstrap_admin',
      'eval-file','tests/qualification/new-reconcile-snapshot.php'],{
        encoding:'utf8',env:{...process.env,BRC_RECONCILE_FORM:name,
          BRC_RECONCILE_PHASE:'before_prepare'},timeout:30000
      });
    const baseline=JSON.parse(raw.trim());
    if(baseline.form_id!==spec[name].form || baseline.phase!=='before_prepare' ||
       !baseline.scope.complete || !baseline.scope.consistent || baseline.pending_active)
      throw Error('Stable independent pre-prepare GF population unavailable: '+name);
    baselines[name]=baseline;
  }
  function bindPrepare(name, operationId) {
    if(typeof operationId!=='string' || !/^[0-9a-f]{32}$/.test(operationId))
      throw Error('Actual NEW prepare operation ID unavailable');
    baselines[name].generation_sha256=crypto.createHash('sha256')
      .update(operationId).digest('hex');
  }
  // Reuse PR #50's real submissions, ZIPs and synthetic-only coverage state.
  // The expectation reader is independent GFAPI + local bytes, not NEW's plan.
  function reconcile(name, archive, expected, omitOne=false, omitPending=false) {
    const raw=cp.execFileSync('php',[cli,'--path='+wp,'--user=bootstrap_admin',
      'eval-file','tests/qualification/new-reconcile-snapshot.php'],{
        encoding:'utf8',env:{...process.env,BRC_RECONCILE_FORM:name},timeout:30000
      });
    const snapshot=JSON.parse(raw.trim());
    if(snapshot.form_id!==spec[name].form)throw Error('Wrong independent GF scope');
    if(baselines[name])snapshot.pre_prepare=baselines[name];
    const file=path.join(lab,'new-reconcile-'+name+'.json');
    fs.writeFileSync(file,JSON.stringify(snapshot),'utf8');
    const args=['tests/qualification/new-reconcile.py',file,'--expect',expected];
    if(archive)args.push('--zip',archive);
    if(omitOne)args.push('--omit-one-selected');
    if(omitPending)args.push('--omit-one-pending');
    cp.execFileSync('python3',args,{stdio:'inherit',timeout:30000});
    return snapshot;
  }
  cp.execFileSync('python3',['tests/qualification/test_new_reconcile.py'],
    {stdio:'inherit',timeout:30000});
  const browser = await chromium.launch({executablePath:process.env.BRC_CHROME,
    headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
  try {
    // No admin cookies: this is a genuine visitor request, not GFAPI::add_entry.
    const visitor = await browser.newContext();
    const applicant = await visitor.newPage();
    async function submit(form, withFile) {
      await applicant.goto(form.page,{waitUntil:'domcontentloaded',timeout:30000});
      const selector = 'form#gform_'+form.form;
      await applicant.locator(selector+' input[name="input_2"]').fill('Synthetic P0 '+form.form);
      if (withFile) {
        await applicant.locator(selector+' input[type="file"]').setInputFiles({
          name:'gfbrc-real-upload.txt',mimeType:'text/plain',buffer:input
        });
      }
      const button=applicant.locator('#gform_submit_button_'+form.form);
      await button.click();
      await applicant.locator('#gform_confirmation_message_'+form.form+
        ', #gform_confirmation_wrapper_'+form.form).first().waitFor({timeout:30000});
    }
    await submit(spec.required,true);
    const persisted = probe('read');
    if (persisted.required.form !== spec.required.form ||
        persisted.required.source.sha256 !== digest ||
        persisted.required.source.size !== input.length ||
        persisted.optional !== null) {
      throw Error('Submitted file not persisted exactly: '+JSON.stringify(persisted));
    }
    console.log('PASS_REAL_ANONYMOUS_GF_MULTIPART_UPLOAD_PERSISTED entry='+persisted.required.id+
      ' sha256='+digest+' bytes='+input.length);
    // Three independent real browser-file fields on one actual GF Entry.
    // Two distinct field occurrences intentionally have identical name/bytes.
    const firstBytes=Buffer.from('P0 unique source occurrence A\\n', 'utf8');
    const equalBytes=Buffer.from('P0 equal source occurrence B\\n', 'utf8');
    await applicant.goto(spec.multi.page,{waitUntil:'domcontentloaded',timeout:30000});
    const multiSelector='form#gform_'+spec.multi.form;
    await applicant.locator(multiSelector+' input[name="input_4"]').fill('Synthetic multi-source');
    for(const [field,name,bytes] of [
      [1,'unique-a.txt',firstBytes],
      [2,'equal.txt',equalBytes],
      [3,'equal.txt',equalBytes]
    ]) {
      await applicant.locator(multiSelector+' input[name="input_'+field+'"]').setInputFiles({
        name,mimeType:'text/plain',buffer:bytes
      });
    }
    await applicant.locator('#gform_submit_button_'+spec.multi.form).click();
    await applicant.locator('#gform_confirmation_message_'+spec.multi.form+
      ', #gform_confirmation_wrapper_'+spec.multi.form).first().waitFor({timeout:30000});
    const multiExpected=probe('read_multi');
    const multiHashes=multiExpected.files.map(f=>f.sha256);
    if(multiExpected.form_id!==spec.multi.form || multiExpected.files.length!==3 ||
       new Set(multiExpected.files.map(f=>f.field_id)).size!==3 ||
       multiHashes[0]!==crypto.createHash('sha256').update(firstBytes).digest('hex') ||
       multiHashes[1]!==crypto.createHash('sha256').update(equalBytes).digest('hex') ||
       multiHashes[1]!==multiHashes[2]) {
      throw Error('Independent real GF multifield source occurrences differ from browser fixture');
    }
    const expectedPath=path.join(lab,'new-real-multifield-expected.json');
    fs.writeFileSync(expectedPath,JSON.stringify(multiExpected),'utf8');
    console.log('PASS_REAL_MULTIFIELD_ANONYMOUS_GF_PERSISTED_OCCURRENCES files=3 distinct_fields=3 equal_content=2');
    await visitor.close();

    const cookieLines=fs.readFileSync(path.join(lab,'auth.cookies'),'utf8').split(/\r?\n/)
      .filter(s=>s && !s.startsWith('#')).map(line=>{
        const t=line.split(/\s+/);
        return {domain:t[0],path:t[2],name:t[5],value:t[6],
          secure:t[3]==='TRUE',expires:Number(t[4]),httpOnly:true};
      });
    const operator = await browser.newContext({acceptDownloads:true});
    await operator.addCookies(cookieLines);
    const page = await operator.newPage();
    async function open(form) {
      await page.goto(base+'/wp-admin/admin.php?page=gf_entries&id='+form,
        {waitUntil:'domcontentloaded',timeout:30000});
      await page.locator('#gfbrc-new-prepare').waitFor({timeout:20000});
    }
    async function check(entries, files, zeros) {
      const wait=page.waitForResponse(r=>r.url().includes('admin-ajax.php') &&
        (r.request().postData()||'').includes('action=gfbrc_new_prepare'),{timeout:45000});
      await page.locator('#gfbrc-new-prepare').click();
      const r=await wait, j=await r.json();
      if(r.status()!==200||j.success!==true||
        j.data.entries!==entries||j.data.files!==files||j.data.zero_entries!==zeros) {
        throw Error('Actual NEW check disagrees with independent GF Entry oracle '+
          JSON.stringify({status:r.status(),j,entries,files,zeros}));
      }
      return j.data;
    }
    async function ack(zero) {
      await page.locator('#gfbrc-new-review').check();
      const response=page.waitForResponse(r=>r.url().includes('admin-ajax.php') &&
        (r.request().postData()||'').includes('action=gfbrc_new_ack'),{timeout:30000});
      await page.locator('#gfbrc-new-ack').click();
      const r=await response, body=await r.json();
      if(r.status()!==200||body.success!==true||body.data.result!=='acknowledged') {
        throw Error('Real NEW receipt ACK failed: '+JSON.stringify(body));
      }
    }
    await open(spec.required.form);
    const before = await check(1,1,0);
    if (!before.first || !before.op || probe('state').required.rev!==0) {
      throw Error('NEW advanced before explicit ACK');
    }
    const downloadPromise=page.waitForEvent('download',{timeout:60000});
    await page.locator('#gfbrc-new-download').click();
    const saved=await downloadPromise;
    const zipPath=path.join(lab,'new-real-form-upload.zip');
    await saved.saveAs(zipPath);
    await page.locator('#gfbrc-new-result').filter({hasText:'SHA-256 matched'}).waitFor();
    const args=[zipPath,digest,String(input.length),'1'];
    cp.execFileSync('python3',['tests/qualification/new-form-oracle.py',...args],{stdio:'inherit'});
    let rejected=false;
    try {
      cp.execFileSync('python3',['tests/qualification/new-form-oracle.py',zipPath,'0'.repeat(64),
        String(input.length),'1'],{stdio:'pipe'});
    } catch(e) { rejected=e.status!==0; }
    if (!rejected) throw Error('Corrupted source-hash negative control was NOT rejected');
    console.log('PASS_P0_REAL_UPLOAD_OMISSION_AND_CORRUPTION_NEGATIVE_CONTROL');
    await ack(false);
    const after=probe('state').required;
    const included=(ranges,id)=>ranges.some(([lo,hi])=>id>=lo && id<=hi);
    if(after.rev!==1 || !included(after.known,persisted.required.id) ||
       !included(after.covered,persisted.required.id)) {
      throw Error('Actual GF upload Entry not durably covered after ACK');
    }
    await open(spec.required.form);
    const none=await check(0,0,0);
    if(none.op)throw Error('Already confirmed upload reappeared unexpectedly');
    console.log('PASS_P0_REAL_FORM_FILE_NEW_ZIP_AND_DURABLE_ACK');

    // Separate real anonymous form POST with no optional file, then explicit
    // zero-file ACK; only the later attachment is a GFAPI-prepared fixture.
    const visitor2=await browser.newContext();
    const noFilePage=await visitor2.newPage();
    await noFilePage.goto(spec.optional.page,{waitUntil:'domcontentloaded'});
    await noFilePage.locator('form#gform_'+spec.optional.form+' input[name="input_2"]')
      .fill('Synthetic no-file');
    await noFilePage.locator('#gform_submit_button_'+spec.optional.form).click();
    await noFilePage.locator('#gform_confirmation_message_'+spec.optional.form+
      ', #gform_confirmation_wrapper_'+spec.optional.form).first().waitFor({timeout:30000});
    await visitor2.close();
    const missing = probe('read').optional;
    if(!missing || missing.value!=='')throw Error('Optional no-file submit persisted unexpected data');
    beforePrepare('optional');
    await open(spec.optional.form);
    const empty=await check(1,0,1);
    if(!empty.zero)throw Error('No-file real submit must use review-only ACK');
    bindPrepare('optional',empty.op);
    reconcile('optional',null,'NO_OBSERVED_MISMATCH');
    // Diagnostic-only mutation: the real pending/covered database is untouched.
    reconcile('optional',null,'CONFIRMED_MISMATCH',false,true);
    console.log('PASS_TEMPORAL_QUALIFIED_ZERO_FILE_PENDING_OMISSION');
    await ack(true);
    const known=probe('state').optional;
    if(!included(known.known,missing.id)||!included(known.covered,missing.id))
      throw Error('Reviewed file-free Entry not marked known');
    const attached=probe('attach');
    if(attached.id!==missing.id||attached.source.sha256!==digest)
      throw Error('Late GFAPI attachment was not truly persisted');
    await open(spec.optional.form);
    const notNew=await check(0,0,0);
    if(notNew.op)throw Error('Creation-only contract unexpectedly changed');
    reconcile('optional',null,'EXPECTED_EXCLUSION');
    console.log('PASS_P0_REAL_FORM_NO_FILE_ACK_THEN_FIXTURE_LATE_UPLOAD_EXCLUDED_BY_CONTRACT');

    // Independent source-derived expected set is never obtained from the NEW
    // planner. Verify the actual saved browser ZIP, then attack a disposable
    // ZIP copy and a deliberately self-consistent reduced plugin plan.
    function oracleVerify(archive,shouldPass,reason) {
      const r=cp.spawnSync('python3',[
        'tests/qualification/new-form-oracle.py','verify',expectedPath,archive
      ],{encoding:'utf8',timeout:30000});
      if(shouldPass) {
        if(r.status!==0 || !r.stdout.includes('PASS_REAL_FORM_MULTIFIELD_OCCURRENCE_ZIP_CONSERVATION')) {
          throw Error('Independent multifield oracle rejected correct ZIP: '+r.stdout+' '+r.stderr);
        }
        console.log(r.stdout.trim());
      } else {
        if(r.status!==2 || !r.stderr.includes(reason)) {
          throw Error('Independent oracle failed to identify '+reason+': '+r.status+' '+r.stderr);
        }
        console.log('PASS_P0_INDEPENDENT_ORACLE_REJECTED_'+reason.replace(/[^a-z]+/gi,'_').toUpperCase());
      }
    }
    beforePrepare('multi');
    await open(spec.multi.form);
    const multiPreview=await check(1,3,0);
    if(!multiPreview.op || probe('state').multi.rev!==0)
      throw Error('Real multifield NEW advanced before ACK');
    bindPrepare('multi',multiPreview.op);
    const multiDownload=page.waitForEvent('download',{timeout:60000});
    await page.locator('#gfbrc-new-download').click();
    const received=await multiDownload;
    const multiZip=path.join(lab,'new-real-multifield.zip');
    await received.saveAs(multiZip);
    await page.locator('#gfbrc-new-result').filter({hasText:'SHA-256 matched'}).waitFor();
    oracleVerify(multiZip,true,'');
    reconcile('multi',multiZip,'NO_OBSERVED_MISMATCH');

    function mutate(mode,filename) {
      const dest=path.join(lab,filename);
      cp.execFileSync('python3',[
        'tests/qualification/new-form-oracle.py','mutate',multiZip,dest,mode
      ],{stdio:'pipe',timeout:30000});
      return dest;
    }
    // A structurally valid archive with one genuine expected occurrence gone.
    oracleVerify(mutate('omit-one','new-real-omitted.zip'),false,'occurrence missing');
    // Same number of members, but one source payload deliberately corrupted.
    oracleVerify(mutate('corrupt-one','new-real-corrupt.zip'),false,'occurrence byte mismatch');
    // Production ZIP writer approves the reduced two-file planner's ZIP;
    // the independent GF-derived three-file oracle MUST still reject it.
    const reduced=probe('partial_zip');
    if(reduced.reduced_count!==2 || reduced.full_source_count!==3 ||
       reduced.internally_verified!==true)
      throw Error('Fault-injected reduced planner did not verify against itself');
    oracleVerify(reduced.archive,false,'occurrence missing');
    // The reduced production writer plan/ZIP agrees with itself; the separate
    // full GF source snapshot still names the missing field/slot/reference.
    reconcile('multi',reduced.archive,'CONFIRMED_MISMATCH',true);
    // Test the original again after fault injection; mutation never touched it.
    oracleVerify(multiZip,true,'');
    console.log('PASS_P0_VALID_ZIP_REAL_OMISSION_CORRUPTION_AND_REDUCED_PLAN_NEGATIVE_CONTROLS');

    await ack(false);
    const covered=probe('state').multi;
    if(covered.rev!==1 || !included(covered.known,multiExpected.entry_id) ||
       !included(covered.covered,multiExpected.entry_id)) {
      throw Error('Real multifield ZIP did not produce durable explicit ACK coverage');
    }
    await open(spec.multi.form);
    const afterMulti=await check(0,0,0);
    if(afterMulti.op)throw Error('Acknowledged multifield Entry appeared NEW again');
    reconcile('multi',null,'EXPECTED_EXCLUSION');
    console.log('PASS_P0_REAL_MULTIFIELD_ZIP_EXPLICIT_ACK_AND_DURABLE_COVERAGE');

    // Temporal control on the SAME genuine GF multifield form: a second
    // Entry exists before prepare; a third arrives after IDs are frozen.
    // Do not ACK this new generation: it is only a disposable observation.
    const temporalVisitor=await browser.newContext();
    try {
      const temporalPage=await temporalVisitor.newPage();
      async function submitTemporal(label) {
        await temporalPage.goto(spec.multi.page,{waitUntil:'domcontentloaded',timeout:30000});
        await temporalPage.locator('form#gform_'+spec.multi.form+' input[name="input_4"]')
          .fill('Synthetic '+label);
        for(const field of [1,2,3]) {
          await temporalPage.locator('form#gform_'+spec.multi.form+
            ' input[name="input_'+field+'"]').setInputFiles({
              name:'temporal-'+field+'.txt',mimeType:'text/plain',
              buffer:Buffer.from(label+'-field-'+field,'utf8')
            });
        }
        await temporalPage.locator('#gform_submit_button_'+spec.multi.form).click();
        await temporalPage.locator('#gform_confirmation_message_'+spec.multi.form+
          ', #gform_confirmation_wrapper_'+spec.multi.form).first().waitFor({timeout:30000});
      }
      await submitTemporal('before-prepare');
      beforePrepare('multi');
      await open(spec.multi.form);
      const temporalOp=await check(1,3,0);
      if(!temporalOp.op)throw Error('Pre-existing synthetic file Entry not selected');
      bindPrepare('multi',temporalOp.op);
      reconcile('multi',null,'EXPECTED_EXCLUSION'); // original ACKed Entry is excluded
      await submitTemporal('after-prepare');
      const temporalResult=reconcile('multi',null,'INCONCLUSIVE');
      const frozen=new Set(temporalResult.pending.ids);
      const captured=new Set(baselines.multi.entries.map(e=>e.entry_id));
      const later=temporalResult.entries.filter(e=>!captured.has(e.entry_id));
      if(later.length!==1 || frozen.has(later[0].entry_id))
        throw Error('Later genuine GF Entry was not isolated from frozen pending IDs');
      console.log('PASS_TEMPORAL_REAL_POST_PREPARE_ENTRY_INCONCLUSIVE');
    } finally {await temporalVisitor.close();}
  } finally {await browser.close();}
})().catch(e=>{console.error('P0_REAL_FORM_FAILURE',e.stack||String(e));process.exit(1);});
