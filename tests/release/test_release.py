"""Release contract: positive packaging and meaningful fail-closed mutation controls."""
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
import zipfile

HERE = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("release_core", HERE / "scripts/release/release.py")
core = importlib.util.module_from_spec(spec)
spec.loader.exec_module(core)


def git(root, *args):
    return subprocess.run(["git", "-C", str(root), *args],
                          check=True, capture_output=True, text=True).stdout.strip()


class ReleaseContract(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / "source"
        self.root.mkdir()
        for relative in core.FILES:
            destination = self.root / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(HERE / relative, destination)
        git(self.root, "init", "-q")
        git(self.root, "add", ".")
        git(self.root, "-c", "user.name=Test", "-c", "user.email=test@example.invalid",
            "commit", "-qm", "baseline")
        self.out = Path(self.tmp.name) / "artifact"
        self.identity = core.source_identity(self.root)
        self.version = core.version(self.root)

    def package(self):
        manifest = core.build(self.root, self.out, self.identity["commit"])
        z, c, m = core.artifact_names(self.version)
        return manifest, self.out / z, self.out / c, self.out / m

    @unittest.skipIf(os.environ.get("BRC_VERSION_REGRESSION_CHILD") == "1",
                     "avoid recursion inside version-bumped isolated suite")
    def test_complete_suite_against_clean_bumped_source(self):
        # A distinct clean Git checkout, not a mutation of the Owner's source.
        major, minor, patch = map(int, self.version.split("."))
        bumped = f"{major}.{minor}.{patch + 1}"
        with tempfile.TemporaryDirectory() as temporary:
            fixture = Path(temporary)
            for relative in (*core.FILES, ".gitignore", "scripts/release/release.py",
                             "tests/release/test_release.py"):
                target = fixture / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(HERE / relative, target)
            php_path = fixture / core.FILES[0]
            php = php_path.read_text(encoding="utf-8")
            php, header_count = re.subn(
                r"(?m)^(\s*\*\s*Version:\s*)" + re.escape(self.version) + r"(\s*)$",
                lambda match: match[1] + bumped + match[2], php,
            )
            before = f"'GFBULKRENAME_VERSION', '{self.version}'"
            after = f"'GFBULKRENAME_VERSION', '{bumped}'"
            self.assertEqual((header_count, php.count(before)), (1, 1))
            php_path.write_text(php.replace(before, after, 1), encoding="utf-8")
            readme_path = fixture / "readme.txt"
            readme = readme_path.read_text(encoding="utf-8")
            before = f"Stable tag: {self.version}"
            self.assertEqual(readme.count(before), 1)
            readme_path.write_text(readme.replace(before, f"Stable tag: {bumped}", 1),
                                   encoding="utf-8")
            git(fixture, "init", "-q")
            git(fixture, "add", ".")
            git(fixture, "-c", "user.name=Test", "-c", "user.email=test@example.invalid",
                "commit", "-qm", "independent clean version-bumped candidate")
            self.assertEqual(core.version(fixture), bumped)
            env = os.environ.copy()
            env["BRC_VERSION_REGRESSION_CHILD"] = "1"
            env["PYTHONDONTWRITEBYTECODE"] = "1"
            result = subprocess.run(
                [sys.executable, "-m", "unittest", "discover",
                 "-s", "tests/release", "-p", "test_*.py", "-v"],
                cwd=fixture, env=env, capture_output=True, text=True,
                check=False, timeout=60,
            )
            self.assertEqual(result.returncode, 0,
                             "The required release suite failed on a genuine "
                             f"{bumped} source fixture:\n{result.stdout}\n{result.stderr}")

    def test_first_release_and_version_intents(self):
        self.assertEqual(self.version, core.release_version(self.root, "first", []))
        with self.assertRaisesRegex(core.ReleaseError, "first release"):
            core.release_version(self.root, "patch", [])
        with self.assertRaisesRegex(core.ReleaseError, "forbids first"):
            core.release_version(self.root, "first", [f"v{self.version}"])
        self.assertEqual("2.0.7", core.release_version(self.root, "patch", ["v2.0.6"]))
        self.assertEqual("2.1.0", core.release_version(self.root, "minor", ["v2.0.6"]))
        self.assertEqual("3.0.0", core.release_version(self.root, "major", ["v2.0.6"]))
        with self.assertRaisesRegex(core.ReleaseError, "does not match"):
            core.release_version(self.root, "patch", ["v2.0.7"])

    def test_real_archive_allowlist_and_determinism(self):
        manifest, z, c, m = self.package()
        self.assertEqual(set(manifest["files"]), {f"{core.SLUG}/{p}" for p in core.FILES})
        self.assertEqual(self.identity, manifest["source"])
        self.assertEqual(manifest, core.validate(self.root, z, c, m))
        original = z.read_bytes()
        shutil.rmtree(self.out)
        self.package()
        self.assertEqual(original, z.read_bytes(), "canonical ZIP bytes must be deterministic")

    def test_bad_zip_or_extra_payload_rejected(self):
        _, z, c, m = self.package()
        with zipfile.ZipFile(z, "a") as archive:
            archive.writestr("gf-bulk-rename-counter/tests/injected.php", "<?php")
        with self.assertRaisesRegex(core.ReleaseError, "manifest"):
            core.validate(self.root, z, c, m)
        # Even an attacker who can edit the sidecar and manifest cannot bypass the allowlist.
        manifest = json.loads(m.read_text())
        manifest["sha256"] = core.sha(z.read_bytes())
        m.write_text(json.dumps(manifest))
        c.write_text(f"{manifest['sha256']}  {z.name}\n")
        with self.assertRaisesRegex(core.ReleaseError, "unexpected/missing"):
            core.validate(self.root, z, c, m)

    def test_invalid_crc_and_checksum_rejected(self):
        _, z, c, m = self.package()
        c.write_text("0" * 64 + f"  {z.name}\n")
        with self.assertRaisesRegex(core.ReleaseError, "sidecar"):
            core.validate(self.root, z, c, m)
        c.write_text(f"{core.sha(z.read_bytes())}  {z.name}\n")
        z.write_bytes(b"NOT A ZIP")
        manifest = json.loads(m.read_text())
        manifest["sha256"] = core.sha(z.read_bytes())
        m.write_text(json.dumps(manifest))
        c.write_text(f"{manifest['sha256']}  {z.name}\n")
        with self.assertRaises(zipfile.BadZipFile):
            core.validate(self.root, z, c, m)

    def test_version_mirror_mismatch(self):
        readme = self.root / "readme.txt"
        readme.write_text(readme.read_text().replace("Stable tag: 2.0.6",
                                                      "Stable tag: 2.0.5"))
        with self.assertRaisesRegex(core.ReleaseError, "mismatch"):
            core.version(self.root)

    def test_dirty_source_and_expected_sha_rejected(self):
        (self.root / core.FILES[0]).write_bytes((self.root / core.FILES[0]).read_bytes() + b"\n")
        with self.assertRaisesRegex(core.ReleaseError, "dirty"):
            core.build(self.root, self.out)
        git(self.root, "checkout", "--", core.FILES[0])
        with self.assertRaisesRegex(core.ReleaseError, "requested SHA"):
            core.build(self.root, self.out, "0" * 40)

    def test_reviewed_candidate_vs_integrated_tree(self):
        original = self.identity["commit"]
        core.binding(self.root, original, original)
        path = self.root / "uninstall.php"
        path.write_bytes(path.read_bytes() + b"\n")
        git(self.root, "add", ".")
        git(self.root, "-c", "user.name=Test", "-c", "user.email=test@example.invalid",
            "commit", "-qm", "unreviewed change")
        next_head = git(self.root, "rev-parse", "HEAD")
        with self.assertRaisesRegex(core.ReleaseError, "not integrated"):
            core.binding(self.root, original, next_head)

    def test_publication_conflict_and_partial_history_fail_closed(self):
        core.publication_history([], [], "2.0.6")
        core.publication_history(["v2.0.6"], [{"tag_name": "v2.0.6", "draft": False}], "2.0.7")
        with self.assertRaisesRegex(core.ReleaseError, "version conflict"):
            core.publication_history(["v2.0.6"], [{"tag_name": "v2.0.6", "draft": False}], "2.0.6")
        with self.assertRaisesRegex(core.ReleaseError, "orphan"):
            core.publication_history(["v2.0.6"], [], "2.0.7")
        with self.assertRaisesRegex(core.ReleaseError, "orphan"):
            core.publication_history([], [{"tag_name": "v2.0.6", "draft": False}], "2.0.7")
        with self.assertRaisesRegex(core.ReleaseError, "draft/partial"):
            core.publication_history(["v2.0.6"], [{"tag_name": "v2.0.6", "draft": True}], "2.0.7")
        with self.assertRaisesRegex(core.ReleaseError, "duplicate"):
            core.publication_history(["v2.0.6", "v2.0.6"], [], "2.0.7")

    def test_prepare_updates_only_version_mirrors_and_metadata(self):
        next_version = core.prepare(self.root, "patch", ["v2.0.6"], "release-candidate/v2.0.7")
        self.assertEqual(next_version, core.version(self.root))
        self.assertEqual("2.0.7", next_version)
        candidate = json.loads((self.root / "release/candidate.json").read_text())
        self.assertEqual(self.identity, candidate["base"])
        status = git(self.root, "status", "--porcelain")
        self.assertIn("gf-bulk-rename-counter.php", status)
        self.assertIn("readme.txt", status)
        self.assertIn("release/", status)
        with self.assertRaisesRegex(core.ReleaseError, "already been integrated"):
            core.prepare(self.root, "patch", ["v2.0.6"], "release-candidate/v2.0.7")


if __name__ == "__main__":
    unittest.main()
