#!/usr/bin/env python3
"""Compare hooked native membership with browser and real GFBRC HTTP ZIP bytes."""
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
import zipfile

lab=Path(sys.argv[1])
fixture=json.loads((lab/'hook-fixture.json').read_text())
observed=json.loads((lab/'hook-browser.json').read_text())
shown=set(observed['displayed_ids'])
allowed=set(fixture['included'])
excluded=set(fixture['excluded'])
assert shown==allowed, ('Native list was not hook-filtered',shown,allowed)
assert excluded.isdisjoint(shown)
assert (lab/'hook-fired.log').exists(), 'Documented GF Entry List hook did not execute'
assert 'FILTER=hook-a' in (lab/'hook-fired.log').read_text()
expect={f['name']:f for f in fixture['files']}
assert len(expect)==6 and len({f['sha256'] for f in fixture['files']})==6
endpoint='http://127.0.0.1:8080/wp-admin/admin-post.php'
headers=lab/'hook-http.headers'
archive=lab/'hook-http.zip'
args=['curl','--fail-with-body','--silent','--show-error','--max-time','80',
      '--cookie',str(lab/'auth.cookies'),'-D',str(headers),'-o',str(archive),
      '--data-urlencode','action=gfbrc_flat_zip_download',
      '--data-urlencode','form_id='+str(fixture['form_id']),
      '--data-urlencode','_wpnonce='+observed['nonce'],
      '--data-urlencode','selection_mode=all_matching',
      '--data-urlencode','selection_scope='+observed['scope'],
      '--data-urlencode','selection_scope_seal='+observed['scope_seal'],
      '--data-urlencode','expected_entry_count='+str(observed['rendered_matching_count']),
      endpoint]
proc=subprocess.run(args,capture_output=True,text=True,timeout=90)
assert proc.returncode==0, ('GFBRC HTTP ZIP failed',proc.stdout,proc.stderr,
                             archive.read_text(errors='replace')[:500] if archive.exists() else '')
head=headers.read_text().lower()
selected=int(re.search(r'^x-gfbrc-selected-entries:\s*(\d+)',head,re.M).group(1))
members=int(re.search(r'^x-gfbrc-zip-files:\s*(\d+)',head,re.M).group(1))
results=[]
for name,filename in [('browser',lab/'hook-browser.zip'),('http',archive)]:
 with zipfile.ZipFile(filename) as z:
  assert z.testzip() is None,(name,'invalid archive')
  names=z.namelist()
  assert len(names)==len(set(names)),(name,'duplicate ZIP members')
  assert set(names).issubset(set(expect)),(name,'unexpected member names',names)
  for x in names:
   assert hashlib.sha256(z.read(x)).hexdigest()==expect[x]['sha256'],(name,x,'source mismatch')
  ids=sorted(expect[x]['entry_id'] for x in names)
  results.append((name,ids,len(names)))
  print('HOOK_ZIP_RESULT',name,'entry_ids='+','.join(map(str,ids)),
        'members='+str(len(names)),flush=True)
assert results[0][1]==results[1][1], 'Browser and HTTP ZIP Entry membership disagrees'
assert members==results[1][2], 'ZIP member header mismatch'
assert selected==len(results[1][1]), 'Selected Entry header mismatch'
outside=sorted(set(results[1][1])-shown)
report={'base_head':'0868ac4685ed415ea2ce06ce642412970af80335',
        'form_id':fixture['form_id'],'native_displayed_ids':sorted(shown),
        'native_count_text':observed['native_count_text'],
        'native_all_marker':observed['native_all_marker_after_banner_attempt'],
        'rendered_matching_count':observed['rendered_matching_count'],
        'server_header_selected_entries':selected,'header_zip_files':members,
        'archive_derived_entry_ids':results[1][1],
        'server_resolver_observable_via_archive':True,
        'outside_displayed_ids':outside,
        'source_hashes_verified':True}
(lab/'hook-result.json').write_text(json.dumps(report,indent=2))
print('HOOK_SELECTION_COMPARISON '+json.dumps(report,sort_keys=True),flush=True)
if outside:
 print('CONFIRMED_FINDING_HOOK_SELECTION_DIVERGENCE '
       'archive_contains_entries_outside_effective_GF_entry_list',flush=True)
else:
 print('NO_MATERIAL_ISSUE_HOOK_MEMBERSHIP_EQUIVALENT_FOR_FIXTURE',flush=True)
