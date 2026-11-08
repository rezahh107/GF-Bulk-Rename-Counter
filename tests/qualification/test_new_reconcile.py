"""Pure safety/negative controls; authentic GF boundary is tested separately in CI."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
import zipfile

p = Path(__file__).with_name('new-reconcile.py')
spec = importlib.util.spec_from_file_location('new_reconcile', p)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


def fixture(status='active', known=False, source='ok', slots=1):
    import hashlib
    url = 'http://example.invalid/uploads/a.txt'
    sha = hashlib.sha256(b'hello').hexdigest()
    occurrences = [{'field_id': '1', 'slot': i, 'url': url if slots == 1 else url + str(i),
                    'source_status': source, 'size': 5, 'sha256': sha} for i in range(slots)]
    selected = [{'entry_id': 7, 'field_id': '1', 'url': o['url'],
                 'name': 'a.txt' if i == 0 else 'b.txt', 'size': 5, 'sha256': sha}
                for i, o in enumerate(occurrences)]
    return {'form_id': 3, 'entries': [{'entry_id': 7, 'status': status,
                                     'occurrences': occurrences}],
            'state': {'schema': 1, 'rev': 1 if known else 0,
                      'known': [[7, 7]] if known else [],
                      'covered': [[7, 7]] if known else []},
            'selected': [] if known or status != 'active' else selected,
            'scope': {'complete': True, 'consistent': True, 'total': 1,
                      'entry_limit': 40}}


class ReconcileControls(unittest.TestCase):
    def test_healthy_and_omitted_identity(self):
        doc = fixture(slots=2)
        self.assertEqual(mod.compare(doc)['classification'], mod.CLEAR)
        doc['selected'] = doc['selected'][:1]
        result = mod.compare(doc)
        self.assertEqual(result['classification'], mod.MISMATCH)
        self.assertEqual(result['checks'][1]['slot'], 1)
        self.assertEqual(result['checks'][1]['reason'], 'eligible_occurrence_missing_from_NEW_plan')

    def test_actual_pending_membership_fails_even_without_a_file(self):
        doc = fixture()
        doc['entries'][0]['occurrences'] = []
        doc['selected'] = []
        doc['pending'] = {'active': True, 'coherent': True, 'ids': []}
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


if __name__ == '__main__':
    unittest.main()
