/* Actual Chromium settings form submission and GF flat-ZIP downloads. */
'use strict';
const fs = require('node:fs'), path = require('node:path');
const {chromium} = require('playwright-core');
(async () => {
  const lab=process.env.BRC_LAB_DIR, base=process.env.BRC_BASE_URL;
  const info=JSON.parse(fs.readFileSync(path.join(lab,'per-form.json'),'utf8'));
  const cookies=fs.readFileSync(path.join(lab,'auth.cookies'),'utf8')
    .split(/\r?\n/).filter(l=>l&&!l.startsWith('#')).map(l=>{
      const p=l.split(/\s+/);return {name:p[5],value:p[6],domain:p[0],path:p[2],
        secure:p[3]==='TRUE',expires:Number(p[4]),httpOnly:true};
    });
  const browser=await chromium.launch({executablePath:process.env.BRC_CHROME,headless:true,
    args:['--no-sandbox','--disable-dev-shm-usage']});
  try {
    const context=await browser.newContext({acceptDownloads:true});
    await context.addCookies(cookies);
    const page=await context.newPage();
    page.on('dialog', d=>d.accept());
    const submitted = [];
    page.on('request', request => {
      if (request.method() !== 'POST' || !request.url().includes('/admin-post.php')) return;
      const body = new URLSearchParams(request.postData() || '');
      if (body.get('action') === 'gfbrc_flat_zip_download')
        submitted.push({form_id:Number(body.get('form_id')),
          ids:body.getAll('entry_ids[]').map(Number)});
    });
    async function settings(key) {
      await page.goto(base+'/wp-admin/options-general.php?page=gf-bulk-rename-settings&gfbrc_form='+info[key].form_id,
        {waitUntil:'domcontentloaded'});
      if (!await page.locator('#gfbrc-method').count()) throw Error('Per-form native admin settings unavailable');
    }
    async function save() {
      const f=page.locator('form[action*="options.php"]').last();
      try {
        await Promise.all([
          page.waitForURL(/settings-updated=true/, {timeout:18000}),
          f.locator('input[type="submit"]').click()
        ]);
      } catch (e) {
        const details=await page.locator('body').innerText().catch(()=>'(body unavailable)');
        throw Error('Settings save did not complete at '+page.url()+': '+
          details.slice(0,850)+'; '+e.message);
      }
    }
    await settings('b');
    await page.locator('#gfbrc-method').selectOption('single');
    await page.locator('#gfbrc-single').selectOption('6');
    await save();
    await settings('c');
    await page.locator('#gfbrc-method').selectOption('composite');
    await page.locator('input[name="gf_bulk_rename_per_form_rules[_include][6]"]').check();
    await page.locator('input[name="gf_bulk_rename_per_form_rules[_include][7]"]').check();
    await page.locator('#gfbrc-order-7').fill('1');
    await page.locator('#gfbrc-order-6').fill('2');
    await page.locator('#gfbrc-separator').fill('__');
    await save();
    // Edit B again without resubmitting the C rule.
    await settings('b');
    if (await page.locator('#gfbrc-single').inputValue()!=='6') throw Error('Single setting did not persist');
    await page.locator('#gfbrc-method').selectOption('legacy');
    await save();
    await settings('b');
    await page.locator('#gfbrc-method').selectOption('single');
    await page.locator('#gfbrc-single').selectOption('6');
    await save();
    // Native Settings API reject: a manually injected invalid field option.
    await settings('b');
    await page.locator('#gfbrc-single').evaluate(el=>{
      const o=document.createElement('option');o.value='999';o.textContent='Invalid field';
      el.appendChild(o); el.value='999';
    });
    await save();
    if (!await page.getByText(/Naming rule not saved/).count())
      throw Error('Invalid form rule lacks visible rejection');
    await settings('c');
    if (await page.locator('#gfbrc-method').inputValue()!=='composite' ||
        await page.locator('#gfbrc-order-7').inputValue()!=='1' ||
        await page.locator('#gfbrc-order-6').inputValue()!=='2' ||
        await page.locator('#gfbrc-separator').inputValue()!=='__')
      throw Error('Form C rule was overwritten while editing B');
    console.log('PASS_BROWSER_NATIVE_SETTINGS_SAVE_EDIT_INVALID_VISIBLE_AND_FORM_ISOLATION');

    for (const key of ['b','c']) {
      const config=info[key];
      await page.goto(base+'/wp-admin/admin.php?page=gf_entries&id='+config.form_id,
        {waitUntil:'domcontentloaded'});
      for (const id of config.entry_ids) {
        const row=page.locator('input[type="checkbox"][name="entry[]"][value="'+id+'"]');
        if (await row.count() !== 1) throw Error('Selected GF Entry not visible: '+id);
        await row.check();
      }
      await page.locator('select[name="action"]').selectOption('gfbrc_independent_flat_zip');
      const previousRequests = submitted.length;
      const [download]=await Promise.all([page.waitForEvent('download',{timeout:20000}),
        page.locator('#doaction').click()]);
      if (submitted.length !== previousRequests+1) throw Error('No single independent POST observed');
      const posted=submitted[submitted.length-1];
      if (posted.form_id !== config.form_id ||
          posted.ids.slice().sort((a,b)=>a-b).join(',') !==
          config.entry_ids.slice().sort((a,b)=>a-b).join(','))
        throw Error('Selected browser Entry IDs differ from intended form selection');
      fs.writeFileSync(path.join(lab,'per-form-'+key+'-selected.json'), JSON.stringify(posted));
      const dest=path.join(lab,'per-form-'+key+'.zip');
      await download.saveAs(dest);
      const bytes=fs.readFileSync(dest);
      if (!download.suggestedFilename().endsWith('.zip') || bytes[0]!==0x50 || bytes[1]!==0x4b)
        throw Error('Not a genuine browser ZIP: '+key);
      console.log('PASS_BROWSER_EXT001_'+key.toUpperCase()+'_ZIP bytes='+bytes.length);
    }
    await context.close();
  } finally {await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exit(1);});
