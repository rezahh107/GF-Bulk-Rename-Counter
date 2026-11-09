#!/usr/bin/env python3
"""Test-only GF/NEW occurrence reconciliation; no production writes.

Independent entries[].occurrences come from bounded GFAPI/physical-file reads.
Selected comes from the plugin NEW plan and is NEVER an expectation source.
"""
import argparse
from collections import Counter, defaultdict
import hashlib
import json
from pathlib import Path
import sys
import zipfile

MISMATCH = 'CONFIRMED_MISMATCH'
EXCLUDED = 'EXPECTED_EXCLUSION'
UNKNOWN = 'INCONCLUSIVE'
CLEAR = 'NO_OBSERVED_MISMATCH'


def in_ranges(ranges, entry_id):
    return any(isinstance(r, list) and len(r) == 2 and
               isinstance(r[0], int) and isinstance(r[1], int) and
               r[0] <= entry_id <= r[1] for r in ranges)


def valid_ranges(ranges):
    last = 0
    for item in ranges:
        if not isinstance(item, list) or len(item) != 2 or any(
            type(x) is not int for x in item
        ) or item[0] <= last or item[0] < 1 or item[1] < item[0]:
            return False
        last = item[1]
    return True


def identity(form_id, entry_id, item):
    return (form_id, entry_id, str(item['field_id']), str(item['url']))


def valid_entry_id(value):
    return type(value) is int and value > 0


def valid_plan_signature(value):
    # Frozen operation fields and the current read-only PHP recomputation.
    return (isinstance(value, dict) and
            type(value.get('files')) is int and value['files'] >= 0 and
            isinstance(value.get('fingerprint'), str) and
            len(value['fingerprint']) == 64 and
            all(c in '0123456789abcdef' for c in value['fingerprint']))


def qualified_pre_prepare_ids(doc, state, pending):
    """Only a separately captured, complete pre-prepare GF observation is evidence.

    The browser lab owns before-prepare ordering. This is not an atomic
    database snapshot and makes no production-site concurrency claim.
    """
    pre = doc.get('pre_prepare')
    if not isinstance(pre, dict) or pre.get('phase') != 'before_prepare':
        return None
    scope = pre.get('scope', {})
    if (not isinstance(scope, dict) or pre.get('form_id') != doc['form_id'] or
            pre.get('generation_sha256') != pending.get('generation_sha256') or
            not isinstance(pre.get('generation_sha256'), str) or
            len(pre['generation_sha256']) != 64 or
            scope.get('complete') is not True or
            scope.get('consistent') is not True or pre.get('pending_active') is not False or
            pre.get('state') != state or not isinstance(pre.get('entries'), list) or
            type(scope.get('total')) is not int or type(scope.get('entry_limit')) is not int or
            len(pre['entries']) != scope.get('total') or
            len(pre['entries']) > scope['entry_limit']):
        return None
    ids, seen = set(), set()
    current = {entry['entry_id']: entry['status'] for entry in doc['entries']}
    for entry in pre['entries']:
        if not isinstance(entry, dict) or not valid_entry_id(entry.get('entry_id')):
            return None
        entry_id = entry['entry_id']
        status = entry.get('status')
        if entry_id in seen or status not in ('active', 'trash', 'spam'):
            return None
        seen.add(entry_id)
        if status == 'active' and not in_ranges(state['known'], entry_id):
            if current.get(entry_id) != 'active':
                # Status changed between observations; no reliable omission claim.
                return None
            ids.add(entry_id)
    return ids


