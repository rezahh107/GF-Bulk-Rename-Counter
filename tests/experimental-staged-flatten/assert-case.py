#!/usr/bin/env python3
"""Independent streaming source-to-closed-ZIP conservation assertion. Non-production."""
import collections
import hashlib
import json
from pathlib import Path
import sys
import zipfile


def sha_stream(stream):
    digest = hashlib.sha256()
    while True:
        chunk = stream.read(1024 * 1024)
        if not chunk:
            return digest.hexdigest()
        digest.update(chunk)


def require(ok, why):
    if not ok:
        raise AssertionError("SF_INTEGRITY_REJECTED: " + why)


def check(manifest, fixture, journal, path, scenario):
    source = manifest["scenarios"][fixture]
    plan = journal["occurrences"]
    original = collections.Counter((x["path"], x["sha256"]) for x in source["uploads"])
    recorded = collections.Counter((x["source"], x["sha256"]) for x in plan)
    require(original == recorded, "missing/extra source occurrences")
    require(len(plan) == len(source["uploads"]), "incorrect occurrence count")
    require(len({(x["entry_id"], x["ordinal"]) for x in plan}) == len(plan),
            "duplicate source occurrence identity")
    require(all(x["entry_id"] in source["entry_ids"] for x in plan), "unknown Entry")
    for item in plan:
        with open(item["source"], "rb") as stream:
            require(sha_stream(stream) == item["sha256"], "original upload changed")
    with zipfile.ZipFile(path) as archive:
        infos = archive.infolist()
        names = [x.filename for x in infos]
        require(len(names) == len(plan), "final member count")
        require(len(names) == len(set(names)), "duplicate exact member paths")
        require(len({name.casefold() for name in names}) == len(names),
                "case-insensitive member collision")
        require(all("/" not in n and "\\\\" not in n and n not in ("", ".", "..")
                    for n in names), "internal ZIP folder")
        require(set(names) == {x["member"] for x in plan}, "source/member mapping")
        for item in plan:
            with archive.open(item["member"]) as stream:
                require(sha_stream(stream) == item["sha256"], "archive payload mismatch")
        require(collections.Counter(x["sha256"] for x in plan) ==
                collections.Counter(x["sha256"] for x in source["uploads"]),
                "source digest multiset")
    if fixture in ("single", "shared", "multiple", "sanitized_equal", "multi_entry_files") and scenario != "sf_late_filter":
        for name, digest in source["expected"].items():
            if "/" not in name:
                require(any(x["member"] == name and x["sha256"] == digest for x in plan),
                        "legacy noncolliding name changed: " + name)
    if scenario == "sf_late_filter":
        require(all(x["candidate"] == "forced-same.jpg" for x in plan),
                "late filter did not produce staged candidates")
    return {"state": "PASS_BOUNDED_STAGED_FLATTEN", "scenario": scenario,
            "sources": len(plan), "zip_members": len(names),
            "zip_bytes": path.stat().st_size}


def main():
    if len(sys.argv) != 6:
        raise SystemExit("Usage: assert-case.py manifest fixture journal archive scenario")
    manifest, fixture, journal, archive, scenario = sys.argv[1:]
    print(json.dumps(check(json.loads(Path(manifest).read_text()), fixture,
                           json.loads(Path(journal).read_text()), Path(archive), scenario)))


if __name__ == "__main__":
    main()
