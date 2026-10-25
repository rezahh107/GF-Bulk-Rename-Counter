"""Post-publication identity regression tests. These never create a release."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts/release/verify-published-publication.py"
WORKFLOW = ROOT / ".github/workflows/release-publish.yml"
REPO = "rezahh107/GF-Bulk-Rename-Counter"
TAG = "v2.1.1"
TAG_SHA = "1bb05cc4e264f9f82ff24529a80c4fb6b7b0c0f7"
COMMIT = "b9e86d15f04a030f1a4c75abc2491461e75022dd"


class PublisherIdentityTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.api = self.root / "api"
        self.api.mkdir()
        self.artifacts = self.root / "artifacts"
        self.artifacts.mkdir()
        self.state = self.root / "identity.json"
        name = "gf-bulk-rename-counter-2.1.1.zip"
        self.names = (name, name + ".sha256", name + ".manifest.json")
        for item in self.names:
            (self.artifacts / item).write_bytes(("isolated-fixture:" + item).encode())
        self.ref = {"ref": "refs/tags/" + TAG, "object": {"type": "tag", "sha": TAG_SHA}}
        self.tag = {"tag": TAG, "sha": TAG_SHA, "object": {"type": "commit", "sha": COMMIT}}
        self.release = {"id": 422268666, "tag_name": TAG, "draft": False,
                        "prerelease": False, "assets": [
                            {"name": item, "id": 670273668 + index,
                             "size": (self.artifacts / item).stat().st_size,
                             "digest": "sha256:" + hashlib.sha256(
                                 (self.artifacts / item).read_bytes()).hexdigest()}
                            for index, item in enumerate(self.names)]}
        self.write_api()
        bin_dir = self.root / "bin"
        bin_dir.mkdir()
        gh = bin_dir / "gh"
        gh.write_text("""#!/usr/bin/env python3
import os, pathlib, sys
endpoint = sys.argv[2]
if sys.argv[1] != "api": sys.exit(90)
if endpoint.endswith("/git/ref/tags/v2.1.1"): kind = "ref"
elif "/git/tags/" in endpoint: kind = "tag"
elif endpoint.endswith("/releases/tags/v2.1.1"): kind = "release"
else: sys.exit(91)
directory = pathlib.Path(os.environ["MOCK_API"])
countfile = directory / (kind + ".attempts")
count = int(countfile.read_text()) if countfile.exists() else 0
countfile.write_text(str(count + 1))
if kind == os.environ.get("MOCK_FAIL_KIND") and count < int(os.environ.get("MOCK_FAIL_COUNT", "0")):
    sys.stderr.write("gh: Error (HTTP " + os.environ.get("MOCK_HTTP", "404") + ")\\n")
    sys.exit(1)
