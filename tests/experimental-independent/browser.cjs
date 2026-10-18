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
    await page.goto(base + '/wp-admin/admin.php?page=gf_entries&id=' + formId,
      {waitUntil:'domcontentloaded', timeout:25000});
    const list = page.locator('select[name="action"]');
    const options = await list.locator('option').evaluateAll(nodes => nodes.map(n=>n.value));
    if (!options.includes('gfbrc_independent_flat_zip') || !options.includes('gf_bulk_download'))
      throw Error('Both actions not available on actual form: '+JSON.stringify(options));
    const rows = page.locator('input[type="checkbox"][name="entry[]"]');
    const visible = await rows.count();
    if (!visible) throw Error('No real selectable entry checkbox in admin list');
    await rows.first().check();
    const id = await rows.first().inputValue();
    await list.selectOption('gfbrc_independent_flat_zip');
    const [download] = await Promise.all([
      page.waitForEvent('download',{timeout:25000}),
      page.locator('#doaction').click(),
    ]);
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
    await context.close();
  } finally {await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exit(1);});
