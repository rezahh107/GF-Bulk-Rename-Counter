#!/usr/bin/env python3
"""Fail-closed 501 Entry enumeration and 501-field request size probes."""
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import subprocess
import sys

lab=Path(sys.argv[1])
obj=json.loads((lab/'selection-limit.json').read_text())
fid=obj["form_id"]
assert obj["entries"]==501
base="http://127.0.0.1:8080"
meta=json.loads((lab/'independent.json').read_text())
base=meta["endpoint"].split('/wp-admin/admin-post.php')[0]

class Parser(HTMLParser):
    config=None
    def handle_starttag(self, tag, attrs):
        a=dict(attrs)
        if tag=="script" and a.get("id")=="gfbrc-independent-flat-zip":
            self.config=json.loads(a["data-config"])

page=subprocess.run(["curl","-fsSL","--cookie",str(lab/'auth.cookies'),
   f"{base}/wp-admin/admin.php?page=gf_entries&id={fid}"],
   capture_output=True,text=True,check=True).stdout
p=Parser();p.feed(page)
assert p.config and p.config["formId"]==fid and p.config["scopeSeal"]
assert p.config["matchingCount"]==501, "Operator preview must disclose over-500 scope"
prefix=["curl","--silent","--show-error","--max-time","75",
        "--cookie",str(lab/'auth.cookies'),"-D","-","-o","/dev/null",
        "--data-urlencode","action=gfbrc_flat_zip_download",
        "--data-urlencode",f"form_id={fid}",
        "--data-urlencode","_wpnonce="+p.config["nonce"]]
def status(args):
    r=subprocess.run(args+[meta["endpoint"]],capture_output=True,text=True,check=True)
    codes=re.findall(r"^HTTP/\S+\s+(\d+)",r.stdout,re.M)
    assert codes, r.stdout[:300]
    assert "content-disposition: attachment" not in r.stdout.lower()
    return int(codes[-1])
global_req=prefix+["--data-urlencode","selection_mode=all_matching",
    "--data-urlencode","selection_scope="+p.config["scope"],
    "--data-urlencode","selection_scope_seal="+p.config["scopeSeal"]]
assert status(global_req)==422,"501 Entry all-matching should reject before archive"
print("PASS_GLOBAL_501_ENTRIES_REJECTED_NO_PARTIAL_ZIP",flush=True)
explicit_req=prefix+["--data-urlencode","selection_mode=explicit"]
explicit_req.extend(item for _ in range(501) for item in
                    ["--data-urlencode","entry_ids[]=1"])
assert status(explicit_req)==400,"501 explicit request fields should reject before archive"
print("PASS_EXPLICIT_501_POST_FIELDS_REJECTED_NO_PARTIAL_ZIP",flush=True)
