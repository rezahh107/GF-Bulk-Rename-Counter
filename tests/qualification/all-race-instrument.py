#!/usr/bin/env python3
"""Insert a deterministic barrier into ONLY the disposable installed plugin.

The reviewed source and canonical installable ZIP are never modified. The barrier
is placed immediately after validated_download() returns its accepted manifest,
before write_verified_zip() and assert_membership(). The script restores bytes.
"""
from pathlib import Path
import hashlib
import os
import sys

lab = Path(os.environ["BRC_LAB_DIR"])
installed = Path(os.environ["BRC_WP_PATH"]) / "wp-content/plugins/gf-bulk-rename-counter/includes/class-flat_zip_download.php"
backup = lab / "flat-zip-before-race.php"
mode = sys.argv[1]
anchor = "                [$ids, $all_plan, $all_zero] = All_Download::validated_download($form_id);"
expected_hash = "c95a93e8dfbf1c5b4e17702644c87926e80eb460"
import subprocess
if mode == "apply":
    if backup.exists():
        raise AssertionError("Unexpected existing instrumentation backup")
    before = installed.read_bytes()
    blob = subprocess.check_output(["git", "hash-object", str(installed)], text=True).strip()
    if blob != expected_hash:
        raise AssertionError(f"Installed PHP differs from exact PR #28 source blob {blob}")
    data = before.decode("utf-8")
    if data.count(anchor) != 1:
        raise AssertionError("Expected unique validated_download() boundary not found")
    injection = r'''
                // Temporary CI-only race barrier: never present in canonical ZIP.
                $race = getenv('BRC_LAB_DIR') . '/all-existing-race';
                if (is_file($race . '.arm')) {
                    file_put_contents($race . '.ready', 'after_validated_manifest_before_writer');
                    $deadline = microtime(true) + 35;
                    while (!is_file($race . '.release')) {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('Qualification barrier timed out');
                        }
                        usleep(20000);
                    }
                }
'''
    backup.write_bytes(before)
    installed.write_text(data.replace(anchor, anchor + "\n" + injection), encoding="utf-8")
    print("QUALIFICATION_DISPOSABLE_INSTRUMENTATION_APPLIED", hashlib.sha256(before).hexdigest())
elif mode == "restore":
    if not backup.exists():
        raise AssertionError("No installed-source backup to restore")
    original = backup.read_bytes()
    installed.write_bytes(original)
    blob = subprocess.check_output(["git", "hash-object", str(installed)], text=True).strip()
    if blob != expected_hash:
        raise AssertionError(f"Restoration lost original runtime blob: {blob}")
    backup.unlink()
    print("QUALIFICATION_INSTALLED_COPY_RESTORED_EXACT_PR28_BLOB", blob)
else:
    raise SystemExit("Usage: all-race-instrument.py apply|restore")
