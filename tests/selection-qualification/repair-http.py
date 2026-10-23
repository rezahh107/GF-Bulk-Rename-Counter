#!/usr/bin/env python3
"""Falsify repaired native filter authority using authentic HTTP and ZIP bytes."""
import html.parser, hashlib, json, pathlib, re, subprocess, sys, zipfile
from urllib.parse import urlencode

lab=pathlib.Path(sys.argv[1])
fixture=json.loads((lab/'hook-fixture.json').read_text())
fid=fixture['form_id']
endpoint='http://127.0.0.1:8080/wp-admin/admin-post.php'
cookies=lab/'auth.cookies'
class Config(html.parser.HTMLParser):
 def __init__(self):super().__init__();self.cfg=None;self.rows=[]
 def handle_starttag(self,tag,attrs):
  a=dict(attrs)
  if tag=='script' and a.get('id')=='gfbrc-independent-flat-zip':
   self.cfg=json.loads(a['data-config'])
  if tag=='input' and a.get('name')=='entry[]':
   self.rows.append(int(a['value']))

def fetch(case):
 qs={'page':'gf_entries','id':fid}
 if case: qs['gfbrc_lab_case']=case
 r=subprocess.run(['curl','-fsS','--cookie',str(cookies),
    'http://127.0.0.1:8080/wp-admin/admin.php?'+urlencode(qs)],
    capture_output=True,text=True,check=True)
 p=Config();p.feed(r.stdout)
 assert p.cfg and p.cfg['formId']==fid,(case,'config missing')
 return p
def request(cfg,path,**changes):
 fields={'action':'gfbrc_flat_zip_download','form_id':str(fid),
     '_wpnonce':cfg['nonce'],'selection_mode':'all_matching',
     'selection_scope':cfg['scope'],'selection_scope_seal':cfg['scopeSeal'],
     'selection_effective':cfg['effectiveCapture'],
     'selection_effective_seal':cfg['effectiveSeal'],
     'expected_entry_count':str(cfg['matchingCount'])}
 fields.update(changes)
 args=['curl','--silent','--show-error','--max-time','75',
       '--cookie',str(cookies),'-D',str(lab/(path+'.headers')),
       '-o',str(lab/(path+'.body'))]
 for k,v in fields.items():
  args.extend(['--data-urlencode',k+'='+str(v)])
 args.append(endpoint)
 r=subprocess.run(args,capture_output=True,text=True,check=True)
 m=re.findall(r'^HTTP/\S+\s+(\d+)',(lab/(path+'.headers')).read_text(),re.M)
 assert m
 return int(m[-1])
def refusal(label,p,**kw):
 code=request(p.cfg,label,**kw)
 assert code in (403,422),(label,code)
 assert 'content-disposition: attachment' not in (lab/(label+'.headers')).read_text().lower()
 print('PASS_EFFECTIVE_FAIL_CLOSED',label,'HTTP='+str(code),flush=True)

p=fetch('')
assert sorted(p.rows)==sorted(fixture['included']) and p.cfg['matchingCount']==3
assert p.cfg['effectiveSeal'] and p.cfg['effectiveCapture']
badseal='0'*64
refusal('tampered_effective',p,selection_effective_seal=badseal)
refusal('wrong_signed_scope',p,selection_scope_seal=badseal)
refusal('different_count',p,expected_entry_count='2')
# Same-current-ID case: strict criteria comparison must reject operator mutation.
p=fetch('same_membership')
assert sorted(p.rows)==sorted(fixture['included']) and p.cfg['matchingCount']==3
refusal('same_ids_different_criteria',p)
p=fetch('context')
assert sorted(p.rows)==sorted(fixture['included'])
refusal('request_context_changed',p)
# Post request policy changes for same user without changing WordPress capability.
def meta(value):
 c=subprocess.run(['php','/tmp/brc-wp-cli.phar','--path=/tmp/brc-wordpress',
  '--user=bootstrap_admin','user','meta','update','bootstrap_admin','gfbrc_hook_allowed',value],
  capture_output=True,text=True)
 assert c.returncode==0,c.stderr
meta('hook-a')
p=fetch('policy')
assert sorted(p.rows)==sorted(fixture['included'])
meta('hook-b')
refusal('user_policy_change_same_count',p)
meta('hook-a')
p=fetch('composed')
assert sorted(p.rows)==sorted(fixture['included'][:2]),p.rows
assert p.cfg['matchingCount']==2,(p.cfg['matchingCount'],p.rows)
status=request(p.cfg,'composed')
assert status==200,status
with zipfile.ZipFile(lab/'composed.body') as archive:
  assert archive.testzip() is None
  byhash={x['sha256']:x['entry_id'] for x in fixture['files']}
  ids=sorted(byhash[hashlib.sha256(archive.read(m)).hexdigest()] for m in archive.namelist())
  assert ids==sorted(p.rows),(ids,p.rows)
print('PASS_COMPOSED_DOCUMENTED_HOOKS_SOURCE_HASHES',flush=True)
# A late callback after GFBRC's maximum-priority observer must disable global.
p=fetch('late')
assert not p.cfg['scopeSeal'] and not p.cfg['effectiveSeal'],(p.cfg['scopeSeal'],p.cfg['effectiveSeal'])
assert p.cfg['matchingCount'] is None
print('PASS_LATE_MAX_PRIORITY_OBSERVER_BYPASS_BLOCKED',flush=True)
print('PASS_AUTHENTIC_REPAIRED_HOOK_SECURITY_AND_COMPOSITION',flush=True)
