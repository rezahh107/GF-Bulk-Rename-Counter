"""Bounded safety checks for the v2.1.1 one-time, no-republish recovery.

These static checks are NOT a substitute for exercising the recovered
public release in the authentic WordPress consumer environment.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
RECOVERY = ROOT / ".github/workflows/release-recover-v2.1.1.yml"
CONSUMER = ROOT / "scripts/release/consumer-smoke.sh"


class PublishedRecoverySafety(unittest.TestCase):
    def test_recovery_is_explicit_and_only_recovers_existing_v2_1_1(self):
        text = RECOVERY.read_text(encoding="utf-8")
        self.assertIn("workflow_dispatch:", text)
        self.assertIn("RECOVER-v2.1.1", text)
        self.assertIn("v2.1.1", text)
        self.assertIn("b9e86d15f04a030f1a4c75abc2491461e75022dd", text)
        self.assertIn("a57b5bf3c9be6bbe6bd13af7dc415f98dba62c14e57bc0349cdf48820ff7ab39", text)
        self.assertIn("gh release download", text)
        self.assertNotIn("gh release create", text)
        self.assertNotIn("gh release edit", text)
        self.assertNotIn("git tag -a", text)
        self.assertNotIn("git push --force", text)
        self.assertNotIn("gh release upload", text)
        self.assertNotIn("PUBLISH", text.split("jobs:", 1)[-1].split("steps:", 1)[0])

    def test_recovery_validates_published_commit_not_changed_main(self):
        text = RECOVERY.read_text(encoding="utf-8")
        self.assertIn("git worktree add --detach", text)
        self.assertIn('--expected-head "$BRC_RELEASE_COMMIT"', text)
        self.assertIn("cmp \"$rebuilt/$z\" \"$dest/$z\"", text)
        self.assertIn('gh api "repos/$GITHUB_REPOSITORY/git/tags/$remote_tag"', text)
        self.assertNotIn('ls-remote --tags origin "refs/tags/$BRC_TAG^{}"', text)

    def test_candidate_marker_cannot_be_retired_before_real_install(self):
        text = RECOVERY.read_text(encoding="utf-8")
        self.assertLess(text.index("gh release download"), text.index("consumer-smoke.sh"))
        self.assertLess(text.index("bash scripts/release/consumer-smoke.sh"), text.index("git rm release/candidate.json"))
        self.assertIn('test "$(git ls-remote origin refs/heads/main | cut -f1)" = "$GITHUB_SHA"', text)
        self.assertIn("if: failure()", text)

    def test_consumer_smoke_accepts_php_8_2_without_pinning_stale_patch(self):
        text = CONSUMER.read_text(encoding="utf-8")
        self.assertIn("PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 2", text)
        self.assertNotIn('echo PHP_VERSION;', text)
        self.assertIn("plugin install \"$zip\" --activate", text)
        self.assertIn("has_filter", text)


if __name__ == "__main__":
    unittest.main()
