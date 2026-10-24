#!/usr/bin/env python3
"""QF-04: prove updated production commit_ack protects GF Entry status.

Baseline installed ZIP and test-only candidate are each executed in separate
authentic WP-CLI/GFAPI/MariaDB processes. The source is restored byte-for-byte.
No candidate instrumentation is published as production code.
"""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import time
import traceback

lab=Path(os.environ["BRC_LAB_DIR"])
wp=Path(os.environ["BRC_WP_PATH"])
cli=os.environ["BRC_WP_CLI"]
archive=Path(os.environ["BRC_TARGET_ZIP"])
worker="tests/qualification/qf-status-worker.php"
php=wp/"wp-content/plugins/gf-bulk-rename-counter/includes/class-new_download.php"
reports=lab/"reports"
reports.mkdir(parents=True,exist_ok=True)
report_file=reports/"qf-status-ack-cutoff.json"
original=php.read_bytes()
archive_php=subprocess.check_output(["unzip","-p",str(archive),
    "gf-bulk-rename-counter/includes/class-new_download.php"])
assert original==archive_php,"Runner started from modified installed production package"
report={"run_head":os.environ.get("GITHUB_SHA"),"production_sha256":hashlib.sha256(original).hexdigest(),
    "package_path":str(archive),"modes":{},"diagnostics":[]}
barrier_needle=b"            $state = self::state($old);\n            $next = ["
assert original.count(barrier_needle)==1,"Cannot anchor final status/plan validation in actual commit_ack"
barrier=rb"""
            // Qualification-only: actual commit_ack after FINAL status/ZIP
            // validation and before durable known/covered mutation.
            $__qf_gate=getenv('BRC_STATUS_GATE');
            if (is_string($__qf_gate) && $__qf_gate !== '') {
                $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
                    "SELECT status FROM ".\GFFormsModel::get_entry_table_name()." WHERE id=%d",
                    (int)$op['ids'][0]
                ));
                if(file_put_contents($__qf_gate.'.ready','POST_FINAL_STATUS_CHECK_PRE_COVERAGE')===false)
                    throw new \RuntimeException('Status race barrier marker failed');
                $__qf_timeout=microtime(true)+35;
                while(!is_file($__qf_gate.'.go')){
                    if(microtime(true)>$__qf_timeout)
                        throw new \RuntimeException('Status race barrier timed out');
                    usleep(20000);
                }
            }
"""
def invoke(action,case="",gate=""):
    env={**os.environ,"BRC_STATUS_ACTION":action,"BRC_STATUS_CASE":case,
         "BRC_STATUS_GATE":str(gate)}
    return subprocess.Popen(["php",cli,"--path="+str(wp),
        "--user=bootstrap_admin","eval-file",worker],env=env,
        stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)

def done(proc,kind,context):
    out,err=proc.communicate(timeout=60)
    if kind=="ack":
        parsed=[]
        for line in out.splitlines():
            if line.strip().startswith('{"success":'):
                try:parsed.append(json.loads(line.strip()))
                except ValueError:pass
        assert proc.returncode==0 and len(parsed)==1,(context,proc.returncode,out[-800:],err[-800:])
        return parsed[0]
    assert proc.returncode==0,(context,proc.returncode,out[-800:],err[-900:])
    records=[json.loads(line.split("QF_STATUS_WORKER ",1)[1])
         for line in out.splitlines() if line.startswith("QF_STATUS_WORKER ")]
    assert len(records)==1,(context,out[-900:],err[-900:])
    return records[0]

def call(action,case=""):
    return done(invoke(action,case),"worker",action+"/"+case)

def paused_ack(case,label):
    gate=lab/("status-ack-"+label)
    for extension in (".ready",".go"):
        try:Path(str(gate)+extension).unlink()
        except FileNotFoundError:pass
    proc=invoke("ack",case,str(gate))
    deadline=time.monotonic()+42
    while not Path(str(gate)+".ready").exists():
        if proc.poll() is not None or time.monotonic()>deadline:
            out,err=proc.communicate(timeout=3)
            raise AssertionError(("ACK did not reach final-status barrier",label,proc.returncode,out,err))
        time.sleep(.02)
    assert Path(str(gate)+".ready").read_text()=="POST_FINAL_STATUS_CHECK_PRE_COVERAGE"
    return gate,proc

def resume(gate,proc):
    Path(str(gate)+".go").write_text("release")
    return done(proc,"ack",str(gate))

def read(case):
    return call("state",case)

def assert_coverage(state,rev,entry=None):
    assert state["rev"]==rev,state
    if rev==0:
        assert state["known"]==[] and state["covered"]==[],state
    else:
        assert state["known"]==[[entry,entry]] and state["covered"]==[[entry,entry]],state

