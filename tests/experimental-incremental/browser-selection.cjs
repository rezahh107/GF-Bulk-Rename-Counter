/* Qualification ONLY: test-harness maintained selection across genuine Gravity Forms list pages.
 * Does not add cross-page selection behavior to released GFBRC.
 */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright-core');
(async () => {
  const lab = process.env.BRC_LAB_DIR, base = process.env.BRC_BASE_URL;
  const fixture = JSON.parse(fs.readFileSync(path.join(lab, 'incremental-entry-selection.json'), 'utf8'));
  const expected = new Set(fixture.expected_entry_ids.map(String));
  if (expected.size !== 154) throw Error('Fixture must contain exactly 154 distinct Entry IDs');
  const binary = process.env.BRC_CHROME;
  if (!binary || !fs.existsSync(binary)) throw Error('Chromium binary unavailable');
  const cookies = fs.readFileSync(path.join(lab, 'auth.cookies'), 'utf8')
    .split(/\r?\n/).filter(line => line && !line.startsWith('#')).map(line => {
      const t = line.split(/\s+/);
      return {name:t[5],value:t[6],domain:t[0],path:t[2],
        secure:t[3]==='TRUE',expires:Number(t[4]),httpOnly:true};
    });
  const flag = path.join(lab, 'ifz-native-page-size');
  fs.writeFileSync(flag, 'native-page-size-only-during-qualification\n');
  const browser = await chromium.launch({executablePath:binary,headless:true,
    args:['--no-sandbox','--disable-dev-shm-usage']});
  try {
    const context = await browser.newContext();
    await context.addCookies(cookies);
    const page = await context.newPage(), selected = new Set(), pages = [];
    let firstPageId = null;
    for (let p=1; p<=30 && selected.size<154; p++) {
      const url = base+'/wp-admin/admin.php?page=gf_entries&id='+fixture.form_id+'&paged='+p;
      await page.goto(url, {waitUntil:'domcontentloaded',timeout:25000});
      const rows = page.locator('input[type="checkbox"][name="entry[]"]');
      const values = await rows.evaluateAll(nodes=>nodes.map(n=>n.value).filter(x=>/^[1-9][0-9]*$/.test(x)));
      if (!values.length) throw Error('Pagination returned no rows before all 154 were selected, page='+p);
      const uniquePage = new Set(values);
      if (uniquePage.size!==values.length) throw Error('Duplicate native checkbox on page '+p);
      if (p===1) firstPageId=values[0];
      if (p===2 && values.includes(firstPageId)) throw Error('Page 2 not actually paginated');
      let chosen=0;
      for (const id of values) {
        if (!expected.has(id)) continue;
        await rows.locator('xpath=.', {}).count(); // Keep selectors scoped to real GF rows.
        await page.locator('input[type="checkbox"][name="entry[]"][value="'+id+'"]').check();
        selected.add(id); chosen++;
      }
      const checked = await rows.evaluateAll(nodes=>nodes.filter(n=>n.checked).length);
      if (checked!==chosen) throw Error('Current-page checked count differs from explicit selection');
      pages.push({page:p,visible:values.length,chosen});
      // The harness remembers selections in its own Set; the real product does not.
    }
    const missing = [...expected].filter(x=>!selected.has(x));
    if (missing.length || selected.size!==154 || pages.length<2) {
      throw Error('Cross-page explicit selection oracle mismatch: missing='+missing.length+
        ' got='+selected.size+' pages='+pages.length);
    }
    const lastChecked = await page.locator('input[type="checkbox"][name="entry[]"]:checked').count();
    if (lastChecked >= 154) throw Error('Unexpected all-pages native DOM selection');
    console.log('PASS_BROWSER_154_EXPLICIT_IDS_ACROSS_'+pages.length+'_PAGES'
      +' collected='+selected.size+' last_page_dom_checked='+lastChecked);
    fs.writeFileSync(path.join(lab,'incremental-browser-result.json'),
      JSON.stringify({expected:154,collected:[...selected].map(Number),pages,
        note:'Selection retained by test harness, NOT current GFBRC implementation'},null,2));
  } finally {
    await browser.close();
    fs.rmSync(flag,{force:true});
  }
})().catch(e=>{console.error('INCREMENTAL_BROWSER_QUALIFICATION_FAILURE',e);process.exitCode=1;});
