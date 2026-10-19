#!/usr/bin/env python3
"""Authenticated genuine WP HTTP tests: Windows-reserved names must never return ZIP."""
import collections
import hashlib
import json
import pathlib
import subprocess
import sys
import zipfile

lab = pathlib.Path(sys.argv[1])
fixture = json.loads((lab / "windows-names.json").read_text())
auth = json.loads((lab / "independent.json").read_text())
cookies = lab / "auth.cookies"
for label, scenario in fixture["cases"].items():
    dest = lab / ("win-device-" + label + ".body")
    headers = lab / ("win-device-" + label + ".headers")
    args = ["curl", "--silent", "--show-error", "--location", "--max-redirs", "3",
            "--max-time", "45", "-D", str(headers), "-o", str(dest),
            "-w", "%{http_code}", "--request", "POST", "--cookie", str(cookies),
            "--data-urlencode", "action=gfbrc_flat_zip_download",
            "--data-urlencode", f"form_id={scenario['form_id']}",
            "--data-urlencode", f"_wpnonce={auth['nonces'][str(scenario['form_id'])]}"]
    for entry_id in scenario["entry_ids"]:
        args.extend(["--data-urlencode", f"entry_ids[]={entry_id}"])
    result = subprocess.run(args + [auth["endpoint"]], check=True,
                            capture_output=True, text=True)
    status = result.stdout.strip().splitlines()[-1]
    head = headers.read_text(errors="replace").lower()
    payload = dest.read_bytes()
    if not scenario["valid"]:
        assert status == "400", (label, status, payload[:500])
        assert not payload.startswith(b"PK"), label
        assert "content-disposition: attachment" not in head, label
        assert "application/zip" not in head, label
        assert b"Windows reserved device" in payload, (label, payload[:500])
        print("PASS_WINDOWS_RESERVED_HTTP_NO_ZIP", label, status, flush=True)
        continue
    assert status == "200", (label, status, payload[:500])
    assert payload.startswith(b"PK"), label
    assert "content-type: application/zip" in head
    assert "content-disposition: attachment" in head
    plan = fixture["plans"][label]
    with zipfile.ZipFile(dest) as archive:
        members = archive.infolist()
        assert len(members) == len(plan), (label, len(members), len(plan))
        assert [member.filename for member in members] == [p["name"] for p in plan]
        assert archive.testzip() is None, label
        actual_sources = collections.Counter()
        for member, expected in zip(members, plan):
            digest = hashlib.sha256()
            with archive.open(member) as f:
                while chunk := f.read(65536):
                    digest.update(chunk)
            assert member.file_size == expected["size"]
            assert digest.hexdigest() == expected["sha256"]
            source = pathlib.Path(expected["source"])
            assert hashlib.sha256(source.read_bytes()).hexdigest() == digest.hexdigest()
            actual_sources[str(source)] += 1
        expected_sources = collections.Counter(p["source"] for p in plan)
        assert actual_sources == expected_sources, label
    print("PASS_WINDOWS_VALID_HTTP_ZIP_MEMBER_COUNT_PATH_BYTES_SHA256", label, flush=True)
    if label == "unicode_casefold_pair":
        names = [p["name"] for p in plan]
        folded = [name.casefold() for name in names]
        print("DIAGNOSTIC_UNICODE_CASEFOLD_NAMES", repr(names),
              "equal_under_unicode_casefold=", len(set(folded)) < len(folded),
              "windows_extraction_collision=NOT_PROVEN", flush=True)
