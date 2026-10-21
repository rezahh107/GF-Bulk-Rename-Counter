#!/usr/bin/env python3
"""Real GF 3.1.1.1 / MariaDB mutation-safe Entry snapshot qualification; no product code."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time

ROOT=Path(__file__).resolve().parents[2]
WORKER=ROOT/'tests/experimental-incremental/exact-snapshot-worker.php'
LAB=Path(os.environ['BRC_LAB_DIR'])
WPCLI=os.environ['BRC_WP_CLI']
WPPATH=os.environ['BRC_WP_PATH']
n=0

def check(condition,message):
    if not condition: raise AssertionError(message)

def spawn(job):
    global n
    n+=1
    path=LAB/('snapshot-job-'+str(n)+'.json')
    path.write_text(json.dumps(job),encoding='utf-8')
    env=dict(os.environ,BRC_SNAPSHOT_JOB=str(path))
    return subprocess.Popen(['php',WPCLI,'--path='+WPPATH,'--user=bootstrap_admin',
                             'eval-file',str(WORKER)],
        cwd=ROOT,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)

def finish(proc,desc):
    out,err=proc.communicate(timeout=65)
    check(proc.returncode==0,desc+' worker failed: '+err[-1800:]+' '+out[-400:])
    arr=[line[len('SNAPSHOT_RESULT '):] for line in out.splitlines()
         if line.startswith('SNAPSHOT_RESULT ')]
    check(len(arr)==1,desc+' no worker result: '+out[-800:])
    return json.loads(arr[0])

def run(job,desc):
    return finish(spawn(job),desc)

def wait_ready(prefix):
    ready=Path(str(prefix)+'.ready')
    deadline=time.monotonic()+55
    while not ready.is_file():
        check(time.monotonic()<deadline,'Barrier timed out: '+str(prefix))
        time.sleep(.025)
    return json.loads(ready.read_text())

def passed(name,**kw):
    print(name,json.dumps(kw,sort_keys=True))

def mutation_case(kind):
    seed=run({'action':'bootstrap'},kind+'-bootstrap')
    check(seed['size']==620 and len(set(seed['expected']))==620,
          'Invalid source creation oracle')
    with tempfile.TemporaryDirectory(prefix='gf-snapshot-') as tmp:
        barrier=Path(tmp)/'reader'
        proc=spawn({'action':'scan','kind':kind,'form':seed['form'],
                    'barrier':str(barrier)})
        first=wait_ready(barrier)
        check(first['first']==53 and first['first_id']==seed['expected'][0],
              'Initial GFAPI page differs from exact oracle')
        mutation=run(dict(action='mutate',**{k:seed[k] for k in
                     ('form','spam','status_change','filter_change','delete')}),
                     kind+'-mutation')
        Path(str(barrier)+'.go').touch()
        result=finish(proc,kind+'-scan')
    expected=set(seed['expected'])
    found=set(result['ids'])
    missing=sorted(expected-found)
    extra=sorted(found-expected)
    if kind=='repeatable':
        check(result['ids']==seed['expected'],
              'Consistent transaction lost its original filtered Entry IDs: '+
              str({'missing':missing[:6],'extra':extra[:6]}))
        check(mutation['inserted'] not in found,'Transaction included later insert')
        passed('PASS_REAL_GF_TRANSACTION_620_EXACT_MUTATION_SAFE',
               original=len(expected),returned=len(result['ids']),
               field_and_status_and_deletion_and_insert=True)
    else:
        check(result['ids']!=seed['expected'] and (missing or extra or
              len(result['ids'])!=len(found)),
              'Unprotected GFAPI offset enumeration unexpectedly unchanged')
        passed('PASS_FALSIFIED_LIVE_OFFSET_620_WITH_MUTATIONS',
               original=len(expected),returned=len(result['ids']),
               missing=missing[:10],extra=extra[:10],
               duplicates=len(result['ids'])-len(found))
    current=run({'action':'capture','form':seed['form']},kind+'-current')
    after=set(current['ids'])
    check(mutation['inserted'] in after and mutation['active'] in after and
          mutation['status_removed'] not in after and
          mutation['membership_removed'] not in after and
          mutation['deleted'] not in after,
          'Mutations not visible to new GFAPI snapshot')
    return seed

def delayed_commit_case(form):
    with tempfile.TemporaryDirectory(prefix='gf-delayed-commit-') as tmp:
        barrier=Path(tmp)/'older-writer'
        proc=spawn({'action':'hold_insert','form':form,'barrier':str(barrier)})
        older=wait_ready(barrier)
        old_id=older['allocated_id']
        newer=run({'action':'insert','form':form},'newer-committed')
        newer_id=newer['id']
        check(old_id<newer_id,
              'Actual allocation order did not match expected delayed commit')
        frozen=run({'action':'capture','form':form},'consistent-frozen')
        check(newer_id in frozen['ids'] and old_id not in frozen['ids'] and
              frozen['frontier']>=newer_id,
              'Delayed uncommitted Entry was unexpectedly visible or no high water')
        Path(str(barrier)+'.go').touch()
        completed=finish(proc,'delayed-older-commit')
    check(completed['committed_id']==old_id,'Delayed commit changed Entry ID')
    post=run({'action':'read_after','form':form,'id':old_id},'delayed-readback')
    check(old_id in post['ids'] and old_id<=frozen['frontier'] and
          newer_id in post['ids'],'Committed Entry did not remain below frozen frontier')
    omitted=sorted(set(post['ids'])-set(frozen['ids']))
    check(old_id in omitted and old_id<=frozen['frontier'],
          'No true post-snapshot older-allocated committed Entry reproduced')
    passed('PASS_REAL_GF_DELAYED_COMMIT_FALSIFIES_HIGH_WATER_COMPLETENESS',
           earlier_allocated_later_committed=old_id,
           later_allocated_earlier_committed=newer_id,
           snapshot_frontier=frozen['frontier'],
           forever_skipped_by_naive_id_only=old_id,
           post_snapshot_omissions=omitted[:10])

def main():
    info=run({'action':'info'},'source-and-engine')
    check(all(x=='InnoDB' for x in info['engines'].values()),
          'GF Entry and Entry Meta must use InnoDB')
    check(info['isolation']=='REPEATABLE-READ',
          'Unexpected isolation, cannot claim snapshot qualification')
    passed('PASS_PINNED_GF_DB_TRANSACTION_SOURCE',**info)
    mutation_case('plain')
    repeatable=mutation_case('repeatable')
    delayed_commit_case(repeatable['form'])
    passed('QUALIFICATION_ONLY_NO_PRODUCTION_MUTATIONS')

if __name__=='__main__':
    main()
