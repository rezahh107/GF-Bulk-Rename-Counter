# GF Bulk Rename Counter

A WordPress add-on that renames Gravity Forms files **during bulk download**, using a configurable entry counter field. It does not rename stored uploads or edit Gravity Forms entries.

## What it does

This repository contains the existing **Gravity Forms Bulk Rename Counter v2.0.6** plugin source. It hooks into the `bdfgf_entry_filename` filter provided by **Bulk Download for Gravity Forms**, looks up the Gravity Forms entry, and returns `d{counter}.{original-extension}`. When a generated name repeats within the same handler instance, it adds the entry ID and, if necessary, a further numeric suffix.

**Dependencies at runtime:** WordPress (declared minimum 5.8), PHP (declared minimum 8.0), Gravity Forms, and Bulk Download for Gravity Forms. Exact versions of the latter two are not pinned or integration-tested here.

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
| `includes/class-settings_page.php` | WordPress Settings API page and option sanitization |
| `uninstall.php` | Deletes plugin settings on uninstall |
| `languages/` | Supplied translation template (not regenerated) |
| `readme.txt` | Supplied WordPress plugin readme and changelog |
| `tests/` | Dependency-free PHP source-level verification, not a WordPress runtime harness |

Runtime plugin files are imported from the owner-supplied `gf-bulk-rename-counter.zip` version `2.0.6`; foundational work does not intentionally change their behavior. The source contains placeholder author/website metadata. Treat these as inherited placeholders, not verified project identity.

## Canonical verification

Prerequisite: PHP CLI 8.0 or newer. No Composer dependencies are required for the current checks.

```sh
php tests/verify.php
```

The command runs PHP syntax checks across the repository's PHP files and a small, isolated regression suite for the renaming handler with controlled WordPress / Gravity Forms stubs. Run this same command locally, in AI-assisted changes and in CI. **Passing does not establish compatibility with an actual WordPress + Gravity Forms + Bulk Download installation or prove correct ZIP generation.** An integration test on the target plugin versions is required for those claims.

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
