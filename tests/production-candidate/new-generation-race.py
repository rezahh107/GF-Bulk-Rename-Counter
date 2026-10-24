#!/usr/bin/env python3
"""Deterministic two-process falsification of authoritative NEW generations.

Uses independent real WP-CLI/PHP connections against the SAME pinned InnoDB
wp_options rows as the genuine browser feature. A .ready/.go file barrier is
placed immediately before invoking actual production transition methods.
"""
import json
import os
from pathlib import Path
import subprocess
import time

lab = Path(os.environ["BRC_LAB_DIR"])
wpcli = os.environ["BRC_WP_CLI"]
wp = os.environ["BRC_WP_PATH"]
worker = "tests/production-candidate/new-generation-worker.php"

def command(action, generation="", gate=None, zero=False):
    env = os.environ.copy()
    env.update(BRC_RACE_ACTION=action, BRC_RACE_GENERATION=generation,
               BRC_RACE_GATE=str(gate) if gate else "",
               BRC_RACE_ZERO="yes" if zero else "no")
    cmd = ["php", wpcli, "--path=" + wp, "--user=bootstrap_admin", "eval-file", worker]
    return cmd, env

def parse(proc, accepted=True, detail=""):
    out, err = proc.communicate(timeout=90)
    if accepted:
        assert proc.returncode == 0, (detail, proc.returncode, out[-1200:], err[-1200:])
        lines = [ln for ln in out.splitlines()
                 if ln.startswith("PASS_REAL_NEW_GENERATION_WORKER ")]
        assert len(lines) == 1, (detail, out[-700:])
        return json.loads(lines[0].partition(" ")[2])
    assert proc.returncode == 42, (detail, proc.returncode, out[-700:], err[-700:])
    assert detail in err, (detail, err[-1200:])
    return err

def invoke(action, generation="", *, accepted=True, detail="", zero=False):
    cmd, env = command(action, generation, zero=zero)
    return parse(subprocess.Popen(cmd, env=env, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, text=True), accepted, detail)

def paused(action, generation, label):
    gate = lab / ("generation-race-" + label)
    for suffix in (".ready", ".go"):
        try: (Path(str(gate) + suffix)).unlink()
        except FileNotFoundError: pass
    cmd, env = command(action, generation, gate=gate)
    child = subprocess.Popen(cmd, env=env, stdout=subprocess.PIPE,
                             stderr=subprocess.PIPE, text=True)
    deadline = time.monotonic() + 45
    while not Path(str(gate) + ".ready").exists():
        if child.poll() is not None or time.monotonic() >= deadline:
            out, err = child.communicate(timeout=2)
            raise AssertionError((label, "barrier not reached", child.returncode, out, err))
        time.sleep(.025)
    return gate, child

def release(gate, child, *, accepted=True, detail=""):
    Path(str(gate) + ".go").write_text("continue")
    return parse(child, accepted, detail)

def state():
    return invoke("state")

def generation(digit):
    return digit * 32

invoke("setup")
A, B, C, D, E, F, G, H, I, Z = map(generation, "abcdef1234")
invoke("prepare", A)
invoke("ready", A)
old_state = state()
assert old_state["rev"] == 0 and old_state["id"] == A

# Delayed A acknowledgment must NOT mutate coverage once prepare B supersedes.
gate, child = paused("ack", A, "stale-ack")
invoke("prepare", B)
release(gate, child, accepted=False, detail="superseded")
assert state()["id"] == B and state()["rev"] == 0
print("PASS_DETERMINISTIC_OLD_ACK_CANNOT_COMMIT_AFTER_NEW_GENERATION", flush=True)

# Delayed B ZIP cannot re-publish B once C becomes authoritative.
gate, child = paused("ready", B, "stale-download")
invoke("prepare", C)
release(gate, child, accepted=False, detail="superseded")
assert state()["id"] == C and state()["status"] == "prepared"
print("PASS_DETERMINISTIC_LATE_ZIP_CANNOT_RESURRECT_SUPERSEDED_OPERATION", flush=True)

# C may genuinely download+commit exactly one durable revision.
invoke("ready", C)
assert invoke("ack", C)["result"] == "acknowledged"
assert state()["rev"] == 1 and state()["id"] == C
assert invoke("ack", C)["result"] == "duplicate"
assert state()["rev"] == 1
print("PASS_DETERMINISTIC_VALID_GENERATION_AND_DUPLICATE_IDEMPOTENT", flush=True)

# Two long prepares with the same initial generation cannot publish in
# completion order; the original observed pending ID is mandatory authority.
gate, child = paused("prepare", D, "stale-prepare")
invoke("prepare", E)
release(gate, child, accepted=False, detail="changed")
assert state()["id"] == E and state()["rev"] == 1
print("PASS_DETERMINISTIC_PREPARE_CANNOT_REPLACE_NEWER_PREPARE", flush=True)

# No verified download => no ACK; expiry also invalidates READY/ACK.
invoke("ack", E, accepted=False, detail="changed before atomic confirmation")
assert state()["rev"] == 1
invoke("expire")
invoke("ready", E, accepted=False, detail="expired")
invoke("ack", E, accepted=False, detail="expired")
assert state()["rev"] == 1
print("PASS_DETERMINISTIC_INTERRUPTED_AND_EXPIRED_FAIL_WITHOUT_COVERAGE", flush=True)

# Explicit file-free batch is ACKed without inventing ZIP output.
invoke("prepare", Z, zero=True)
assert invoke("ack", Z)["result"] == "acknowledged"
assert state()["rev"] == 2
print("PASS_DETERMINISTIC_ZERO_FILE_ACK_WITHOUT_FAKE_ZIP", flush=True)

# Delayed ACK cannot overwrite a later independent coverage revision.
invoke("prepare", F)
invoke("ready", F)
gate, child = paused("ack", F, "coverage-revision")
assert invoke("external_revision")["rev"] == 3
release(gate, child, accepted=False, detail="coverage changed")
assert state()["rev"] == 3 and state()["id"] == F
print("PASS_DETERMINISTIC_CONCURRENT_COVERAGE_REVISION_NOT_OVERWRITTEN", flush=True)

# Two genuinely simultaneous OS processes confirm the SAME live generation.
invoke("prepare", G)
invoke("ready", G)
gate1, p1 = paused("ack", G, "ack-one")
gate2, p2 = paused("ack", G, "ack-two")
Path(str(gate1) + ".go").write_text("continue")
Path(str(gate2) + ".go").write_text("continue")
outcomes = sorted([parse(p1)["result"], parse(p2)["result"]])
assert outcomes == ["acknowledged", "duplicate"], outcomes
assert state()["rev"] == 4
print("PASS_DETERMINISTIC_CONCURRENT_ACK_ONE_REVISION_IDEMPOTENT", flush=True)

# An unacknowledged prepared operation is disposable, but only a subsequently
# authorized generation can advance after normal ZIP verification.
invoke("prepare", H)
invoke("prepare", I)
invoke("ack", H, accepted=False, detail="superseded")
assert state()["rev"] == 4
invoke("ready", I)
assert invoke("ack", I)["result"] == "acknowledged"
assert state()["rev"] == 5
print("PASS_DETERMINISTIC_RETRY_NEW_GENERATION_AFTER_INTERRUPTED_OPERATION", flush=True)
