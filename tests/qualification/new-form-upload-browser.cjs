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
    await open(spec.optional.form);
    const empty=await check(1,0,1);
    if(!empty.zero)throw Error('No-file real submit must use review-only ACK');
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
    console.log('PASS_P0_REAL_FORM_NO_FILE_ACK_THEN_FIXTURE_LATE_UPLOAD_EXCLUDED_BY_CONTRACT');
  } finally {await browser.close();}
})().catch(e=>{console.error('P0_REAL_FORM_FAILURE',e.stack||String(e));process.exit(1);});
