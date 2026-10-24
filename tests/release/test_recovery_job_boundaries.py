"""Executable recovery-job boundary checks using isolated GitHub/Git simulators.

Simulated consumer work does not qualify the actual public ZIP; authentic
WordPress coverage stays in the separate native ZIP CI workflow.
"""
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import textwrap
import unittest

ROOT = Path(__file__).resolve().parents[2]
WORKFLOW = ROOT / ".github/workflows/release-recover-v2.1.1.yml"
TAG = "1bb05cc4e264f9f82ff24529a80c4fb6b7b0c0f7"
COMMIT = "b9e86d15f04a030f1a4c75abc2491461e75022dd"
DIGEST = "a57b5bf3c9be6bbe6bd13af7dc415f98dba62c14e57bc0349cdf48820ff7ab39"
ZIP = "gf-bulk-rename-counter-2.1.1.zip"


def read_jobs():
    source = WORKFLOW.read_text().split("\njobs:\n", 1)[1]
    matches = list(re.finditer(r"(?m)^  ([a-z][a-z0-9-]*):\s*$", source))
    return {m[1]: source[m.end():matches[i+1].start() if i+1 < len(matches) else len(source)]
            for i, m in enumerate(matches)}


def read_steps(job):
    chunks = re.split(r"(?m)^      - (?=name:|uses:)", job)
    steps = {}
    for chunk in chunks:
        if chunk.startswith("name: ") and "        run: |\n" in chunk:
            name = chunk.split("\n", 1)[0][6:]
            steps[name] = textwrap.dedent(chunk.split("        run: |\n", 1)[1])
    return steps


