/* Qualification only: inspect authentic GF 3.1.1.1 all-matching selection seam.
 * Must not infer semantics from current-page row checkboxes.
 */
'use strict';
const fs=require('node:fs'), path=require('node:path'), {chromium}=require('playwright-core');
(async()=>{
 const lab=process.env.BRC_LAB_DIR,base=process.env.BRC_BASE_URL;
 const meta=JSON.parse(fs.readFileSync(path.join(lab,'independent.json'),'utf8'));
 const formId=meta.cases.one.form_id;
 const cookies=fs.readFileSync(path.join(lab,'auth.cookies'),'utf8')
  .split(/\r?\n/).filter(x=>x&&!x.startsWith('#')).map(x=>{
   const t=x.split(/\s+/);return {name:t[5],value:t[6],domain:t[0],path:t[2],secure:t[3]==='TRUE',expires:Number(t[4]),httpOnly:true};
  });
 const browser=await chromium.launch({executablePath:process.env.BRC_CHROME,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 try{
  const context=await browser.newContext();
  await context.addCookies(cookies);
  const page=await context.newPage();
  await page.goto(base+'/wp-admin/admin.php?page=gf_entries&id='+formId,{waitUntil:'domcontentloaded'});
  const summary=()=>page.evaluate(()=>{
   const form=document.querySelector('form#entry_list_form')||document.querySelector('form#posts-filter')||document.querySelector('form');
   const allowed=(x)=>/select|search|filter|entry|lead|status|action|mode|page/i.test(x);
   const fields=[...document.querySelectorAll('input,select')].filter(x=>allowed(x.name||x.id||''))
    .slice(0,80).map(x=>({name:x.name||'',id:x.id||'',type:x.type,value:x.type==='checkbox'?x.checked:x.value}));
   const links=[...document.querySelectorAll('a')].filter(x=>/select all|all selected|clear selection|全部|همه|انتخاب/i.test(x.textContent))
    .map(x=>({label:x.textContent.trim().slice(0,160),id:x.id,href:x.getAttribute('href'),onclick:x.getAttribute('onclick')}));
   return {url:location.href,formId:form?.id,fields,links,
    bodyLines:document.body.innerText.split('\n').filter(x=>/select all|all selected|selected entries|selected\s+[0-9]|entries selected/i.test(x)).slice(0,10),
    gfGlobals:Object.keys(window).filter(x=>/^(gf|gform|rg|entry)/i.test(x)).slice(0,65)};
  });
  const before=await summary();
  const rowCount=await page.locator('input[type="checkbox"][name="entry[]"]').count();
  const headers=page.locator('thead input[type="checkbox"]');
  if(!rowCount || !await headers.count()) throw Error('Authentic GF checkbox/header missing');
  await headers.first().check();
  const afterPage=await summary();
  const selectAll=page.locator('a').filter({hasText:/select all\s+\d+|select all entries|select all/i});
  let afterGlobal=null;
  if(await selectAll.count()){
   await selectAll.first().click();
   afterGlobal=await summary();
  }
  const out={rowCount,before,afterPage,afterGlobal};
  fs.writeFileSync(path.join(lab,'gf-native-selection-seam.json'),JSON.stringify(out,null,2));
  console.log('GF_NATIVE_SELECTION_SEAM '+JSON.stringify({rowCount,headers:await headers.count(),beforeLinks:before.links,afterPageLinks:afterPage.links,afterGlobalLinks:afterGlobal?.links,afterPageFields:afterPage.fields,afterGlobalFields:afterGlobal?.fields,bodyLines:afterGlobal?.bodyLines,gfGlobals:afterGlobal?.gfGlobals}).slice(0,15000));
  if(!afterGlobal) throw Error('Could not exercise GF native Select all matching link');
  console.log('PASS_DISCOVERED_AUTHENTIC_GF_SELECT_ALL_LINK');
  await context.close();
 }finally{await browser.close();}
})().catch(e=>{console.error(e.stack||e);process.exitCode=1;});
