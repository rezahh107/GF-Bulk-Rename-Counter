/* Genuine GF + Chromium: empty-form, only-Entry-trashed, and file-free controls. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright-core');

(async () => {
  const lab = process.env.BRC_LAB_DIR;
  const base = process.env.BRC_BASE_URL;
  const cacheLog = path.join(lab, 'recent-zero-cache.jsonl');
  const httpLog = path.join(lab, 'http-server.log');
  const instrument = path.join('tests', 'production-candidate', 'recent-zero-instrument.py');
  const fixture = JSON.parse(fs.readFileSync(path.join(lab, 'recent-fixture.json'), 'utf8'));
  const cases = [
    {name: 'A', form: fixture.zero, counts: [50, 0, 0, 0]},
    {name: 'B', form: fixture.trashed, counts: [50, 0, 0, 0]},
    {name: 'D', form: fixture.empty, counts: [50, 1, 0, 1]},
  ];
  function gf(action) {
    const output = execFileSync('php', [process.env.BRC_WP_CLI, '--path=' + process.env.BRC_WP_PATH,
      '--user=bootstrap_admin', 'eval-file', 'tests/production-candidate/recent-fixture.php'],
    {env: {...process.env, BRC_RECENT_ACTION: action}, encoding: 'utf8', timeout: 90000});
    const row = output.split(/\r?\n/).find(s => s.startsWith('GFBRC_RECENT_FIXTURE '));
    assert.ok(row, output);
    return JSON.parse(row.slice('GFBRC_RECENT_FIXTURE '.length));
  }
  const membership = gf('zero-membership');
  assert.deepEqual(membership.active, {zero: 0, trashed: 0, file_free: 1});
  assert.equal(membership.trashed_status, 'trash');
  console.log('GFBRC_ZERO_NATIVE_GF_MEMBERSHIP', JSON.stringify(membership));
  const cookies = fs.readFileSync(path.join(lab, 'auth.cookies'), 'utf8').split(/\r?\n/)
    .filter(row => row && !row.startsWith('#')).map(row => {
      const [domain, , cookiePath, , expires, name, value] = row.split(/\s+/);
      return {domain, path: cookiePath, expires: Number(expires), name, value, httpOnly: true};
    });
  const browser = await chromium.launch({executablePath: process.env.BRC_CHROME, headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage']});
  let probeApplied = false;
  const failures = [];
  try {
    const ctx = await browser.newContext({acceptDownloads: true});
    await ctx.addCookies(cookies);
    const page = await ctx.newPage();
    let zipRequests = 0;
    let downloads = 0;
    page.on('request', req => {
      if (req.url().includes('/wp-admin/admin-post.php') &&
        req.postData()?.includes('gfbrc_flat_zip_download')) zipRequests++;
    });
    page.on('download', () => { downloads++; });
    async function runCase(phase, spec) {
      const start = fs.statSync(httpLog).size;
      const beforeZip = zipRequests;
      const beforeDownloads = downloads;
      const url = base + '/wp-admin/admin.php?page=gf_entries&view=entries&id=' + spec.form;
      await page.goto(url, {waitUntil: 'domcontentloaded'});
      const selector = page.locator('#gfbrc-recent-count');
      await selector.waitFor(); // Genuine empty native Entries page must still render the panel.
      if (spec.name !== 'D') {
        assert.equal(await page.locator('input[type="checkbox"][name="entry[]"]').count(), 0,
          spec.name + ': unexpected native active Entry row');
      }
      await selector.selectOption('50');
      const response = page.waitForResponse(r => r.url() === base + '/wp-admin/admin-ajax.php' &&
        r.request().postData()?.includes('action=gfbrc_recent_prepare'));
      await page.locator('#gfbrc-recent-prepare').click();
      const actual = await response;
      const raw = await actual.text();
      let payload;
      try { payload = JSON.parse(raw); } catch { payload = null; }
      console.log('GFBRC_ZERO_HTTP', JSON.stringify({phase, case: spec.name,
        status: actual.status(), contentType: actual.headers()['content-type'],
        success: payload?.success, data: payload?.data || null,
        malformed: payload === null ? raw.slice(0, 250) : undefined}));
      assert.equal(actual.status(), 200, raw);
      assert.match(actual.headers()['content-type'] || '', /application\/json/);
      assert.equal(payload?.success, true, raw);
      const data = payload.data;
      assert.deepEqual([data.requested, data.entries, data.files, data.zero_entries], spec.counts);
      assert.equal(data.token, undefined, 'Unexpected download token');
      assert.equal(data.seal, undefined, 'Unexpected download seal');
      const expected = 'Requested: ' + spec.counts[0] + '. Active Entries: ' + spec.counts[1] +
        '. Eligible files: 0. Entries without files: ' + spec.counts[3] + '.';
      await page.locator('#gfbrc-recent-result').filter({hasText: expected}).waitFor();
      assert.match(await page.locator('#gfbrc-recent-result').innerText(), /No ZIP exists for this scope/);
      assert.equal(await page.locator('#gfbrc-recent-download').isVisible(), false);
      assert.equal(zipRequests, beforeZip, 'A ZIP endpoint was requested');
      assert.equal(downloads, beforeDownloads, 'A browser download was offered');
      const diagnostics = fs.readFileSync(httpLog).subarray(start).toString('utf8');
      assert.doesNotMatch(diagnostics, /PHP (?:Warning|Fatal error|Notice|Deprecated)|Uncaught|Stack trace/i,
        phase + ' ' + spec.name + ': PHP diagnostics appeared');
      console.log('PASS_RECENT_ZERO_CASE', phase, spec.name, JSON.stringify(spec.counts));
    }
    async function runPhase(phase) {
      for (const spec of cases) {
        try { await runCase(phase, spec); }
        catch (error) {
          failures.push(phase + '/' + spec.name + ': ' + error.message);
          console.error('FAIL_RECENT_ZERO_CASE', phase, spec.name, error.stack);
        }
      }
    }
    async function revalidateRuntimeSource(stage) {
      const response = await ctx.request.get(base + '/', {
        headers: {'X-BRC-Recent-Zero-Revalidate': '1'},
      });
      const state = response.headers()['x-gfbrc-zero-opcache'];
      console.log('GFBRC_ZERO_HTTP_OPCODE_REVALIDATION', stage, response.status(), state);
      assert.equal(response.status(), 200);
      assert.ok(['invalidated', 'not-cached-or-disabled'].includes(state),
        'Test-only HTTP worker invalidation hook did not execute');
    }
    await runPhase('baseline');
    fs.rmSync(cacheLog, {force: true});
    try {
      console.log(execFileSync('python3', [instrument, 'apply'], {encoding: 'utf8'}).trim());
      probeApplied = true;
      await revalidateRuntimeSource('instrumented');
      await runPhase('diagnostic');
    } finally {
      if (probeApplied) {
        console.log(execFileSync('python3', [instrument, 'restore'], {encoding: 'utf8'}).trim());
        probeApplied = false;
        await revalidateRuntimeSource('restored');
      }
    }
    const records = fs.existsSync(cacheLog) ? fs.readFileSync(cacheLog, 'utf8').trim().split('\n')
      .filter(Boolean).map(line => JSON.parse(line)) : [];
    for (const record of records) console.log('GFBRC_ZERO_CACHE_BOUNDARY', JSON.stringify(record));
    for (const spec of cases) {
      const found = records.filter(record => record.form === spec.form);
      if (found.length !== 1) failures.push(spec.name + ': expected exactly one cache-boundary record, got ' + found.length);
      else if (found[0].ids !== spec.counts[1]) failures.push(spec.name + ': incorrect cache-boundary Entry count');
    }
    if (failures.length) throw new Error('Zero-Entry qualification failed:\n' + failures.join('\n'));
    console.log('PASS_RECENT_ZERO_AUTHENTIC_BASELINE_DIAGNOSTIC_AND_RESTORATION');
  } finally {
    if (probeApplied) {
      // Defensive restoration on unexpected interruptions; CI step should also restore.
      console.log(execFileSync('python3', [instrument, 'restore'], {encoding: 'utf8'}).trim());
      await revalidateRuntimeSource('emergency-restored');
    }
    await browser.close();
  }
})().catch(err => { console.error(err); process.exit(1); });
