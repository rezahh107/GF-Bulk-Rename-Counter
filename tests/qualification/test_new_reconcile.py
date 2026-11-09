"""Temporal safety and source/ZIP controls; real GF boundary needs CI."""
import importlib.util
from pathlib import Path
import hashlib
import json
import tempfile
import unittest
import zipfile

p = Path(__file__).with_name('new-reconcile.py')
spec = importlib.util.spec_from_file_location('new_reconcile', p)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


def fixture(status='active', known=False, source='ok', slots=1):
    url = 'http://example.invalid/uploads/a.txt'
    sha = hashlib.sha256(b'hello').hexdigest()
    occurrences = [{'field_id': '1', 'slot': i, 'url': url if slots == 1 else url + str(i),
                    'source_status': source, 'size': 5, 'sha256': sha,
                    'source_stable': True} for i in range(slots)]
    selected = [{'entry_id': 7, 'field_id': '1', 'url': o['url'],
                 'name': chr(97 + i) + '.txt', 'size': 5, 'sha256': sha}
                for i, o in enumerate(occurrences)]
    doc = {'form_id': 3, 'entries': [{'entry_id': 7, 'status': status,
                                     'occurrences': occurrences}],
            'state': {'schema': 1, 'rev': 1 if known else 0,
                      'known': [[7, 7]] if known else [],
                      'covered': [[7, 7]] if known else []},
            'selected': [] if known or status != 'active' else selected,
            'pending': {'active': not known and status == 'active', 'coherent': True,
                        'generation_sha256': 'd' * 64,
                        'ids': [7] if not known and status == 'active' else []},
            'scope': {'complete': True, 'consistent': True, 'total': 1,
                      'entry_limit': 40, 'atomic': False}}
    return freeze_current_plan(doc)


def capture_pre_prepare(doc):
    doc['pre_prepare'] = {'phase': 'before_prepare', 'form_id': doc['form_id'],
        'generation_sha256': 'd' * 64,
        'state': dict(doc['state']), 'pending_active': False,
        'entries': [{'entry_id': e['entry_id'], 'status': e['status']}
                    for e in doc['entries']],
        'scope': {'complete': True, 'consistent': True,
                  'total': len(doc['entries']), 'entry_limit': 40,
                  'atomic': False}}
    return doc


def plan_signature(selected):
    # Test fixture only: the PHP snapshot uses wp_json_encode with the exact
    # six-field order from NEW::fingerprint(). All test references are ASCII.
    payload = [[int(p['entry_id']), str(p['field_id']), str(p['url']),
                str(p['name']), int(p['size']), str(p['sha256'])] for p in selected]
    encoded = json.dumps(payload, separators=(',', ':'), ensure_ascii=True)
    return {'files': len(selected), 'fingerprint': hashlib.sha256(encoded.encode()).hexdigest()}


def freeze_current_plan(doc):
    signature = plan_signature(doc['selected'])
    doc['recomputed_plan'] = dict(signature)
    doc['pending'].update(signature)
    return doc


