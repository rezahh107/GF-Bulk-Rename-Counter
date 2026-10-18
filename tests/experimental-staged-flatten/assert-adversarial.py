#!/usr/bin/env python3
"""Test-only EXPECTED FALSIFICATIONS on closed native WordPress ZIP responses.

A produced but unsafe ZIP is evidence that this public-hook-only method does
NOT satisfy the Owner's integrity requirement. Do not treat it as success.
"""
import json
import sys
import zipfile
from pathlib import Path

from importlib.util import spec_from_file_location, module_from_spec


def require(ok, message):
    if not ok:
        raise AssertionError("SF_NEGATIVE_PROOF_INVALID: " + message)


def read_json_if_exists(path):
    return json.loads(path.read_text()) if path.exists() else None


def archive_info(path):
    require(zipfile.is_zipfile(path), f"actual native ZIP unavailable: {path.name}")
    with zipfile.ZipFile(path) as zip_file:
        return zip_file.namelist(), {name: zip_file.read(name) for name in zip_file.namelist()}


def temp_cleanup(lab, name):
    record = lab / f"sf-temp-path-{name}.txt"
    require(record.is_file(), f"native temporary ZIP filename not recorded: {name}")
    host_path = Path(record.read_text().strip())
    require(host_path.is_absolute(), f"invalid native temporary path: {name}")
    require(not host_path.exists(), f"native host temporary archive leaked: {name}")
    return True


