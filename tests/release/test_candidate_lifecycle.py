"""Source-bound candidate lifecycle and mixed-history regression tests.

All fixtures are isolated Git repositories or mocked GitHub records. No publication.
"""
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("brc_candidate_core", ROOT / "scripts/release/release.py")
core = importlib.util.module_from_spec(spec)
spec.loader.exec_module(core)

OLD_BASE = "1" * 40
NEW_BASE = "2" * 40
OLD_HEAD = "3" * 40
NEW_HEAD = "4" * 40
MERGE = "5" * 40


def pr_record(number, branch, source, reviewed, *, base_ref="main", merged=True):
    return {
        "number": number,
        "head": {"ref": branch, "sha": reviewed},
        "base": {"ref": base_ref, "sha": source},
        "merged_at": "2026-10-20T09:54:47Z" if merged else None,
        "merge_commit_sha": MERGE if merged else None,
    }


def git(root, *args):
    return subprocess.run(["git", "-C", str(root), *args],
                          check=True, capture_output=True, text=True).stdout.strip()


class CandidateLifecycleContract(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / "plugin"
        self.root.mkdir()
        for relative in core.FILES:
            target = self.root / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ROOT / relative, target)
        git(self.root, "init", "-q")
        git(self.root, "add", ".")
        git(self.root, "-c", "user.name=Test", "-c", "user.email=test@example.invalid",
            "commit", "-qm", "source base")
        self.source = core.source_identity(self.root)
        self.version = core.version(self.root)
        self.branch = core.candidate_branch(self.version, self.source["commit"])

    def fake_marker(self, base=NEW_BASE, version=None):
        target = version or self.version
        return {
            "schema": 1, "intent": "first", "version": target,
            "base": {"commit": base, "tree": "9" * 40},
            "branch": core.candidate_branch(target, base),
        }

    def mixed_pr_history(self, marker):
        old_branch = f"release-candidate/v{self.version}"
        return [
            pr_record(14, old_branch, OLD_BASE, OLD_HEAD),
            pr_record(17, marker["branch"], marker["base"]["commit"], NEW_HEAD),
            pr_record(12, "chore/owner-author-change", NEW_BASE, "6" * 40),
        ]

    def test_historical_branch_does_not_block_distinct_successor(self):
        historical_branch = f"release-candidate/v{self.version}"
        git(self.root, "branch", historical_branch)
        self.assertNotEqual(historical_branch, self.branch)
        self.assertEqual(core.candidate_branch(self.version, self.source["commit"]),
                         self.branch)
        self.assertEqual(core.prepare(self.root, "first", [], self.branch), self.version)
        marker = core.validated_candidate_marker(self.root)
        self.assertEqual(marker["base"], self.source)
        self.assertEqual(marker["branch"], self.branch)
        self.assertEqual(marker["schema"], 1)
        self.assertEqual(marker["intent"], "first")

    def test_old_and_new_candidate_mixed_history_selects_only_new_pr(self):
        marker = self.fake_marker()
        selection = core.select_candidate_pr(marker, self.mixed_pr_history(marker))
        self.assertEqual(selection, {"pr": 17, "reviewed": NEW_HEAD})

    def test_no_matching_pr_fails_even_when_old_pr_exists(self):
        marker = self.fake_marker()
        with self.assertRaisesRegex(core.ReleaseError, "missing or ambiguous"):
            core.select_candidate_pr(marker, self.mixed_pr_history(marker)[:1])

    def test_ambiguous_new_identity_fails_closed(self):
        marker = self.fake_marker()
        records = self.mixed_pr_history(marker)
        records.append(pr_record(18, marker["branch"], NEW_BASE, "7" * 40))
        with self.assertRaisesRegex(core.ReleaseError, "missing or ambiguous"):
            core.select_candidate_pr(marker, records)

    def test_wrong_base_branch_and_version_are_rejected(self):
        marker = self.fake_marker()
        candidates = self.mixed_pr_history(marker)
        for name, mutation, reason in (
            ("wrong-source", {"base": {"ref": "main", "sha": OLD_BASE}}, "base commit"),
            ("wrong-target", {"base": {"ref": "dev", "sha": NEW_BASE}}, "wrong base branch"),
            ("not-merged", {"merged_at": None}, "not merged"),
            ("invalid-review-head", {"head": {"ref": marker["branch"], "sha": "not-a-sha"}}, "reviewed SHA"),
        ):
            with self.subTest(name=name):
                altered = [dict(p) for p in candidates]
                altered[1].update(mutation)
                with self.assertRaisesRegex(core.ReleaseError, reason):
                    core.select_candidate_pr(marker, altered)

        bad = self.fake_marker()
        bad["version"] = "9.9.9"
        with self.assertRaisesRegex(core.ReleaseError, "source mismatch"):
            core.select_candidate_pr(bad, candidates)
        wrong_branch = self.fake_marker()
        wrong_branch["branch"] = f"release-candidate/v{self.version}"
        with self.assertRaisesRegex(core.ReleaseError, "source mismatch"):
            core.select_candidate_pr(wrong_branch, candidates)

    def test_malformed_marker_base_and_reviewed_tree_fail_closed(self):
        core.prepare(self.root, "first", [], self.branch)
        marker_path = self.root / "release/candidate.json"
        good = json.loads(marker_path.read_text(encoding="utf-8"))
        for name, mutation in (
            ("version", {"version": "9.9.9"}),
            ("branch", {"branch": f"release-candidate/v{self.version}"}),
            ("base-tree", {"base": {"commit": self.source["commit"], "tree": "f" * 40}}),
            ("base-commit", {"base": {"commit": OLD_BASE, "tree": self.source["tree"]}}),
        ):
            with self.subTest(name=name):
                altered = dict(good)
                altered.update(mutation)
                marker_path.write_text(json.dumps(altered), encoding="utf-8")
                with self.assertRaises(core.ReleaseError):
                    core.validated_candidate_marker(self.root)
        marker_path.write_text(json.dumps(good), encoding="utf-8")
        self.assertEqual(core.validated_candidate_marker(self.root)["base"], self.source)

        # An unreviewed main change is still blocked by the original strict
        # tree-equality check, not made acceptable by PR-number matching.
        git(self.root, "add", "release/candidate.json")
        git(self.root, "-c", "user.name=Test", "-c", "user.email=test@example.invalid",
            "commit", "-qm", "reviewed marker")
        reviewed = git(self.root, "rev-parse", "HEAD")
        core.binding(self.root, reviewed, reviewed)
        (self.root / "uninstall.php").write_text(
            (self.root / "uninstall.php").read_text() + "\n", encoding="utf-8")
        git(self.root, "add", "uninstall.php")
        git(self.root, "-c", "user.name=Test", "-c", "user.email=test@example.invalid",
            "commit", "-qm", "unreviewed runtime change")
        with self.assertRaisesRegex(core.ReleaseError, "not integrated"):
            core.binding(self.root, reviewed, git(self.root, "rev-parse", "HEAD"))

    def test_wrong_prepared_branch_rejected(self):
        with self.assertRaisesRegex(core.ReleaseError, "not bound"):
            core.prepare(self.root, "first", [], f"release-candidate/v{self.version}")
        with self.assertRaisesRegex(core.ReleaseError, "invalid candidate base"):
            core.candidate_branch(self.version, "not-a-sha")
        with self.assertRaisesRegex(core.ReleaseError, "invalid candidate version"):
            core.candidate_branch("garbage", NEW_BASE)

    def test_ci_only_accepts_success_on_exact_head_branch_and_event(self):
        accepted = {
            "headSha": NEW_HEAD,
            "headBranch": self.fake_marker()["branch"],
            "event": "workflow_dispatch",
            "conclusion": "success",
        }
        core.candidate_ci_qualified([accepted], NEW_HEAD, accepted["headBranch"])
        for name, broken in (
            ("old-PR-success", {"headSha": OLD_HEAD}),
            ("wrong-branch", {"headBranch": "release-candidate/v2.1.0"}),
            ("wrong-event", {"event": "pull_request"}),
            ("failed-run", {"conclusion": "failure"}),
        ):
            with self.subTest(name=name):
                candidate = dict(accepted)
                candidate.update(broken)
                with self.assertRaisesRegex(core.ReleaseError, "CI missing"):
                    core.candidate_ci_qualified([candidate], NEW_HEAD, accepted["headBranch"])

        with self.assertRaisesRegex(core.ReleaseError, "CI missing"):
            core.candidate_ci_qualified([], NEW_HEAD, accepted["headBranch"])
        with self.assertRaisesRegex(core.ReleaseError, "CI missing"):
            core.candidate_ci_qualified(
                [{"headSha": OLD_HEAD, "headBranch": accepted["headBranch"],
                  "event": "workflow_dispatch", "conclusion": "success"}],
                NEW_HEAD, accepted["headBranch"])


if __name__ == "__main__":
    unittest.main()
