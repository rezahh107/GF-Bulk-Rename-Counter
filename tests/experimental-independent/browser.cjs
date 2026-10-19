/* Browser interaction CI experiment: real GF list selection and ZIP attachment. */
'use strict';
const fs = require('node:fs'), path = require('node:path');
const {chromium} = require('playwright-core');
(async () => {
  const lab = process.env.BRC_LAB_DIR, base = process.env.BRC_BASE_URL;
  const meta = JSON.parse(fs.readFileSync(path.join(lab, 'independent.json'), 'utf8'));
  const binary = process.env.BRC_CHROME;
  if (!binary || !fs.existsSync(binary)) throw Error('Chrome executable missing');
  const cookies = fs.readFileSync(path.join(lab, 'auth.cookies'), 'utf8')
    .split(/\r?\n/).filter(line => line && !line.startsWith('#')).map(line => {
      const t = line.split(/\s+/);
      return {name: t[5], value: t[6], domain: t[0], path: t[2],
        secure: t[3] === 'TRUE', expires: Number(t[4]), httpOnly: true};
    });
  const browser = await chromium.launch({executablePath:binary, headless:true,
    args:['--no-sandbox','--disable-dev-shm-usage']});
  try {
    const context = await browser.newContext({acceptDownloads:true});
    await context.addCookies(cookies);
    const page = await context.newPage(), formId = meta.cases.one.form_id;
    const diagnostic = [];
    page.on('dialog', async d => {diagnostic.push('dialog='+d.message()); await d.dismiss();});
    page.on('pageerror', e => diagnostic.push('pageerror='+String(e.message).slice(0,200)));
    page.on('request', req => {
      if (req.url().includes('admin-post.php')) {
        const body = new URLSearchParams(req.postData() || '');
        diagnostic.push('endpoint-request='+req.method()+' entry_count='+body.getAll('entry_ids[]').length+
          ' body_keys='+Array.from(new Set(body.keys())).join(','));
      }
    });
    page.on('response', response => {
      if (response.url().includes('admin-post.php'))
        diagnostic.push('endpoint-response='+response.status());
    });
    await page.goto(base + '/wp-admin/admin.php?page=gf_entries&id=' + formId,
      {waitUntil:'domcontentloaded', timeout:25000});
    const list = page.locator('select[name="action"]');
    const options = await list.locator('option').evaluateAll(nodes => nodes.map(n=>n.value));
    if (!options.includes('gfbrc_independent_flat_zip') || !options.includes('gf_bulk_download'))
      throw Error('Both actions not available on actual form: '+JSON.stringify(options));
    // Guard both Apply selectors: native Bulk Download must not be hijacked
    // just because the OTHER dropdown is set to our independent action.
    const isolated = await page.evaluate(action => {
      const top = document.querySelector('select[name="action"]');
      const bottom = document.querySelector('select[name="action2"]');
      if (!top || !bottom) return false;
      const probe = document.createElement('button');
      probe.type = 'button';
      document.body.appendChild(probe);
      top.value = action;
      bottom.value = 'gf_bulk_download';
      probe.id = 'doaction2';
      const nativeBottom = new MouseEvent('click', {bubbles:true,cancelable:true});
      const bottomAllowed = probe.dispatchEvent(nativeBottom) && !nativeBottom.defaultPrevented;
      top.value = 'gf_bulk_download';
      bottom.value = action;
      probe.id = 'doaction';
      const nativeTop = new MouseEvent('click', {bubbles:true,cancelable:true});
      const topAllowed = probe.dispatchEvent(nativeTop) && !nativeTop.defaultPrevented;
      probe.remove();
      return bottomAllowed && topAllowed;
    }, 'gfbrc_independent_flat_zip');
    if (!isolated) throw Error('Independent control intercepted native other-selector Apply');
    console.log('PASS_BROWSER_NATIVE_BULK_ACTION_ISOLATION_TOP_BOTTOM');
    const rows = page.locator('input[type="checkbox"][name="entry[]"]');
    const visible = await rows.count();
    if (!visible) throw Error('No real selectable entry checkbox in admin list');
    await rows.first().check();
    const id = await rows.first().inputValue();
    console.log('BROWSER_SELECTED_GENUINE_GF_ROW value='+id+' visible_rows='+visible);
    await list.selectOption('gfbrc_independent_flat_zip');
    let download;
    try {
      [download] = await Promise.all([
        page.waitForEvent('download',{timeout:12000}),
        page.locator('#doaction').click(),
      ]);
    } catch (e) {
      console.error('BROWSER_EVENT_DIAGNOSTIC',diagnostic);
      console.error('BROWSER_FINAL_PAGE_URL',page.url());
      throw e;
    }
    const saved = path.join(lab,'browser-one.zip');
    await download.saveAs(saved);
    const bytes = fs.readFileSync(saved);
    if (!download.suggestedFilename().endsWith('.zip') ||
        bytes.length < 30 || bytes[0] !== 0x50 || bytes[1] !== 0x4b)
      throw Error('Browser did not receive ZIP attachment');
    fs.writeFileSync(path.join(lab,'browser-selected.json'), JSON.stringify({
      selected_id:id, form_id:formId, visible_rows:visible,
      download_name:download.suggestedFilename(), browser_zip_bytes:bytes.length
    }));
    console.log('PASS_REAL_CHROMIUM_GF_ENTRY_LIST_DOWNLOAD selected='+id+
      ' zip_bytes='+bytes.length+' visible_rows='+visible);
    // Select TWO known valid entries on the actual visible page and verify
    // both IDs survive the native checkbox -> dedicated POST boundary.
    await page.goto(base + '/wp-admin/admin.php?page=gf_entries&id=' + formId,
      {waitUntil:'domcontentloaded', timeout:25000});
    const twoIds = [
      String(meta.cases.larger_workload.entry_ids[0]),
      String(meta.cases.previously_deleted.entry_ids[0])
    ];
    if (twoIds[0] === twoIds[1]) throw Error('Two-row fixture IDs must be distinct');
    for (const id2 of twoIds) {
      // Match the real GF row by its exact, already-persisted Entry ID.
      const match = page.locator('input[type="checkbox"][name="entry[]"][value="' + id2 + '"]');
      if (await match.count() !== 1) throw Error('Expected valid Entry not visible in actual GF list: '+id2);
      await match.check();
    }
    await page.locator('select[name="action"]').selectOption('gfbrc_independent_flat_zip');
    const [downloadTwo] = await Promise.all([
      page.waitForEvent('download',{timeout:25000}),
      page.locator('#doaction').click(),
    ]);
    const savedTwo = path.join(lab,'browser-two.zip');
    await downloadTwo.saveAs(savedTwo);
    const bTwo = fs.readFileSync(savedTwo);
    if (!downloadTwo.suggestedFilename().endsWith('.zip') ||
        bTwo.length < 30 || bTwo[0] !== 0x50 || bTwo[1] !== 0x4b) {
      throw Error('Browser did not receive valid two-entry ZIP');
    }
    fs.writeFileSync(path.join(lab,'browser-two-selected.json'), JSON.stringify({
      selected_ids:twoIds, form_id:formId, browser_zip_bytes:bTwo.length
    }));
    console.log('PASS_REAL_CHROMIUM_TWO_ENTRY_DOWNLOAD ids='+twoIds.join(',')+
      ' zip_bytes='+bTwo.length);

    // Deliberate LAB-ONLY GF page-size=160, NOT a bulk action threshold
    // change. This qualifies the real checkbox -> browser POST path at
    // 99, 100 and 101 in a single visible Entry-list page.
    for (const [label, number] of [['n99',99],['n100',100],['n101',101]]) {
      await page.goto(base + '/wp-admin/admin.php?page=gf_entries&id=' + formId,
        {waitUntil:'domcontentloaded', timeout:40000});
      const requested = meta.cases[label].entry_ids.map(String);
      if (requested.length !== number || new Set(requested).size !== number)
        throw Error('Malformed '+label+' selected fixture');
      const available = page.locator('input[type="checkbox"][name="entry[]"]');
      const visibleNow = await available.count();
      if (visibleNow < 101)
        throw Error('GF entry list did not honor test-only 160-row page size: '+visibleNow);
      const picked = await available.evaluateAll((nodes, ids) => {
        const wanted = new Set(ids);
        for (const item of nodes) {
          item.checked = wanted.has(item.value);
          item.dispatchEvent(new Event('change',{bubbles:true}));
        }
        return nodes.filter(x=>x.checked).map(x=>x.value);
      }, requested);
      if (picked.length !== number || new Set(picked).size !== number ||
          !requested.every(x=>picked.includes(x)))
        throw Error('Browser selected checkbox IDs differ from exactly requested '+label);
      await page.locator('select[name="action"]').selectOption('gfbrc_independent_flat_zip');
      const [multiDownload] = await Promise.all([
        page.waitForEvent('download',{timeout:40000}),
        page.locator('#doaction').click(),
      ]);
      const multiPath = path.join(lab,'browser-'+label+'.zip');
      await multiDownload.saveAs(multiPath);
      const multiBytes = fs.readFileSync(multiPath);
      if (!multiDownload.suggestedFilename().endsWith('.zip') ||
          multiBytes.length < 30 || multiBytes[0]!==0x50 || multiBytes[1]!==0x4b)
        throw Error(label+' did not deliver a browser ZIP');
      fs.writeFileSync(path.join(lab,'browser-'+label+'-selected.json'), JSON.stringify({
        selected_ids:requested, form_id:formId, browser_zip_bytes:multiBytes.length,
        visible_rows:visibleNow
      }));
      console.log('PASS_REAL_GF_ENTRY_LIST_SELECTED_'+number+
        ' visible='+visibleNow+' browser_zip_bytes='+multiBytes.length);
    }

    // Control: native Bulk Download must STILL work from the authentic GF
    // dropdown in the same browser, with the test-only plugin active.
    await page.goto(base + '/wp-admin/admin.php?page=gf_entries&id=' + formId,
      {waitUntil:'domcontentloaded', timeout:25000});
    const nativeEntry = String(meta.cases.larger_workload.entry_ids[0]);
    const nativeCheckbox = page.locator('input[type="checkbox"][name="entry[]"][value="' + nativeEntry + '"]');
    if (await nativeCheckbox.count() !== 1) throw Error('Native control fixture not visible');
    await nativeCheckbox.check();
    await page.locator('select[name="action"]').selectOption('gf_bulk_download');
    let nativeDownload;
    try {
      [nativeDownload] = await Promise.all([
        page.waitForEvent('download',{timeout:18000}),
        page.locator('#doaction').click(),
      ]);
    } catch (err) {
      console.error('NATIVE_CONTROL_EVENT_DIAGNOSTIC',diagnostic.slice(-15));
      console.error('NATIVE_CONTROL_PAGE',page.url());
      throw err;
    }
    const nativeSaved = path.join(lab,'browser-native-control.zip');
    await nativeDownload.saveAs(nativeSaved);
    const nBytes = fs.readFileSync(nativeSaved);
    if (!nativeDownload.suggestedFilename().endsWith('.zip') ||
        nBytes.length < 30 || nBytes[0] !== 0x50 || nBytes[1] !== 0x4b)
      throw Error('Native Bulk Download browser action no longer downloads ZIP');
    fs.writeFileSync(path.join(lab,'browser-native-selected.json'), JSON.stringify({
      selected_ids:[nativeEntry],form_id:formId,bytes:nBytes.length
    }));
    console.log('PASS_REAL_CHROMIUM_NATIVE_BULK_DOWNLOAD_UNCHANGED entry='+nativeEntry+
      ' zip_bytes='+nBytes.length);
    await context.close();
  } finally {await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exit(1);});
