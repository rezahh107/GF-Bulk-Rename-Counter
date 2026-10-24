#!/usr/bin/env python3
"""QF-01 and QF-02 real, disposable WordPress/GF/MariaDB falsification.

Instruments ONLY the already-installed CI plugin copy; the ZIP built from
committed production source remains byte-for-byte intact. After each run
the installed source is restored with a SHA256 identity assertion.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import time
import traceback

lab = Path(os.environ["BRC_LAB_DIR"])
lab.mkdir(parents=True, exist_ok=True)
wp = Path(os.environ["BRC_WP_PATH"])
cli = os.environ["BRC_WP_CLI"]
plugin = wp / "wp-content/plugins/gf-bulk-rename-counter/includes/class-new_download.php"
zip_path = Path(os.environ["BRC_TARGET_ZIP"])
worker = "tests/qualification/qf-runtime-worker.php"
report_file = lab / "reports/qf-runtime-results.json"
report_file.parent.mkdir(parents=True, exist_ok=True)
report = {"head": os.environ.get("GITHUB_SHA", ""), "installed_zip": str(zip_path),
          "instruments": [], "qf01": {}, "qf02": {}, "errors": []}
original = plugin.read_bytes()
expected = subprocess.check_output(["unzip", "-p", str(zip_path),
    "gf-bulk-rename-counter/includes/class-new_download.php"])
assert original == expected, "Installed production source differs from exact ZIP before instrumentation"
report["production_sha256"] = hashlib.sha256(original).hexdigest()
barrier_snippet = r"""
            $__qf_barrier = getenv('BRC_QF01_BARRIER');
            if (is_string($__qf_barrier) && $__qf_barrier !== '') {
                if (file_put_contents($__qf_barrier . '.ready', 'AFTER_ENTRY_SQL_BEFORE_GFAPI') === false) {
                    throw new \RuntimeException('QF01 barrier marker failed');
                }
                $__qf_deadline = microtime(true) + 40;
                while (!is_file($__qf_barrier . '.go')) {
                    if (microtime(true) > $__qf_deadline) { throw new \RuntimeException('QF01 barrier timeout'); }
                    usleep(15000);
                }
            }
"""
ack_snippet = r"""
            $__qf_ack_gate = getenv('BRC_QF02_BARRIER');
            if (is_string($__qf_ack_gate) && $__qf_ack_gate !== '') {
                if (file_put_contents($__qf_ack_gate . '.ready', 'AFTER_FINAL_SOURCE_VALIDATION_BEFORE_COMMIT') === false) {
                    throw new \RuntimeException('QF02 barrier marker failed');
                }
                $__qf_deadline = microtime(true) + 40;
                while (!is_file($__qf_ack_gate . '.go')) {
                    if (microtime(true) > $__qf_deadline) { throw new \RuntimeException('QF02 barrier timeout'); }
                    usleep(15000);
                }
            }
