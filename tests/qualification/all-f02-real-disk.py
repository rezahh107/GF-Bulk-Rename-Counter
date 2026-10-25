#!/usr/bin/env python3
"""Authentic tmpfs capacity qualification, installed-copy only; never shipped.

The genuine WP_TEMP_DIR, verified ZIP and disk_free_space() all share one
bounded real tmpfs filesystem. The pre-F02 behavior is reproduced by reverting
only the installed final-manifest call (never repository/release source).
"""
from pathlib import Path
import json
import os
import re
import shutil
import subprocess
import sys

LAB = Path(os.environ["BRC_LAB_DIR"])
WP = Path(os.environ["BRC_WP_PATH"])
CONFIG = WP / "wp-config.php"
INSTALLED = WP / "wp-content/plugins/gf-bulk-rename-counter/includes"
ZIP_PHP = INSTALLED / "class-flat_zip_download.php"
ALL_PHP = INSTALLED / "class-all_download.php"
BACKUP_CONFIG = LAB / "f02-wp-config.original"
BACKUP_ZIP = LAB / "f02-zip.original"
BACKUP_ALL = LAB / "f02-all.original"
DIR_INFO = LAB / "f02-temp-dir.json"
CURRENT_ALL_BLOB = "c4bc27a7bd5195db3b9dbce7c587e92065f8398d"
CURRENT_ZIP_BLOB = "396f89f91dcc2022906f61ef9b0f5d3c4df448e8"
ZIP_ANCHOR = "            $temp = self::write_verified_zip($plan);"
OLD_FINAL = "        $actual = self::manifest($form, false);"
BASE_FINAL = "        $actual = self::manifest($form);"
MB = 1048576

def blob(path):
    return subprocess.check_output(["git", "hash-object", str(path)], text=True).strip()

def directory():
    d = json.loads(DIR_INFO.read_text())["path"]
    if not (d.startswith("/dev/shm/gfbrc-f02-capacity-") or
            d.startswith("/tmp/gfbrc-f02-private-tmpfs/gfbrc-f02-capacity-")):
        raise RuntimeError("Unexpected capacity fixture path")
    return Path(d)

def free_bytes(directory):
    v = os.statvfs(directory)
    return v.f_bavail * v.f_frsize

def setup():
    if any(x.exists() for x in (BACKUP_CONFIG, BACKUP_ZIP, BACKUP_ALL, DIR_INFO)):
        raise RuntimeError("Capacity fixture already present")
    if blob(ALL_PHP) != CURRENT_ALL_BLOB or blob(ZIP_PHP) != CURRENT_ZIP_BLOB:
        raise RuntimeError("Installed plugin differs from exact repair source")
    # Never fill a machine-wide multi-GB /dev/shm. Prefer a dedicated 64 MiB
    # tmpfs with bounded maximum storage consumption on the disposable runner.
    private_mount = Path("/tmp/gfbrc-f02-private-tmpfs")
    private_mount.mkdir(mode=0o755, exist_ok=True)
    mounted = False
    command = ["sudo", "-n", "mount", "-t", "tmpfs", "-o",
               "size=64m,mode=1777", "tmpfs", str(private_mount)]
    try:
        subprocess.run(command, check=True, capture_output=True, text=True, timeout=10)
        sh = private_mount
        mounted = True
    except (OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired):
        private_mount.rmdir()
        sh = Path("/dev/shm")
        if not sh.is_dir():
            raise RuntimeError("REAL_HTTP_CAPACITY_NOT_FEASIBLE: tmpfs unavailable")
        fallback_total = os.statvfs(sh).f_blocks * os.statvfs(sh).f_frsize
        if fallback_total > 96 * MB:
            raise RuntimeError(
                "REAL_HTTP_CAPACITY_NOT_FEASIBLE: cannot mount private tmpfs; "
                "refusing to fill shared multi-GB /dev/shm")
    stat = os.statvfs(sh)
    total = stat.f_blocks * stat.f_frsize
    free = free_bytes(sh)
    if total < 48 * MB or free < 39 * MB or total > 96 * MB:
        if mounted:
            subprocess.run(["sudo", "-n", "umount", str(private_mount)], check=True)
            private_mount.rmdir()
        raise RuntimeError(f"REAL_HTTP_CAPACITY_NOT_FEASIBLE: tmpfs total={total} free={free}")
    d = sh / ("gfbrc-f02-capacity-" + str(os.getpid()))
    d.mkdir(mode=0o700)
    config = CONFIG.read_bytes()
    match = re.search(rb"require_once\s+ABSPATH\s*\.\s*['\"]wp-settings\.php['\"]\s*;", config)
    if not match:
        d.rmdir()
        raise RuntimeError("Cannot find unique WP settings bootstrap anchor")
    if config.count(match.group()) != 1 or b"WP_TEMP_DIR" in config:
        d.rmdir()
        raise RuntimeError("Ambiguous or preconfigured WP_TEMP_DIR")
    source = ZIP_PHP.read_bytes()
    anchor = ZIP_ANCHOR.encode()
    if source.count(anchor) != 1:
        d.rmdir()
        raise RuntimeError("Cannot find unique ZIP completion anchor")
    # Report the *real* postwrite temporary ZIP existence and free blocks.
    probe = b'''
            // Disposable qualification only; NOT in canonical package.
            file_put_contents(getenv('BRC_LAB_DIR') . '/f02-verified-zip-state.json',
                wp_json_encode(['path' => $temp, 'size' => filesize($temp),
                    'free' => disk_free_space(get_temp_dir()),
                    'temp_dir' => get_temp_dir()]));
'''
    BACKUP_CONFIG.write_bytes(config)
    BACKUP_ZIP.write_bytes(source)
    BACKUP_ALL.write_bytes(ALL_PHP.read_bytes())
    DIR_INFO.write_text(json.dumps({"path": str(d), "capacity": total,
                                    "available_before": free,
                                    "private_mount": mounted}))
    CONFIG.write_bytes(config[:match.start()] +
        f"define('WP_TEMP_DIR', '{d.as_posix()}/');\n".encode() + config[match.start():])
    ZIP_PHP.write_bytes(source.replace(anchor, anchor + b"\n" + probe))
    print("F02_REAL_TMPFS_INITIALIZED", json.dumps({
        "tmpfs_total_bytes": total, "before_bytes": free, "path": str(d)}))

