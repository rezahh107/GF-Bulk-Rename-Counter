# Release infrastructure — repository-specific V1

## Discovery and product authority
- Release Unit: single installable WordPress plugin (`gf-bulk-rename-counter`).
- Canonical version source: `Version:` header in `gf-bulk-rename-counter.php`; the runtime `GFBULKRENAME_VERSION` constant and `readme.txt` `Stable tag:` are exact mirrors.
- Current plugin metadata version: 2.0.6 (unreleased). Independent verified flat ZIP and per-form naming were added after the original release-infrastructure work. No release-related bump has been authorized.
- Production payload allowlist: main PHP, **five** `includes/` PHP classes (`class-rename_handler.php`, `class-settings_page.php`, `class-flat_zip_download.php`, `class-form_naming_rules.php`, `class-naming_strategy.php`), translation POT, `readme.txt`, `uninstall.php` — precisely **nine files** rooted under `gf-bulk-rename-counter/`. Developer material/CI/tests/secrets excluded. No bundled third-party vendor libraries; SBOM not material for this first-party ZIP. GF/Bulk Download remain external WordPress dependencies, validated by existing pinned native CI.
- GPP referenced for architecture only. No Gravity Flow/Elementor/profile runtime paths or development-version sentinel.
- Existing `verify.yml` and native runtime scenarios remain; native runtime now installs canonical ZIP instead of symlinking checkout.

## Boundaries and invariants
- `scripts/release/release.py` is the only packaging/validation implementation. Version, strict allowlist, source dirty check, Git HEAD/tree identity, per-file hashes, manifest and ZIP SHA-256 sidecar are verified. ZIP ordering/timestamps are stable; deterministic bytes are regression tested.
- `release-prepare.yml` on explicit Owner input creates a version/candidate marker PR and dispatches CI at the branch head (GitHub-generated PRs may require approval). The Owner reviews CI and merges separately.
- `release/candidate.json` stores initial source identity, intent and version; it is **not** included in the ZIP. Publication matches the merged candidate PR and verifies reviewed PR-head Git tree identity against the current integrated `main` tree. This works with merge, squash and rebase **only when the final tree is identical**. Subsequent unrelated modifications fail closed.
- `release-dry-run.yml` is read-only and uses the canonical builder and mutation tests. It may upload a non-production Actions artifact but cannot tag or publish.
- `release-publish.yml` requires explicit `PUBLISH`, exact candidate-bound successful dispatched dry-run **and** native integration CI, version/history/conflict checks, read-only immutability verification through `BRC_RELEASE_ADMIN_READ_TOKEN`, and clean unchanged `main` before mutating. The workflow uses a non-canceling shared concurrency group and grants job-specific `contents:write`/`actions:read` only for publication.
- Distribution: GitHub immutable Release, with draft + ZIP/checksum/manifest before publication. No WordPress.org auto-publishing. Consumer ZIP is re-downloaded; exact bytes, checksum, immutable signature/identity and activation in fresh pinned WordPress with authentic dependencies are checked.
- Post-publication maintenance removes only the consumed candidate marker in a normal fast-forward commit. If `main` changed meanwhile, the workflow stops, leaving the immutable published artifact alone. An unsuccessful last-mile or cleanup step is **partial publication**, never a silent rollback.
- Release conflict: any existing version Tag or Release, inconsistent existing history, uncertain candidate identity or missing CI blocks publication. A replacement tag/upload/release and force-push are not recovery actions.

## Evidence and limits
- PHP canonical checks exercise source PHP only.
- Release unit/mutation tests exercise parser, version, content, archive, source and review binding failures in isolated temporary repositories.
- Native integration workflow tests actual GF/Bulk Download hook, downloads, and source preservation using the installable ZIP in synthetic WordPress.
- Successful dry-run does **not** prove publication, immutable-release status, GitHub consumer download, production-site equivalence or a future release. Those need a separately authorized production run.
- The Owner controls first publish and any recovery. No tagging/merging/publication is part of infrastructure PR approval.
## Post-infrastructure production candidates (merged PR #9 and #10; NOT releases)

PR #9 added the independent current-page flat ZIP class. PR #10 added per-form naming rules and candidate evaluation classes exclusively for that independent action. The **current canonical nine-file allowlist in `scripts/release/release.py`** is authoritative; old six-/seven-file counts describe prior history only. Source hashes, manifest/ZIP validation, Owner gates and negative controls remain. Neither merge constitutes a plugin release, tag, deployment or publication.
