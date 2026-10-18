#!/usr/bin/env python3
"""Falsify the exact diagnostic gate with mutations of a *real* native ZIP.

Usage: python3 falsify-zip.py manifest.json native-collision.zip assert-zip.php
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


def run_assert(checker, manifest_path, zip_path):
    result = subprocess.run(
        ["php", str(checker), str(manifest_path), "host_folder_collision", str(zip_path)],
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
    if len(sys.argv) != 4:
        raise SystemExit("Usage: falsify-zip.py manifest.json native.zip assert-zip.php")
    manifest_path, native_path, checker = map(Path, sys.argv[1:])
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    case = manifest["scenarios"]["host_folder_collision"]
    uploads = case["uploads"]
    require(len(uploads) == 2, "Genuine native case must record two uploads")

    source_bytes = [Path(item["path"]).read_bytes() for item in uploads]
    source_hashes = [hashlib.sha256(data).hexdigest() for data in source_bytes]
    require(source_hashes == [item["sha256"] for item in uploads],
            "Genuine original source bytes do not match manifest")
    require(len(set(source_hashes)) == 2, "Genuine sources are not distinct")

    result, report = run_assert(checker, manifest_path, native_path)
    require(result.returncode == 0 and report is not None and
            report["state"] == "NATIVE_ZIP_INTEGRITY_VIOLATION_OBSERVED" and
            report["members"] == {"shared/same.jpg": report["members"].get("shared/same.jpg")} and
            report["members"]["shared/same.jpg"] in source_hashes and
            report["required_source_count"] == 2 and report["count"] == 1,
            "Actual native ZIP did not prove the EXACT host collision: " + result.stdout + result.stderr)
    with zipfile.ZipFile(native_path) as archive:
        surviving_bytes = archive.read("shared/same.jpg")
    require(hashlib.sha256(surviving_bytes).hexdigest() in source_hashes,
            "Native member contents do not match either genuine source")

    with tempfile.TemporaryDirectory(prefix="brc-zip-falsify-") as tmp:
        directory = Path(tmp)
        different = b"unrelated member payload - never present in native source uploads"
        require(hashlib.sha256(different).hexdigest() not in source_hashes,
                "Unrelated digest is accidentally a source hash")

        mutation_cases = {
            "outside_shared_folder": [("other/same.jpg", surviving_bytes)],
            "unrelated_member_hash": [("shared/same.jpg", different)],
            "zero_members": [],
            "duplicate_member_path": [
                ("shared/same.jpg", source_bytes[0]),
                ("shared/same.jpg", source_bytes[1]),
            ],
            "wrong_member_name": [("shared/other.jpg", surviving_bytes)],
            "extra_unrelated_member": [
                ("shared/same.jpg", surviving_bytes),
                ("shared/other.jpg", different),
            ],
        }
        for name, members in mutation_cases.items():
            zip_path = directory / (name + ".zip")
            make_zip(zip_path, members)
            failed, out = run_assert(checker, manifest_path, zip_path)
            require(failed.returncode != 0 and
                    (out is None or out.get("state") != "NATIVE_ZIP_INTEGRITY_VIOLATION_OBSERVED"),
                    "Unrelated ZIP fault was admitted as diagnostic: " + name)
            print("PASS reject nonconforming native-ZIP mutation:", name)

        malformed_path = directory / "malformed.zip"
        malformed_path.write_bytes(b"not-a-zip")
        failed, out = run_assert(checker, manifest_path, malformed_path)
        require(failed.returncode != 0 and out is None,
                "Malformed ZIP accepted as diagnostic")
        print("PASS reject malformed archive")

        conserving_path = directory / "fully-conserving.zip"
        make_zip(conserving_path, [
            ("shared/same.jpg", source_bytes[0]),
            ("shared/same-2.jpg", source_bytes[1]),
        ])
        not_reproduced, out = run_assert(checker, manifest_path, conserving_path)
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
            mutation(modified["scenarios"]["host_folder_collision"])
            changed = directory / (name + ".json")
            changed.write_text(json.dumps(modified), encoding="utf-8")
            failed, out = run_assert(checker, changed, native_path)
            require(failed.returncode != 0 and
                    (out is None or out.get("state") != "NATIVE_ZIP_INTEGRITY_VIOLATION_OBSERVED"),
                    "Missing/ambiguous source evidence was accepted: " + name)
            print("PASS reject nonconforming source evidence:", name)

    print("Exact native host collision and 12 diagnostic falsifications: PASS")


if __name__ == "__main__":
    main()
