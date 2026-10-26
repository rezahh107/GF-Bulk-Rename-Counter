#!/usr/bin/env python3
"""Disposable only: two deterministic concurrency gates and final-check timer.

No source or released plugin ZIP changes. Restore exact installed byte identity.
"""
from pathlib import Path
import os
import subprocess
import sys

lab = Path(os.environ["BRC_LAB_DIR"])
installed = Path(os.environ["BRC_WP_PATH"]) / "wp-content/plugins/gf-bulk-rename-counter/includes/class-flat_zip_download.php"
backup = lab / "flat-zip-before-race.php"
expected = "cafc10859be88f42b17088a792bcfab63f938c93"
before_anchor = "                [$ids, $all_plan, $all_zero] = All_Download::validated_download($form_id);"
after_anchor = "            $temp = self::write_verified_zip($plan);"
validation_anchor = "                All_Download::assert_manifest($form_id, $ids, $plan);"

def pause(label, temp=False):
    prefix = r"""
            $race = getenv('BRC_LAB_DIR') . '/all-existing-race';
            if (is_file($race . '.arm') && trim(file_get_contents($race . '.arm')) === 'STAGE') {
                TEMP_PLACEHOLDER
                file_put_contents($race . '.ready', 'STAGE');
                $deadline = microtime(true) + 35;
                while (!is_file($race . '.release')) {
                    if (microtime(true) > $deadline) {
                        throw new \RuntimeException('Qualification barrier timed out');
                    }
                    usleep(20000);
                }
            }
"""
    return prefix.replace("STAGE",label).replace("TEMP_PLACEHOLDER",
      "file_put_contents($race . '.zip-path', $temp);" if temp else "")

mode = sys.argv[1]
if mode == "apply":
    if backup.exists():
        raise RuntimeError("Instrumentation backup already exists")
    raw = installed.read_bytes()
    sha = subprocess.check_output(["git", "hash-object", str(installed)], text=True).strip()
    if sha != expected:
        raise RuntimeError(f"Installed source differs from repair Head blob: {sha}")
    text = raw.decode()
    for anchor in (before_anchor,after_anchor,validation_anchor):
        if text.count(anchor) != 1:
            raise RuntimeError(f"Missing unique instrumentation anchor: {anchor}")
    text = text.replace(before_anchor, before_anchor+"\n"+pause("prewrite"))
    text = text.replace(after_anchor, after_anchor+"\n"+pause("postwrite",temp=True))
    text = text.replace(validation_anchor, r"""
                $__race_timer = microtime(true);
"""+validation_anchor+r"""
                file_put_contents(getenv('BRC_LAB_DIR') . '/all-final-manifest-seconds.log',
                    sprintf("%.6f", microtime(true) - $__race_timer) . "\n", FILE_APPEND);
""")
    backup.write_bytes(raw)
    installed.write_text(text)
    print("PASS_DISPOSABLE_ALL_RACE_INSTRUMENTED",sha)
elif mode == "restore":
    if not backup.is_file():
        raise RuntimeError("Missing installed plugin byte backup")
    installed.write_bytes(backup.read_bytes())
    sha = subprocess.check_output(["git", "hash-object", str(installed)], text=True).strip()
    if sha != expected:
        raise RuntimeError(f"Failed exact installed PHP restoration: {sha}")
    backup.unlink()
    print("PASS_INSTALLED_PLUGIN_RESTORED_EXACT_REPAIR_BLOB",sha)
else:
    raise SystemExit("usage: script apply|restore")