class ReconcileControls(unittest.TestCase):
    def test_stable_three_file_complete_zip(self):
        doc = capture_pre_prepare(fixture(slots=3))
        with tempfile.TemporaryDirectory() as tmp:
            z = Path(tmp) / 'complete.zip'
            with zipfile.ZipFile(z, 'w') as archive:
                for selected in doc['selected']:
                    archive.writestr(selected['name'], b'hello')
            result = mod.compare(doc, z)
            self.assertEqual(result['classification'], mod.CLEAR)
            self.assertTrue(result['zip_checked'])
            self.assertFalse(result['historical_receipt_proven'])
            doc['selected'].pop()
            self.assertEqual(mod.compare(doc, z)['classification'], mod.MISMATCH)

    def test_healthy_and_omitted_identity(self):
        # A fully qualified positive requires matching pre-prepare evidence.
        doc = capture_pre_prepare(fixture(slots=2))
        self.assertEqual(mod.compare(doc)['classification'], mod.CLEAR)
        doc['selected'] = doc['selected'][:1]
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.MISMATCH)
        self.assertEqual(result['checks'][1]['slot'], 1)
        self.assertEqual(result['checks'][1]['reason'], 'eligible_occurrence_missing_from_NEW_plan')

    def test_actual_pending_membership_fails_even_without_a_file(self):
        doc = capture_pre_prepare(fixture())
        doc['entries'][0]['occurrences'] = []
        doc['selected'] = []
        freeze_current_plan(doc)
        doc['pending']['ids'] = []
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.MISMATCH)
        self.assertEqual(result['checks'][0]['reason'],
                         'eligible_entry_missing_from_actual_NEW_pending')
        doc['pending']['ids'] = [7]
        self.assertEqual(mod.compare(doc)['classification'], mod.CLEAR)

    def test_unstable_pending_cannot_establish_mismatch(self):
        doc = fixture()
        doc['pending'] = {'active': True, 'coherent': False, 'ids': []}
        self.assertEqual(mod.compare(doc)['classification'], mod.UNKNOWN)

    def test_later_upload_after_ack_not_a_defect(self):
        self.assertEqual(mod.compare(fixture(known=True))['classification'], mod.EXCLUDED)
        self.assertFalse(mod.compare(fixture(known=True))['historical_receipt_proven'])

    def test_status_and_unknown_source(self):
        self.assertEqual(mod.compare(fixture(status='trash'))['classification'], mod.EXCLUDED)
        self.assertEqual(mod.compare(fixture(status='spam'))['classification'], mod.EXCLUDED)
        self.assertEqual(mod.compare(fixture(source='unsupported_remote'))['classification'], mod.UNKNOWN)
        self.assertEqual(mod.compare(fixture(source='missing_or_inaccessible_local'))['classification'], mod.UNKNOWN)

    def test_change_and_bounded_scope(self):
        doc = fixture()
        doc['scope']['consistent'] = False
        self.assertEqual(mod.compare(doc)['classification'], mod.UNKNOWN)
        doc['scope']['consistent'] = True
        doc['scope']['complete'] = False
        self.assertEqual(mod.compare(doc)['classification'], mod.UNKNOWN)

    def test_zip_missing_member(self):
        doc = fixture(slots=2)
        with tempfile.TemporaryDirectory() as tmp:
            z = Path(tmp) / 'partial.zip'
            with zipfile.ZipFile(z, 'w') as f:
                f.writestr('a.txt', b'hello')
            self.assertEqual(mod.compare(doc, z)['classification'], mod.MISMATCH)

    def test_duplicate_url_slots_are_not_falsely_distinguished(self):
        doc = fixture(slots=2)
        doc['entries'][0]['occurrences'][1]['url'] = doc['entries'][0]['occurrences'][0]['url']
        doc['selected'][1]['url'] = doc['selected'][0]['url']
        self.assertEqual(mod.compare(doc)['classification'], mod.UNKNOWN)

    def test_invalid_coverage_does_not_assert_missing_file(self):
        doc = fixture()
        doc['state']['known'] = [[9, 3]]
        self.assertEqual(mod.compare(doc)['classification'], mod.UNKNOWN)
        doc['state']['known'] = [[7, 7], [7, 8]]
        self.assertEqual(mod.compare(doc)['classification'], mod.UNKNOWN)

    def test_duplicate_reference_omission_has_no_invented_slot(self):
        doc = fixture(slots=2)
        ref = doc['entries'][0]['occurrences'][0]['url']
        doc['entries'][0]['occurrences'][1]['url'] = ref
        doc['selected'][1]['url'] = ref
        doc['selected'] = doc['selected'][:1]
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.MISMATCH)
        missing = [c for c in result['checks'] if c['classification'] == mod.MISMATCH]
        self.assertEqual(len(missing), 1)
        self.assertIsNone(missing[0]['slot'])
        self.assertEqual(missing[0]['reason'],
                         'duplicate_reference_multiplicity_missing_slot_ambiguous')

    def test_corrupt_zip_does_not_claim_receipt(self):
        doc = fixture()
        with tempfile.TemporaryDirectory() as tmp:
            z = Path(tmp) / 'bad.zip'
            with zipfile.ZipFile(z, 'w') as f:
                f.writestr('a.txt', b'wrong')
            r = mod.compare(doc, z)
            self.assertEqual(r['classification'], mod.UNKNOWN)
            self.assertIn('zip_member_bytes_differ', r['problems'])

    def test_entry_created_after_prepare_is_not_omission(self):
        doc = capture_pre_prepare(fixture())
        late = fixture()['entries'][0].copy()
        late['entry_id'] = 8
        doc['entries'].append(late)
        doc['scope']['total'] = 2
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.UNKNOWN)
        self.assertFalse(any(c['classification'] == mod.MISMATCH for c in result['checks']))
        self.assertEqual(next(c for c in result['checks'] if c['entry_id'] == 8)['reason'],
                         'eligibility_at_prepare_not_established')

    def test_missing_baseline_cannot_claim_zero_file_omission(self):
        doc = fixture()
        doc['entries'][0]['occurrences'] = []
        doc['selected'] = []
        doc['pending']['ids'] = []
        self.assertEqual(mod.compare(doc)['classification'], mod.UNKNOWN)

    def test_no_live_pending_has_uncertain_eligibility(self):
        doc = fixture()
        doc['pending'] = {'active': False, 'coherent': True, 'ids': []}
        doc['selected'] = []
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.UNKNOWN)
        self.assertFalse(any(c['classification'] == mod.MISMATCH for c in result['checks']))

    def test_non_atomic_pre_capture_change_unqualified(self):
        doc = capture_pre_prepare(fixture())
        doc['pre_prepare']['scope']['consistent'] = False
        doc['pending']['ids'] = []
        self.assertEqual(mod.compare(doc)['classification'], mod.UNKNOWN)
        doc['pre_prepare']['scope']['consistent'] = True
        doc['state']['rev'] = 2
        self.assertEqual(mod.compare(doc)['classification'], mod.UNKNOWN)

    def test_other_prepare_generation_makes_omission_inconclusive(self):
        doc = capture_pre_prepare(fixture())
        doc['entries'][0]['occurrences'] = []
        doc['selected'] = []
        doc['pending']['ids'] = []
        doc['pending']['generation_sha256'] = 'a' * 64
        r = mod.compare(doc)
        self.assertEqual(r['classification'], mod.UNKNOWN)
        self.assertFalse(any(c['classification'] == mod.MISMATCH for c in r['checks']))

    def _assert_unqualified_clean_membership(self, doc, reason):
        # Every currently visible Entry and file occurs in the pending set.
        # Nevertheless the actual prepare-boundary completeness is not proven.
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.UNKNOWN, reason)
        self.assertIn('pre_prepare_eligibility_not_established', result['problems'])
        self.assertFalse(any(c['classification'] == mod.MISMATCH
                             for c in result['checks']))
        self.assertEqual(result['scope']['atomic'], False)

    def test_wrong_generation_matching_current_ids_must_not_pass(self):
        doc = capture_pre_prepare(fixture(slots=2))
        doc['pending']['generation_sha256'] = 'a' * 64
        self._assert_unqualified_clean_membership(doc, 'wrong pending generation')

    def test_incomplete_pre_prepare_matching_current_ids_must_not_pass(self):
        doc = capture_pre_prepare(fixture(slots=2))
        doc['pre_prepare']['scope']['complete'] = False
        self._assert_unqualified_clean_membership(doc, 'incomplete pre-prepare scan')

    def test_changed_state_matching_current_ids_must_not_pass(self):
        doc = capture_pre_prepare(fixture(slots=2))
        doc['pre_prepare']['state']['rev'] = 9
        self._assert_unqualified_clean_membership(doc, 'state changed since pre-prepare')

    def test_valid_generation_matching_current_ids_is_positive(self):
        doc = capture_pre_prepare(fixture(slots=2))
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.CLEAR)
        self.assertEqual(result['problems'], [])

    def test_pre_prepare_entry_identity_must_be_a_positive_integer(self):
        for invalid in (7.5, 7.0, '7', True, 0, -7, None):
            with self.subTest(entry_id=invalid, value_type=type(invalid)):
                doc = capture_pre_prepare(fixture())
                doc['pre_prepare']['entries'][0]['entry_id'] = invalid
                self._assert_unqualified_clean_membership(doc, 'invalid pre-prepare Entry ID')

    def test_current_and_selected_entry_identities_cannot_be_truncated(self):
        for collection in ('entries', 'selected'):
            with self.subTest(collection=collection):
                doc = capture_pre_prepare(fixture())
                doc[collection][0]['entry_id'] = 7.5
                result = mod.compare(doc)
                self.assertEqual(result['classification'], mod.UNKNOWN)
                self.assertFalse(result['checks'])

    def test_entry_arose_between_baseline_and_prepare_but_pending_includes_it(self):
        # This operation may be valid; the independent earlier read simply
        # cannot prove completeness for an Entry absent from that read.
        doc = capture_pre_prepare(fixture(slots=1))
        extra = fixture(slots=1)['entries'][0]
        extra['entry_id'] = 8
        doc['entries'].append(extra)
        doc['pending']['ids'].append(8)
        additional = dict(doc['selected'][0])
        additional['entry_id'] = 8
        additional['name'] = 'extra.txt'
        doc['selected'].append(additional)
        doc['scope']['total'] = 2
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.UNKNOWN)
        self.assertIn('pending_ids_not_in_pre_prepare_population', result['problems'])
        self.assertFalse(any(c['classification'] == mod.MISMATCH for c in result['checks']))

    def test_unqualified_generation_does_not_hide_real_file_omission(self):
        doc = capture_pre_prepare(fixture(slots=2))
        doc['pending']['generation_sha256'] = 'a' * 64
        doc['selected'].pop()
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.MISMATCH)
        self.assertIn('pre_prepare_eligibility_not_established', result['problems'])
        self.assertTrue(any(c['reason'] == 'eligible_occurrence_missing_from_NEW_plan'
                            for c in result['checks']))

    def test_negative_pending_control_cli_logic(self):
        doc = capture_pre_prepare(fixture())
        doc['entries'][0]['occurrences'] = []
        doc['selected'] = []
        freeze_current_plan(doc)
        self.assertEqual(mod.compare(doc)['classification'], mod.CLEAR)
        r = mod.compare(doc, omit_pending=True)
        self.assertEqual(r['classification'], mod.MISMATCH)

    def test_late_file_added_to_zero_file_prepared_operation(self):
        doc = fixture()
        original = doc['entries'][0]['occurrences'][0]
        added_plan = doc['selected'][0]
        doc['entries'][0]['occurrences'] = []
        doc['selected'] = []
        freeze_current_plan(doc)  # operation truly froze zero files
        capture_pre_prepare(doc)
        doc['entries'][0]['occurrences'] = [original]
        doc['selected'] = [added_plan]
        doc['recomputed_plan'] = plan_signature(doc['selected'])
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.UNKNOWN)
        self.assertIn('prepared_plan_changed_since_prepare', result['problems'])
        self.assertFalse(any(c['classification'] == mod.MISMATCH for c in result['checks']))

    def test_same_count_file_bytes_changed_after_prepare(self):
        doc = capture_pre_prepare(fixture())
        replacement = hashlib.sha256(b'changed bytes').hexdigest()
        doc['entries'][0]['occurrences'][0]['sha256'] = replacement
        doc['selected'][0]['sha256'] = replacement
        doc['recomputed_plan'] = plan_signature(doc['selected'])
        self.assertEqual(doc['recomputed_plan']['files'], doc['pending']['files'])
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.UNKNOWN)
        self.assertIn('prepared_plan_changed_since_prepare', result['problems'])
        self.assertFalse(any(c['classification'] == mod.MISMATCH for c in result['checks']))

    def test_source_bytes_changed_between_independent_read_and_recomputation(self):
        doc = capture_pre_prepare(fixture())
        occurrence = doc['entries'][0]['occurrences'][0]
        doc['selected'][0]['sha256'] = hashlib.sha256(b'world').hexdigest()
        doc['recomputed_plan'] = plan_signature(doc['selected'])
        self.assertEqual(doc['pending']['files'], doc['recomputed_plan']['files'])
        for stability in (False, True, None):
            with self.subTest(stability=stability):
                # Even equal endpoint reads cannot explain a conflicting planner hash.
                occurrence['source_stable'] = stability
                result = mod.compare(doc)
                self.assertEqual(result['classification'], mod.UNKNOWN)
                self.assertIn('prepared_plan_changed_since_prepare', result['problems'])
                self.assertEqual(result['checks'][0]['classification'], mod.UNKNOWN)

    def test_source_mutation_does_not_hide_a_separate_stable_omission(self):
        doc = capture_pre_prepare(fixture(slots=2))
        doc['entries'][0]['occurrences'][0]['source_stable'] = False
        doc['selected'][0]['sha256'] = hashlib.sha256(b'world').hexdigest()
        doc['selected'].pop()
        doc['recomputed_plan'] = plan_signature(doc['selected'])
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.MISMATCH)
        self.assertEqual([(c['slot'], c['classification']) for c in result['checks']],
                         [(0, mod.UNKNOWN), (1, mod.MISMATCH)])
        self.assertEqual(result['checks'][1]['reason'], 'eligible_occurrence_missing_from_NEW_plan')

    def test_unstable_or_unobserved_source_cannot_prove_its_own_omission(self):
        for stability in (False, None):
            with self.subTest(stability=stability):
                doc = capture_pre_prepare(fixture())
                doc['entries'][0]['occurrences'][0]['source_stable'] = stability
                doc['selected'].clear()
                doc['recomputed_plan'] = plan_signature(doc['selected'])
                result = mod.compare(doc)
                self.assertEqual(result['classification'], mod.UNKNOWN)
                self.assertEqual(result['checks'][0]['classification'], mod.UNKNOWN)

    def test_unstable_shared_reference_cannot_prove_a_duplicate_slot_omission(self):
        doc = capture_pre_prepare(fixture(slots=2))
        first, second = doc['entries'][0]['occurrences']
        second['url'] = first['url']
        first['source_stable'] = False
        doc['selected'].pop()
        doc['recomputed_plan'] = plan_signature(doc['selected'])
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.UNKNOWN)
        self.assertEqual([c['classification'] for c in result['checks']], [mod.UNKNOWN, mod.UNKNOWN])

    def test_drifted_plan_cannot_prove_omission_from_earlier_zip(self):
        doc = capture_pre_prepare(fixture())
        doc['selected'][0]['name'] = 'new-name.txt'
        doc['recomputed_plan'] = plan_signature(doc['selected'])
        with tempfile.TemporaryDirectory() as tmp:
            z = Path(tmp) / 'prepared.zip'
            with zipfile.ZipFile(z, 'w') as archive:
                archive.writestr('a.txt', b'hello')
            result = mod.compare(doc, z)
            self.assertEqual(result['classification'], mod.UNKNOWN)
            self.assertFalse(any(c['classification'] == mod.MISMATCH for c in result['checks']))

    def test_missing_prepared_fingerprint_cannot_claim_complete_operation(self):
        doc = capture_pre_prepare(fixture(slots=3))
        doc['pending'].pop('fingerprint')
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.UNKNOWN)
        self.assertIn('prepared_plan_signature_unavailable_or_invalid', result['problems'])

    def test_changed_plan_does_not_hide_independent_occurrence_omission(self):
        doc = capture_pre_prepare(fixture(slots=2))
        doc['selected'].pop()
        doc['recomputed_plan'] = plan_signature(doc['selected'])
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.MISMATCH)
        self.assertIn('prepared_plan_changed_since_prepare', result['problems'])
        self.assertTrue(any(c['reason'] == 'eligible_occurrence_missing_from_NEW_plan'
                            for c in result['checks']))



if __name__ == '__main__':
    unittest.main()