"""
needle1 = b"            if (!is_array($rows) || count($rows) > self::MAX_HISTORY) {"
needle2 = b"            $result = self::commit_ack($form, $id, $op);"
assert original.count(needle1) == 1 and original.count(needle2) == 1, "Exact production method seams unavailable"
instrumented = (original.replace(needle1, barrier_snippet.encode()+needle1)
                .replace(needle2, ack_snippet.encode()+needle2))
report["instruments"] = ["QF01 after first SQL Entry enumeration, before GFAPI",
                         "QF02 after actual ACK fingerprint/Entry validation, before commit_ack"]
def wpcli(action, case=None, env_extra=None, timeout=55):
    env = os.environ.copy()
    env["BRC_QF_ACTION"] = action
    env["BRC_QF_CASE"] = case or ""
    env.pop("BRC_QF01_BARRIER", None)
    env.pop("BRC_QF02_BARRIER", None)
    if env_extra:
        env.update(env_extra)
    cmd = ["php", cli, "--path="+str(wp), "--user=bootstrap_admin", "eval-file", worker]
    return subprocess.Popen(cmd, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

def checked(p, expected="worker", detail=""):
    out, err = p.communicate(timeout=65)
    if expected == "worker":
        assert p.returncode == 0, (detail, p.returncode, out[-600:], err[-600:])
        matches = [l for l in out.splitlines() if l.startswith("QF_WORKER_RESULT ")]
        assert len(matches) == 1, (detail, out[-700:], err[-700:])
        return json.loads(matches[0][len("QF_WORKER_RESULT "):])
    if expected == "ack":
        matches = re.findall(r'\{"success":(?:true|false).*?\}', out)
        # WordPress JSON contains nested objects; parse the full line instead.
        records = []
        for line in out.splitlines():
            line = line.strip()
            if line.startswith('{"success":'):
                try: records.append(json.loads(line))
                except ValueError: pass
        assert p.returncode == 0 and len(records)==1, (detail, p.returncode, out[-900:], err[-500:])
        return records[0]
    assert p.returncode == 42 and "QF_WORKER_ERROR " in err, (
        detail, p.returncode, out[-600:], err[-900:])
    return {"rejection": err.split("QF_WORKER_ERROR ",1)[1].strip()}

def invoke(action, case=None, expected="worker", env_extra=None):
    return checked(wpcli(action, case, env_extra), expected, action + " " + str(case))

def pause(action, case, family, label):
    gate = lab / ("qf-" + label)
    for suffix in (".ready",".go"):
        try: Path(str(gate)+suffix).unlink()
        except FileNotFoundError: pass
    p=wpcli(action,case,{f"BRC_{family}_BARRIER":str(gate)})
    deadline=time.monotonic()+45
    while not Path(str(gate)+".ready").exists():
        if p.poll() is not None or time.monotonic()>=deadline:
            out,err=p.communicate(timeout=3)
            raise AssertionError((label, "barrier not reached",p.returncode,out[-600:],err[-900:]))
        time.sleep(.02)
    return gate,p

def release(gate,p,expected):
    Path(str(gate)+".go").write_text("continue")
    return checked(p,expected,"released " + str(gate))

def dbengine(target):
    sql="SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '"+target+"'"
    return subprocess.check_output(["php",cli,"--path="+str(wp),"db","query",sql,
         "--skip-column-names"],text=True).strip()

def entry_table():
    out=subprocess.check_output(["php",cli,"--path="+str(wp),"eval",
        'echo GFFormsModel::get_entry_table_name();'],text=True)
    name=out.strip()
    assert re.fullmatch("[A-Za-z0-9_]+",name),name
    return name

def dbquery(sql):
    subprocess.run(["php",cli,"--path="+str(wp),"db","query",sql],
                   check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)

def record(case, phase, extra=None):
    x=invoke("state",case)
    return {"phase":phase,**x,**(extra or {})}

try:
    plugin.write_bytes(instrumented)
    subprocess.run(["php","-l",str(plugin)],check=True,capture_output=True,text=True)
    assert plugin.read_bytes()!=expected, "Test instrumentation unexpectedly absent"
    fixtures=invoke("init")["forms"]
    report["fixture_cases"]={k:{"form":v["form"],"entry":v["entry"]} for k,v in fixtures.items()}
    for case in ["file_pre","file_during","file_post","zero_pre","zero_during","zero_post"]:
        invoke("prepare",case)
        initial=record(case,"prepared")
        assert initial["rev"]==0 and initial["known"]==[] and initial["covered"]==[],case
        if case.endswith("_pre"):
            mutation=invoke("mutate",case)
            result=invoke("ack",case,"ack")
            assert result.get("success") is False,(case,result)
            final=record(case,"after_pre_mutation_ack_rejected",{"mutation":mutation,"ack":result})
            assert final["rev"]==0 and final["covered"]==[] and final["next_new"]==[final["entry"]],final
            outcome="PRE_ACK_MUTATION_REJECTED"
        elif case.endswith("_during"):
            gate,p=pause("ack",case,"QF02",case)
            mutation=invoke("mutate",case)
            result=release(gate,p,"ack")
            final=record(case,"after_during_mutation_ack",{"mutation":mutation,"ack":result})
            # NOT an arbitrary 'PASS' assumption: encode observation, and
            # assert invariant/readback consistency without blessing the policy.
            if result.get("success") is True:
                assert final["rev"]==1 and final["known"]==[[final["entry"],final["entry"]]]
                assert final["covered"]==[[final["entry"],final["entry"]]]
                assert final["next_new"]==[],final
                outcome="DURING_ACK_MUTATION_ACCEPTED_AND_COVERED"
            else:
                assert final["rev"]==0 and final["covered"]==[] and final["next_new"]==[final["entry"]]
                outcome="DURING_ACK_MUTATION_REJECTED"
        else:
            result=invoke("ack",case,"ack")
            assert result.get("success") is True,(case,result)
            mutation=invoke("mutate",case)
            final=record(case,"after_post_commit_mutation",{"mutation":mutation,"ack":result})
            assert final["rev"]==1 and final["covered"]==[[final["entry"],final["entry"]]]
            assert final["next_new"]==[],final
            outcome="POST_COMMIT_MUTATION_DOES_NOT_REQUEUE_CREATION_ONLY_ENTRY"
        report["qf02"][case]={"outcome":outcome,"prepared":initial,"final":final}
        print("QF02_OBSERVED",case,outcome,json.dumps({
            "rev":final["rev"],"known":final["known"],"covered":final["covered"],
            "next_new":final["next_new"],"before":mutation["before"],
            "after":mutation["after"]}),flush=True)
    # QF01: run last because changing GF table engine affects all GF forms.
    table=entry_table()
    assert dbengine(table)=="InnoDB","Expected actual GF Entry storage InnoDB"
    assert dbengine(wp.name.replace("whatever",""))!="ignore" if False else True
    gate,p=pause("snapshot","snapshot_innodb","QF01","snapshot-innodb")
    mutation=invoke("status_mutate","snapshot_innodb")
    valid=release(gate,p,"worker")
    expected_id=fixtures["snapshot_innodb"]["entry"]
    assert expected_id in valid["new"] and mutation["after"]=="spam", (valid,mutation)
    report["qf01"]["innodb"]={"snapshot":valid,"mutation":mutation,
        "observation":"INNODB_SNAPSHOT_RETAINS_PRE_MUTATION_ACTIVE_ID"}
    print("QF01_INNODB_CONTROL",json.dumps(report["qf01"]["innodb"]),flush=True)
    options_table=os.environ.get("BRC_WP_OPTIONS_TABLE","wp_options")
    assert dbengine(options_table)=="InnoDB","Option storage changed before MyISAM negative control"
    try:
        dbquery("ALTER TABLE "+table+" ENGINE=MyISAM")
        assert dbengine(table)=="MyISAM" and dbengine(options_table)=="InnoDB"
        guard=invoke("guard","snapshot_myisam")
        idle=invoke("snapshot","snapshot_myisam_idle")
        assert fixtures["snapshot_myisam_idle"]["entry"] in idle["new"]
        gate,p=pause("snapshot","snapshot_myisam","QF01","snapshot-myisam")
        mutation=invoke("status_mutate","snapshot_myisam")
        unexpected=release(gate,p,"error" if True else "worker")
        report["qf01"]["myisam"]={"guard":guard,"idle":idle,"concurrent":unexpected,
            "mutation":mutation,"options_engine":dbengine(options_table),
            "entry_engine":dbengine(table)}
        print("QF01_MYISAM_NEGATIVE",json.dumps(report["qf01"]["myisam"]),flush=True)
    finally:
        dbquery("ALTER TABLE "+table+" ENGINE=InnoDB")
        assert dbengine(table)=="InnoDB" and dbengine(options_table)=="InnoDB", "DB engine restoration failed"
    report["qf01"]["restored_engines"]=True
except BaseException as exc:
    report["errors"].append({"type":type(exc).__name__,"message":str(exc),"traceback":traceback.format_exc()[-4500:]})
    raise
finally:
    # Prevent any modified executable from leaking into lifecycle checks.
    plugin.write_bytes(original)
    report["installed_source_restored"]=(plugin.read_bytes()==expected)
    report["archive_source_unmodified"]=(subprocess.check_output(["unzip","-p",str(zip_path),
        "gf-bulk-rename-counter/includes/class-new_download.php"])==expected)
    report_file.write_text(json.dumps(report,indent=2,ensure_ascii=False))
    print("QF_RUNTIME_REPORT",str(report_file),
          "source_restored="+str(report["installed_source_restored"]),flush=True)
    assert report["installed_source_restored"] and report["archive_source_unmodified"]
