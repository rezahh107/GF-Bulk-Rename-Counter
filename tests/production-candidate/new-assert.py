#!/usr/bin/env python3
"""Independent browser-saved NEW ZIP audit against fixture source hashes."""
import collections
import hashlib
import json
import pathlib
import sys
import zipfile

lab = pathlib.Path(sys.argv[1])
summary = json.loads((lab / "new-browser-summary.json").read_text())
manifest = json.loads((lab / "manifest.json").read_text())["scenarios"]
source_hashes = [item["sha256"] for item in manifest["single"]["uploads"]]
assert len(source_hashes) == 1
source_hash = source_hashes[0]

for key, file, count in [
    ("initial", "new-initial.zip", 155),
    ("incremental", "new-incremental.zip", 1),
]:
    target = lab / file
    blob = target.read_bytes()
    assert summary[key]["sha"] == hashlib.sha256(blob).hexdigest()
    assert summary[key]["files"] == count
    with zipfile.ZipFile(target) as zipped:
        names = zipped.namelist()
        assert len(names) == count, (key, len(names), count)
        assert len({name.casefold() for name in names}) == count
        assert all("/" not in name and "\\" not in name for name in names)
        observed = []
        for info in zipped.infolist():
            payload = zipped.read(info.filename)
            assert len(payload) == info.file_size
            observed.append(hashlib.sha256(payload).hexdigest())
        assert collections.Counter(observed) == collections.Counter([source_hash] * count)
    print("PASS_NEW_BROWSER_SAVED_ZIP_SOURCE_HASHES", key, "files", count,
          "sha256", summary[key]["sha"])
