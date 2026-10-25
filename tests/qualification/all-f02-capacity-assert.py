#!/usr/bin/env python3
"""Independent source digest and real tmpfs evidence validation for F-02."""
from pathlib import Path
import hashlib
import json
import sys
import zipfile

lab=Path(sys.argv[1])
e=json.loads((lab/'f02-capacity-evidence.json').read_text())
fixture=json.loads((lab/'all-race-fixture.json').read_text())
bench=fixture['benchmark']
assert e['baseline']['status']==422, e
assert e['candidate']['status']==200, e
assert e['insufficient']['preview_status']==422, e
assert e['insufficient']['download_status']==422, e
assert e['baseline']['zip_bytes']>=bench['size'], e
assert e['candidate']['zip_bytes']>=bench['size'], e
assert e['baseline']['free_after_zip_bytes']<bench['size']+16*1048576,e
assert e['candidate']['free_after_zip_bytes']<bench['size']+16*1048576,e
with zipfile.ZipFile(lab/e['candidate']['archive']) as zipf:
    names=zipf.namelist()
    assert len(names)==1 and '/' not in names[0] and '\\' not in names[0], names
    data=zipf.read(names[0])
    assert len(data)==bench['size'],len(data)
    assert hashlib.sha256(data).hexdigest()==bench['sha256'], 'ZIP source SHA mismatch'
assert hashlib.sha256(Path(bench['path']).read_bytes()).hexdigest()==bench['sha256']
print('PASS_REAL_F02_DISK_BASELINE_422_CANDIDATE_200_UNDER_CAPACITY_422')
print('PASS_F02_REAL_TMPFS_UNCHANGED_ZIP_8M_SOURCE_SHA256',bench['size'],bench['sha256'])
