#!/usr/bin/env python3
"""Independent closed-ZIP oracle for authoritative GF file-state mutation race."""
from collections import Counter
import hashlib
import json
from pathlib import Path
import sys
import zipfile

lab = Path(sys.argv[1])
report = json.loads((lab / "all-existing-race-result.json").read_text())
a, b = report["fixture"]["a"], report["fixture"]["b"]
if a["sha256"] == b["sha256"]:
    raise AssertionError("Distinct A/B controls were not distinct")
for k in ("reference", "deletion"):
    entry = report["fixture"]["cases"][k]["entry"]
    scenario = report["cases"][k]
    if scenario["before"]["files"] != (1 if k == "reference" else 2):
        raise AssertionError("Invalid pre-write state")
    if scenario["after"]["files"] != 1:
        raise AssertionError("Mutation did not materially change authoritative file state")
    if scenario["before"]["plan_sha256"] == scenario["after"]["plan_sha256"]:
        raise AssertionError("No meaningful file-state change")
    if scenario["stale"]["status"] != 200 or scenario["fresh"]["status"] != 200:
        raise AssertionError("One HTTP download was not successful")
    hashes = {}
    names = {}
    for label in ("stale", "fresh"):
        with zipfile.ZipFile(lab / scenario[label]["filename"]) as zipf:
            member_names = zipf.namelist()
            if any("/" in n or "\\" in n for n in member_names):
                raise AssertionError("Non-flat ZIP")
            if len(set(x.casefold() for x in member_names)) != len(member_names):
                raise AssertionError("ZIP collision")
            digests = []
            for name in member_names:
                payload = zipf.read(name)
                digests.append(hashlib.sha256(payload).hexdigest())
                if not payload:
                    raise AssertionError("Unexpected empty source")
            hashes[label] = Counter(digests)
            names[label] = member_names
    if k == "reference":
        assert hashes["stale"] == Counter([a["sha256"]]), hashes
        assert hashes["fresh"] == Counter([b["sha256"]]), hashes
        assert names["stale"] == names["fresh"] == ["dRACE.jpg"], names
    else:
        assert hashes["stale"] == Counter([a["sha256"], b["sha256"]]), hashes
        assert hashes["fresh"] == Counter([b["sha256"]]), hashes
        assert len(names["stale"]) == 2 and names["fresh"] == ["dRACE.jpg"], names
    assert scenario["after"]["plan_sha256"] == [b["sha256"]], scenario
    assert scenario["after"]["a_readable"] and scenario["after"]["b_readable"], scenario
    print("CONFIRMED_ALL_EXISTING_ENTRY_MUTATION_ZIP_STALE_BUT_HTTP200",
          k, "entry", entry, "old_files", len(names["stale"]),
          "current_files", len(names["fresh"]), "old_hashes", dict(hashes["stale"]),
          "current_hashes", dict(hashes["fresh"]))
print("PASS_AUTHENTIC_GF_DUAL_MUTATION_FALSIFICATION_ORACLE")
