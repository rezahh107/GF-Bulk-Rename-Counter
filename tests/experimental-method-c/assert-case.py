#!/usr/bin/env python3
"""Independent original-source occurrence and native ZIP verifier."""
import collections
import hashlib
import io
import json
from pathlib import Path
import sys
import warnings
import zipfile


def require(value, detail):
    if not value:
        raise RuntimeError("METHOD_C_INTEGRITY_REJECTED: " + detail)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def check(manifest, scenario, plan, raw, experiment):
    original = manifest["scenarios"][scenario]
    provenance = plan["occurrences"]
    expected = collections.Counter((x["path"], x["sha256"]) for x in original["uploads"])
    actual = collections.Counter((x["source"], x["sha256"]) for x in provenance)
    require(actual == expected, "source occurrence multiset mismatch")
    ids = set(original["entry_ids"])
    identities = [(x["entry_id"], x["ordinal"]) for x in provenance]
    require(len(identities) == len(set(identities)), "duplicate occurrence identity")
    require(all(x["entry_id"] in ids for x in provenance), "unexpected entry ID")
    for x in provenance:
        require(digest(Path(x["source"]).read_bytes()) == x["sha256"],
                "original source bytes drift")
    with zipfile.ZipFile(io.BytesIO(raw)) as archive:
        names = archive.namelist()
        require(len(names) == len(provenance), "ZIP member count")
        require(len(names) == len(set(names)), "duplicate ZIP member paths")
        require(all("/" not in x and "\\" not in x and x not in (".", "..") for x in names),
                "archive not flat")
        require(set(names) == {x["member"] for x in provenance},
                "member identity/mapping mismatch")
        for x in provenance:
            require(digest(archive.read(x["member"])) == x["sha256"],
                    "archived SHA-256 misattributed")
        # Independent content conservation, not just totals.
        require(collections.Counter(digest(archive.read(n)) for n in names) ==
                collections.Counter(x["sha256"] for x in original["uploads"]),
                "original / archived hash multisets differ")
    if experiment != "mc_late_filter" and scenario in ("single", "shared", "multiple", "sanitized_equal", "multi_entry_files"):
        # Existing counter naming remains unchanged for all already-flat members.
        for name, sha in original["expected"].items():
            if "/" not in name:
                require(any(x["member"] == name and x["sha256"] == sha for x in provenance),
                        "legacy d+Counter naming drift: " + name)
    if experiment == "mc_late_filter":
        require(any(x["member"] == "forced-same.jpg" for x in provenance),
                "later filter did not reach host")
        require(all(x["candidate"] == "forced-same.jpg" for x in provenance),
                "later filter was not the final candidate")
    return len(names)


def main():
    if len(sys.argv) != 6:
        raise SystemExit("Usage: assert-case.py manifest fixture-case journal archive experiment")
    manifest_path, fixture, journal, archive, experiment = sys.argv[1:]
    manifest = json.loads(Path(manifest_path).read_text())
    plan = json.loads(Path(journal).read_text())
    raw = Path(archive).read_bytes()
    count = check(manifest, fixture, plan, raw, experiment)
    if experiment == "mc_hook_same_bytes":
        require(Path(Path(journal).parent / ("mc-hook-" + experiment + ".txt")).is_file(),
                "final hook did not execute")
        print("NOT_ADMISSIBLE_WITH_UNDECLARED_POST_HOOK: same-byte overwrite "
              "undetectable by complete path/count/content validation")
    else:
        print(f"PASS_METHOD_C_BOUNDED {experiment} eligible={count} ZIP={count} flat=true")
    # Falsification: independently mutate actual ZIP bytes with wrong payload,
    # missing / extra / duplicate members, and misattribute original membership.
    members = []
    with zipfile.ZipFile(io.BytesIO(raw)) as z:
        for name in z.namelist():
            members.append((name, z.read(name)))
    cases = {
        "corrupt": [(name, b"wrong") if i == 0 else (name, data)
                    for i, (name, data) in enumerate(members)],
        "missing": members[:-1],
        "extra": members + [("unregistered.txt", b"extra")],
        "duplicate": members + [members[0]],
    }
    if len(members) > 1 and members[0][1] != members[1][1]:
        cases["misattributed"] = [
            (name, members[1][1]) if i == 0 else
            (name, members[0][1]) if i == 1 else (name, data)
            for i, (name, data) in enumerate(members)
        ]
    if len(members) > 1 and members[0][1] == members[1][1]:
        print('NOT_OBSERVABLE_EQUAL_BYTES_ATTRIBUTION: path/hash cannot identify which occurrence physically supplied bytes')
    for label, mutation in cases.items():
        out = io.BytesIO()
        with warnings.catch_warnings():
            warnings.simplefilter("ignore", UserWarning)
            with zipfile.ZipFile(out, "w") as z:
                for name, data in mutation:
                    z.writestr(name, data)
        try:
            check(manifest, fixture, plan, out.getvalue(), experiment)
        except (RuntimeError, zipfile.BadZipFile):
            print("PASS_NEGATIVE_CONTROL_REJECTED", label, experiment)
        else:
            raise RuntimeError("Negative control was accepted: " + label)


if __name__ == "__main__":
    main()
