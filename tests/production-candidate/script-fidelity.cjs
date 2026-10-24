/* QF-03 authentic Chromium diagnostics: no production changes.
 * Capture real script URLs, status, MIME, HTML prefixes, CDP exceptions,
 * and native GF UI markers. Preserve evidence even when page scripts fail.
 */
'use strict';
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require('playwright-core');
(async()=>{
 const lab=process.env.BRC_LAB_DIR,base=process.env.BRC_BASE_URL;
 const fixture=JSON.parse(fs.readFileSync(path.join(lab,'new-independent-form.json'),'utf8'));
 const form=Number(fixture.form);
 if(!form)throw Error('Actual NEW browser form was not seeded');
 const cookies=fs.readFileSync(path.join(lab,'auth.cookies'),'utf8').split(/\r?\n/)
   .filter(s=>s && !s.startsWith('#')).map(line=>{
    const t=line.split(/\s+/);
    return {domain:t[0],path:t[2],name:t[5],value:t[6],
      secure:t[3]==='TRUE',expires:Number(t[4]),httpOnly:true};
   });
 const browser=await chromium.launch({executablePath:process.env.BRC_CHROME,headless:true,
   args:['--no-sandbox','--disable-dev-shm-usage']});
 const report={base,form,head:process.env.GITHUB_SHA,script_responses:[],failed_requests:[],
   pageerrors:[],cdp_exceptions:[],console_errors:[],runtime:{},failures:[]};
 try {
  const context=await browser.newContext({acceptDownloads:true});
  await context.addCookies(cookies);
  const page=await context.newPage();
  const cdp=await context.newCDPSession(page);
  await cdp.send('Runtime.enable');
  await cdp.send('Network.enable');
  cdp.on('Runtime.exceptionThrown',({exceptionDetails:d})=>{
    report.cdp_exceptions.push({text:d.text,url:d.url,line:d.lineNumber,
      column:d.columnNumber,description:d.exception?.description?.slice(0,1200),
      frames:d.stackTrace?.callFrames?.slice(0,4).map(f=>({url:f.url,line:f.lineNumber,column:f.columnNumber}))});
  });
  const network={};
  cdp.on('Network.requestWillBeSent',r=>{
    if(r.type==='Script'||/\.js(?:\?|$)/i.test(r.request.url))
      network[r.requestId]={url:r.request.url,method:r.request.method,
        resource_type:r.type,initiator:r.initiator?.type};
  });
  cdp.on('Network.responseReceived',r=>{
    if(r.type==='Script'||network[r.requestId]){
      const n=network[r.requestId]||{url:r.response.url};
      n.status=r.response.status;n.mime=r.response.mimeType;
      n.response_headers=r.response.headers;
      n.from_cache=!!r.response.fromDiskCache;
    }
  });
  cdp.on('Network.loadingFailed',r=>{
    if(network[r.requestId])network[r.requestId].cdp_failure=r.errorText;
  });
  page.on('pageerror',e=>report.pageerrors.push({message:e.message,stack:String(e.stack||'').slice(0,1400)}));
  page.on('console',m=>{if(m.type()==='error')
    report.console_errors.push({text:m.text().slice(0,300),location:m.location()})});
  page.on('requestfailed',r=>report.failed_requests.push({
    url:r.url(),type:r.resourceType(),failure:r.failure()?.errorText}));
  const responses=[];
  page.on('response',r=>{
    if(r.request().resourceType()!=='script')return;
    responses.push((async()=>{
      const row={url:r.url(),status:r.status(),content_type:r.headers()['content-type']||'',
        status_text:r.statusText()};
      try{
        const body=await r.body();
        row.bytes=body.length;
        row.sha256=require('node:crypto').createHash('sha256').update(body).digest('hex');
        row.prefix=body.subarray(0,180).toString('utf8');
        row.starts_html=/^\s*(?:<!doctype|<html|<\?php|<br|<b>)/i.test(row.prefix);
      }catch(e){row.body_error=String(e).slice(0,150)}
      report.script_responses.push(row);
    })());
  });
  const url=base+'/wp-admin/admin.php?page=gf_entries&id='+form;
  const top=await page.goto(url,{waitUntil:'domcontentloaded',timeout:35000});
  await page.waitForTimeout(1900);
  await Promise.allSettled(responses);
  report.document={url:page.url(),http_status:top?.status(),title:await page.title()};
  report.runtime=await page.evaluate(()=>({
    jquery:typeof window.jQuery,
    wp:typeof window.wp,
    gf_global:typeof window.gf_global,
    new_prepare_present:!!document.querySelector('#gfbrc-new-prepare'),
    new_ack_present:!!document.querySelector('#gfbrc-new-ack'),
    entry_rows:document.querySelectorAll('tr[id*="lead_row"], tr[id*="entry_row"]').length,
    native_bulk_selects:Array.from(document.querySelectorAll('select')).filter(
      x=>/action|bulk/i.test(x.name+' '+x.id)).map(x=>({id:x.id,name:x.name}))
  }));
  report.cdp_script_responses=Object.values(network);
  report.summary={script_count:report.script_responses.length,
    html_as_script:report.script_responses.filter(x=>x.starts_html).length,
    http_failed_scripts:report.script_responses.filter(x=>x.status>=400).length,
    pageerror_count:report.pageerrors.length,
    exceptions_with_urls:report.cdp_exceptions.filter(x=>x.url || x.frames?.some(y=>y.url)).length,
    new_controls_present:report.runtime.new_prepare_present && report.runtime.new_ack_present};
  if(!report.summary.new_controls_present)report.failures.push('NEW CONTROLS ABSENT');
  if(!report.script_responses.length)report.failures.push('NO SCRIPT RESPONSES CAPTURED');
  if(report.summary.html_as_script!==0)report.failures.push('HTML served as executable JavaScript');
  if(report.summary.http_failed_scripts!==0)report.failures.push('Required JavaScript HTTP failure');
  if(report.pageerrors.length!==0)report.failures.push('Uncaught JavaScript page errors');
  if(report.runtime.jquery!=='function')report.failures.push('jQuery global unavailable');
  if(report.runtime.wp!=='object')report.failures.push('WordPress wp global unavailable');
  if(report.runtime.gf_global==='undefined')report.failures.push('Gravity Forms admin runtime unavailable');
  if(!report.runtime.native_bulk_selects.length)report.failures.push('Native GF bulk selectors missing');
  const failed=report.script_responses.filter(x=>x.starts_html||x.status>=400);
  console.log('PASS_NATIVE_SCRIPT_FIDELITY_SUMMARY',JSON.stringify(report.summary));
  console.log('NATIVE_SCRIPT_FIDELITY_FAILURES',JSON.stringify(failed.map(x=>({
    url:x.url,status:x.status,content_type:x.content_type,prefix:x.prefix?.slice(0,120)}))));
  console.log('NATIVE_SCRIPT_FIDELITY_EXCEPTIONS',JSON.stringify(report.cdp_exceptions.slice(0,6)));
  console.log('NATIVE_SCRIPT_FIDELITY_GLOBALS',JSON.stringify(report.runtime));
 }catch(e){report.failures.push(String(e.stack||e));throw e}
 finally {
   const dest=path.join(lab,'reports/native-script-fidelity.json');
   fs.mkdirSync(path.dirname(dest),{recursive:true});
   fs.writeFileSync(dest,JSON.stringify(report,null,2));
   console.log('NATIVE_SCRIPT_FIDELITY_ARTIFACT',dest);
   await browser.close();
 }
 if(report.failures.length)process.exitCode=1;
})().catch(e=>{console.error('QF03_BROWSER_DIAGNOSTIC_FAILURE',e.stack||String(e));process.exitCode=1;});
