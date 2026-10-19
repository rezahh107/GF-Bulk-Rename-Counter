#!/usr/bin/env python3
"""Verify real authenticated response boundary for PHP output-buffer configurations."""
import glob
import hashlib
import json
import pathlib
import subprocess
import sys
import tempfile
import zipfile

lab = pathlib.Path(sys.argv[1])
meta = json.loads((lab / "independent.json").read_text())
plan = json.loads((lab / "independent-plans.json").read_text())["one"]
one = meta["cases"]["one"]
nonce = meta["nonces"][str(one["form_id"])]
expected_files = sorted(set(glob.glob(str(pathlib.Path(tempfile.gettempdir()) / "gfbrc-independent-flat*"))))

for case in ("removable", "nested", "nonremovable", "stack"):
    body = lab / ("buffer-" + case + ".body")
    headers = lab / ("buffer-" + case + ".headers")
    args = [
        "curl", "--silent", "--show-error", "--max-time", "8",
        "-D", str(headers), "-o", str(body), "-w", "%{http_code}",
        "--cookie", str(lab / "auth.cookies"), "--request", "POST",
        "--data-urlencode", "action=gfbrc_flat_zip_download",
        "--data-urlencode", f"form_id={one['form_id']}",
        "--data-urlencode", f"_wpnonce={nonce}",
        "--data-urlencode", f"entry_ids[]={one['entry_ids'][0]}",
        "--data-urlencode", f"gfbrc_buffer_lab={case}",
        meta["endpoint"],
    ]
    result = subprocess.run(args, capture_output=True, text=True, timeout=12, check=False)
    if result.returncode:
        raise AssertionError(f"Buffer {case}: endpoint did not terminate: {result.stderr[-400:]}")
    status = result.stdout.strip()
    response_headers = headers.read_text(errors="replace").lower()
    payload = body.read_bytes()
    if case in ("removable", "nested"):
        if status != "200" or "content-disposition: attachment;" not in response_headers:
            raise AssertionError(f"Buffer {case}: expected valid ZIP, got HTTP {status}")
        with zipfile.ZipFile(body) as archive:
            if len(archive.infolist()) != len(plan) or archive.testzip() is not None:
                raise AssertionError(f"Buffer {case}: corrupted ZIP")
            for member, source in zip(archive.infolist(), plan):
                if member.filename != source["name"] or hashlib.sha256(
                    archive.read(member)
                ).hexdigest() != source["sha256"]:
                    raise AssertionError(f"Buffer {case}: source/ZIP mismatch")
        print(f"PASS_AUTHENTIC_BUFFER_DOWNLOAD {case}", flush=True)
    else:
        if (status != "422" or "content-disposition: attachment;" in response_headers
                or "content-type: application/zip" in response_headers or payload[:2] == b"PK"
                or b"Cannot remove output buffer before ZIP response" not in payload):
            raise AssertionError(f"Buffer {case}: fail-closed response not established: HTTP {status}, "
                                 f"headers={response_headers[-400:]}, body={payload[:350]!r}")
        print(f"PASS_AUTHENTIC_BUFFER_NO_ZIP_PREHEADER {case}", flush=True)
    actual_files = sorted(set(glob.glob(str(pathlib.Path(tempfile.gettempdir()) /
                                              "gfbrc-independent-flat*"))))
    if actual_files != expected_files:
        raise AssertionError(f"Buffer {case}: temporary ZIP leaked: "
                             f"{set(actual_files) ^ set(expected_files)}")
    print(f"PASS_BUFFER_TEMP_CLEANUP {case}", flush=True)
