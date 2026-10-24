#!/usr/bin/env python3
"""Two genuine independent PHP/GFAPI writers versus InnoDB range locking."""
import json,os,pathlib,subprocess,time,re
root=pathlib.Path(os.environ["BRC_LAB_DIR"])
cli=os.environ["BRC_WP_CLI"]
wp=os.environ["BRC_WP_PATH"]
script="tests/qualification/qf-meta-probe.php"
records={"head":os.environ.get("GITHUB_SHA"),"cases":{}}
def call(action,case="",gate=""):
    env={**os.environ,"BRC_META_ACTION":action,"BRC_META_CASE":case,"BRC_META_GATE":gate}
    return subprocess.Popen(["php",cli,"--path="+wp,"--user=bootstrap_admin","eval-file",script],
      env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
def result(p,prefix):
    out,err=p.communicate(timeout=55)
    assert p.returncode==0,(prefix,p.returncode,out[-800:],err[-800:])
    matching=[x[len(prefix)+1:] for x in out.splitlines() if x.startswith(prefix+" ")]
    assert len(matching)==1,(prefix,out[-900:],err[-900:])
    return json.loads(matching[0])
def once(action,case="",gate="",prefix="QF_META_STATE"):
    return result(call(action,case,gate),prefix)
def emit(item):
    print(item,flush=True)
source=call("source");out,err=source.communicate(timeout=25)
assert source.returncode==0,(source.returncode,err[-800:])
raw=[json.loads(x.split(" ",1)[1]) for x in out.splitlines() if x.startswith("QF_GF_SOURCE ")]
schema=[json.loads(x.split(" ",1)[1]) for x in out.splitlines() if x.startswith("QF_META_SCHEMA ")]
assert schema and schema[0]["engine"]=="InnoDB",schema
assert any(x["method"]=="GFAPI::update_entry_field" for x in raw)
assert any("entry_id"==i.get("Column_name") for i in schema[0]["indexes"]),schema
records["source"]=raw;records["schema"]=schema[0]
emit("QF_PINNED_GF_UPDATE_PATH "+json.dumps(
  [{"method":x["method"],"file":x["file"],"start":x["start"],"end":x["end"]} for x in raw]))
cases=once("setup",prefix="QF_META_SETUP")
for case in ["existing","absent"]:
    gate=str(root/("qf-meta-"+case))
    for suffix in [".locked",".go",".writer_ready"]:
        try:pathlib.Path(gate+suffix).unlink()
        except FileNotFoundError:pass
    before=once("inspect",case)
    locker=call("lock",case,gate)
    deadline=time.monotonic()+35
    while not pathlib.Path(gate+".locked").exists():
        if locker.poll() is not None or time.monotonic()>deadline:
            raise RuntimeError("Cannot acquire test lock "+str(locker.communicate()))
        time.sleep(.02)
    locked=json.loads(pathlib.Path(gate+".locked").read_text())
    writer=call("mutate",case,gate)
    until=time.monotonic()+15
    while not pathlib.Path(gate+".writer_ready").exists():
        if writer.poll() is not None or time.monotonic()>until:
            raise RuntimeError("GFAPI writer not started "+str(writer.communicate()))
        time.sleep(.01)
    time.sleep(1.8)
    blocked=writer.poll() is None
    during=once("inspect",case)
    pathlib.Path(gate+".go").write_text("release")
    wrote=result(writer,"QF_META_WRITER")
    released=result(locker,"QF_META_LOCK_RELEASED")
    after=once("inspect",case)
    observed={"locked":locked,"blocked_before_release":blocked,
         "before":before,"during":during,"after":after,"writer":wrote,"release":released}
    records["cases"][case]=observed
    emit("QF_META_LOCK_RESULT "+case+" "+json.dumps({
        "blocked":blocked,"locked_rows":locked["rows"],
        "before":before["value"],"during":during["value"],"after":after["value"],
        "mutations":[q.split(" ",1)[0] for q in wrote["queries"]]}))
    assert blocked and before["value"]==during["value"] and after["value"]==wrote["after"],observed
    assert any("INSERT" in q.upper() for q in wrote["queries"]) if case=="absent" else any("UPDATE" in q.upper() for q in wrote["queries"]),observed
outpath=root/"reports/qf-meta-lock-results.json"
outpath.write_text(json.dumps(records,indent=2))
emit("QF_META_LOCK_PROVEN "+str(outpath))