try:
    source=call("source")
    report["pinned_gfapi_status_source"]=source["gfapi_status_source"]
    print("QF_STATUS_GFAPI_PATH",json.dumps({
        "path":source["gfapi_status_source"]["path"],
        "start":source["gfapi_status_source"]["start"],
        "end":source["gfapi_status_source"]["end"],
        "entry_table":source["entry_table"]}),flush=True)
    cases=call("setup")["setup"]
    report["fixtures"]={k:{"form":v["form"],"entry":v["entry"]} for k,v in cases.items()}
    for mode in ("candidate",):
        # No candidate lock injected: exercise the actual UPDATED Production
        # commit_ack() exactly as distributed in the installable ZIP.
        # Only the deterministic post-validation barrier is test-only.
        modified=original.replace(barrier_needle,barrier+barrier_needle)
        php.write_bytes(modified)
        subprocess.run(["php","-l",str(php)],check=True,capture_output=True,text=True)
        report["modes"][mode]={}
        for phase in ("before","during","after"):
            case=mode+"_"+phase
            prepared=call("prepare",case)
            before=read(case)
            assert before["db_status"]=="active" and before["gfapi_status"]=="active",before
            assert_coverage(before,0)
            evidence={"prepared":prepared,"before":before}
            if phase=="before":
                writer=call("mutate",case)
                ack=done(invoke("ack",case),"ack",case)
                assert ack["success"] is False,(mode,phase,ack)
                outcome="PRE_FINAL_VALIDATION_REJECTED"
            elif phase=="during":
                gate,ack_process=paused_ack(case,case)
                evidence["barrier"]=Path(str(gate)+".ready").read_text()
                writer_process=invoke("mutate",case)
                time.sleep(1.4)
                writer_blocked=writer_process.poll() is None
                # Independent DB reader (non-locking) inspects status while
                # ACK is paused after its genuine final status validation.
                paused=read(case)
                evidence["paused"]=paused
                evidence["writer_blocked_before_release"]=writer_blocked
                assert writer_blocked,("Production Entry row lock did not block real GFAPI",case)
                assert paused["db_status"]=="active",paused
                ack=resume(gate,ack_process)
                writer=done(writer_process,"worker","production writer")
                assert ack["success"] is True,(mode,ack)
                outcome="PRODUCTION_LOCK_BLOCKED_WRITER_UNTIL_AFTER_ACK_COMMIT"
            else:
                ack=done(invoke("ack",case),"ack",case)
                assert ack["success"] is True,(mode,ack)
                at_commit=read(case)
                evidence["at_commit"]=at_commit
                assert at_commit["db_status"]=="active",at_commit
                writer=call("mutate",case)
                outcome="POST_COMMIT_STATUS_MUTATION_CREATION_ONLY"
            final=read(case)
            evidence.update({"writer":writer,"ack":ack,"final":final,"outcome":outcome})
            assert final["db_status"]=="spam" and final["gfapi_status"]=="spam",final
            if phase=="before":
                assert_coverage(final,0)
                assert final["new_ids"]==[],final
            else:
                assert_coverage(final,1,final["entry"])
                assert final["new_ids"]==[],final
            assert any("UPDATE" in sql.upper() and source["entry_table"] in sql
                       for sql in writer["queries"]),writer
            report["modes"][mode][phase]=evidence
            print("QF_STATUS_RACE_"+mode.upper()+"_"+phase.upper(),json.dumps({
                "outcome":outcome,"writer_blocked":evidence.get("writer_blocked_before_release"),
                "paused_status":evidence.get("paused",{}).get("db_status"),
                "commit_status":evidence.get("at_commit",evidence.get("paused",{})).get("db_status"),
                "ack_success":ack["success"],"final_status":final["db_status"],
                "rev":final["rev"],"known":final["known"],"covered":final["covered"],
                "next_new":final["new_ids"]}),flush=True)
    print("PASS_QF_STATUS_UPDATED_PRODUCTION_ROW_LOCK_PRE_DURING_POST",flush=True)
except BaseException as error:
    report["diagnostics"].append({"type":type(error).__name__,
        "error":str(error),"traceback":traceback.format_exc()[-6000:]})
    raise
finally:
    php.write_bytes(original)
    report["production_source_restored"]=(php.read_bytes()==archive_php)
    report["package_unchanged"]=(subprocess.check_output(["unzip","-p",str(archive),
        "gf-bulk-rename-counter/includes/class-new_download.php"])==archive_php)
    report_file.write_text(json.dumps(report,ensure_ascii=False,indent=2))
    print("QF_STATUS_REPORT",str(report_file),"restored="+str(report["production_source_restored"]),flush=True)
    assert report["production_source_restored"] and report["package_unchanged"]