def main():
    lab = Path(sys.argv[1])
    manifest = json.loads((lab / "manifest.json").read_text())
    expected = manifest["scenarios"]["shared"]["uploads"]
    require(len(expected) == 2, "shared original source fixture must contain two files")

    # Real ZipArchive::renameIndex() returned false; callback threw, but the
    # host delivered an unflattened archive anyway (host/GF action contract).
    scenario = "sf_fail_rename"
    names, _ = archive_info(lab / f"sf-failure-{scenario}.out")
    failed = lab / f"sf-real-rename-failed-{scenario}.txt"
    started = read_json_if_exists(lab / f"sf-flatten-start-{scenario}.json")
    require(failed.is_file() and "returned false" in failed.read_text(),
            "occupied renameIndex(false) was not reached")
    require(started and started["count"] == started["plan_count"] == 2,
            "rename failure finalizer was not reached with two staged members")
    require(len(names) == 2 and all("/gfb-stage-" in n for n in names),
            "unexpected native ZIP member paths after failed rename")
    require(not (lab / f"sf-flatten-success-{scenario}.txt").exists(), "failing flatten wrongly marked successful")
    temp_cleanup(lab, scenario)
    print("FALSIFIED_RENAME_FAILURE_NONDELIVERY: native host streamed staging ZIP after real renameIndex(false)")

    # Priority 5 callback appends an undeclared member BEFORE finalization.
    scenario = "sf_hook_early_extra"
    names, _ = archive_info(lab / f"sf-failure-{scenario}.out")
    started = read_json_if_exists(lab / f"sf-flatten-start-{scenario}.json")
    hook = read_json_if_exists(lab / f"sf-hook-{scenario}.txt")
    require(hook and hook["result"] and hook["after_count"] == 3,
            "third-party early final-hook addition did not happen")
    require(started and started["count"] == 3 and started["plan_count"] == 2,
            "finalizer was not confronted with the unregistered third member")
    require(len(names) == 3 and "third-party-extra.txt" in names and
            sum("/gfb-stage-" in n for n in names) == 2,
            "invalid unflattened ZIP returned for early hook addition")
    require(not (lab / f"sf-flatten-success-{scenario}.txt").exists(),
            "failing pre-check incorrectly marked successful")
    temp_cleanup(lab, scenario)
    print("FALSIFIED_PREHOOK_ERROR_PROPAGATION: extra staged member survived host streaming")

    # Priority 1001 name filter overrides staged paths AFTER our filter, but
    # BEFORE the host's addFile. 2 source occurrences become one member.
    scenario = "sf_post_stage_filename"
    names, members = archive_info(lab / "sf-post-stage.out")
    changes = (lab / "sf-post-stage-paths.txt").read_text().splitlines()
    before = read_json_if_exists(lab / "sf-post-stage-before.json")
    started = read_json_if_exists(lab / f"sf-flatten-start-{scenario}.json")
    require(len(changes) == 2 and all(" -> collapsed.jpg" in x for x in changes),
            "late filter did not override two staged occurrences")
    require(before and before["count"] == 1 and before["names"] == ["collapsed.jpg"],
            "native writer's pre-final-hook overwrite not reproduced")
    require(started and started["count"] == 1 and started["plan_count"] == 2,
            "finalizer did not see existing 2-to-1 source loss")
    require(names == ["collapsed.jpg"], "unsafe one-file archive did not reach client")
    import hashlib
    require(hashlib.sha256(members["collapsed.jpg"]).hexdigest() in
            {x["sha256"] for x in expected}, "remaining member is not a real source")
    temp_cleanup(lab, scenario)
    print("FALSIFIED_PRE_WRITER_STAGING: two source occurrences collapsed into one before final hook")

    # Third-party changes from either side of the finalizer. Closed archives
    # may be structurally valid, but are NOT source-conserving.
    file_path = Path(__file__).with_name("assert-case.py")
    spec = spec_from_file_location("staged_zip_integrity_assert", file_path)
    assert spec and spec.loader
    module = module_from_spec(spec)
    spec.loader.exec_module(module)
    for scenario in ["sf_hook_early_overwrite", "sf_hook_late_extra", "sf_hook_late_overwrite"]:
        path = lab / f"sf-{scenario}.zip"
        names, _ = archive_info(path)
        require((lab / f"sf-hook-{scenario}.txt").is_file(),
                f"third-party final hook not invoked: {scenario}")
        journal = json.loads((lab / f"sf-{scenario}.json").read_text())
        try:
            module.check(manifest, "shared", journal, path, scenario)
        except (AssertionError, ValueError, zipfile.BadZipFile):
            print(f"FALSIFIED_POST_HOOK_INTEGRITY {scenario} members={len(names)}")
        else:
            raise AssertionError(f"independent verifier falsely accepted {scenario}")
        temp_cleanup(lab, scenario)

    # A same-byte replacement after the finalizer is causally invisible even
    # though all paths, counts and SHA-256 values still match recorded sources.
    scenario = "sf_hook_late_same_bytes"
    path = lab / f"sf-{scenario}.zip"
    journal = json.loads((lab / f"sf-{scenario}.json").read_text())
    module.check(manifest, "shared", journal, path, scenario)
    require((lab / f"sf-hook-{scenario}.txt").is_file(), "same-byte overwrite not executed")
    temp_cleanup(lab, scenario)
    print("NOT_PROVEN_CAUSAL_PROVENANCE: same-byte post-hook overwrite is indistinguishable")

    # A mixed valid+unresolvable GF entry successfully downloads only one
    # member: matching the collector's reduced output can never prove
    # complete entry source eligibility by itself.
    scenario = "sf_mixed_missing"
    item = manifest["scenarios"][scenario]
    stored_values = next(iter(item["entry_values"].values()))
    require(stored_values["1"] and len(json.loads(stored_values["2"])) == 1,
            "GF mixed Entry must contain both valid and unresolvable URLs")
    path = lab / f"sf-{scenario}.zip"
    names, _ = archive_info(path)
    require(len(names) == 1 and "/" not in names[0],
            "native mixed collector did not silently emit a one-member ZIP")
    journal = json.loads((lab / f"sf-{scenario}.json").read_text())
    module.check(manifest, scenario, journal, path, scenario)
    temp_cleanup(lab, scenario)
    print("FALSIFIED_COLLECTOR_COMPLETENESS_VIA_PUBLIC_HOOK: GF Entry contains 2 referenced URLs, downloaded ZIP has 1")

    # The host's get_uploaded_files() can silently drop an unresolved URL
    # before any filename filter is called.
    scenario = "sf_missing_source"
    source = manifest["scenarios"][scenario]
    stored_values = next(iter(source["entry_values"].values()))
    require(stored_values["1"] and not source["uploads"],
            "missing-source fixture must retain an unresolved GF URL but no accessible file")
    response = lab / "sf-missing.out"
    failure = lab / f"sf-{scenario}.exit"
    require(response.exists() and failure.exists(),
            "native missing-source request and exit status not recorded")
    require(int(failure.read_text().strip()) != 0 and not zipfile.is_zipfile(response),
            "host must reject the all-unresolved case without returning a ZIP")
    require(not (lab / f"sf-{scenario}.json").exists(),
            "unavailable source unexpectedly reached the staging hook")
    require(not (lab / f"sf-flatten-start-{scenario}.json").exists(),
            "all-missing case unexpectedly reached the final flatten hook")
    print("NOT_PROVEN_COLLECTOR_COMPLETENESS: unresolved GF URL never reaches the public filename/finalizer hooks")


if __name__ == "__main__":
    main()
