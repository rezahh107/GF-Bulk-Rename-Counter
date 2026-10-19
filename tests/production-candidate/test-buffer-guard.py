#!/usr/bin/env python3
"""Bounded process falsification of production PHP output-buffer cleanup."""
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "includes/class-flat_zip_download.php"
WORKER = ROOT / "tests/production-candidate/buffer-guard-worker.php"
original = SOURCE.read_text(encoding="utf-8")
call = "self::clear_output_buffers();"
download = original.split("public static function download(): void", 1)
if len(download) != 2 or download[1].count(call) != 1:
    raise AssertionError("download() must invoke the bounded guard exactly once")
if download[1].index(call) >= download[1].index("$f = fopen($temp, 'rb')"):
    raise AssertionError("Buffer guard must execute before response handle and ZIP headers")
if "while (ob_get_level()) { ob_end_clean(); }" in download[1]:
    raise AssertionError("Unsafe unconditional loop restored")

for case in ("none", "removable", "nested", "nonremovable", "stack"):
    result = subprocess.run(
        ["php", "-d", "output_buffering=0", str(WORKER), case, str(SOURCE)],
        capture_output=True, text=True, timeout=4, check=False,
    )
    if result.returncode != 0 or f"PASS_BUFFER_GUARD {case} " not in result.stderr:
        raise AssertionError(f"Production guard rejected {case}: {result.returncode} "
                             f"{result.stderr[:900]}")
    if case in ("none", "removable", "nested") and result.stdout:
        raise AssertionError(f"Removable buffer leaked output: {case}")
    print(f"PASS_REAL_PRODUCTION_BUFFER_GUARD {case}", flush=True)

# Reintroduce the ORIGINAL unbounded cleanup algorithm only in a disposable
# source copy. The same negative subprocess probe MUST time out (and is killed
# by subprocess.run), proving the normal regression probe rejects this mutant.
begin = original.index("    private static function clear_output_buffers(): void {")
end = original.index("    public static function download(): void {", begin)
mutant = (original[:begin] +
          "    private static function clear_output_buffers(): void {\n"
          "        while (ob_get_level()) { ob_end_clean(); }\n"
          "    }\n\n" + original[end:])
with tempfile.TemporaryDirectory(prefix="gfbrc-buffer-mutant-") as root:
    path = Path(root) / "class-flat_zip_download.php"
    path.write_text(mutant, encoding="utf-8")
    try:
        outcome = subprocess.run(
            ["php", "-d", "output_buffering=0", str(WORKER), "nonremovable", str(path)],
            capture_output=True, text=True, timeout=1.5, check=False,
        )
    except subprocess.TimeoutExpired:
        print("PASS_ORIGINAL_UNCONDITIONAL_LOOP_MUTATION_REJECTED_TIMEOUT", flush=True)
    else:
        raise AssertionError(f"Original loop mutant unexpectedly terminated: {outcome.returncode}, "
                             f"{outcome.stderr[-400:]}")
