#!/usr/bin/env python3
"""HTTP all-matching request pinned during concurrent live GF membership change."""
import hashlib
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import subprocess
import sys
import time
import zipfile

lab = Path(sys.argv[1])
meta = json.loads((lab / "independent.json").read_text())
plans = json.loads((lab / "independent-plans.json").read_text())
case = meta["cases"]["global154"]
endpoint = meta["endpoint"]
form_id = case["form_id"]
url = endpoint.replace("admin-post.php", f"admin.php?page=gf_entries&id={form_id}")
for name in ("mutation.ready", "mutation.go", "mutation-changes.json"):
    (lab / name).unlink(missing_ok=True)


class Config(HTMLParser):
    value = None

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "script" and a.get("id") == "gfbrc-independent-flat-zip":
            self.value = json.loads(a["data-config"])


page = subprocess.run(["curl", "-fsSL", "--cookie", str(lab / "auth.cookies"), url],
                      text=True, capture_output=True, check=True).stdout
parser = Config()
parser.feed(page)
cfg = parser.value
assert cfg and cfg["scopeSeal"] and cfg["formId"] == form_id, "Real Entry List scope not present"
archive_path, headers_path = lab / "mutation.zip", lab / "mutation.headers"
args = ["curl", "--fail-with-body", "--silent", "--show-error", "--max-time", "90",
        "--cookie", str(lab / "auth.cookies"), "-D", str(headers_path),
        "-o", str(archive_path),
        "--data-urlencode", "action=gfbrc_flat_zip_download",
        "--data-urlencode", f"form_id={form_id}",
        "--data-urlencode", "_wpnonce=" + cfg["nonce"],
        "--data-urlencode", "selection_mode=all_matching",
        "--data-urlencode", "selection_scope=" + cfg["scope"],
        "--data-urlencode", "selection_scope_seal=" + cfg["scopeSeal"],
        "--data-urlencode", "gfbrc_mutation_lab=snapshot-membership", endpoint]
proc = subprocess.Popen(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
try:
    deadline = time.monotonic() + 30
    while not (lab / "mutation.ready").exists() and proc.poll() is None:
        if time.monotonic() > deadline:
            raise RuntimeError("Real HTTP selection did not reach GF SQL snapshot barrier")
        time.sleep(0.05)
    assert proc.poll() is None and (lab / "mutation.ready").exists(), "Snapshot barrier not reached"
    env = dict(os.environ)
    result = subprocess.run(["php", env["BRC_WP_CLI"], "--path=" + env["BRC_WP_PATH"],
                             "--user=bootstrap_admin", "eval-file",
                             "tests/selection-qualification/mutate.php"],
                            text=True, capture_output=True, timeout=40)
    assert result.returncode == 0, (result.stdout, result.stderr)
finally:
    (lab / "mutation.go").write_text("continue after other connection commit")
out, err = proc.communicate(timeout=60)
assert proc.returncode == 0, (proc.returncode, out, err, archive_path.read_text(errors="replace")[:300] if archive_path.exists() else "")
changes = json.loads((lab / "mutation-changes.json").read_text())
assert changes["removed_id"] in case["entry_ids"] and changes["inserted_id"] not in case["entry_ids"]
headers = headers_path.read_text().lower()
assert "x-gfbrc-selected-entries: 154" in headers, headers
assert "x-gfbrc-zip-files: 155" in headers, headers
plan = plans["global154"]
assert len(plan) == 155
with zipfile.ZipFile(archive_path) as archive:
    assert archive.testzip() is None
    assert archive.namelist() == [x["name"] for x in plan], "Membership changed inside consistent snapshot"
    for item in plan:
        assert hashlib.sha256(archive.read(item["name"])).hexdigest() == item["sha256"]
    assert changes["inserted_id"] not in {x["entry_id"] for x in plan}
    assert changes["removed_id"] in {x["entry_id"] for x in plan}
print("PASS_REAL_HTTP_CONSISTENT_SELECTION_DURING_COMMITTED_GF_MEMBERSHIP_CHANGE "
      "entries=154 files=155 excluded_later_insert=" + str(changes["inserted_id"]), flush=True)
