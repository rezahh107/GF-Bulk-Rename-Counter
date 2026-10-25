#!/usr/bin/env python3
"""Fail-closed, read-only GitHub identity verification for an already-created release.

Capture pins GitHub-assigned Release/asset IDs only after checking the exact local
artifact bytes. Verify rechecks the pinned identities before marker retirement.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
import time


class PublicationError(Exception):
    pass


def github_json(repo, endpoint):
    url = f"repos/{repo}/{endpoint}"
    for attempt in range(4):
        process = subprocess.run(["gh", "api", url], capture_output=True, text=True, check=False)
        if process.returncode == 0:
            try:
                return json.loads(process.stdout)
            except json.JSONDecodeError as exc:
                raise PublicationError(f"Invalid GitHub API JSON: {endpoint}") from exc
        error = process.stderr.strip()
        transient = re.search(r"\bHTTP (404|429|500|502|503|504)\b", error)
        if not transient or attempt == 3:
            raise PublicationError(f"GitHub API failed: {endpoint}: {error or 'exit '+str(process.returncode)}")
        time.sleep(0.25 * 2**attempt)
    raise AssertionError("unreachable")


def expect(condition, message):
    if not condition:
        raise PublicationError(message)


def verify_tag(repo, tag, tag_sha, commit):
    reference = github_json(repo, f"git/ref/tags/{tag}")
    expect(reference.get("ref") == f"refs/tags/{tag}", "Unexpected tag ref identity")
    target = reference.get("object") or {}
    expect(target.get("type") == "tag", "Tag is not annotated")
    expect(target.get("sha") == tag_sha, "Annotated tag object changed")
    annotated = github_json(repo, f"git/tags/{tag_sha}")
    expect(annotated.get("sha") == tag_sha and annotated.get("tag") == tag,
           "Annotated tag name or object SHA changed")
    pointed = annotated.get("object") or {}
    expect(pointed.get("type") == "commit", "Annotated tag does not target a commit")
    expect(pointed.get("sha") == commit, "Annotated tag targets an unexpected commit")


def local_assets(directory, version):
    base = f"gf-bulk-rename-counter-{version}.zip"
    result = {}
    for name in (base, base + ".sha256", base + ".manifest.json"):
        path = directory / name
        expect(path.is_file(), f"Missing original artifact: {name}")
        data = path.read_bytes()
        result[name] = {"name": name, "size": len(data),
                        "digest": "sha256:" + hashlib.sha256(data).hexdigest()}
    return result


def verify_release(repo, tag, expected):
    release = github_json(repo, f"releases/tags/{tag}")
    expect(type(release.get("id")) is int and release["id"] > 0,
           "Missing GitHub release identity")
    expect(release.get("tag_name") == tag and release.get("draft") is False
           and release.get("prerelease") is False, "Wrong or unpublished release")
    assets = release.get("assets")
    expect(isinstance(assets, list) and len(assets) == len(expected),
           "Release asset count changed")
    observed = {}
    for asset in assets:
        name = asset.get("name")
        expect(isinstance(name, str) and name in expected and name not in observed,
               "Unexpected or duplicate release asset")
        expect(type(asset.get("id")) is int and asset["id"] > 0,
               "Missing GitHub asset identity")
        for field in ("size", "digest"):
            expect(asset.get(field) == expected[name][field],
                   f"Release asset {name}: {field} mismatch")
        observed[name] = {**expected[name], "id": asset["id"]}
    return {"release_id": release["id"], "assets": observed}


def execute(args):
    expect(re.fullmatch(r"[0-9a-f]{40}", args.commit) is not None, "Invalid expected commit SHA")
    expect(re.fullmatch(r"[0-9a-f]{40}", args.tag_object) is not None, "Invalid tag object SHA")
    expect(re.fullmatch(r"v[0-9]+\.[0-9]+\.[0-9]+", args.tag) is not None, "Invalid release tag")
    expect(args.repo == "rezahh107/GF-Bulk-Rename-Counter", "Unexpected repository")
    assets = local_assets(Path(args.artifacts), args.tag[1:])
    verify_tag(args.repo, args.tag, args.tag_object, args.commit)
    current = verify_release(args.repo, args.tag, assets)
    identity = {"repo": args.repo, "tag": args.tag, "tag_object": args.tag_object,
                "commit": args.commit, **current}
    state = Path(args.state)
    if args.mode == "capture":
        # Never silently replace an earlier pinned observation within the same run.
        with state.open("x", encoding="utf-8") as output:
            json.dump(identity, output, sort_keys=True)
            output.write("\n")
    else:
        try:
            pinned = json.loads(state.read_text(encoding="utf-8"))
        except (OSError, ValueError) as exc:
            raise PublicationError("Missing or invalid captured publication identity") from exc
        expect(pinned == identity, "Published Release or asset IDs changed since capture")
    print(f"PUBLISHED_TAG_RELEASE_ASSETS_VERIFIED: {args.tag} {args.commit}")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("mode", choices=("capture", "verify"))
    parser.add_argument("--repo", required=True)
    parser.add_argument("--tag", required=True)
    parser.add_argument("--tag-object", required=True)
    parser.add_argument("--commit", required=True)
    parser.add_argument("--artifacts", required=True)
    parser.add_argument("--state", required=True)
    args = parser.parse_args()
    try:
        execute(args)
    except (PublicationError, OSError) as exc:
        print(f"PUBLICATION_IDENTITY_NOT_VERIFIED: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