print((directory / (kind + ".json")).read_text())
""")
        gh.chmod(0o755)
        self.env = os.environ.copy()
        self.env["PATH"] = str(bin_dir) + os.pathsep + self.env["PATH"]
        self.env["MOCK_API"] = str(self.api)

    def write_api(self):
        for name, data in (("ref", self.ref), ("tag", self.tag), ("release", self.release)):
            (self.api / (name + ".json")).write_text(json.dumps(data))

    def invoke(self, mode, **extra):
        return subprocess.run(
            ["python3", str(SCRIPT), mode, "--repo", REPO, "--tag", TAG,
             "--tag-object", TAG_SHA, "--commit", COMMIT, "--artifacts",
             str(self.artifacts), "--state", str(self.state)],
            env={**self.env, **extra}, capture_output=True, text=True,
            check=False, timeout=20)

    def capture(self):
        result = self.invoke("capture")
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_valid_annotated_tag_commit_and_exact_assets(self):
        self.capture()
        result = self.invoke("verify")
        self.assertEqual(result.returncode, 0, result.stderr)
        pinned = json.loads(self.state.read_text())
        self.assertEqual(pinned["tag_object"], TAG_SHA)
        self.assertEqual(pinned["commit"], COMMIT)
        self.assertEqual(pinned["release_id"], self.release["id"])
        self.assertEqual(set(pinned["assets"]), set(self.names))

    def test_temporary_missing_tag_is_retried_but_exhausted_missing_tag_fails(self):
        good = self.invoke("capture", MOCK_FAIL_KIND="ref", MOCK_FAIL_COUNT="2")
        self.assertEqual(good.returncode, 0, good.stderr)
        self.assertEqual((self.api / "ref.attempts").read_text(), "3")
        self.state.unlink()
        (self.api / "ref.attempts").unlink()
        bad = self.invoke("capture", MOCK_FAIL_KIND="ref", MOCK_FAIL_COUNT="20")
        self.assertNotEqual(bad.returncode, 0)
        self.assertIn("HTTP 404", bad.stderr)
        self.assertFalse(self.state.exists())

    def test_incorrect_tag_type_sha_target_and_object_type(self):
        for mutation in ({"type": "commit"}, {"sha": "f" * 40}):
            with self.subTest(mutation=mutation):
                original = dict(self.ref["object"])
                self.ref["object"].update(mutation)
                self.write_api()
                self.assertNotEqual(self.invoke("capture").returncode, 0)
                self.assertFalse(self.state.exists())
                self.ref["object"] = original
        for mutation in ({"type": "tag"}, {"sha": "e" * 40}):
            with self.subTest(mutation=mutation):
                original = dict(self.tag["object"])
                self.tag["object"].update(mutation)
                self.write_api()
                self.assertNotEqual(self.invoke("capture").returncode, 0)
                self.assertFalse(self.state.exists())
                self.tag["object"] = original

    def test_transient_api_503_recovered_and_exhausted_or_forbidden_api_fails(self):
        good = self.invoke("capture", MOCK_FAIL_KIND="tag", MOCK_FAIL_COUNT="1",
                           MOCK_HTTP="503")
        self.assertEqual(good.returncode, 0, good.stderr)
        self.state.unlink()
        (self.api / "tag.attempts").unlink()
        bad = self.invoke("capture", MOCK_FAIL_KIND="tag", MOCK_FAIL_COUNT="20",
                          MOCK_HTTP="503")
        self.assertNotEqual(bad.returncode, 0)
        self.assertIn("HTTP 503", bad.stderr)
        self.assertFalse(self.state.exists())
        (self.api / "tag.attempts").unlink()
        forbidden = self.invoke("capture", MOCK_FAIL_KIND="tag", MOCK_FAIL_COUNT="20",
                                MOCK_HTTP="403")
        self.assertNotEqual(forbidden.returncode, 0)
        self.assertEqual((self.api / "tag.attempts").read_text(), "1")

    def test_asset_absence_bad_size_digest_or_wrong_id_is_rejected(self):
        original_assets = json.loads(json.dumps(self.release["assets"]))
        for mutation in ({"size": 1}, {"digest": "sha256:" + "f" * 64}, {"id": None}):
            with self.subTest(mutation=mutation):
                self.release["assets"] = json.loads(json.dumps(original_assets))
                self.release["assets"][0].update(mutation)
                self.write_api()
                self.assertNotEqual(self.invoke("capture").returncode, 0)
                self.assertFalse(self.state.exists())
        self.release["assets"] = original_assets[:2]
        self.write_api()
        self.assertNotEqual(self.invoke("capture").returncode, 0)
        self.assertFalse(self.state.exists())
        self.release["assets"] = original_assets
        self.write_api()
        self.capture()
        self.release["assets"][0]["id"] += 1
        self.write_api()
        self.assertNotEqual(self.invoke("verify").returncode, 0)

    def test_original_artifact_missing_or_mutated_after_capture_is_rejected(self):
        self.capture()
        (self.artifacts / self.names[0]).write_bytes(b"changed zip")
        self.assertNotEqual(self.invoke("verify").returncode, 0)
        (self.artifacts / self.names[1]).unlink()
        self.assertNotEqual(self.invoke("verify").returncode, 0)

    def test_retirement_requires_successful_consumer_step_and_recheck(self):
        # Static control-flow check; does NOT execute WordPress or a real publish.
        workflow = WORKFLOW.read_text()
        before, retirement = workflow.split(
            "      - name: Retire candidate marker only after successful last-mile check", 1)
        consumer = before.split(
            "      - name: Verify downloaded consumer artifact and activate in fresh real WordPress", 1)[1]
        self.assertIn("set -euo pipefail", consumer)
        self.assertIn("bash scripts/release/consumer-smoke.sh", consumer)
        self.assertNotIn("git rm release/candidate.json", consumer)
        self.assertIn("verify-published-publication.py verify", retirement)
        self.assertLess(retirement.index("verify-published-publication.py verify"),
                        retirement.index("git rm release/candidate.json"))
        self.assertIn("if: failure()", workflow)

    def test_simulated_consumer_activation_failure_preserves_marker(self):
        # Execute the actual workflow consumer step using an isolated GitHub mock.
        # The final WordPress activation is intentionally stubbed to fail.
        import shutil
        import textwrap

        self.capture()
        shutil.copyfile(self.state, self.root / "brc-published-identity.json")
        marker = self.root / "release/candidate.json"
        marker.parent.mkdir()
        marker.write_text('{"version":"2.1.1"}')
        for item in self.names:
            shutil.copyfile(self.artifacts / item, marker.parent / item)

        scripts = self.root / "scripts/release"
        scripts.mkdir(parents=True)
        shutil.copyfile(SCRIPT, scripts / SCRIPT.name)
        (scripts / "release.py").write_text(
            "import sys\nassert sys.argv[1] == 'validate'\n")
        (scripts / "consumer-smoke.sh").write_text(
            "#!/bin/bash\necho SMOKE_CALLED > \"$RUNNER_TEMP/smoke-called\"\nexit 75\n")

        gh = self.root / "bin/gh"
        original = self.root / "bin/gh-api"
        gh.rename(original)
        gh.write_text("""#!/usr/bin/env bash
