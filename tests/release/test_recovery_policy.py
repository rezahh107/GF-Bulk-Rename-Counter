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



class ExecutedTagIdentityGates(unittest.TestCase):
    """Execute the actual YAML gate scripts with read-only, controlled GitHub responses."""

    PINNED_OBJECT = "1bb05cc4e264f9f82ff24529a80c4fb6b7b0c0f7"
    RELEASE_COMMIT = "b9e86d15f04a030f1a4c75abc2491461e75022dd"
    PRE = "Verify recovery preconditions and original published identity"
    RETIRE = "Retire ONLY the consumed candidate marker after consumer verification"

    @staticmethod
    def gate_script(name):
        import textwrap
        source = RECOVERY.read_text(encoding="utf-8")
        step = source.split(f"      - name: {name}\n", 1)
        if len(step) != 2:
            raise AssertionError(f"Missing recovery gate: {name}")
        block = step[1].split("\n      - name:", 1)[0]
        return textwrap.dedent(block.split("        run: |\n", 1)[1])

    def setUp(self):
        import json
        import shutil
        import tempfile
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.bin = self.root / "bin"
        self.bin.mkdir()
        helper = ROOT / "scripts/release/verify-published-tag.sh"
        dst = self.root / "scripts/release/verify-published-tag.sh"
        dst.parent.mkdir(parents=True)
        shutil.copyfile(helper, dst)
        marker = self.root / "release/candidate.json"
        marker.parent.mkdir()
        marker.write_text(json.dumps({
            "schema": 1, "intent": "patch", "version": "2.1.1",
            "branch": "release-candidate/v2.1.1-from-8c1e65886a28ccc677f230ebf01c4a6fc9d1ee5b",
            "base": {"commit": "8c1e65886a28ccc677f230ebf01c4a6fc9d1ee5b"}
        }))
        z = "gf-bulk-rename-counter-2.1.1.zip"
        (self.root / "release.json").write_text(json.dumps({
            "tag_name": "v2.1.1", "draft": False, "prerelease": False,
            "assets": [
                {"name": z, "digest": "sha256:a57b5bf3c9be6bbe6bd13af7dc415f98dba62c14e57bc0349cdf48820ff7ab39"},
                {"name": z + ".sha256"},
                {"name": z + ".manifest.json"},
            ],
        }))
        # These mocks never contact GitHub and never perform real Git writes.
        (self.bin / "gh").write_text("""#!/usr/bin/env bash
set -euo pipefail
test "$1" = api
case "$2" in
  repos/*/git/ref/tags/v2.1.1)
    test "$MOCK_MISSING" != 1 || exit 22
    printf '{"object":{"type":"%s","sha":"%s"}}\n' "$MOCK_REF_TYPE" "$MOCK_REF_SHA"
    ;;
  repos/*/git/tags/*)
    printf '{"sha":"%s","object":{"type":"%s","sha":"%s"}}\n' "$MOCK_OBJECT_SHA" "$MOCK_TARGET_TYPE" "$MOCK_TARGET_COMMIT"
    ;;
  repos/*/releases/tags/v2.1.1) cat "$MOCK_RELEASE_JSON" ;;
  *) echo "unexpected gh invocation: $*" >&2; exit 98 ;;
esac
""")
        (self.bin / "git").write_text("""#!/usr/bin/env bash
set -euo pipefail
case "$*" in
  "rev-parse HEAD") echo "$GITHUB_SHA" ;;
  "ls-remote origin refs/heads/main") printf '%s\trefs/heads/main\n' "$GITHUB_SHA" ;;
  "status --porcelain"|"fetch origin refs/tags/v2.1.1:refs/tags/v2.1.1") : ;;
  "rev-parse refs/tags/v2.1.1^{commit}") echo "$BRC_RELEASE_COMMIT" ;;
  "rev-parse refs/tags/v2.1.1^{tree}") echo 50b98d9dc36a5ca094630d9f6febc3f355552c97 ;;
  "config user.name "*|"config user.email "*) : ;;
  "rm release/candidate.json"|"commit -m "*|"push origin HEAD:main")
    printf '%s\n' "$*" >> "$MOCK_GIT_EFFECTS" ;;
  *) echo "unexpected git invocation: $*" >&2; exit 97 ;;
esac
""")
        (self.bin / "python3").write_text("#!/usr/bin/env bash\necho 2.1.1\n")
        for item in self.bin.iterdir():
            item.chmod(0o755)

    def run_gate(self, gate, **alterations):
        import os
        import subprocess
        env = os.environ.copy()
        env.update({
            "PATH": str(self.bin) + os.pathsep + env.get("PATH", ""),
            "GITHUB_REPOSITORY": "rezahh107/GF-Bulk-Rename-Counter",
            "GITHUB_REF": "refs/heads/main",
            "GITHUB_SHA": "a" * 40,
            "BRC_TAG": "v2.1.1",
            "BRC_VERSION": "2.1.1",
            "BRC_RELEASE_COMMIT": self.RELEASE_COMMIT,
            "BRC_TAG_OBJECT_SHA": self.PINNED_OBJECT,
            "BRC_ZIP_SHA256": "a57b5bf3c9be6bbe6bd13af7dc415f98dba62c14e57bc0349cdf48820ff7ab39",
            "BRC_CANDIDATE_BRANCH": "release-candidate/v2.1.1-from-8c1e65886a28ccc677f230ebf01c4a6fc9d1ee5b",
            "RUNNER_TEMP": str(self.root),
            "GITHUB_STEP_SUMMARY": str(self.root / "summary"),
            "MOCK_GIT_EFFECTS": str(self.root / "effects"),
            "MOCK_RELEASE_JSON": str(self.root / "release.json"),
            "MOCK_REF_TYPE": "tag",
            "MOCK_REF_SHA": self.PINNED_OBJECT,
            "MOCK_OBJECT_SHA": self.PINNED_OBJECT,
            "MOCK_TARGET_TYPE": "commit",
            "MOCK_TARGET_COMMIT": self.RELEASE_COMMIT,
            "MOCK_MISSING": "0",
        })
        env.update(alterations)
        for name in ("summary", "effects"):
            (self.root / name).write_text("")
        return subprocess.run(
            ["bash", "-c", self.gate_script(gate)],
            cwd=self.root, env=env, capture_output=True, text=True, check=False,
            timeout=15,
        )

    def test_original_observed_tag_and_commit_pass_both_executed_gates(self):
        for gate in (self.PRE, self.RETIRE):
            with self.subTest(gate=gate):
                result = self.run_gate(gate)
                self.assertEqual(result.returncode, 0, result.stderr)
                summary = (self.root / "summary").read_text()
                self.assertIn(
                    "PUBLISHED_TAG_RELEASE_AND_ASSET_IDENTITY_VERIFIED" if gate == self.PRE
                    else "RECOVERED_EXISTING_PUBLIC_V2_1_1_CONSUMER_VERIFIED_AND_MARKER_RETIRED",
                    summary,
                )

    def test_mutated_and_unavailable_tag_fail_in_both_executed_gates(self):
        mutated = "2" * 40
        cases = {
            "replacement-annotated-object-same-commit": {
                "MOCK_REF_SHA": mutated, "MOCK_OBJECT_SHA": mutated,
            },
            "ref-points-to-commit-not-annotated-tag": {"MOCK_REF_TYPE": "commit"},
            "missing-tag": {"MOCK_MISSING": "1"},
            "wrong-peeled-commit": {"MOCK_TARGET_COMMIT": "3" * 40},
            "wrong-peeled-object-type": {"MOCK_TARGET_TYPE": "tree"},
            "object-endpoint-identity-mismatch": {"MOCK_OBJECT_SHA": "4" * 40},
        }
        for case, changes in cases.items():
            for gate in (self.PRE, self.RETIRE):
                with self.subTest(case=case, gate=gate):
                    result = self.run_gate(gate, **changes)
                    self.assertNotEqual(result.returncode, 0, result.stdout)
                    self.assertEqual((self.root / "effects").read_text(), "",
                                     "Failed gate executed deletion, commit, or push")
                    summary = (self.root / "summary").read_text()
                    self.assertNotIn("RECOVERED_EXISTING_PUBLIC", summary)
                    self.assertNotIn("PUBLISHED_TAG_RELEASE_AND_ASSET_IDENTITY_VERIFIED", summary)

    def test_gate_order_and_shared_executed_verifier(self):
        source = RECOVERY.read_text(encoding="utf-8")
        self.assertEqual(source.count("bash scripts/release/verify-published-tag.sh"), 2)
        self.assertIn("bash scripts/release/verify-published-tag.sh", self.gate_script(self.PRE))
        retirement = self.gate_script(self.RETIRE)
        self.assertLess(retirement.index("bash scripts/release/verify-published-tag.sh"),
                        retirement.index("git rm release/candidate.json"))
        self.assertLess(source.index(self.PRE), source.index("bash scripts/release/consumer-smoke.sh"))
        self.assertLess(source.index("bash scripts/release/consumer-smoke.sh"),
                        source.index(self.RETIRE))

if __name__ == "__main__":
    unittest.main()