def compare(doc, archive=None, omit_one=False, omit_pending=False):
    scope = doc.get('scope', {})
    form = int(doc['form_id'])
    state = doc.get('state')
    problems = []
    checks = []
    if not scope.get('complete') or not scope.get('consistent') or not isinstance(state, dict):
        return {'classification': UNKNOWN, 'checks': [],
                'problems': ['bounded_or_consistent_snapshot_unavailable'],
                'scope': scope, 'zip_checked': False}
    if state.get('schema') != 1 or not isinstance(state.get('known'), list) or not isinstance(state.get('covered'), list):
        return {'classification': UNKNOWN, 'checks': [],
                'problems': ['invalid_or_unknown_coverage_state'],
                'scope': scope, 'zip_checked': False}
    if not valid_ranges(state['known']) or not valid_ranges(state['covered']) or (
        type(state.get('rev')) is not int or state['rev'] < 0
    ):
        return {'classification': UNKNOWN, 'checks': [],
                'problems': ['malformed_coverage_ranges_or_revision'],
                'scope': scope, 'zip_checked': False}
    entries = doc.get('entries')
    if (not isinstance(entries, list) or len(entries) != scope.get('total') or
            len(entries) > scope.get('entry_limit', 0)):
        return {'classification': UNKNOWN, 'checks': [],
                'problems': ['incomplete_entry_population'],
                'scope': scope, 'zip_checked': False}
    selected = doc.get('selected', [])
    for population, rows in (('current', entries), ('selected', selected)):
        if not isinstance(rows, list) or any(
            not isinstance(row, dict) or not valid_entry_id(row.get('entry_id')) for row in rows
        ):
            return {'classification': UNKNOWN, 'checks': [],
                    'problems': [f'invalid_{population}_entry_identities'],
                    'scope': scope, 'zip_checked': False}
    if doc.get('planner_error'):
        problems.append('planner_unavailable')
    # The pending generation is frozen at prepare. A later GFAPI row cannot
    # establish that its Entry was eligible at that earlier boundary.
    pending = doc.get('pending')
    pending_ids = set()
    prepared_plan_matches = False
    has_active_pending = isinstance(pending, dict) and pending.get('active') is True
    if has_active_pending:
        if not pending.get('coherent'):
            return {'classification': UNKNOWN, 'checks': [],
                    'problems': ['pending_generation_or_base_unstable'],
                    'scope': scope, 'zip_checked': False}
        ids = pending.get('ids')
        if (not isinstance(ids, list) or not all(valid_entry_id(item) for item in ids) or
                len(set(ids)) != len(ids)):
            return {'classification': UNKNOWN, 'checks': [],
                    'problems': ['invalid_pending_entry_identities'],
                    'scope': scope, 'zip_checked': False}
        pending_ids = set(ids)
        qualified_ids = qualified_pre_prepare_ids(doc, state, pending)
        if omit_pending:
            if not pending_ids:
                raise ValueError('pending omission control requires an Entry ID')
            pending_ids.remove(min(pending_ids))
        # NEW freezes count/fingerprint at prepare, and its download path
        # rejects a subsequent plan whose file identities or bytes changed.
        # Current GFAPI/plan agreement is not evidence of prepared agreement.
        current_plan = doc.get('recomputed_plan')
        if not valid_plan_signature(pending) or not valid_plan_signature(current_plan):
            problems.append('prepared_plan_signature_unavailable_or_invalid')
        elif (pending['files'] != current_plan['files'] or
              pending['fingerprint'] != current_plan['fingerprint']):
            problems.append('prepared_plan_changed_since_prepare')
        else:
            prepared_plan_matches = True
        independent_now = {e['entry_id'] for e in entries
                           if e['status'] == 'active' and
                           not in_ranges(state['known'], e['entry_id'])}
        if qualified_ids is None:
            # Matching today's IDs or files cannot prove the completeness of a
            # frozen operation without a qualified, same-generation baseline.
            problems.append('pre_prepare_eligibility_not_established')
        else:
            for omitted in sorted(qualified_ids - pending_ids):
                checks.append({'form_id': form, 'entry_id': omitted,
                               'classification': MISMATCH,
                               'reason': 'eligible_entry_missing_from_actual_NEW_pending'})
            if pending_ids - qualified_ids:
                # An Entry can legitimately arrive between a non-atomic
                # pre-prepare read and prepare. Do not claim complete parity.
                problems.append('pending_ids_not_in_pre_prepare_population')
        if pending_ids - independent_now:
            problems.append('pending_contains_out_of_scope_or_excluded_entries')
    elif any(e['status'] == 'active' and
             not in_ranges(state['known'], e['entry_id']) for e in entries):
        problems.append('no_live_NEW_operation_to_compare')
    if omit_one:
        if len(selected) < 2:
            raise ValueError('negative control requires two selected occurrences')
        selected = selected[:-1]
    actual = Counter()
    source_readings = defaultdict(set)
    for p in selected:
        ident = identity(form, p['entry_id'], p)
        actual[ident] += 1
        source_readings[ident].add((p.get('size'), p.get('sha256')))
    seen_ids = set()
    initial_selected_counts = actual.copy()
    refs_in_entries = Counter()
    for entry in entries:
        if entry['status'] != 'active':
            continue
        for occurrence in entry['occurrences']:
            ident = identity(form, entry['entry_id'], occurrence)
            refs_in_entries[ident] += 1
            reading = (occurrence.get('size'), occurrence.get('sha256'))
            source_readings[ident].add(reading if occurrence.get('source_stable') is True else None)
    for entry in entries:
        entry_id = entry['entry_id']
        if entry_id in seen_ids:
            return {'classification': UNKNOWN, 'checks': [],
                    'problems': ['duplicate_entry_identity'], 'scope': scope, 'zip_checked': False}
        seen_ids.add(entry_id)
        status = entry['status']
        known = in_ranges(state['known'], entry_id)
        covered = in_ranges(state['covered'], entry_id)
        for occurrence in entry['occurrences']:
            field = str(occurrence['field_id'])
            slot = occurrence['slot']
            ident = identity(form, entry_id, occurrence)
            # Slot is preserved even when a plan omits it; repeated identical URLs
            # within one field cannot be mapped to a unique slot without proof.
            who = {'form_id': form, 'entry_id': entry_id, 'field_id': field,
                   'slot': slot, 'reference_sha256': hashlib.sha256(
                       str(occurrence['url']).encode()).hexdigest()[:16]}
            reason = ''
            if status != 'active':
                kind, reason = EXCLUDED, 'inactive_status:' + str(status)
            elif occurrence.get('deleted'):
                kind, reason = EXCLUDED, 'explicit_host_deleted_reference'
            elif known:
                kind, reason = EXCLUDED, 'creation_only_known_entry_not_reselected'
                if not covered:
                    kind, reason = UNKNOWN, 'known_without_acknowledged_coverage'
            elif not has_active_pending:
                kind, reason = UNKNOWN, 'no_live_NEW_operation_to_compare'
            elif entry_id not in pending_ids:
                # Do not treat post-prepare additions as planner omissions.
                if qualified_ids is not None and entry_id in qualified_ids:
                    kind, reason = MISMATCH, 'eligible_entry_missing_from_actual_NEW_pending'
                else:
                    kind, reason = UNKNOWN, 'eligibility_at_prepare_not_established'
            elif occurrence.get('source_status') != 'ok':
                kind, reason = UNKNOWN, 'local_source_unavailable_or_invalid'
            elif doc.get('planner_error'):
                kind, reason = UNKNOWN, 'planner_failed_before_comparison'
            else:
                was_selected = actual[ident] > 0
                if was_selected:
                    actual[ident] -= 1
                # Qualify this reference independently. Conflicting planner
                # bytes can also expose a transient change between equal reads.
                readings = source_readings[ident]
                if None in readings or len(readings) != 1:
                    kind, reason = UNKNOWN, 'source_stability_not_established_across_plan'
                elif was_selected:
                    if refs_in_entries[ident] > 1:
                        kind, reason = UNKNOWN, 'same_reference_multiple_slots_unresolvable'
                    else:
                        kind, reason = CLEAR, 'independent_occurrence_selected'
                else:
                    kind, reason = MISMATCH, 'eligible_occurrence_missing_from_NEW_plan'
                    if refs_in_entries[ident] > 1:
                        # Multiplicity is missing, but no planner slot exists
                        # to determine which identical-reference slot it was.
                        who['slot'] = None
                        reason = 'duplicate_reference_multiplicity_missing_slot_ambiguous'
                        who['expected_count'] = refs_in_entries[ident]
                        who['selected_count'] = initial_selected_counts[ident]
            checks.append({**who, 'classification': kind, 'reason': reason})
    if any(v for v in actual.values()):
        problems.append('selected_occurrence_not_in_independent_eligible_population')
    zip_checked = archive is not None and prepared_plan_matches and not doc.get('planner_error')
    if archive is not None and not zip_checked:
        problems.append('zip_plan_not_bound_to_prepare')
    if zip_checked:
        try:
            with zipfile.ZipFile(archive) as z:
                members = z.infolist()
                names = [x.filename for x in members]
                if len(names) != len(set(n.casefold() for n in names)) or any(
                    x.is_dir() or '/' in x.filename or '\\' in x.filename for x in members
                ):
                    problems.append('invalid_flat_archive_names')
                planned = Counter(p['name'] for p in selected)
                zipped = Counter(names)
                if planned != zipped:
                    problems.append('zip_member_set_differs_from_selected_plan')
                    if planned - zipped:
                        checks.append({'form_id': form, 'classification': MISMATCH,
                                       'reason': 'expected_selected_zip_member_missing'})
                for member in members:
                    matching = [p for p in selected if p['name'] == member.filename]
                    if len(matching) != 1:
                        problems.append('zip_member_identity_ambiguous')
                        continue
                    data = z.read(member)
                    if hashlib.sha256(data).hexdigest() != matching[0]['sha256'] or len(data) != matching[0]['size']:
                        problems.append('zip_member_bytes_differ')
        except (OSError, zipfile.BadZipFile, RuntimeError, KeyError) as exc:
            problems.append('zip_inaccessible_or_invalid:' + type(exc).__name__)
    # Problems may include uncertain evidence rather than a proven omission.
    if any(c['classification'] == MISMATCH for c in checks):
        overall = MISMATCH
    elif problems or any(c['classification'] == UNKNOWN for c in checks):
        overall = UNKNOWN
    elif any(c['classification'] == EXCLUDED for c in checks):
        overall = EXCLUDED
    else:
        overall = CLEAR
    # Neither covered nor a downloaded ZIP establishes historical physical receipt.
    return {'classification': overall, 'checks': checks, 'problems': problems,
            'scope': scope, 'zip_checked': zip_checked,
            'historical_receipt_proven': False}


def main():
    p = argparse.ArgumentParser()
    p.add_argument('snapshot', type=Path)
    p.add_argument('--zip', type=Path)
    p.add_argument('--omit-one-selected', action='store_true', help='isolated negative control only')
    p.add_argument('--omit-one-pending', action='store_true', help='test-only Entry-ID fault injection')
    p.add_argument('--expect', choices=[MISMATCH, EXCLUDED, UNKNOWN, CLEAR], required=True)
    args = p.parse_args()
    doc = json.loads(args.snapshot.read_text(encoding='utf-8'))
    result = compare(doc, args.zip, args.omit_one_selected, args.omit_one_pending)
    print(json.dumps(result, ensure_ascii=True, sort_keys=True))
    if result['classification'] != args.expect:
        print('RECONCILE_UNEXPECTED_CLASS expected=' + args.expect, file=sys.stderr)
        return 2
    print('PASS_RECONCILE_' + args.expect)
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (ValueError, KeyError, OSError, json.JSONDecodeError) as exc:
        print('RECONCILE_INVALID_INPUT ' + repr(exc), file=sys.stderr)
        sys.exit(2)
