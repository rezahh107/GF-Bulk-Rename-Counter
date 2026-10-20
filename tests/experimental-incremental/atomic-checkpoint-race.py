#!/usr/bin/env python3
"""Qualification ONLY: independently bootstrapped WordPress PHP processes, real DB."""
import json
import os
import subprocess
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
LIB = ROOT / "tests/experimental-incremental/atomic-checkpoint-lib.php"
WORKER = ROOT / "tests/experimental-incremental/atomic-checkpoint-worker.php"
LAB = Path(os.environ["BRC_LAB_DIR"])
WPCLI = os.environ["BRC_WP_CLI"]
WPPATH = os.environ["BRC_WP_PATH"]
LAB.mkdir(parents=True, exist_ok=True)


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def task(job, label):
    path = LAB / ("checkpoint-" + label + ".json")
    path.write_text(json.dumps(job), encoding="utf-8")
    env = dict(os.environ, BRC_IQ_JOB=str(path), BRC_IQ_LIB=str(LIB))
    cmd = ["php", WPCLI, "--path=" + WPPATH, "--user=bootstrap_admin",
           "eval-file", str(WORKER)]
    return subprocess.Popen(cmd, cwd=ROOT, env=env, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, text=True)


def finish(process, label):
    out, err = process.communicate(timeout=55)
    require(process.returncode == 0, label + " worker failed:\n" + err[-1800:])
    matches = [line[len("IQ_RESULT "):] for line in out.splitlines()
               if line.startswith("IQ_RESULT ")]
    require(len(matches) == 1, label + " missing exact worker result: " + out[-600:])
    return json.loads(matches[0])


def run(job, label):
    return finish(task(job, label), label)


def state(form):
    read = run({"action": "read", "form": form}, "read-" + str(time.monotonic_ns()))
    require(read["match"] and read["db"] == read["api"], "WordPress API / DB divergence")
    return read["db"]


def seed(form, frontier=100):
    x = run({"action": "reset", "form": form, "frontier": frontier},
            "seed-" + str(time.monotonic_ns()))
    require(x["value"]["frontier"] == frontier, "Reset failed")


def snap(form, name, old, end, selected=None, no_files=()):
    ids = list(range(old + 1, end + 1))
    files = ["f-" + str(n) for n in ids if n not in no_files]
    return {"id": name, "form": form, "base_frontier": old, "base_rev": 0,
            "target": end, "expected": ids,
            "included": list(ids) if selected is None else list(selected),
            "expected_files": files, "zip_members": list(files),
            "file_free": list(no_files), "zip_verified": True}


def commit(form, snapshot, ack, label):
    return run({"action": "commit", "form": form,
                "snapshot": snapshot, "ack": ack}, label)


def race(jobs, label, release="simultaneous"):
    """Both independent PHP processes must pre-read the same state before either commits."""
    handles = []
    with tempfile.TemporaryDirectory(prefix="gfbrc-cas-") as tmp:
        for number, job in enumerate(jobs):
            barrier = Path(tmp) / ("worker-" + str(number))
            current = dict(job, barrier=str(barrier))
            handles.append((task(current, label + "-" + str(number)), barrier))
        until = time.monotonic() + 40
        while not all(Path(str(b) + ".ready").exists() for _, b in handles):
            require(time.monotonic() < until, "Workers did not synchronize before commit")
            time.sleep(0.025)
        prec = [json.loads(Path(str(b) + ".ready").read_text())
                for _, b in handles]
        require(len({p["pid"] for p in prec}) == len(prec),
                "The requests did not run in separate PHP processes")
        require(all(p["api_same"] for p in prec), "Cached option differs before race")
        require(len({p["rev"] for p in prec}) == 1, "Workers did not read same revision")
        results = [None] * len(handles)
        if release == "larger-first":
            # Force the lower stale writer to run after a completed higher write.
            order = list(reversed(range(len(handles))))
            for idx in order:
                Path(str(handles[idx][1]) + ".go").touch()
                results[idx] = finish(handles[idx][0], label + "-" + str(idx))
        else:
            for _, barrier in handles:
                Path(str(barrier) + ".go").touch()
            for idx, (proc, _) in enumerate(handles):
                results[idx] = finish(proc, label + "-" + str(idx))
        return results


def outcome(result, expected):
    require(result["outcome"] == expected,
            "Expected " + expected + ", got " + str(result))


def print_pass(code, **data):
    print(code, json.dumps(data, sort_keys=True))


