#!/usr/bin/env python3
"""Falsify the exact diagnostic gate with mutations of a *real* native ZIP.

Usage: python3 falsify-zip.py manifest.json native-collision.zip assert-zip.php [scenario]
No test-only WordPress filters or production files are involved.
"""
import copy
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import warnings
import zipfile


def run_assert(checker, manifest_path, zip_path, scenario):
    result = subprocess.run(
        ["php", str(checker), str(manifest_path), scenario, str(zip_path)],
        capture_output=True,
        text=True,
        check=False,
    )
    try:
        report = json.loads(result.stdout)
    except json.JSONDecodeError:
        report = None
    return result, report


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def make_zip(path, members):
    with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_STORED) as archive:
        with warnings.catch_warnings():
            warnings.simplefilter("ignore", UserWarning)
            for member, contents in members:
                archive.writestr(member, contents)


def main():
    if len(sys.argv) not in (4, 5):
        raise SystemExit("Usage: falsify-zip.py manifest.json native.zip assert-zip.php [scenario]")
    manifest_path, native_path, checker = map(Path, sys.argv[1:4])
    scenario = sys.argv[4] if len(sys.argv) == 5 else "host_folder_collision"
    allowed = {
        "host_folder_collision",
        "default_equal_within_entry",
        "entry_folder_equal_within_entry",
        "shared_sanitized_upload_collision",
    }
    require(scenario in allowed, "Not a qualified native host diagnostic")
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    case = manifest["scenarios"][scenario]
    uploads = case["uploads"]
    require(len(uploads) == 2, "Genuine native case must record two uploads")

    source_bytes = [Path(item["path"]).read_bytes() for item in uploads]
    source_hashes = [hashlib.sha256(data).hexdigest() for data in source_bytes]
    require(source_hashes == [item["sha256"] for item in uploads],
            "Genuine original source bytes do not match manifest")
    require(len(set(source_hashes)) == 2, "Genuine sources are not distinct")

    result, report = run_assert(checker, manifest_path, native_path, scenario)
    member = report.get("expected_collision_member") if report else None
    require(isinstance(member, str) and "/" in member, "Expected member identity missing")
    require(result.returncode == 0 and report is not None and
            report["state"] == "NATIVE_ZIP_INTEGRITY_VIOLATION_OBSERVED" and
            report["members"] == {member: report["members"].get(member)} and
            report["members"][member] in source_hashes and
            report["required_source_count"] == 2 and report["count"] == 1,
            "Actual native ZIP did not prove the EXACT host collision: " + result.stdout + result.stderr)
    with zipfile.ZipFile(native_path) as archive:
        surviving_bytes = archive.read(member)
    require(hashlib.sha256(surviving_bytes).hexdigest() in source_hashes,
            "Native member contents do not match either genuine source")

    with tempfile.TemporaryDirectory(prefix="brc-zip-falsify-") as tmp:
        directory = Path(tmp)
        different = b"unrelated member payload - never present in native source uploads"
        require(hashlib.sha256(different).hexdigest() not in source_hashes,
                "Unrelated digest is accidentally a source hash")

        other_member = member.rsplit("/", 1)[0] + "/other.jpg"
        mutation_cases = {
            "outside_expected_folder": [("other/same.jpg", surviving_bytes)],
            "unrelated_member_hash": [(member, different)],
            "zero_members": [],
            "duplicate_member_path": [
                (member, source_bytes[0]),
                (member, source_bytes[1]),
            ],
            "wrong_member_name": [(other_member, surviving_bytes)],
            "extra_unrelated_member": [
                (member, surviving_bytes),
                (other_member, different),
            ],
        }
        for name, members in mutation_cases.items():
            zip_path = directory / (name + ".zip")
            make_zip(zip_path, members)
            failed, out = run_assert(checker, manifest_path, zip_path, scenario)
            require(failed.returncode != 0 and
                    (out is None or out.get("state") != "NATIVE_ZIP_INTEGRITY_VIOLATION_OBSERVED"),
                    "Unrelated ZIP fault was admitted as diagnostic: " + name)
            print("PASS reject nonconforming native-ZIP mutation:", name)

        malformed_path = directory / "malformed.zip"
        malformed_path.write_bytes(b"not-a-zip")
        failed, out = run_assert(checker, manifest_path, malformed_path, scenario)
        require(failed.returncode != 0 and out is None,
                "Malformed ZIP accepted as diagnostic")
        print("PASS reject malformed archive")

        conserving_path = directory / "fully-conserving.zip"
        stem, extension = member.rsplit(".", 1)
        make_zip(conserving_path, [
            (member, source_bytes[0]),
            (stem + "-2." + extension, source_bytes[1]),
        ])
        not_reproduced, out = run_assert(checker, manifest_path, conserving_path, scenario)
        require(not_reproduced.returncode != 0 and out is not None and
                out["state"] == "NOT_REPRODUCED" and out["count"] == 2 and
                set(out["members"].values()) == set(source_hashes),
                "Full source conservation did not demand requalification")
        print("PASS fully conserving ZIP is NOT_REPRODUCED (non-zero exit)")

        for name, mutation in (
            ("missing_source_digest", lambda c: c["uploads"][0].pop("sha256")),
            ("ambiguous_source_hashes", lambda c: c["uploads"][1].update(
                sha256=c["uploads"][0]["sha256"])),
            ("wrong_expected_folder", lambda c: c.update(
                expected_path_prefix="not-shared/")),
            ("missing_source_identity", lambda c: c["uploads"].pop()),
        ):
            modified = copy.deepcopy(manifest)
            mutation(modified["scenarios"][scenario])
            changed = directory / (name + ".json")
            changed.write_text(json.dumps(modified), encoding="utf-8")
            failed, out = run_assert(checker, changed, native_path, scenario)
            require(failed.returncode != 0 and
                    (out is None or out.get("state") != "NATIVE_ZIP_INTEGRITY_VIOLATION_OBSERVED"),
                    "Missing/ambiguous source evidence was accepted: " + name)
            print("PASS reject nonconforming source evidence:", name)

    print(f"Exact native host diagnostic {scenario} and 12 falsifications: PASS")


if __name__ == "__main__":
    main()
