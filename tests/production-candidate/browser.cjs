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
    const diagnostic = [], endpointSelections = [], confirmations = [];
    let cancelNext = false, cancellations = 0;
    page.on('dialog', async d => {
      diagnostic.push('dialog='+d.message());
      if (d.type() === 'confirm') {
        if (!d.message().includes('explicitly checked Entries') && !d.message().includes('ALL Entries matching'))
          throw Error('Unclear explicit/global download confirmation: '+d.message());
        if (cancelNext) { cancelNext = false; cancellations++; await d.dismiss(); }
        else { confirmations.push(d.message()); await d.accept(); }
      } else await d.dismiss();
    });
    page.on('pageerror', e => diagnostic.push('pageerror='+String(e.message).slice(0,200)));
    page.on('request', req => {
      if (req.url().includes('admin-post.php')) {
        const body = new URLSearchParams(req.postData() || '');
        endpointSelections.push({method:req.method(), ids:body.getAll('entry_ids[]'), form_id:body.get('form_id'),
          mode:body.get('selection_mode'), scope:body.get('selection_scope'),
          seal:body.get('selection_scope_seal'), expected_count:body.get('expected_entry_count')});
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
    // Actual browser cancellation must not dispatch an authenticated ZIP POST.
    await page.goto(base + '/wp-admin/admin.php?page=gf_entries&id=' + formId,
      {waitUntil:'domcontentloaded', timeout:25000});
    await page.locator('input[type="checkbox"][name="entry[]"]').first().check();
    await page.locator('select[name="action"]').selectOption('gfbrc_independent_flat_zip');
    const requestsBeforeCancel = endpointSelections.length;
    cancelNext = true;
    await page.locator('#doaction').click();
    await page.waitForTimeout(500);
    if (cancellations !== 1 || endpointSelections.length !== requestsBeforeCancel || cancelNext)
      throw Error('Cancelled confirmation submitted or was not observed');
    console.log('PASS_BROWSER_CONFIRMATION_CANCEL_NO_DOWNLOAD_POST');

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
        // Submitted checkbox order follows visible DOM order (not fixture
        // creation order). Preserve that real order when verifying suffix names.
        selected_ids:picked, form_id:formId, browser_zip_bytes:multiBytes.length,
        visible_rows:visibleNow
      }));
      console.log('PASS_REAL_GF_ENTRY_LIST_SELECTED_'+number+
        ' visible='+visibleNow+' browser_zip_bytes='+multiBytes.length);
    }

    // Native GF page-size qualification: remove the disposable 160-row test
    // override without altering the real GF pagination or background threshold.
    const marker = path.join(lab, 'ifz-native-page-size');
    fs.writeFileSync(marker, 'normal GF pagination\\n');
    try {
      await page.goto(base + '/wp-admin/admin.php?page=gf_entries&id=' + formId,
        {waitUntil:'domcontentloaded', timeout:25000});
      const nativeRows = page.locator('input[type="checkbox"][name="entry[]"]');
      const pageOneCount = await nativeRows.count();
      const next = page.locator('a.next-page').first();
      if (pageOneCount < 1 || pageOneCount >= 101 || await next.count() !== 1)
        throw Error('Normal GF page size / next-page link not observed: visible='+pageOneCount+
          ' next='+await next.count()+' url='+page.url());
      const firstPageId = await nativeRows.first().inputValue();
      await nativeRows.first().check();
      const pageOneChecked = await nativeRows.evaluateAll(nodes => nodes.filter(x=>x.checked).map(x=>x.value));
      if (!pageOneChecked.includes(firstPageId)) throw Error('Page one checkbox not selected');
      await next.click();
      await page.waitForLoadState('domcontentloaded');
      const secondRows = page.locator('input[type="checkbox"][name="entry[]"]');
      const pageTwoCount = await secondRows.count();
      const secondPageId = await secondRows.first().inputValue();
      if (!pageTwoCount || firstPageId === secondPageId)
        throw Error('Real GF pagination did not change rows');
      const pageTwoInitiallyChecked = await secondRows.evaluateAll(nodes => nodes.filter(x=>x.checked).map(x=>x.value));
      const visibleAction = await page.locator('select[name="action"] option[value="gfbrc_independent_flat_zip"]').textContent();
      if (!visibleAction.includes('Flat ZIP with Rename') || visibleAction.includes('selected on this page only'))
        throw Error('Independent action label misrepresents global selection');
      await secondRows.first().check();
      const secondChecked = await secondRows.evaluateAll(nodes => nodes.filter(x=>x.checked).map(x=>x.value));
      const submittedBefore = endpointSelections.length, promptsBefore = confirmations.length;
      await page.locator('select[name="action"]').selectOption('gfbrc_independent_flat_zip');
      const [pagedDownload] = await Promise.all([
        page.waitForEvent('download', {timeout:25000}),
        page.locator('#doaction').click(),
      ]);
      const pagedPath = path.join(lab, 'browser-pagination.zip');
      await pagedDownload.saveAs(pagedPath);
      const received = fs.readFileSync(pagedPath);
      if (received[0] !== 0x50 || received[1] !== 0x4b || !pagedDownload.suggestedFilename().endsWith('.zip'))
        throw Error('Normal-paged case did not deliver ZIP');
      const posted = endpointSelections.slice(submittedBefore);
      if (posted.length !== 1 || posted[0].method !== 'POST' ||
          JSON.stringify(posted[0].ids) !== JSON.stringify(secondChecked) ||
          posted[0].ids.includes(firstPageId) || confirmations.length !== promptsBefore+1 ||
          !confirmations.at(-1).includes(String(secondChecked.length)+' explicitly checked Entries'))
        throw Error('Actual paged UI/post/confirmation entry mismatch: '+JSON.stringify({posted,secondChecked,firstPageId}));
      // Return through the REAL previous-page control to determine whether a
      // checkbox checked on page one remains checked after visiting page two.
      const prev = page.locator('a.prev-page').first();
      if (await prev.count() !== 1) throw Error('GF previous-page control absent');
      await prev.click();
      await page.waitForLoadState('domcontentloaded');
      const originalCheckbox = page.locator('input[type="checkbox"][name="entry[]"][value="' + firstPageId + '"]');
      if (await originalCheckbox.count() !== 1)
        throw Error('Original checked Entry not found after navigating back');
      const pageOneRetainedOnReturn = await originalCheckbox.isChecked();
      fs.writeFileSync(path.join(lab,'browser-pagination-selected.json'), JSON.stringify({
        form_id:formId, selected_ids:posted[0].ids, page_one_selected_ids:pageOneChecked,
        page_two_initially_checked:pageTwoInitiallyChecked,
        page_one_retained_on_return:pageOneRetainedOnReturn, page_one_first_id:firstPageId,
        page_two_first_id:secondPageId, page_one_rows:pageOneCount,
        page_two_rows:pageTwoCount, page_scoped_confirmation:confirmations.at(-1),
        zip_bytes:received.length,
      }));
      console.log('PASS_NATIVE_GF_PAGINATION_EXPLICIT_SELECTION page1_rows='+pageOneCount+
        ' page2_rows='+pageTwoCount+' page1_selected='+pageOneChecked.join(',')+
        ' page2_initial_checked='+pageTwoInitiallyChecked.join(',')+
        ' page1_retained_on_return='+pageOneRetainedOnReturn+
        ' submitted='+posted[0].ids.join(',')+' zip_bytes='+received.length);
    } finally { fs.rmSync(marker,{force:true}); }

    fs.writeFileSync(marker, 'native pagination global all matching\n');
    try {
    // Installed production path: user selects all matching from GFBRC's
    // accessible companion toggle, independently of a GF header bug.
    for (const [caseName, urlSuffix, count] of [
      ['global154', '', 154],
      ['global_filtered', '&field_id=5&s=group-a&operator=is', 77],
    ]) {
      const ids = meta.cases[caseName].entry_ids.map(Number);
      if (ids.length !== count || new Set(ids).size !== count) throw Error('Bad global fixture');
      const form = meta.cases[caseName].form_id;
      await page.goto(base+'/wp-admin/admin.php?page=gf_entries&id='+form+urlSuffix,
        {waitUntil:'domcontentloaded',timeout:25000});
      const gfRows = page.locator('input[type="checkbox"][name="entry[]"]');
      const visible = await gfRows.count();
      if (visible <= 0 || visible >= count) throw Error('Native paging not active');
      const toggle = page.locator('#gfbrc-all-matching');
      if (await toggle.count() !== 1) throw Error('Missing independent all-matching operator control');
      const config = await page.locator('#gfbrc-independent-flat-zip')
        .evaluate(el => JSON.parse(el.dataset.config));
      if (config.matchingCount !== count || !config.globalLabel.includes('500'))
        throw Error('Displayed all-matching count/bound does not match native GF criteria');
      let independentlyChecked = null;
      if (caseName === 'global154') {
        independentlyChecked = await gfRows.first().inputValue();
        await gfRows.first().check();
      }
      await toggle.check();
      if (independentlyChecked && !(await gfRows.first().isChecked()))
        throw Error('All-matching toggle unexpectedly changed native row selection');
      await page.locator('select[name="action"]').selectOption('gfbrc_independent_flat_zip');
      const postsBefore = endpointSelections.length;
      const [downloadAll] = await Promise.all([
        page.waitForEvent('download',{timeout:35000}),
        page.locator('#doaction').click(),
      ]);
      const fullPath=path.join(lab,'browser-'+caseName+'.zip');
      await downloadAll.saveAs(fullPath);
      const b=fs.readFileSync(fullPath);
      if(!downloadAll.suggestedFilename().endsWith('.zip')||b[0]!==0x50||b[1]!==0x4b)
        throw Error('Global selection did not deliver a verified browser ZIP');
      const req = endpointSelections.at(-1);
      if(endpointSelections.length!==postsBefore+1 || req.ids.length ||
          req.form_id!==String(form) || req.mode!=='all_matching' || !req.scope || !req.seal ||
          req.expected_count!==String(count) ||
          !confirmations.at(-1).includes('ALL '+count+' Entries') ||
          downloadAll.suggestedFilename() !== 'gfbrc-flat-'+form+'-'+count+'-entries-'+
            (caseName==='global154'?155:78)+'-files.zip')
        throw Error('Global browser path sent incorrect explicit ID set');
      fs.writeFileSync(path.join(lab,'browser-'+caseName+'-selected.json'),JSON.stringify({
        form_id:form,selected_ids:ids,zip_bytes:b.length,
        visible_rows:visible,expected_entry_count:count,
      }));
      console.log('PASS_BROWSER_ALL_MATCHING_'+count+
        ' visible='+visible+' browser_zip_bytes='+b.length+' form='+form);
      if (independentlyChecked) {
        await toggle.uncheck();
        if (!(await gfRows.first().isChecked()))
          throw Error('Unchecking all-matching erased an independent checked row');
        const beforeExplicit = endpointSelections.length;
        const [explicitDownload] = await Promise.all([
          page.waitForEvent('download',{timeout:25000}),
          page.locator('#doaction').click(),
        ]);
        await explicitDownload.saveAs(path.join(lab,'browser-mixed-explicit.zip'));
        const explicitRequest = endpointSelections.at(-1);
        if (endpointSelections.length !== beforeExplicit+1 || explicitRequest.mode !== 'explicit' ||
            JSON.stringify(explicitRequest.ids) !== JSON.stringify([independentlyChecked]) ||
            explicitRequest.expected_count !== null ||
            !confirmations.at(-1).includes('1 explicitly checked Entries')) {
          throw Error('Manual row selection did not remain independent after all-matching');
        }
        fs.writeFileSync(path.join(lab,'browser-mixed-explicit-selected.json'),
          JSON.stringify({form_id:form, selected_ids:[independentlyChecked]}));
        console.log('PASS_BROWSER_MIXED_SELECTION_GLOBAL_AND_INDEPENDENT_EXPLICIT');
      }
    }

    } finally { fs.rmSync(marker,{force:true}); }

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
