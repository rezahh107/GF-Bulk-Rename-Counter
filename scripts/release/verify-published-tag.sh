#!/usr/bin/env bash
# Enforce the currently verified GitHub annotated-tag object, not just its peeled commit.
set -euo pipefail

: "${GITHUB_REPOSITORY:?}"
: "${BRC_TAG:?}"
: "${BRC_TAG_OBJECT_SHA:?}"
: "${BRC_RELEASE_COMMIT:?}"

ref_json="$(gh api "repos/$GITHUB_REPOSITORY/git/ref/tags/$BRC_TAG")"
if ! jq -e --arg expected "$BRC_TAG_OBJECT_SHA" \
  '.object.type == "tag" and .object.sha == $expected' <<< "$ref_json" >/dev/null; then
  echo "Recovery aborted: annotated tag ref object identity differs from pinned object" >&2
  exit 1
fi

tag_json="$(gh api "repos/$GITHUB_REPOSITORY/git/tags/$BRC_TAG_OBJECT_SHA")"
if ! jq -e --arg expected "$BRC_TAG_OBJECT_SHA" --arg commit "$BRC_RELEASE_COMMIT" \
  '.sha == $expected and .object.type == "commit" and .object.sha == $commit' <<< "$tag_json" >/dev/null; then
  echo "Recovery aborted: pinned tag object or release commit identity differs" >&2
  exit 1
fi
