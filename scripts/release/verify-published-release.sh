#!/usr/bin/env bash
# Verify the currently observed public v2.1.1 Release and immutable-by-policy asset identities.
set -euo pipefail

: "${GITHUB_REPOSITORY:?}"
: "${BRC_TAG:?}"
: "${BRC_ZIP_SHA256:?}"

release_json="$(gh api "repos/$GITHUB_REPOSITORY/releases/tags/$BRC_TAG")"
if ! jq -e --arg tag "$BRC_TAG" --arg zip_digest "sha256:$BRC_ZIP_SHA256" '
  .id == 422268666 and .tag_name == $tag and .draft == false and .prerelease == false
  and ([.assets[] | {id, name, digest, size}] | sort_by(.name)) == (
    [
      {id: 670273670, name: "gf-bulk-rename-counter-2.1.1.zip",
       digest: $zip_digest, size: 23961},
      {id: 670273668, name: "gf-bulk-rename-counter-2.1.1.zip.sha256",
       digest: "sha256:83c7a5dd13495359de4e7306fe6546bf44b0b26367f6265cb959d7d2919c7955", size: 99},
      {id: 670273669, name: "gf-bulk-rename-counter-2.1.1.zip.manifest.json",
       digest: "sha256:ae54f0bd8acf78c2f4bf6836133112de23ca47361a780b18dbf5439a10bc87bc", size: 1499}
    ] | sort_by(.name)
  )
' <<< "$release_json" >/dev/null; then
  echo "Recovery aborted: public Release or one of its three asset identities changed" >&2
  exit 1
fi