def main():
    observed = run({"action": "forms"}, "make-forms")
    a, b = observed["forms"]
    require(a != b and observed["engine"] == "InnoDB", "Unsupported storage engine")
    print_pass("PASS_REAL_OPTIONS_INNODB_ENVIRONMENT", **observed)

    seed(a); seed(b)
    require(state(b)["frontier"] == 100, "Second form not independent")
    complete = snap(a, "complete-110", 100, 110)
    outcome(commit(a, complete, "", "no-ack"), "NO_ACK")
    incomplete = snap(a, "gap-110", 100, 110,
                      [x for x in range(101, 111) if x not in (106, 107)])
    outcome(commit(a, incomplete, incomplete["id"], "gap"), "INCOMPLETE")
    missing = snap(a, "missing-member-110", 100, 110)
    missing["zip_members"].pop()
    outcome(commit(a, missing, missing["id"], "missing-member"), "INCOMPLETE")
    require(state(a) == {"frontier":100, "rev":0, "last_op":""},
            "Invalid/incomplete operation advanced state")
    print_pass("PASS_REAL_DB_NO_ACK_INCOMPLETE_AND_MISSING_MEMBER_REJECTED")

    outcome(commit(a, complete, complete["id"], "first-ack"), "COMMITTED")
    outcome(commit(a, complete, complete["id"], "duplicate-ack"), "DUPLICATE")
    require(state(a) == {"frontier":110,"rev":1,"last_op":"complete-110"},
            "Duplicate mutated state")
    stale = snap(a, "older-other-operation",100,120)
    outcome(commit(a, stale, stale["id"], "stale-after-first"), "STALE")
    print_pass("PASS_REAL_DB_ACK_DUPLICATE_AND_STALE_REJECTION", state=state(a))

    seed(a)
    newer = snap(a, "newer-first",100,120)
    outcome(commit(a,newer,newer["id"],"newer-ack"),"COMMITTED")
    outcome(commit(a,complete,complete["id"],"older-after-newer"),"STALE")
    require(state(a)["frontier"]==120,"Stale acknowledgment rolled back")
    print_pass("PASS_REAL_DB_REORDERED_ACK_NO_ROLLBACK")

    seed(a)
    jobs = [{"action":"commit","form":a,"snapshot":s,"ack":s["id"]}
            for s in (snap(a,"concurrent-110",100,110),
                      snap(a,"concurrent-120",100,120))]
    racing = race(jobs,"real-cas-race")
    outcomes = sorted(x["outcome"] for x in racing)
    require(outcomes==["CAS_CONFLICT","COMMITTED"],
            "Atomic CAS must have exactly one winner: "+str(outcomes))
    winner = state(a)
    require(winner["rev"]==1 and winner["frontier"] in (110,120) and
            winner["last_op"] in ("concurrent-110","concurrent-120"),
            "CAS race final checkpoint not justified")
    print_pass("PASS_TWO_PROCESS_BARRIER_ATOMIC_CAS", outcomes=outcomes,
               persisted=winner)

    seed(a)
    bad = race([{"action":"unsafe_wp_update","form":a,"unsafe_target":110},
                {"action":"unsafe_wp_update","form":a,"unsafe_target":120}],
               "unsafe-wordpress-update","larger-first")
    require(state(a)["frontier"]==110,
            "Deliberate stale update_option overwrite not reproduced")
    print_pass("PASS_FALSIFIED_UNGUARDED_UPDATE_OPTION",
               attempted=[110,120], final=state(a))

    seed(a)
    initial=snap(a,"retry-initial",100,110)
    outcome(commit(a,initial,initial["id"],"retry-initial"),"COMMITTED")
    late=snap(a,"retry-late",100,120)
    outcome(commit(a,late,late["id"],"retry-conflict"),"STALE")
    late["base_frontier"]=110
    late["base_rev"]=1
    late["expected"]=list(range(111,121))
    late["included"]=list(late["expected"])
    late["expected_files"]=["f-"+str(n) for n in late["expected"]]
    late["zip_members"]=list(late["expected_files"])
    outcome(commit(a,late,late["id"],"retry-fresh"),"COMMITTED")
    require(state(a)["frontier"]==120 and state(a)["rev"]==2,
            "Failed commit/retry corrupted state")
    print_pass("PASS_REAL_DB_FAILED_CONFLICT_RETRY", state=state(a))

    seed(a)
    no_file=snap(a,"filefree-provisional",100,110,no_files=(105,))
    outcome(commit(a,no_file,no_file["id"],"filefree-provisional"),"COMMITTED")
    require(state(a)["frontier"]==110,"File-free provisional coverage not represented")
    print_pass("PASS_PROVISIONAL_FILE_FREE_ENTRY_WITH_VERIFIED_OTHERS")
    require(state(b)=={"frontier":100,"rev":0,"last_op":""},
            "Per-form state accidentally changed Form B")
    print_pass("PASS_REAL_DB_PER_FORM_ISOLATION")

    gf = run({"action":"gf_seed","form":a},"genuine-gf-seed")
    seed(a,gf["frontier"])
    later=run({"action":"gf_append","form":a},"genuine-gf-after-snapshot")
    first=gf["first_id"]
    require(all(i>first for i in later["ids"]),"Post-snapshot GF ID order changed")
    # Provisional file-free snapshot. Not evidence of universal GF enumeration completeness.
    frozen={"id":"gf-real-frozen","form":a,"base_frontier":gf["frontier"],
            "base_rev":0,"target":first,"expected":[first],"included":[first],
            "file_free":[first],"expected_files":[],"zip_members":[],
            "zip_verified":True}
    outcome(commit(a,frozen,frozen["id"],"genuine-gf-ack"),"COMMITTED")
    require(state(a)["frontier"]==first and
            all(x>state(a)["frontier"] for x in later["ids"]),
            "New GF Entries improperly acknowledged")
    print_pass("PASS_REAL_GF_ENTRIES_OUTSIDE_ACKNOWLEDGED_SNAPSHOT",
               frozen=first, outside=later["ids"], state=state(a))

    # Readback is deliberately performed by separate WP-CLI processes.
    require(state(a)["frontier"]==first and state(b)["frontier"]==100,
            "Independent WP Options readback inconsistent")
    print_pass("PASS_WORDPRESS_OPTION_API_READBACK_IN_SEPARATE_PROCESSES",
               persistent_cache=observed["external_cache"])
    # Explicit disposable cleanup; no production or GFBRC state touched.
    for form in (a,b):
        seed(form,0)
    print_pass("CHECKPOINT_QUALIFICATION_COMPLETE_NO_PRODUCTION_STATE")


if __name__ == "__main__":
    main()
