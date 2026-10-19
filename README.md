# GF Bulk Rename Counter

A WordPress add-on that renames Gravity Forms files **during bulk download**, using a configurable entry counter field. It does not rename stored uploads or edit Gravity Forms entries.

## What it does

This repository contains the existing **Gravity Forms Bulk Rename Counter v2.0.6** plugin source. It hooks into the `bdfgf_entry_filename` filter provided by **Bulk Download for Gravity Forms**, looks up the Gravity Forms entry, and returns `d{counter}.{original-extension}`. When a generated name repeats within the same handler instance, it adds the entry ID and, if necessary, a further numeric suffix.

**Dependencies at runtime:** WordPress (declared minimum 5.8), PHP (declared minimum 8.0), Gravity Forms, and Bulk Download for Gravity Forms. The supported runtime dependency range is not exhaustively pinned or certified. A bounded CI integration lane uses **Gravity Forms 3.1.1.1** and **Bulk Download for Gravity Forms 4.2.0** in a synthetic WordPress environment; see [Native ZIP integration qualification](docs/NATIVE_ZIP_INTEGRATION_LAB.md).

## Independent flat ZIP (unreleased production candidate)

A separately registered **Download Flat ZIP with Rename (selected on this page only)** action appears beside native Bulk Download. It confirms the exact selected Entry count; cross-page selections are not retained. The separate authenticated POST verifies Gravity Forms permission, nonce, source paths and SHA-256 of every expected member before responding. It leaves stored Entries, uploads, the original Legacy action and native Bulk Download unchanged.

The compatibility check interprets both existing host deletion metadata keys through public Gravity Forms meta retrieval without comparing `BDFGF_VERSION` to any exact release number. Malformed, contradictory or unknown observable deletion states, missing source files and unsafe paths produce a visible error and **no ZIP**. A future release using new deletion semantics with no observable change in these keys cannot be detected or certified automatically. Remote/offloaded storage is outside the local upload-root contract.

Noncolliding `d{counter}.{extension}` names remain unchanged. Collisions use the Legacy visual `d125-132.jpg`, `d125-132-1.jpg` suffix scheme, with natural names reserved first. Interrupted network delivery after streaming starts is outside pre-delivery atomicity.

## Install and configure

1. Copy the plugin files from the repository root into a directory named `gf-bulk-rename-counter` under `wp-content/plugins/` (or package that directory as a WordPress plugin ZIP).
2. Activate **Gravity Forms** and **Bulk Download for Gravity Forms**, then activate **Gravity Forms Bulk Rename Counter**.
3. Open **Settings → GF Bulk Rename** in WordPress.
4. Select a target form (or **All forms**) and enter the **Counter Field ID**; save settings.
5. Use the bulk-download feature supplied by Bulk Download for Gravity Forms.

A Counter Field ID of `0` disables renaming. The plugin reads an existing entry field; it does **not** assign or increment counters.

## Source layout

| Path | Purpose |
| --- | --- |
| `gf-bulk-rename-counter.php` | Plugin metadata, dependency checks, WordPress hooks and loader |
| `includes/class-rename_handler.php` | Filename transformation and within-handler duplicate handling |
| `includes/class-flat_zip_download.php` | Separate current-page-only authenticated ZIP download, source planner, deleted-state validator, archive writer/verifier |
| `includes/class-settings_page.php` | WordPress Settings API page and option sanitization |
| `uninstall.php` | Deletes plugin settings on uninstall |
| `languages/` | Supplied translation template (not regenerated) |
| `readme.txt` | Supplied WordPress plugin readme and changelog |
| `tests/verify.php` and `tests/rename-handler-test.php` | Canonical dependency-free PHP lint and isolated regressions |
| `tests/integration/` | Synthetic WordPress / Gravity Forms fixtures, genuine Bulk Download ZIP checks, and negative integrity control |
| `.github/workflows/native-zip-integration.yml` | Repeatable native ZIP qualification CI, separate from source verification |

Runtime plugin files are imported from the owner-supplied `gf-bulk-rename-counter.zip` version `2.0.6`; foundational work does not intentionally change their behavior. The source contains placeholder author/website metadata. Treat these as inherited placeholders, not verified project identity.

## Canonical verification

Prerequisite: PHP CLI 8.0 or newer. No Composer dependencies are required for the current checks.

```sh
php tests/verify.php
```

The command runs PHP syntax checks across the repository's PHP files and a small, isolated regression suite for the renaming handler with controlled WordPress / Gravity Forms stubs. Run this same command locally, in AI-assisted changes and in CI. **Passing this command alone does not establish WordPress/Gravity Forms/Bulk Download integration or ZIP integrity.**

### Authentic native ZIP qualification

The supplemental [Native WordPress ZIP qualification workflow](.github/workflows/native-zip-integration.yml) installs real WordPress 6.8.3, Gravity Forms 3.1.1.1, Bulk Download for Gravity Forms 4.2.0, and this plugin v2.0.6. It retains `php tests/verify.php` as the canonical source check, then provisions synthetic forms, entries and uploads through WordPress/Gravity Forms APIs. It verifies one authenticated HTTP single-entry ZIP download and three native multi-file/multi-entry ZIP paths dispatched from WP-CLI, including ZIP member paths, counts, SHA-256 checksums, a deliberately wrong-checksum negative control, and persistence/upload readback.

The tests **do not certify the production environment**, all custom Bulk Download folder configurations, or every possible filename collision. The multi-entry checks invoke the genuine host action in WordPress rather than browser UI selection. Exact source/dependency provenance, scenarios, observations, and evidence limits are documented in [Native ZIP integration qualification](docs/NATIVE_ZIP_INTEGRATION_LAB.md). CI conclusions must be checked against the actual exercised commit; the source workflow explicitly checks out the PR Head for pull requests (and the pushed commit for `main`).

## Known behavior and limits of v2.0.6

- The generated filename is flat (it does not preserve an incoming ZIP subdirectory path).
- Non-empty counter values are stringified and sanitized as filenames; they are not validated as numeric IDs. The literal value `0` is treated as empty and keeps the original name.
- Duplicate handling only sees filenames emitted by the rename handler and depends on processing order; original names returned without renaming are not registered for collision detection.
- The handler calls `GFAPI::get_entry()` for each file and does not add an entry cache.
- The integration relies on `BDFGF_VERSION` and a three-argument `bdfgf_entry_filename` filter; the installed bulk-download plugin must provide that contract.
- The supplied POT file and WordPress.org readme have not been regenerated or compatibility-certified by this foundation change.

## Change and release boundary

Preserve runtime behavior unless a separately scoped fix and tests justify a change. Open a reviewable PR, run `php tests/verify.php`, inspect the diff and report integration checks separately. The existence of a CI workflow does not imply its jobs ran or that branch protection is enabled. No automatic publishing, tagging, version bump, or production deployment is configured.

**Licensing:** the supplied PHP plugin header and WordPress readme declare `GPL-2.0-or-later`; this repository foundation does not make a new licensing decision.
