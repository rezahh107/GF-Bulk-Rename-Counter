"""Focused release-workflow regression for the Owner's personal-project policy.

This is a source-contract test, not an executed GitHub publication.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/release-publish.yml"


class PersonalReleasePolicyTest(unittest.TestCase):
    def test_no_immutable_or_extra_token_dependency(self):
        source = WORKFLOW.read_text(encoding="utf-8")
        for forbidden in (
            "BRC_RELEASE_ADMIN_READ_TOKEN",
            "/immutable-releases",
            "gh release verify ",
            "gh release verify-asset",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, source)

    def test_user_approval_and_candidate_identity_gates_retained(self):
        source = WORKFLOW.read_text(encoding="utf-8")
        for required in (
            "inputs.confirmation == 'PUBLISH'",
            'test -f release/candidate.json',
            "scripts/release/release.py bind --reviewed",
            "scripts/release/release.py candidate-ci --runs-file",
            "scripts/release/release.py select-candidate --merged-prs-file",
            'test -z "$(git ls-remote --tags origin',
            "scripts/release/release.py history --tags",
            "scripts/release/release.py validate --zip",
            'gh release create "$BRC_TAG" --verify-tag --draft',
        ):
            with self.subTest(required=required):
                self.assertIn(required, source)

    def test_downloaded_assets_still_verified_without_attestations(self):
        source = WORKFLOW.read_text(encoding="utf-8")
        for required in (
            'gh release download "$BRC_TAG"',
            'cmp "$RUNNER_TEMP/release/$zname" "$dest/$zname"',
            'cmp "$RUNNER_TEMP/release/$zname.sha256" "$dest/$zname.sha256"',
            'cmp "$RUNNER_TEMP/release/$zname.manifest.json" "$dest/$zname.manifest.json"',
            'test "$(gh release view "$BRC_TAG" --json tagName --jq',
            'test "$(gh release view "$BRC_TAG" --json isDraft --jq',
            'bash scripts/release/consumer-smoke.sh',
            'PUBLISHED_AND_CONSUMER_VERIFIED',
        ):
            with self.subTest(required=required):
                self.assertIn(required, source)


if __name__ == "__main__":
    unittest.main()
