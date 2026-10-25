#!/usr/bin/env python3
"""Independent digest/multiplicity oracle for fresh authoritative ALL responses."""
from collections import Counter
from pathlib import Path
import json
import sys
import zipfile
import hashlib
lab=Path(sys.argv[1]); result=json.loads((lab/'all-race-repair-results.json').read_text())
a=result['fixture']['a']['sha256']; b=result['fixture']['b']['sha256']
assert a != b
expected={'reference':Counter([b]),'deletion':Counter([b]),
          'repeated':Counter([b,a,a,b])}
for label,item in result['cases'].items():
    kind=label.split('-')[0]
    assert item['before']['entry']==item['after']['entry']
    if item['mutate']:
        assert item['status']==422, (label,item)
        assert item['fresh']['status']==200
        filename=item['fresh']['filename']
        wanted=expected[kind]
    else:
        assert item['status']==200
        filename='race-positive.zip'
        wanted=Counter([b])
    with zipfile.ZipFile(lab/filename) as z:
        names=z.namelist()
        assert all('/' not in n and '\\' not in n for n in names),names
        assert len(names)==len(set(n.casefold() for n in names)),names
        got=Counter(hashlib.sha256(z.read(n)).hexdigest() for n in names)
        assert got==wanted,(label,got,wanted)
        assert len(names)==sum(wanted.values())
    print('PASS_VERIFIED_AUTHORITY_OCCURRENCE_HASHES',label,len(names),got)
print('PASS_ALL_RACE_REPAIR_FRESH_ZIP_HASH_AND_COLLISION_ORACLE')
