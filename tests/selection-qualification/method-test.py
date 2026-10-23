#!/usr/bin/env python3
"""Real WordPress HTTP differential of method A replay and method B signed capture."""
import html.parser
import json
from pathlib import Path
import subprocess
import sys
from urllib.parse import urlencode

lab=Path(sys.argv[1])
fixture=json.loads((lab/'hook-fixture.json').read_text())
fid=fixture['form_id']
base="http://127.0.0.1:8080/wp-admin/"
cookie=lab/'auth.cookies'
in_a=sorted(fixture['included'])
in_b=sorted(fixture['excluded'])
class Reader(html.parser.HTMLParser):
 def __init__(self):
  super().__init__();self.rows=[];self.capture=None;self.seal=None;self.count=[]
 def handle_starttag(self,tag,attrs):
  x=dict(attrs)
  if tag=='input' and x.get('name')=='entry[]':
   self.rows.append(int(x['value']))
  if tag=='script' and x.get('id')=='gfbrc-method-capture':
   self.capture=x.get('data-payload');self.seal=x.get('data-seal')
  if 'displaying-num' in (x.get('class') or '').split():
   self.count.append('native-count-present')
def curl(args):
 p=subprocess.run(['curl','--silent','--show-error','--max-time','45',
       '--cookie',str(cookie),*args],capture_output=True,text=True)
 if p.returncode: raise AssertionError((p.returncode,p.stderr,p.stdout[:800]))
 return p.stdout
def scenario(case,expected,policy_before=None,policy_after=None,expected_state='ALLOW_SAME_MEMBERSHIP'):
 policy=lab/'method-policy.txt'
 if policy_before:
  if case=='user_policy':
   result=subprocess.run(['php','/tmp/brc-wp-cli.phar','--path=/tmp/brc-wordpress','--user=bootstrap_admin',
      'user','meta','update','bootstrap_admin','gfbrc_method_allowed_cohort',policy_before],
      capture_output=True,text=True)
   assert result.returncode==0,(case,'user policy setup',result.stderr)
  else:policy.write_text(policy_before)
 url=base+'admin.php?'+urlencode({'page':'gf_entries','id':fid,'gfbrc_method_case':case})
 page=curl([url]);parser=Reader();parser.feed(page)
 assert parser.capture and parser.seal,(case,'Capture absent')
 shown=sorted(parser.rows)
 assert shown==sorted(expected),(case,'native ids differ',shown,expected)
 p=json.loads(parser.capture)
 assert p['case']==case and p['form_id']==fid
 if policy_after:
  if case=='user_policy':
   result=subprocess.run(['php','/tmp/brc-wp-cli.phar','--path=/tmp/brc-wordpress','--user=bootstrap_admin',
      'user','meta','update','bootstrap_admin','gfbrc_method_allowed_cohort',policy_after],
      capture_output=True,text=True)
   assert result.returncode==0,(case,'user policy change',result.stderr)
  else:policy.write_text(policy_after)
 result=json.loads(curl(['--data-urlencode','action=gfbrc_method_qualify',
   '--data-urlencode','gfbrc_method_case='+case,
   '--data-urlencode','payload='+parser.capture,
   '--data-urlencode','seal='+parser.seal,
   base+'admin-post.php']))
 assert result['state']==expected_state,(case,result)
 if expected_state in ('BLOCK_CURRENT_SHAPE','BLOCK_CAPTURE_SHAPE'):
  print('METHOD_SHAPE_REJECT',json.dumps({'case':case,'native_display':shown,
    'decision':result['state']},sort_keys=True),flush=True)
  return parser,result
 assert sorted(result['display_ids'])==shown,(case,'captured member mismatch',result,shown)
 if expected_state=='ALLOW_SAME_MEMBERSHIP':
  current_expected=expected
 elif case=='context':
  current_expected=in_b
 elif policy_after in ('hook-a','hook-b'):
  current_expected=in_a if policy_after=='hook-a' else in_b
 else:
  raise AssertionError((case,'Undefined dynamic policy control'))
 assert sorted(result['current_ids'])==sorted(current_expected),(case,'server replay ids',result,current_expected)
 if expected_state=='BLOCK_MEMBERSHIP_CHANGED':
  assert result['display_count']==result['current_count']==3,(case,result)
 print('METHOD_DIFF',json.dumps({'case':case,'native_display':shown,
   'captured_B_ids':result['display_ids'],'replayed_A_ids':result['current_ids'],
   'display_count':result['display_count'],'current_count':result['current_count'],
   'decision':result['state']},sort_keys=True),flush=True)
 return parser,result
scenario('none',sorted(in_a+in_b))
scenario('single',in_a)
scenario('multi',in_a[:2])
scenario('stacked',in_a[:2])
# Equal-count but different members: the GET-to-POST effective policy changes.
scenario('policy',in_a,policy_before='hook-a',policy_after='hook-b',
         expected_state='BLOCK_MEMBERSHIP_CHANGED')
# Even with stable saved user policy, a request-method-dependent callback diverges.
scenario('context',in_a,expected_state='BLOCK_MEMBERSHIP_CHANGED')
# User-specific authorization rule changes, while the base GF capability stays true.
scenario('user_policy',in_a,policy_before='hook-a',policy_after='hook-b',
         expected_state='BLOCK_MEMBERSHIP_CHANGED')
# A callback choosing the B cohort on both requests remains composable.
scenario('same_count',in_b)
# Safe denial when an args hook unexpectedly changes form or emits non-scalar criteria.
scenario('shape_drift',sorted(in_a+in_b),expected_state='BLOCK_CURRENT_SHAPE')
scenario('malformed_criteria',sorted(in_a+in_b),expected_state='BLOCK_CURRENT_SHAPE')
# Context changed but policy returned to A: no false admission of the old B group.
scenario('policy',in_b,policy_before='hook-b',policy_after='hook-a',
         expected_state='BLOCK_MEMBERSHIP_CHANGED')
print('METHOD_DIFFERENTIAL_REAL_GF_COMPLETE',flush=True)
