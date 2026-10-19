#!/usr/bin/env python3
"""Independent reader of HTTP-downloaded ZIPs; no prototype plan imported."""
import collections
import hashlib
import json
import os
import pathlib
import sys
import zipfile

manifest = json.loads(pathlib.Path(sys.argv[1]).read_text())["scenarios"]
cases = json.loads(pathlib.Path(sys.argv[2]).read_text())["cases"]
name = sys.argv[3]
archive = pathlib.Path(sys.argv[4])

def hashes(case):
    return [x["sha256"] for x in manifest[case]["uploads"]]

if name in ("one", "n99", "n100", "n101"):
    n = {"one": 1, "n99": 99, "n100": 100, "n101": 101}[name]
    expected = hashes("single") * n
elif name in ("multiple", "shared", "sanitized_equal", "multi_entry_files", "host_folder_collision"):
    expected = hashes(name)
elif name in ("zero_counter", "empty_counter"):
    expected = hashes("single")
elif name == "repeated_physical":
    expected = hashes("single") * 3
elif name == "previously_deleted":
    expected = hashes("shared")[:1]
else:
    raise AssertionError("unknown positive case: " + name)

assert archive.stat().st_size > 100, f"{name}: empty ZIP response"
with zipfile.ZipFile(archive) as z:
    infos = z.infolist()
    paths = [info.filename for info in infos]
    assert len(paths) == len(expected), f"{name}: expected {len(expected)} members, actual {len(paths)}"
    assert len(set(p.casefold() for p in paths)) == len(paths), f"{name}: duplicate portable paths"
    assert all("/" not in p and "\\" not in p and p not in ("", ".", "..") for p in paths)
    actual = []
    for info in infos:
        dig = hashlib.sha256()
        total = 0
        with z.open(info) as member:
            while part := member.read(65536):
                dig.update(part)
                total += len(part)
        assert total == info.file_size
        actual.append(dig.hexdigest())
    assert collections.Counter(actual) == collections.Counter(expected), f"{name}: source occurrence loss"
    if name in ("one", "multiple", "n99", "n100", "n101"):
        assert "d125.jpg" in paths, f"{name}: Legacy natural name lost"
    if name in ("zero_counter", "empty_counter"):
        assert paths == ["single-a.jpg"], f"{name}: unexpected fallback name {paths}"
    if name == "host_folder_collision":
        assert "same.jpg" in paths, f"{name}: natural name stolen"
    if name == "shared":
        assert "d125.jpg" in paths and len(paths) == 2
    if name == "previously_deleted":
        assert paths == ["d125.jpg"], f"{name}: deleted URL leaked or fallback misnamed {paths}"
    print(json.dumps({"state":"PASS_INDEPENDENT_FLAT_ZIP", "case":name,"count":len(paths),
       "bytes":archive.stat().st_size,"member_names":paths[:5]}))