def fill(target_mb):
    d = directory()
    target = target_mb * MB
    avail = free_bytes(d)
    filler = d / "space-reservation.bin"
    if filler.exists():
        filler.unlink()
        avail = free_bytes(d)
    if avail <= target:
        raise RuntimeError(f"Insufficient tmpfs initial space {avail} for target {target}")
    amount = avail - target
    with filler.open("wb") as fh:
        remaining = amount
        block = b"\0" * MB
        while remaining > 0:
            n = min(remaining, len(block))
            fh.write(block[:n])
            remaining -= n
        fh.flush()
        os.fsync(fh.fileno())
    avail = free_bytes(d)
    if not target - MB <= avail <= target + MB:
        raise RuntimeError(f"tmpfs fill target mismatch target={target} actual={avail}")
    print("F02_TMPFS_GENUINELY_CONSTRAINED",json.dumps({
        "target_free_bytes":target,"real_free_bytes":avail,"reserved_bytes":amount}))

def switch_baseline():
    src = BACKUP_ALL.read_bytes()
    if src.count(OLD_FINAL.encode()) != 1:
        raise RuntimeError("Missing unique F02 repair call to reproduce old behavior")
    ALL_PHP.write_bytes(src.replace(OLD_FINAL.encode(), BASE_FINAL.encode()))
    print("F02_BASELINE_FINAL_REPEATS_ZIP_DISK_ADMISSION",blob(ALL_PHP))

def switch_candidate():
    src = BACKUP_ALL.read_bytes()
    ALL_PHP.write_bytes(src)
    if blob(ALL_PHP) != CURRENT_ALL_BLOB:
        raise RuntimeError("Candidate installed file identity mismatch")
    print("F02_CANDIDATE_RESTORED_EXACT_CURRENT_BLOB",blob(ALL_PHP))

def status():
    d = directory()
    print("F02_TMPFS_STATE",json.dumps({
        "free_bytes":free_bytes(d),
        "zip_probe":json.loads((LAB/"f02-verified-zip-state.json").read_text())
        if (LAB/"f02-verified-zip-state.json").exists() else None}))

def cleanup():
    if BACKUP_ALL.exists():
        ALL_PHP.write_bytes(BACKUP_ALL.read_bytes())
        assert blob(ALL_PHP) == CURRENT_ALL_BLOB
        BACKUP_ALL.unlink()
    if BACKUP_ZIP.exists():
        ZIP_PHP.write_bytes(BACKUP_ZIP.read_bytes())
        assert blob(ZIP_PHP) == CURRENT_ZIP_BLOB
        BACKUP_ZIP.unlink()
    if BACKUP_CONFIG.exists():
        CONFIG.write_bytes(BACKUP_CONFIG.read_bytes())
        BACKUP_CONFIG.unlink()
    if DIR_INFO.exists():
        d = directory()
        info = json.loads(DIR_INFO.read_text())
        shutil.rmtree(d)
        DIR_INFO.unlink()
        if info.get("private_mount"):
            mp = Path("/tmp/gfbrc-f02-private-tmpfs")
            subprocess.run(["sudo", "-n", "umount", str(mp)], check=True)
            mp.rmdir()
    print("PASS_F02_ALL_DISPOSABLE_FILES_AND_CONFIG_RESTORED")

if __name__=="__main__":
    cmd = sys.argv[1]
    if cmd=="setup": setup()
    elif cmd=="fill": fill(int(sys.argv[2]))
    elif cmd=="baseline": switch_baseline()
    elif cmd=="candidate": switch_candidate()
    elif cmd=="status": status()
    elif cmd=="cleanup": cleanup()
    else: raise SystemExit("setup|fill N_MIB|baseline|candidate|status|cleanup")
