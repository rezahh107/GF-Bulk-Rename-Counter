/* Real GF Entries UI, one-use Recent N previews and Chromium ZIP downloads. */
'use strict';
const assert = require('node:assert/strict');
const {createHash} = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright-core');

(async () => {
  const lab = process.env.BRC_LAB_DIR;
  const base = process.env.BRC_BASE_URL;
  const ajax = base + '/wp-admin/admin-ajax.php';
  const adminPost = base + '/wp-admin/admin-post.php';
  function fixture(action, env = {}) {
    const out = execFileSync('php', [process.env.BRC_WP_CLI, '--path=' + process.env.BRC_WP_PATH,
      '--user=bootstrap_admin', 'eval-file', 'tests/production-candidate/recent-fixture.php'],
    {env: {...process.env, BRC_RECENT_ACTION: action, ...env}, encoding: 'utf8', timeout: 90000});
    const line = out.split(/\r?\n/).find(s => s.startsWith('GFBRC_RECENT_FIXTURE '));
    assert.ok(line, out);
    return JSON.parse(line.slice('GFBRC_RECENT_FIXTURE '.length));
  }
  const setup = fixture('setup');
  const cookies = fs.readFileSync(path.join(lab, 'auth.cookies'), 'utf8').split(/\r?\n/)
    .filter(line => line && !line.startsWith('#')).map(line => {
      const [domain, , cookiePath, , expires, name, value] = line.split(/\s+/);
      return {domain, path: cookiePath, expires: Number(expires), name, value, httpOnly: true};
    });
  const browser = await chromium.launch({executablePath: process.env.BRC_CHROME, headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage']});
  try {
    const ctx = await browser.newContext({acceptDownloads: true});
    await ctx.addCookies(cookies);
    const page = await ctx.newPage();
    const select = page.locator('#gfbrc-recent-count');
    const download = page.locator('#gfbrc-recent-download');
    let cfg;
    async function open(form, filter = '') {
      await page.goto(base + '/wp-admin/admin.php?page=gf_entries&view=entries&id=' + form + filter,
        {waitUntil: 'domcontentloaded'});
      await select.waitFor();
      cfg = await page.locator('#gfbrc-recent-config').evaluate(el => JSON.parse(el.textContent));
    }
    async function check(n, entries, files, zero) {
      await select.selectOption(String(n));
      const response = page.waitForResponse(r => r.url() === ajax &&
        r.request().postData()?.includes('action=gfbrc_recent_prepare'));
      await page.locator('#gfbrc-recent-prepare').click();
      const r = await response;
      const result = await r.json();
      assert.equal(r.status(), 200, JSON.stringify(result));
      assert.equal(result.success, true, JSON.stringify(result));
      const data = result.data;
      assert.deepEqual([data.requested, data.entries, data.files, data.zero_entries], [n, entries, files, zero]);
      await page.locator('#gfbrc-recent-result').filter({hasText:
        'Requested: ' + n + '. Active Entries: ' + entries + '. Eligible files: ' + files +
        '. Entries without files: ' + zero + '.'}).waitFor();
      if (files) await download.waitFor();
      else {
        assert.equal(data.token, undefined);
        assert.equal(await download.isVisible(), false);
      }
      return data;
    }
    function postData(preview) {
      return {action: 'gfbrc_flat_zip_download', form_id: String(cfg.form), _wpnonce: cfg.nonce,
        selection_mode: 'recent_n', recent_n: String(preview.requested),
        recent_token: preview.token, recent_seal: preview.seal};
    }
    async function receive(label, n, entries, files) {
      const [result] = await Promise.all([page.waitForEvent('download'), download.click()]);
      assert.equal(result.suggestedFilename(),
        'gfbrc-recent-' + n + '-' + cfg.form + '-' + entries + '-entries-' + files + '-files.zip');
      await result.saveAs(path.join(lab, 'recent-' + label + '.zip'));
      assert.equal(await result.failure(), null);
    }
    async function deny(response, label, expected) {
      assert.ok(response.status() >= 400, label + ': ' + response.status());
      assert.doesNotMatch(response.headers()['content-type'] || '', /application\/zip/);
      if (expected) assert.match(await response.text(), expected, label);
      console.log('PASS_RECENT_REJECTED', label, response.status());
    }
    const prepare = n => ({action: 'gfbrc_recent_prepare', form_id: String(cfg.form),
      _wpnonce: cfg.nonce, recent_n: String(n)});
    const sha = bytes => createHash('sha256').update(bytes).digest('hex');

    // A genuine NEW download and ACK precede the small-form Recent N re-download.
    await open(setup.small);
    const newCfg = await page.locator('#gfbrc-new-config').evaluate(el => JSON.parse(el.textContent));
    async function newRequest(action, extra = {}) {
      return page.request.post(ajax, {form: {action: 'gfbrc_new_' + action,
        form_id: String(setup.small), _wpnonce: newCfg.nonce, ...extra}});
    }
    const beforeAck = await (await newRequest('prepare')).json();
    assert.equal(beforeAck.success, true, JSON.stringify(beforeAck));
    assert.deepEqual([beforeAck.data.entries, beforeAck.data.files], [6, 1]);
    const operation = beforeAck.data.op;
    const newZip = await newRequest('download', {operation});
    assert.equal(newZip.status(), 200);
    const digest = sha(await newZip.body());
    assert.equal(newZip.headers()['x-gfbrc-zip-sha256'], digest);
    const ack = await (await newRequest('ack', {operation, sha256: digest, confirmed: 'yes'})).json();
    assert.equal(ack.success, true, JSON.stringify(ack));
    assert.equal(ack.data.covered, 6);
    const state = fixture('state');
    assert.ok(state.covered && state.pending);
    const small = await check(50, 6, 1, 5);
    const smallPost = postData(small);
    await receive('after-ack', 50, 6, 1);
    await deny(await page.request.post(adminPost, {form: smallPost}), 'same-session replay', /already used/);

    await open(setup.empty);
    await check(50, 1, 0, 1);

    await open(setup.form, '&field_id=5&s=group-not-found&operator=is');
    assert.equal(await page.locator('input[type="checkbox"][name="entry[]"]').count(), 0);
    await check(50, 50, 50, 2);

    await open(setup.form, '&field_id=5&s=group-a&operator=is&paged=2');
    const rows = page.locator('input[type="checkbox"][name="entry[]"]');
    const visible = await rows.count();
    assert.ok(visible > 0 && visible < 50, 'Expected native filtered pagination');
    const groupA = new Set(setup.entries.slice(-250).map(entry => String(entry.id)));
    assert.ok((await rows.evaluateAll(els => els.map(el => el.value))).every(id => groupA.has(id)),
      'Native filter did not select group-a');
    await rows.first().check();
    assert.deepEqual(await select.locator('option').evaluateAll(els => els.map(el => el.value)),
      ['50', '100', '200', '500']);
    for (const n of [50, 100, 200, 500]) {
      await check(n, n, n, 2);
      await receive(String(n), n, n, n);
    }
    const current = await check(50, 50, 50, 2);
    const request = postData(current);
    for (const [label, patch] of [
      ['forged count', {recent_n: '050'}], ['different valid count', {recent_n: '100'}],
      ['forged token', {recent_token: current.token.replace('"n":50', '"n":500')}],
      ['bad nonce', {_wpnonce: 'invalid'}], ['checked-row conflict', {'entry_ids[]': '1'}],
      ['filter conflict', {selection_scope: '{}'}], ['ALL conflict', {all_token: '{}'}],
      ['reverse mode conflict', {selection_mode: 'explicit', 'entry_ids[]': '1'}],
    ]) {
      await deny(await page.request.post(adminPost, {form: {...request, ...patch}}), label);
    }
    await deny(await page.request.post(ajax, {form: prepare(501)}), 'out of range');
    await deny(await page.request.get(adminPost + '?action=gfbrc_flat_zip_download'), 'GET download');
    const anon = await browser.newContext();
    try {
      await deny(await anon.request.post(adminPost, {form: request}), 'unauthenticated ZIP');
      await deny(await anon.request.post(ajax, {form: prepare(50)}), 'unauthenticated preview');
    } finally { await anon.close(); }
    const actors = fixture('actors');
    for (const [name, actor] of Object.entries(actors)) {
      const actorCtx = await browser.newContext();
      try {
        await actorCtx.addCookies(actor.cookies.map(cookie => ({...cookie, url: base})));
        const own = await actorCtx.request.post(ajax, {form: {...prepare(50), _wpnonce: actor.nonce}});
        if (name === 'viewer') assert.equal(own.status(), 200, await own.text());
        else await deny(own, 'signed-in without Entry permission');
        await deny(await actorCtx.request.post(adminPost, {form: {...request, _wpnonce: actor.nonce}}),
          name + ' uses another session preview');
      } finally { await actorCtx.close(); }
    }
    await open(setup.small);
    await deny(await page.request.post(adminPost, {form: {...request, form_id: String(cfg.form),
      _wpnonce: cfg.nonce}}), 'other form with its own nonce', /changed/);

    await open(setup.form);
    const expires = await check(50, 50, 50, 2);
    fixture('expire', {BRC_RECENT_SEAL: expires.seal});
    await deny(await page.request.post(adminPost, {form: postData(expires)}), 'expired preview', /expired/);
    fixture('trash-top');
    await deny(await page.request.post(adminPost, {form: request}), 'changed status', /changed/);
    await check(500, 500, 498, 2);
    await receive('exact500', 500, 500, 498);
    fixture('restore-top');

    for (const fault of ['short-selection', 'meta-read', 'memory']) {
      await deny(await page.request.post(ajax, {form: prepare(50),
        headers: {'X-BRC-Recent-Fault': fault}}), fault);
    }
    const source = setup.race_source.path;
    const original = fs.readFileSync(source);
    try {
      const prewrite = postData(await check(50, 50, 50, 2));
      const mutated = Buffer.from(original);
      mutated[0] = 88;
      fs.writeFileSync(source, mutated);
      await deny(await page.request.post(adminPost, {form: prewrite}), 'same-size source changed before ZIP', /changed/);
      fs.writeFileSync(source, original);

      const postwrite = postData(await check(50, 50, 50, 2));
      await deny(await page.request.post(adminPost, {form: postwrite,
        headers: {'X-BRC-Recent-Fault': 'postwrite-source'}}), 'same-size source changed after ZIP', /changed/);
      assert.equal(fs.readFileSync(source)[0], 88, 'Postwrite mutation never ran');
    } finally { fs.writeFileSync(source, original); }

    const missing = setup.missing_source.path;
    fs.renameSync(missing, missing + '.hold');
    try {
      await deny(await page.request.post(ajax, {form: prepare(50)}), 'missing source', /missing or unreadable/);
    } finally { fs.renameSync(missing + '.hold', missing); }

    const beforeInsert = postData(await check(50, 50, 50, 2));
    const commit = fixture('late-commit');
    assert.deepEqual(commit.before, [commit.later, setup.entries[0].id]);
    assert.deepEqual(commit.after, [commit.later, commit.late]);
    await deny(await page.request.post(adminPost, {form: beforeInsert}), 'committed file-free members', /changed/);
    await check(50, 50, 48, 4);
    console.log('PASS_RECENT_DELAYED_COMMIT_AND_BACKDATED_ID_ORDER');
    assert.deepEqual(fixture('state'), state, 'Recent N changed NEW/ACK or naming settings');
    console.log('PASS_RECENT_EXACT_NEW_COVERAGE_PENDING_ACK_AND_SETTINGS_NON_MUTATION');
  } finally { await browser.close(); }
})().catch(err => { console.error(err); process.exit(1); });
