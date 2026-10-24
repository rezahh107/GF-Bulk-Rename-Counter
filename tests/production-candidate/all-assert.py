#!/usr/bin/env python3
"""Independently inspect ALL browser-saved ZIPs against the original GF source bytes."""
import collections
import hashlib
import json
import pathlib
import sys
import zipfile

lab = pathlib.Path(sys.argv[1])
original = json.loads((lab / "manifest.json").read_text())["scenarios"]["single"]["uploads"][0]
source_hash = original["sha256"]
source_size = original["size"]
for filename, expected in [
    ("all-large-filtered.zip", 504),
    ("all-large-repeat.zip", 504),
    ("new-all-after-ack.zip", 155),
]:
    archive = lab / filename
    if not archive.exists():
        raise AssertionError(f"Missing real browser download {filename}")
    with zipfile.ZipFile(archive) as z:
        infos = z.infolist()
        if len(infos) != expected:
            raise AssertionError(f"{filename}: lost member: {len(infos)} != {expected}")
        names = [item.filename for item in infos]
        if len({n.casefold() for n in names}) != expected:
            raise AssertionError(f"{filename}: duplicate name")
        if any("/" in n or "\\" in n or not n for n in names):
            raise AssertionError(f"{filename}: non-flat or empty name")
        found = collections.Counter()
        for member in infos:
            with z.open(member) as f:
                sha = hashlib.sha256()
                length = 0
                while chunk := f.read(65536):
                    length += len(chunk)
                    sha.update(chunk)
                if length != source_size or length != member.file_size:
                    raise AssertionError(f"{filename}: source length mismatch")
                found[sha.hexdigest()] += 1
        if found != collections.Counter({source_hash: expected}):
            raise AssertionError(f"{filename}: missing/corrupted source occurrences")
    if filename.startswith("all-large-"):
        # Every eligible Entry identity must be represented; equal source
        # hashes alone would not detect replacing one Entry with another.
        fixture = json.loads((lab / "all-independent-form.json").read_text())
        assert len(fixture["ids"]) == 503
        natural = "single-a.jpg"
        assert natural in names, f"{filename}: missing natural source name"
        assert "single-a-" + str(fixture["ids"][1]) + "-1.jpg" in names, f"{filename}: lost multi-file occurrence"
        for entry_id in fixture["ids"][1:]:
            expected_name = "single-a-" + str(entry_id) + ".jpg"
            assert expected_name in names, f"{filename}: omitted Entry {entry_id}"
        assert all(str(fixture["inactive"]) not in item for item in names), f"{filename}: ineligible spam Entry included"
    print(f"PASS_ALL_SAVED_ZIP_MATCHES_SOURCE_SHA256 {filename} entries={expected} source_bytes={source_size}")
