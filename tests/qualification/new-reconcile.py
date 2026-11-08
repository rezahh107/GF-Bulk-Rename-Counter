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


def identity(form_id, entry_id, item):
    return (form_id, entry_id, str(item['field_id']), str(item['url']))


def compare(doc, archive=None, omit_one=False):
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
    entries = doc['entries']
    if len(entries) != scope.get('total') or len(entries) > scope.get('entry_limit', 0):
        return {'classification': UNKNOWN, 'checks': [],
                'problems': ['incomplete_entry_population'],
                'scope': scope, 'zip_checked': False}
    if doc.get('planner_error'):
        problems.append('planner_unavailable')
    selected = list(doc.get('selected', []))
    if omit_one:
        if len(selected) < 2:
            raise ValueError('negative control requires two selected occurrences')
        selected.pop()
    actual = defaultdict(list)
    for p in selected:
        actual[identity(form, int(p['entry_id']), p)].append(p)
    seen_ids = set()
    refs_in_entries = Counter(identity(form, int(e['entry_id']), o)
                              for e in entries if e['status'] == 'active'
                              for o in e['occurrences'])
    for entry in entries:
        entry_id = int(entry['entry_id'])
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
            elif occurrence.get('source_status') != 'ok':
                kind, reason = UNKNOWN, 'local_source_unavailable_or_invalid'
            elif doc.get('planner_error'):
                kind, reason = UNKNOWN, 'planner_failed_before_comparison'
            else:
                if actual[ident]:
                    item = actual[ident].pop(0)
                    if item.get('sha256') != occurrence.get('sha256') or item.get('size') != occurrence.get('size'):
                        kind, reason = MISMATCH, 'selected_source_bytes_differ'
                    elif refs_in_entries[ident] > 1:
                        kind, reason = UNKNOWN, 'same_reference_multiple_slots_unresolvable'
                    else:
                        kind, reason = CLEAR, 'independent_occurrence_selected'
                else:
                    kind, reason = MISMATCH, 'eligible_occurrence_missing_from_NEW_plan'
            checks.append({**who, 'classification': kind, 'reason': reason})
    if any(v for v in actual.values()):
        problems.append('selected_occurrence_not_in_independent_eligible_population')
    if archive is not None and not doc.get('planner_error'):
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
            'scope': scope, 'zip_checked': archive is not None,
            'historical_receipt_proven': False}


def main():
    p = argparse.ArgumentParser()
    p.add_argument('snapshot', type=Path)
    p.add_argument('--zip', type=Path)
    p.add_argument('--omit-one-selected', action='store_true', help='isolated negative control only')
    p.add_argument('--expect', choices=[MISMATCH, EXCLUDED, UNKNOWN, CLEAR], required=True)
    args = p.parse_args()
    doc = json.loads(args.snapshot.read_text(encoding='utf-8'))
    result = compare(doc, args.zip, args.omit_one_selected)
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