class BoundaryExecution(unittest.TestCase):
    PRE = "Verify recovery preconditions and original published identity"
    DOWNLOAD = "Download and verify the exact EXISTING public artifacts against the published source"
    RETIRE = "Retire ONLY the consumed candidate marker after consumer verification"
    REPORT = "Report retirement failure without rewriting public history"

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.bin = self.root / "bin"
        self.bin.mkdir()
        (self.root / "scripts/release").mkdir(parents=True)
        for name in ("verify-published-tag.sh", "verify-published-release.sh", "consumer-smoke.sh"):
            shutil.copyfile(ROOT / "scripts/release" / name, self.root / "scripts/release" / name)
        marker = self.root / "release/candidate.json"
        marker.parent.mkdir()
        marker.write_text(json.dumps({
            "schema": 1, "intent": "patch", "version": "2.1.1",
            "branch": "release-candidate/v2.1.1-from-8c1e65886a28ccc677f230ebf01c4a6fc9d1ee5b",
            "base": {"commit": "8c1e65886a28ccc677f230ebf01c4a6fc9d1ee5b"},
        }))
        self.release = self.root / "release-fixture.json"
        self.release.write_text(json.dumps({
            "id": 422268666, "tag_name": "v2.1.1", "draft": False, "prerelease": False,
            "assets": [
                {"id": 670273670, "name": ZIP, "digest": "sha256:" + DIGEST, "size": 23961},
                {"id": 670273668, "name": ZIP + ".sha256", "digest": "sha256:83c7a5dd13495359de4e7306fe6546bf44b0b26367f6265cb959d7d2919c7955", "size": 99},
                {"id": 670273669, "name": ZIP + ".manifest.json", "digest": "sha256:ae54f0bd8acf78c2f4bf6836133112de23ca47361a780b18dbf5439a10bc87bc", "size": 1499},
            ]
        }))
        self.public = self.root / "mock-public"
        self.public.mkdir()
        for name in (ZIP, ZIP + ".sha256", ZIP + ".manifest.json"):
            (self.public / name).write_bytes(("fixture:" + name).encode())
        current = (ROOT / "scripts/release/consumer-smoke.sh").read_text()
        old = """test "$(php -r 'echo PHP_VERSION;')" = 8.2.34"""
        new = """php -r 'exit((PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 2) ? 0 : 1);'"""
        self.assertEqual(current.count(new), 1)
        self.original = self.root / "published-original-smoke.sh"
        self.original.write_text(current.replace(new, old))
        self.effects = self.root / "effects"
        self.summary = self.root / "summary"
        self.effects.write_text("")
        self.summary.write_text("")
        self.install_mock("gh", r"""#!/bin/bash
set -euo pipefail
if [[ "$1" == api ]]; then
  if [[ "$2" == --method ]]; then
    test "$MOCK_PERMISSION" = write || exit 77
    echo unauthorized-write >> "$MOCK_EFFECTS"
    exit 0
  fi
  case "$2" in
    repos/*/git/ref/tags/v2.1.1)
      test "$MOCK_MISSING" != 1 || exit 25
      printf '{"object":{"type":"tag","sha":"%s"}}\n' "$MOCK_TAG" ;;
    repos/*/git/tags/*)
      printf '{"sha":"%s","object":{"type":"commit","sha":"%s"}}\n' "$BRC_TAG_OBJECT_SHA" "$BRC_RELEASE_COMMIT" ;;
    repos/*/releases/tags/v2.1.1) cat "$MOCK_RELEASE" ;;
    *) exit 99 ;;
  esac
elif [[ "$1 $2" == "release download" ]]; then
  while [[ "$1" != --dir ]]; do shift; done
  shift
  mkdir -p "$1"
  cp "$MOCK_PUBLIC"/* "$1/"
  echo download >> "$MOCK_EFFECTS"
else
  exit 99
fi
""")
        self.install_mock("git", r"""#!/bin/bash
set -euo pipefail
case "$*" in
  "rev-parse HEAD")
    if grep -q '^commit -m ' "$MOCK_EFFECTS"; then echo "$MOCK_NEW_HEAD"
    else echo "$GITHUB_SHA"; fi ;;
  "ls-remote origin refs/heads/main")
    if grep -q '^push origin HEAD:main' "$MOCK_EFFECTS"; then
      test "$MOCK_READBACK_FAILURE" != 1 || exit 55
      printf '%s\trefs/heads/main\n' "$MOCK_NEW_HEAD"
    elif [[ "$MOCK_DRIFT" == 1 ]]; then
      printf '%s\trefs/heads/main\n' "eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee"
    else
      printf '%s\trefs/heads/main\n' "$GITHUB_SHA"
    fi ;;
  "status --porcelain"|"fetch origin refs/tags/v2.1.1:refs/tags/v2.1.1") : ;;
  "rev-parse refs/tags/v2.1.1^{commit}") echo "$BRC_RELEASE_COMMIT" ;;
  "rev-parse refs/tags/v2.1.1^{tree}") echo 50b98d9dc36a5ca094630d9f6febc3f355552c97 ;;
  "worktree add --detach "*)
    mkdir -p "$RUNNER_TEMP/published-source/scripts/release"
    cp "$MOCK_ORIGINAL" "$RUNNER_TEMP/published-source/scripts/release/consumer-smoke.sh"
    echo worktree >> "$MOCK_EFFECTS" ;;
  "config user.name "*|"config user.email "*) : ;;
  "rm release/candidate.json"|"commit -m "*)
    echo "$*" >> "$MOCK_EFFECTS" ;;
  "push origin HEAD:main")
    echo "$*" >> "$MOCK_EFFECTS"
    test "$MOCK_PUSH_FAILURE" != 1 || exit 62 ;;
  *) echo "Unexpected git: $*" >&2; exit 96 ;;
esac
""")
        self.install_mock("python3", r"""#!/bin/bash
set -euo pipefail
if [[ "$1" == - ]]; then
  exec /usr/bin/python3 "$@"
elif [[ "$*" == *"release.py version" ]]; then
  echo 2.1.1
elif [[ "$*" == *"release.py validate"* ]]; then
  [[ "$*" == *"--expected-head $BRC_RELEASE_COMMIT"* ]] || exit 80
  echo validate >> "$MOCK_EFFECTS"
elif [[ "$*" == *"release.py build"* ]]; then
  [[ "$*" == *"--expected-head $BRC_RELEASE_COMMIT"* ]] || exit 81
  while [[ "$1" != --output-dir ]]; do shift; done
  shift
  mkdir -p "$1"
  cp "$MOCK_PUBLIC"/* "$1/"
  echo build >> "$MOCK_EFFECTS"
else
  exit 99
fi
""")
        self.install_mock("sha256sum", r"""#!/bin/bash
set -euo pipefail
test "$*" = "-c -" || exit 91
read -r digest filename
test "$digest" = "$BRC_ZIP_SHA256"
cmp "$MOCK_PUBLIC/gf-bulk-rename-counter-2.1.1.zip" "$filename"
echo checksum >> "$MOCK_EFFECTS"
""")
        self.install_mock("bash", r"""#!/bin/bash
set -euo pipefail
if [[ "$1" == scripts/release/consumer-smoke.sh ]]; then
  test -z "${GH_TOKEN:-}" && test -z "${GITHUB_TOKEN:-}" || exit 73
  cmp "$2" "$MOCK_PUBLIC/gf-bulk-rename-counter-2.1.1.zip"
  test "$3" = 2.1.1
  echo consumer-smoke >> "$MOCK_EFFECTS"
  test "$MOCK_CONSUMER_FAILURE" != 1 || exit 75
else
  exec /bin/bash "$@"
fi
""")

    def install_mock(self, name, source):
        path = self.bin / name
        path.write_text(source)
        path.chmod(0o755)

    def env(self, **changes):
        data = os.environ.copy()
        data.update({
            "PATH": str(self.bin) + os.pathsep + data.get("PATH", ""),
            "GITHUB_REPOSITORY": "rezahh107/GF-Bulk-Rename-Counter",
            "GITHUB_REF": "refs/heads/main",
            "GITHUB_SHA": "a" * 40,
            "BRC_TAG": "v2.1.1", "BRC_VERSION": "2.1.1",
            "BRC_RELEASE_COMMIT": COMMIT, "BRC_TAG_OBJECT_SHA": TAG,
            "BRC_ZIP_SHA256": DIGEST,
            "BRC_CANDIDATE_BRANCH": "release-candidate/v2.1.1-from-8c1e65886a28ccc677f230ebf01c4a6fc9d1ee5b",
            "GH_TOKEN": "read-only-fixture", "GITHUB_TOKEN": "",
            "GITHUB_STEP_SUMMARY": str(self.summary),
            "RUNNER_TEMP": str(self.root),
            "MOCK_EFFECTS": str(self.effects),
            "MOCK_RELEASE": str(self.release),
            "MOCK_PUBLIC": str(self.public),
            "MOCK_ORIGINAL": str(self.original),
            "MOCK_NEW_HEAD": "b" * 40, "MOCK_TAG": TAG, "MOCK_MISSING": "0",
            "MOCK_DRIFT": "0", "MOCK_PERMISSION": "read",
            "MOCK_READBACK_FAILURE": "0", "MOCK_PUSH_FAILURE": "0",
            "MOCK_CONSUMER_FAILURE": "0",
        })
        data.update(changes)
        return data

    def run_step(self, job, name, env):
        script = read_steps(read_jobs()[job])[name]
        return subprocess.run(["/bin/bash", "-c", script], cwd=self.root,
                              env=env, capture_output=True, text=True,
                              timeout=15, check=False)

    def qualify_consumer(self, env):
        for name in (self.PRE, self.DOWNLOAD):
            result = self.run_step("consumer", name, env)
            if result.returncode:
                return result
        return result

    def test_selected_jobs_have_enforced_security_and_success_dependency(self):
        j = read_jobs()
        self.assertEqual(set(j), {"consumer", "retire-marker"})
        self.assertRegex(j["consumer"], r"(?m)^    permissions:\n      contents: read$")
        self.assertRegex(j["retire-marker"], r"(?m)^    permissions:\n      contents: write")
        self.assertRegex(j["retire-marker"], r"(?m)^    needs: consumer$")
        self.assertIn("needs.consumer.result == 'success'", j["retire-marker"])
        self.assertIn("inputs.confirmation == 'RECOVER-v2.1.1'", j["retire-marker"])
        self.assertIn("persist-credentials: false", j["consumer"])
        self.assertNotIn("git push origin HEAD:main", j["consumer"])
        self.assertNotIn("git rm release/candidate.json", j["consumer"])
        self.assertNotIn("consumer-smoke.sh", j["retire-marker"])
        self.assertIn("env -u GH_TOKEN -u GITHUB_TOKEN bash scripts/release/consumer-smoke.sh", j["consumer"])

    def test_exact_source_validation_and_simulated_public_zip_path(self):
        env = self.env()
        result = self.qualify_consumer(env)
        self.assertEqual(result.returncode, 0, result.stderr)
        effects = self.effects.read_text()
        for earlier, later in (("download", "checksum"), ("checksum", "validate"),
                               ("validate", "build"), ("build", "consumer-smoke")):
            self.assertLess(effects.index(earlier), effects.index(later))
        result = self.run_step("retire-marker", self.RETIRE, env)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("push origin HEAD:main", self.effects.read_text())
        self.assertIn("RECOVERED_EXISTING_PUBLIC_V2_1_1", self.summary.read_text())

    def test_consumer_failure_prevents_write_job(self):
        env = self.env(MOCK_CONSUMER_FAILURE="1")
        result = self.qualify_consumer(env)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("consumer-smoke", self.effects.read_text())
        self.assertNotIn("rm release", self.effects.read_text())
        self.assertNotIn("push origin", self.effects.read_text())
        self.assertIn("needs.consumer.result == 'success'", read_jobs()["retire-marker"])

    def test_untrusted_consumer_cannot_use_write_token(self):
        env = self.env()
        p = subprocess.run([str(self.bin / "gh"), "api", "--method", "DELETE",
                            "repos/rezahh107/GF-Bulk-Rename-Counter/git/refs/tags/v2.1.1"],
                           env=env, capture_output=True, check=False)
        self.assertEqual(p.returncode, 77)
        self.assertNotIn("unauthorized-write", self.effects.read_text())
        self.assertEqual(self.qualify_consumer(env).returncode, 0)
        self.assertFalse((self.root / ".git/config").exists())

    def test_asset_replacement_after_consumer_prevents_mutation(self):
        env = self.env()
        self.assertEqual(self.qualify_consumer(env).returncode, 0)
        data = json.loads(self.release.read_text())
        data["assets"][1]["id"] += 1
        self.release.write_text(json.dumps(data))
        result = self.run_step("retire-marker", self.RETIRE, env)
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn("rm release/candidate.json", self.effects.read_text())
        self.assertNotIn("push origin", self.effects.read_text())

    def test_same_commit_tag_swap_or_main_drift_before_retirement(self):
        for mutation in ({"MOCK_TAG": "2" * 40}, {"MOCK_DRIFT": "1"}):
            with self.subTest(mutation=mutation):
                self.effects.write_text("")
                env = self.env()
                self.assertEqual(self.qualify_consumer(env).returncode, 0)
                env.update(mutation)
                result = self.run_step("retire-marker", self.RETIRE, env)
                self.assertNotEqual(result.returncode, 0)
                self.assertNotIn("rm release/candidate.json", self.effects.read_text())
                self.assertNotIn("push origin", self.effects.read_text())

    def test_accepted_push_with_failed_readback_is_uncertain(self):
        env = self.env(MOCK_READBACK_FAILURE="1")
        self.assertEqual(self.qualify_consumer(env).returncode, 0)
        result = self.run_step("retire-marker", self.RETIRE, env)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("push origin HEAD:main", self.effects.read_text())
        self.assertTrue((self.root / "recovery-push-accepted").exists())
        self.assertNotIn("RECOVERED_EXISTING_PUBLIC_V2_1_1_CONSUMER_VERIFIED_AND_MARKER_RETIRED",
                         self.summary.read_text())
        report = self.run_step("retire-marker", self.REPORT, env)
        self.assertEqual(report.returncode, 0, report.stderr)
        self.assertIn("RETIREMENT_PUSH_ACCEPTED_READBACK_UNPROVEN", self.summary.read_text())

    def test_push_failure_is_unknown_not_uncommitted(self):
        env = self.env(MOCK_PUSH_FAILURE="1")
        self.assertEqual(self.qualify_consumer(env).returncode, 0)
        self.assertNotEqual(self.run_step("retire-marker", self.RETIRE, env).returncode, 0)
        self.assertFalse((self.root / "recovery-push-accepted").exists())
        self.run_step("retire-marker", self.REPORT, env)
        self.assertIn("RETIREMENT_PUSH_OUTCOME_UNKNOWN", self.summary.read_text())
