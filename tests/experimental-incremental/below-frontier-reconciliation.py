#!/usr/bin/env python3
"""Bounded REAL WordPress / GF / MariaDB reconciliation qualification, never product code."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
WORKER = ROOT / 'tests/experimental-incremental/below-frontier-worker.php'
LAB = Path(os.environ['BRC_LAB_DIR'])
CLI = os.environ['BRC_WP_CLI']
WP = os.environ['BRC_WP_PATH']
seq = 0

def check(ok, why):
    if not ok:
        raise AssertionError(why)

def spawn(job):
    global seq
    seq += 1
    path = LAB / ('recon-job-' + str(seq) + '.json')
    path.write_text(json.dumps(job), encoding='utf-8')
    env = dict(os.environ, BRC_RECON_JOB=str(path))
    return subprocess.Popen(
        ['php', CLI, '--path='+WP, '--user=bootstrap_admin',
         'eval-file', str(WORKER)],
        cwd=ROOT, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        text=True)

def finish(p, description):
    out, err = p.communicate(timeout=65)
    check(p.returncode == 0, description + ': ' + err[-1600:] + out[-400:])
    tagged = [s[len('RECON_RESULT '):] for s in out.splitlines()
              if s.startswith('RECON_RESULT ')]
    check(len(tagged) == 1, description + ' missing exact result: ' + out[-1000:])
    return json.loads(tagged[0])

def run(job, description):
    return finish(spawn(job), description)

def ready(prefix):
    flag = Path(str(prefix) + '.ready')
    deadline = time.monotonic() + 55
    while not flag.is_file():
        check(time.monotonic() < deadline, 'Barrier timeout '+str(prefix))
        time.sleep(0.025)
    return json.loads(flag.read_text())

def go(prefix):
    Path(str(prefix) + '.go').touch()

def op(form, name, ids):
    # The operation membership and artifact verification are TEST-OWNED inputs.
    # This is not an authenticated real HTTP acknowledgment implementation.
    check(bool(ids) and len(ids) == len(set(ids)), 'Invalid test snapshot')
    return dict(id=name, form=form, ids=sorted(ids), artifact_verified=True)

def ack(form, obj, label, token=None):
    return run(dict(action='ack', form=form, op=obj,
                    ack=obj['id'] if token is None else token), label)

def status(result, expected):
    check(result['outcome'] == expected,
          'expected '+expected+' got '+str(result))

def evaluate(form):
    result = run(dict(action='evaluate',form=form), 'evaluate')
    expected = sorted(set(result['eligible'])-set(result['acked']))
    check(result['unacknowledged']==expected,
          'Exact identity set-difference differs from independent oracle')
    return result

def p(label, **data):
    print(label, json.dumps(data, sort_keys=True))

def main():
    form = run(dict(action='setup'),'form-setup')['form']
    other = run(dict(action='setup'),'other-form-setup')['form']

    # Keep each genuine GF writer open for the MINIMUM necessary API calls:
    # create subsequent committed Entry, freeze snapshot, ACK, then release.
    # Multiple separate delayed-commit rounds still exercise the defect family.
    with tempfile.TemporaryDirectory(prefix='gfbrc-recon-holds-') as temp:
        first_gate=Path(temp)/'writer-one'
        first_worker=spawn(dict(action='hold',form=form,barrier=str(first_gate)))
        older1=ready(first_gate)['id']

        # Five real global allocations in another form falsify a fixed 4-ID
        # lookback even without any time-based bound.
        others=run(dict(action='add',form=other,count=5),'real-intervening-ids')['ids']
        newer=run(dict(action='add',form=form,count=1),'visible-newer')['ids'][0]
        check(older1<max(others)<newer and newer-older1>4,
              'Real fixed-size lookback falsification precondition missing')
        frozen=run(dict(action='snapshot',form=form),
                   'first-consistent-snapshot')
        check(frozen['ids']==[newer],
              'First frozen snapshot unexpectedly saw uncommitted older ID')
        first=op(form,'first-exact-confirmed',[newer])
        status(ack(form,first,'first-valid-ack'),'COMMITTED')
        go(first_gate)
        check(finish(first_worker,'late-first')['committed']==older1,
              'First real late GF commit failed')

        # All duplicate and invalid ACK checks run AFTER releasing GF writer.
        duplicate=ack(form,first,'repeat-first')
        status(duplicate,'ALREADY_ACKNOWLEDGED')
        invalid=ack(form,op(form,'not-yet-authorized',[older1]),
                    'wrong-token','unmatched-token')
        status(invalid,'NO_VALID_ACK')
        now=evaluate(form)
        check(now['acked']==[newer] and now['unacknowledged']==[older1],
              'First acknowledged snapshot missed real delayed Entry')
        above_unack=run(dict(action='add',form=form,count=1),
                        'above-first-frontier')['ids'][0]

        second_gate=Path(temp)/'writer-two'
        second_worker=spawn(dict(action='hold',form=form,barrier=str(second_gate)))
        older2=ready(second_gate)['id']
        higher=run(dict(action='add',form=form,count=1),
                   'second-visible-newer')['ids'][0]
        check(above_unack<older2<higher,
              'Second real delayed commit allocation order missing')
        frozen2=run(dict(action='snapshot',form=form),
                    'second-consistent-snapshot')
        check(older1 in frozen2['ids'] and above_unack in frozen2['ids']
              and higher in frozen2['ids'] and older2 not in frozen2['ids'],
              'Second snapshot visibility mismatch')
        # This exact snapshot includes old1 and above_unack. For this
        # checkpoint-isolation experiment we ACK only a controlled operation
        # selecting higher; this is NOT a complete NEW frontier advancement.
        status(ack(form,op(form,'second-exact-confirmed',[higher]),
                   'second-valid-ack'),'COMMITTED')
        go(second_gate)
        check(finish(second_worker,'late-second')['committed']==older2,
              'Second real late GF commit failed')

    final_above=run(dict(action='add',form=form,count=1),
                    'above-second-frontier')['ids'][0]
    current=evaluate(form)
    expected_missing=[older1,above_unack,older2,final_above]
    check(current['acked']==[newer,higher] and
          current['unacknowledged']==expected_missing,
          'Reconciliation did not preserve exact real delayed Entry identities')
    check(older1<newer<above_unack<older2<higher<final_above,
          'Expected mixed IDs below and above diagnostic frontier absent')
    p('PASS_REAL_GF_MULTI_DELAYED_COMMIT_RECONCILED_AFTER_ACK',
      late=[older1,older2],acknowledged=[newer,higher],
      unacknowledged=current['unacknowledged'])
    p('PASS_REAL_GF_FIXED_4_LOOKBACK_FALSIFIED',
      late_id=older1,diagnostic_frontier=newer,gap=newer-older1)
    frontier_only=[x for x in current['eligible'] if x>higher]
    check(older1 not in frontier_only and older2 not in frontier_only,
          'Frontier-only falsifier missed genuinely below-frontier Entries')
    stored=run(dict(action='read',form=form),'exact-ack-ranges')
    check(stored['state']['ranges']==[[newer,newer],[higher,higher]],
          'Lossless ranges did not preserve confirmed identities and gaps')
    p('PASS_REAL_IDENTITIES_LOSSLESS_RANGES_VS_LOSSY_INTERVAL',
      exact_ranges=stored['state']['ranges'],
      falsely_covered_by_frontier=[older1,older2])

    # Actual two independent concurrent ACKs race on identical in-memory revision.
    op_left=op(form,'ack-late-left',[older1,above_unack])
    op_right=op(form,'ack-late-right',[older2,final_above])
    with tempfile.TemporaryDirectory(prefix='gfbrc-recon-race-') as temp:
        bar1=Path(temp)/'ack-left'
        bar2=Path(temp)/'ack-right'
        w1=spawn(dict(action='ack',form=form,op=op_left,ack=op_left['id'],barrier=str(bar1)))
        r1=ready(bar1)
        w2=spawn(dict(action='ack',form=form,op=op_right,ack=op_right['id'],barrier=str(bar2)))
        r2=ready(bar2)
        check(r1['pid']!=r2['pid'] and r1['rev']==r2['rev']==2,
              'Independent workers must read the same persisted revision')
        go(bar1);go(bar2)
        race=[finish(w1,'race-left'),finish(w2,'race-right')]
    check(sorted(v['outcome'] for v in race)==['CAS_CONFLICT','COMMITTED'],
          'Exactly one CAS winner required, got '+str(race))
    after_race=evaluate(form)
    check(len(after_race['unacknowledged']) == 2,
          'Race lost or improperly advanced acknowledged identities')
    for candidate in (op_left,op_right):
        response=ack(form,candidate,'retry-same-frozen-evidence')
        status(response,'ALREADY_ACKNOWLEDGED' if
               not(set(candidate['ids'])-set(after_race['acked']))
               else 'COMMITTED')
    final=evaluate(form)
    check(final['unacknowledged']==[] and
          final['acked']==sorted([older1,older2,newer,higher,above_unack,final_above]),
          'Stale retry failed to restore exact acknowledgment coverage')
    p('PASS_TWO_PROCESS_REAL_DB_CONCURRENT_ACK_CAS_AND_STALE_RETRY',
      race=[v['outcome'] for v in race], final_rev=final['rev'],
      final_acknowledged=final['acked'])
    status(ack(form,first,'old-duplicate-after-other-acks'),'ALREADY_ACKNOWLEDGED')
    check(evaluate(form)['unacknowledged']==[],
          'Older duplicate altered exact confirmed coverage')

    # Status/field changes are real GF mutations. Their product NEW policy
    # is unapproved, so this ONLY qualifies an eligible-minus-ACK query.
    run(dict(action='mutate',form=form,id=newer,kind='status',value='spam'),
        'acked-status-out')
    check(newer not in evaluate(form)['eligible'],'Spam should leave active filter')
    run(dict(action='mutate',form=form,id=newer,kind='status',value='active'),
        'acked-status-back')
    check(newer in evaluate(form)['acked'] and
          newer not in evaluate(form)['unacknowledged'],
          'Previously ACKed Entry misclassified after status restoration')
    run(dict(action='mutate',form=form,id=higher,kind='field',value='other'),
        'acked-membership-out')
    check(higher not in evaluate(form)['eligible'],'Field edit did not alter filter')
    run(dict(action='mutate',form=form,id=higher,kind='field',value='match'),
        'acked-membership-back')
    check(higher not in evaluate(form)['unacknowledged'],
          'Previously ACKed Entry reappeared as missing on membership restoration')
    old_inactive=run(dict(action='add',form=form,count=1,marker='other'),
                     'old-not-eligible')['ids'][0]
    run(dict(action='mutate',form=form,id=old_inactive,kind='field',value='match'),
        'newly-eligible-existing')
    changed=evaluate(form)
    check(changed['unacknowledged']==[old_inactive],
          'Current eligibility set minus ACKed identities did not detect membership')
    # Do NOT decide whether an existing now-eligible Entry belongs to Owner NEW policy.
    p('PASS_REAL_GF_MEMBERSHIP_CHANGE_IDENTITY_DISTINCTION_POLICY_UNDECIDED',
      newly_eligible_old=old_inactive, unacknowledged=changed['unacknowledged'])

    # Negative control: absence of one confirmed identity in an incomplete record
    # falsely claims it is unacknowledged; equal counts/maxima cannot identify which.
    actual=set(final['acked'])
    omitted=set(actual)-{newer}
    check(newer in set(changed['eligible'])-omitted and newer not in
          set(changed['eligible'])-actual,
          'Incomplete history false positive not reproduced')
    aggregate_left={newer,higher}
    aggregate_right={older1,higher}
    check(len(aggregate_left)==len(aggregate_right) and
          max(aggregate_left)==max(aggregate_right) and
          aggregate_left!=aggregate_right,
          'Count+max indistinguishability control invalid')
    p('PASS_SYNTH_INCOMPLETE_IDENTITY_AND_EQUAL_AGGREGATE_NEGATIVE_CONTROLS',
      missing_identity=newer, same_count_and_max_distinct_sets=True)

    clean1=run(dict(action='cleanup',form=form),'cleanup-a')
    clean2=run(dict(action='cleanup',form=other),'cleanup-other')
    check(clean1['deleted'] and clean1['absent'] and
          clean2['deleted'] and clean2['absent'], 'Temporary option cleanup failed')
    p('RECONCILIATION_QUALIFICATION_ONLY_NO_PRODUCTION_PERSISTENCE',
      actual_form=form)

if __name__=='__main__':
    main()