set -euo pipefail
if [[ "$1" == api ]]; then exec "$MOCK_GH_API" "$@"; fi
if [[ "$1 $2" == "release view" ]]; then
    if [[ "$*" == *".tagName"* ]]; then echo v2.1.1; else echo false; fi
elif [[ "$1 $2" == "release download" ]]; then
    while [[ "$1" != --dir ]]; do shift; done
    shift
    mkdir -p "$1"
    cp "$MOCK_ASSETS/"* "$1/"
else
    exit 92
fi
""")
        gh.chmod(0o755)
        workflow = WORKFLOW.read_text()
        consumer = workflow.split(
            "      - name: Verify downloaded consumer artifact and activate in fresh real WordPress", 1
        )[1].split("      - name: Retire candidate marker only after successful last-mile check", 1)[0]
        command = textwrap.dedent(consumer.split("        run: |\n", 1)[1])
        env = {**self.env, "GITHUB_REPOSITORY": REPO, "BRC_TAG": TAG,
               "BRC_VERSION": TAG[1:], "GITHUB_SHA": COMMIT,
               "BRC_TAG_OBJECT_SHA": TAG_SHA, "RUNNER_TEMP": str(self.root),
               "MOCK_GH_API": str(original), "MOCK_ASSETS": str(self.artifacts)}
        result = subprocess.run(["/bin/bash", "-c", command], cwd=self.root,
                                env=env, capture_output=True, text=True,
                                check=False, timeout=20)
        self.assertEqual(result.returncode, 75, result.stderr)
        self.assertTrue((self.root / "smoke-called").exists())
        self.assertTrue(marker.exists(), "Consumer step must not delete candidate")
        self.assertNotIn("git rm release/candidate.json", command)

    def test_actual_retirement_step_aborts_before_git_rm_on_remote_mismatch(self):
        # Run the real retirement shell step, but against isolated read-only
        # GitHub/Git shims. No remote mutation or real publication.
        import shutil
        import textwrap

        self.capture()
        shutil.copyfile(self.state, self.root / "brc-published-identity.json")
        release_dir = self.root / "release"
        release_dir.mkdir()
        marker = release_dir / "candidate.json"
        marker.write_text('{"version":"2.1.1"}')
        for item in self.names:
            shutil.copyfile(self.artifacts / item, release_dir / item)

        scripts = self.root / "scripts/release"
        scripts.mkdir(parents=True)
        shutil.copyfile(SCRIPT, scripts / SCRIPT.name)
        git = self.root / "bin/git"
        git.write_text("""#!/usr/bin/env bash
set -euo pipefail
if [[ "$1 $2 $3" == "ls-remote origin refs/heads/main" ]]; then
    printf '%s\\trefs/heads/main\n' "$GITHUB_SHA"
else
    echo "$*" >> "$RUNNER_TEMP/unexpected-git-operation"
    exit 95
fi
""")
        git.chmod(0o755)
        workflow = WORKFLOW.read_text()
        retire = workflow.split(
            "      - name: Retire candidate marker only after successful last-mile check", 1
        )[1].split("      - name: Report partial failure without rewriting a tag or asset", 1)[0]
        command = textwrap.dedent(retire.split("        run: |\n", 1)[1])
        env = {**self.env, "GITHUB_REPOSITORY": REPO, "BRC_TAG": TAG,
               "BRC_VERSION": TAG[1:], "GITHUB_SHA": COMMIT,
               "BRC_TAG_OBJECT_SHA": TAG_SHA, "RUNNER_TEMP": str(self.root)}
        self.release["assets"][0]["id"] += 1
        self.write_api()
        run = subprocess.run(["/bin/bash", "-c", command], cwd=self.root,
                             env=env, capture_output=True, text=True,
                             check=False, timeout=20)
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("Published Release or asset IDs changed", run.stderr)
        self.assertTrue(marker.exists())
        self.assertFalse((self.root / "unexpected-git-operation").exists())

    def test_existing_owner_safety_controls_and_no_peeled_remote_check(self):
        workflow = WORKFLOW.read_text()
        for condition in (
            "inputs.confirmation == 'PUBLISH'", "release.py bind",
            "candidate-ci --runs-file", "release.py history",
            "git ls-remote --tags origin", "gh release create",
            "gh release download", 'cmp "$RUNNER_TEMP/release/$zname" "$dest/$zname"',
            "scripts/release/consumer-smoke.sh", "git rm release/candidate.json",
            'tag_object="$(git rev-parse "refs/tags/$BRC_TAG")"',
            "verify-published-publication.py capture", "PUBLISH_OUTCOME_UNKNOWN_OR_PARTIAL"
        ):
            self.assertIn(condition, workflow)
        self.assertGreaterEqual(workflow.count("verify-published-publication.py verify"), 2)
        self.assertNotIn('refs/tags/$BRC_TAG^{}', workflow)
        self.assertNotIn("gh release delete", workflow)
        self.assertNotIn("git push --force", workflow)


if __name__ == "__main__":
    unittest.main()
