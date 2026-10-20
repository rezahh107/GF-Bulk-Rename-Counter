#!/usr/bin/env python3
"""Single canonical WordPress release ZIP builder and fail-closed verifier."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import zipfile

SLUG = "gf-bulk-rename-counter"
FILES = (
    "gf-bulk-rename-counter.php",
    "includes/class-rename_handler.php",
    "includes/class-settings_page.php",
    "includes/class-flat_zip_download.php",
    "includes/class-form_naming_rules.php",
    "includes/class-naming_strategy.php",
    "languages/gf-bulk-rename-counter.pot",
    "readme.txt",
    "uninstall.php",
)
VERSION = re.compile(r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\Z")
SECRET_MARKERS = (b"-----BEGIN PRIVATE KEY-----", b"github_pat_", b"ghp_")
ROOT = Path(__file__).resolve().parents[2]


class ReleaseError(ValueError):
    pass


def require(condition: bool, message: str) -> None:
    if not condition:
        raise ReleaseError(message)


def git(root: Path, *args: str) -> str:
    result = subprocess.run(
        ["git", "-C", str(root), *args], text=True, capture_output=True, check=False
    )
    require(result.returncode == 0, f"git {' '.join(args)}: {result.stderr.strip()}")
    return result.stdout.strip()


def version(root: Path) -> str:
    php = (root / FILES[0]).read_text(encoding="utf-8")
    readme = (root / "readme.txt").read_text(encoding="utf-8")
    mirrors = (
        re.findall(r"(?m)^\s*\*\s*Version:\s*(\S+)\s*$", php),
        re.findall(r"(?m)^define\(\s*'GFBULKRENAME_VERSION',\s*'([^']+)'\s*\);", php),
        re.findall(r"(?m)^Stable tag:\s*(\S+)\s*$", readme),
    )
    require(all(len(values) == 1 for values in mirrors), "missing or duplicate version mirror")
    v = mirrors[0][0]
    require(bool(VERSION.fullmatch(v)), "invalid strict numeric SemVer")
    require(all(values[0] == v for values in mirrors), "plugin header/constant/readme version mismatch")
    return v


def expected_payload(root: Path) -> dict[str, bytes]:
    version(root)
    result = {}
    for rel in FILES:
        path = root / rel
        require(path.is_file() and not path.is_symlink(), f"missing or symlinked: {rel}")
        content = path.read_bytes()
        require(bool(content), f"empty production file: {rel}")
        require(not any(marker in content for marker in SECRET_MARKERS), f"secret marker in: {rel}")
        result[f"{SLUG}/{rel}"] = content
    return result


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def source_identity(root: Path, require_clean: bool = True) -> dict[str, str]:
    head = git(root, "rev-parse", "HEAD")
    tree = git(root, "rev-parse", "HEAD^{tree}")
    if require_clean:
        require(not git(root, "status", "--porcelain", "--untracked-files=all"),
                "source working tree is dirty; refuse uncommitted build")
    return {"commit": head, "tree": tree}


def artifact_names(v: str) -> tuple[str, str, str]:
    prefix = f"{SLUG}-{v}.zip"
    return prefix, prefix + ".sha256", prefix + ".manifest.json"


def build(root: Path, out: Path, expected_head: str | None = None) -> dict:
    identity = source_identity(root)
    if expected_head:
        require(identity["commit"] == expected_head, "source HEAD differs from requested SHA")
    v = version(root)
    payload = expected_payload(root)
    out.mkdir(parents=True, exist_ok=True)
    zname, cname, mname = artifact_names(v)
    target = out / zname
    require(not any((out / n).exists() for n in (zname, cname, mname)),
            "release output already exists; refusing overwrite")
    with zipfile.ZipFile(target, "w", compression=zipfile.ZIP_DEFLATED,
                         compresslevel=9, strict_timestamps=True) as archive:
        for name, content in sorted(payload.items()):
            info = zipfile.ZipInfo(name, date_time=(2020, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = (0o100644 << 16)
            info.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(info, content, compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    digest = sha(target.read_bytes())
    (out / cname).write_text(f"{digest}  {zname}\n", encoding="ascii")
    manifest = {
        "schema": 1, "plugin": SLUG, "version": v, "source": identity,
        "zip": zname, "sha256": digest,
        "files": {name: sha(data) for name, data in sorted(payload.items())},
    }
    (out / mname).write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n",
                             encoding="utf-8")
    validate(root, target, out / cname, out / mname)
    return manifest


def validate(root: Path, target: Path, checksum: Path, manifest_path: Path,
             expected_head: str | None = None) -> dict:
    identity = source_identity(root)
    if expected_head:
        require(identity["commit"] == expected_head, "validation target HEAD mismatch")
    payload = expected_payload(root)
    v = version(root)
    zname, cname, mname = artifact_names(v)
    require(target.name == zname and checksum.name == cname and manifest_path.name == mname,
            "artifact name/version mismatch")
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    require(manifest == {
        "schema": 1, "plugin": SLUG, "version": v, "source": identity,
        "zip": zname, "sha256": sha(target.read_bytes()),
        "files": {name: sha(data) for name, data in sorted(payload.items())},
    }, "manifest version/source/files/checksum mismatch")
    require(checksum.read_text(encoding="ascii") ==
            f"{manifest['sha256']}  {zname}\n", "SHA-256 sidecar mismatch")
    with zipfile.ZipFile(target, "r") as archive:
        infos = archive.infolist()
        names = [x.filename for x in infos]
        require(len(names) == len(set(names)), "duplicate ZIP path")
        require(set(names) == set(payload), "unexpected/missing production ZIP path")
        for info in infos:
            require(not info.is_dir(), "ZIP directory entry not on allowlist")
            require((info.external_attr >> 16) & 0o170000 == 0o100000,
                    "ZIP contains non-regular file")
            require(info.compress_type == zipfile.ZIP_DEFLATED, "unexpected compression")
            require(info.file_size == len(payload[info.filename]), "archive expanded-size mismatch")
            require(not info.flag_bits & 1, "encrypted ZIP entry")
        require(archive.testzip() is None, "ZIP CRC/integrity failure")
        for info in infos:
            require(archive.read(info) == payload[info.filename],
                    f"packaged bytes differ from source: {info.filename}")
    return manifest


def publication_history(tags: list[str], releases: list[dict],
                        candidate: str | None = None) -> None:
    """Reject orphan tags, draft/partial releases, duplicate and conflicting names."""
    require(len(set(tags)) == len(tags), "duplicate history tag")
    require(all(tag.startswith("v") and VERSION.fullmatch(tag[1:]) for tag in tags),
            "malformed production history tag")
    require(isinstance(releases, list), "GitHub Releases history must be a list")
    release_tags = []
    for item in releases:
        require(isinstance(item, dict) and isinstance(item.get("tag_name"), str),
                "invalid GitHub Release entry")
        require(item.get("draft") is False, "draft/partial GitHub Release requires Owner recovery")
        release_tags.append(item["tag_name"])
    require(len(set(release_tags)) == len(release_tags), "duplicate GitHub Release identity")
    require(set(tags) == set(release_tags), "orphan tag or Release indicates partial history")
    if candidate:
        require(VERSION.fullmatch(candidate) is not None, "invalid candidate version")
        require(f"v{candidate}" not in tags, "production publication version conflict")


def release_version(root: Path, intent: str, tags: list[str]) -> str:
    current = version(root)
    versions = []
    for tag in tags:
        require(tag.startswith("v") and VERSION.fullmatch(tag[1:]),
                f"unexpected production tag: {tag}")
        versions.append(tuple(map(int, tag[1:].split("."))))
    if not versions:
        require(intent == "first", "first release requires 'first' intent")
        return current
    require(intent in ("patch", "minor", "major"), "existing history forbids first intent")
    latest = max(versions)
    require(tuple(map(int, current.split("."))) == latest,
            "normal source version does not match latest production tag")
    major, minor, patch = latest
    if intent == "patch":
        patch += 1
    elif intent == "minor":
        minor, patch = minor + 1, 0
    else:
        major, minor, patch = major + 1, 0, 0
    return f"{major}.{minor}.{patch}"


def candidate_branch(candidate_version: str, base_commit: str) -> str:
    """Unique release-candidate namespace bound to the complete source commit SHA."""
    require(VERSION.fullmatch(candidate_version) is not None, "invalid candidate version")
    require(re.fullmatch(r"[0-9a-f]{40}", base_commit) is not None,
            "invalid candidate base commit")
    return f"release-candidate/v{candidate_version}-from-{base_commit}"


def validated_candidate_marker(root: Path) -> dict:
    marker = json.loads((root / "release/candidate.json").read_text(encoding="utf-8"))
    require(isinstance(marker, dict) and
            set(marker) == {"schema", "intent", "version", "branch", "base"},
            "invalid candidate marker structure")
    require(type(marker["schema"]) is int and marker["schema"] == 1,
            "unsupported candidate marker schema")
    require(marker["intent"] in ("first", "patch", "minor", "major"),
            "invalid candidate intent")
    base = marker["base"]
    require(isinstance(base, dict) and set(base) == {"commit", "tree"},
            "invalid candidate base identity")
    require(isinstance(base["commit"], str) and
            re.fullmatch(r"[0-9a-f]{40}", base["commit"]) is not None,
            "invalid candidate base SHA")
    require(isinstance(base["tree"], str) and
            re.fullmatch(r"[0-9a-f]{40}", base["tree"]) is not None,
            "invalid candidate base tree")
    require(isinstance(marker["version"], str) and
            marker["version"] == version(root), "candidate version/source mismatch")
    require(marker["branch"] ==
            candidate_branch(marker["version"], base["commit"]),
            "candidate branch does not match source base identity")
    require(git(root, "rev-parse", f'{base["commit"]}^{{tree}}') == base["tree"],
            "candidate recorded base tree mismatch")
    return marker


def select_candidate_pr(marker: dict, merged_prs: list[dict]) -> dict:
    """Do not let an old PR for the same version stand in for this source-bound PR."""
    require(isinstance(merged_prs, list), "invalid merged PR collection")
    require(isinstance(marker, dict) and isinstance(marker.get("base"), dict),
            "invalid candidate marker")
    base = marker["base"]
    require(marker.get("branch") == candidate_branch(marker.get("version"), base.get("commit")),
            "candidate branch/source mismatch")
    matches = [pr for pr in merged_prs
               if isinstance(pr, dict) and isinstance(pr.get("head"), dict)
               and pr["head"].get("ref") == marker["branch"]]
    require(len(matches) == 1,
            "candidate PR identity missing or ambiguous")
    pr = matches[0]
    require(isinstance(pr.get("base"), dict) and pr["base"].get("ref") == "main",
            "candidate PR targets wrong base branch")
    require(pr["base"].get("sha") == base["commit"],
            "candidate PR base commit mismatch")
    require(isinstance(pr.get("merged_at"), str) and pr["merged_at"],
            "candidate PR is not merged")
    require(isinstance(pr.get("merge_commit_sha"), str) and
            re.fullmatch(r"[0-9a-f]{40}", pr["merge_commit_sha"]) is not None,
            "candidate PR missing merge commit identity")
    reviewed = pr["head"].get("sha")
    require(isinstance(reviewed, str) and
            re.fullmatch(r"[0-9a-f]{40}", reviewed) is not None,
            "candidate PR has invalid reviewed SHA")
    number = pr.get("number")
    require(type(number) is int and number > 0, "invalid candidate PR number")
    return {"pr": number, "reviewed": reviewed}


def candidate_ci_qualified(runs: list[dict], reviewed: str, branch: str) -> None:
    require(isinstance(runs, list) and
            re.fullmatch(r"[0-9a-f]{40}", reviewed) is not None,
            "invalid candidate CI input")
    require(any(
        isinstance(run, dict)
        and run.get("headSha") == reviewed
        and run.get("headBranch") == branch
        and run.get("event") == "workflow_dispatch"
        and run.get("conclusion") == "success"
        for run in runs), "exact reviewed candidate workflow_dispatch CI missing")


def prepare(root: Path, intent: str, tags: list[str], branch: str) -> str:
    require(not (root / "release/candidate.json").exists(),
            "a candidate has already been integrated; do not stack preparation")
    identity = source_identity(root)
    new = release_version(root, intent, tags)
    require(branch == candidate_branch(new, identity["commit"]),
            "prepared candidate branch not bound to source identity")
    old = version(root)
    if new != old:
        php_path = root / FILES[0]
        php = php_path.read_text(encoding="utf-8")
        php = re.sub(r"(?m)^(\s*\*\s*Version:\s*)\S+(\s*)$", lambda m: m[1]+new+m[2], php)
        php = php.replace(f"'GFBULKRENAME_VERSION', '{old}'",
                          f"'GFBULKRENAME_VERSION', '{new}'")
        php_path.write_text(php, encoding="utf-8")
        readme_path = root / "readme.txt"
        readme = readme_path.read_text(encoding="utf-8")
        readme = readme.replace(f"Stable tag: {old}", f"Stable tag: {new}", 1)
        require("== Changelog ==\n" in readme, "changelog section absent")
        readme = readme.replace("== Changelog ==\n",
                                f"== Changelog ==\n\n= {new} =\n* Release candidate; see reviewed GitHub changes for details.\n", 1)
        readme_path.write_text(readme, encoding="utf-8")
    require(version(root) == new, "prepared mirrors disagree")
    (root / "release").mkdir(exist_ok=True)
    (root / "release/candidate.json").write_text(json.dumps({
        "schema": 1, "intent": intent, "version": new, "base": identity,
        "branch": branch,
    }, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return new


def binding(root: Path, reviewed_sha: str, integrated_sha: str) -> None:
    require(re.fullmatch(r"[0-9a-f]{40}", reviewed_sha) is not None, "bad reviewed SHA")
    require(re.fullmatch(r"[0-9a-f]{40}", integrated_sha) is not None, "bad integrated SHA")
    require(git(root, "rev-parse", f"{reviewed_sha}^{{tree}}") ==
            git(root, "rev-parse", f"{integrated_sha}^{{tree}}"),
            "reviewed candidate tree is not integrated main tree")
    require(git(root, "rev-parse", "HEAD") == integrated_sha,
            "checkout does not match integrated HEAD")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=("build", "validate", "version", "plan", "prepare", "bind", "history",
                                     "candidate-branch", "select-candidate", "candidate-ci"))
    parser.add_argument("--root", type=Path, default=ROOT)
    parser.add_argument("--output-dir", type=Path)
    parser.add_argument("--zip", type=Path)
    parser.add_argument("--checksum", type=Path)
    parser.add_argument("--manifest", type=Path)
    parser.add_argument("--expected-head")
    parser.add_argument("--intent", choices=("first", "patch", "minor", "major"))
    parser.add_argument("--tags", nargs="*", default=[])
    parser.add_argument("--branch")
    parser.add_argument("--releases-file", type=Path)
    parser.add_argument("--candidate-version")
    parser.add_argument("--reviewed")
    parser.add_argument("--integrated")
    parser.add_argument("--base-commit")
    parser.add_argument("--merged-prs-file", type=Path)
    parser.add_argument("--runs-file", type=Path)
    args = parser.parse_args()
    try:
        if args.command == "version":
            print(version(args.root))
        elif args.command == "plan":
            require(bool(args.intent), "--intent is required")
            print(release_version(args.root, args.intent, args.tags))
        elif args.command == "candidate-branch":
            require(bool(args.candidate_version and args.base_commit),
                    "candidate version and base commit required")
            print(candidate_branch(args.candidate_version, args.base_commit))
        elif args.command == "select-candidate":
            require(args.merged_prs_file is not None, "merged PR records required")
            marker = validated_candidate_marker(args.root)
            records = json.loads(args.merged_prs_file.read_text(encoding="utf-8"))
            print(json.dumps(select_candidate_pr(marker, records), sort_keys=True))
        elif args.command == "candidate-ci":
            require(args.runs_file is not None and args.reviewed and args.branch,
                    "candidate CI records, reviewed SHA and branch required")
            runs = json.loads(args.runs_file.read_text(encoding="utf-8"))
            candidate_ci_qualified(runs, args.reviewed, args.branch)
            print("EXACT_CANDIDATE_CI_PASS")
        elif args.command == "prepare":
            require(bool(args.intent and args.branch), "--intent and --branch required")
            print(prepare(args.root, args.intent, args.tags, args.branch))
        elif args.command == "history":
            require(args.releases_file is not None, "--releases-file required")
            publication_history(args.tags,
                                json.loads(args.releases_file.read_text(encoding="utf-8")),
                                args.candidate_version)
            print("RELEASE_HISTORY_CONFLICT_GATE_PASS")
        elif args.command == "bind":
            require(bool(args.reviewed and args.integrated), "two exact SHA values required")
            binding(args.root, args.reviewed, args.integrated)
            print("CANDIDATE_TO_MAIN_TREE_IDENTITY_PASS")
        elif args.command == "build":
            require(args.output_dir is not None, "--output-dir required")
            print(json.dumps(build(args.root, args.output_dir, args.expected_head), sort_keys=True))
        else:
            require(all((args.zip, args.checksum, args.manifest)), "three artifact paths required")
            print(json.dumps(validate(args.root, args.zip, args.checksum,
                                      args.manifest, args.expected_head), sort_keys=True))
    except (ReleaseError, OSError, ValueError, zipfile.BadZipFile) as error:
        print(f"RELEASE_GATE_FAIL: {error}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
