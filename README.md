# GF Bulk Rename Counter

A WordPress add-on that renames Gravity Forms files **during bulk download**, using a configurable entry counter field. It does not rename stored uploads or edit Gravity Forms entries.

## What it does

This repository contains the source of **Gravity Forms Bulk Rename Counter v2.1.0**, published on GitHub Releases on **2026-10-20**. The reviewed release source is `f2009eddae0b9b863e7aadde85cc29252b73f69a`; the post-release `main` maintenance commit retired the consumed candidate marker without changing shipped files. Download the installable [v2.1.0 Release ZIP](https://github.com/rezahh107/GF-Bulk-Rename-Counter/releases/tag/v2.1.0), not GitHub’s automatically generated source archive. Its retained Legacy workflow hooks into the `bdfgf_entry_filename` filter of **Bulk Download for Gravity Forms**, looks up the Gravity Forms entry, and returns `d{counter}.{original-extension}`. When a generated name repeats within the same handler instance, it adds the entry ID and, if necessary, a further numeric suffix.

**Dependencies at runtime:** WordPress (declared minimum 5.8), PHP (declared minimum 8.0), Gravity Forms, and Bulk Download for Gravity Forms. The independent flat ZIP additionally requires the PHP `ZipArchive` extension and a writable WordPress temporary-file location (`wp_tempnam()`); it is not a remote-storage downloader. The supported runtime dependency range is not exhaustively pinned or certified. A bounded CI integration lane uses **Gravity Forms 3.1.1.1** and **Bulk Download for Gravity Forms 4.2.0** in a synthetic WordPress environment; see [Native ZIP integration qualification](docs/NATIVE_ZIP_INTEGRATION_LAB.md).

## Independent flat ZIP (included in v2.1.0)

A separately registered **Download Flat ZIP with Rename (selected on this page only)** action appears beside native Bulk Download. It confirms the exact selected Entry count; cross-page selections are not retained. The separate authenticated POST verifies Gravity Forms permission, nonce, source paths and SHA-256 of every expected member before responding. It leaves stored Entries, uploads, the original Legacy action and native Bulk Download unchanged.

The compatibility check interprets both existing host deletion metadata keys through public Gravity Forms meta retrieval without comparing `BDFGF_VERSION` to any exact release number. Malformed, contradictory or unknown observable deletion states, missing source files and unsafe paths produce a visible error and **no ZIP**. A future release using new deletion semantics with no observable change in these keys cannot be detected or certified automatically. Remote/offloaded storage is outside the local upload-root contract.

The independent new Single Field/Composite strategy rejects tested Windows reserved-device basenames before download and accepts tested Persian UTF-8 names. **Do not infer Windows extraction safety:** both `Ж.jpg` and `ж.jpg` can be present as distinct ZIP entries; their behavior when extracted on a Windows filesystem is `NOT_PROVEN`. Legacy and unchanged-source names do not receive that new-strategy reserved-device guard. Very large archives, disk exhaustion and interrupted streams have not been proven safe. See the [qualification record](docs/POST_RELEASE_V2.1.0_INTEGRITY_QUALIFICATION.md).

Noncolliding `d{counter}.{extension}` names remain unchanged. Collisions use the Legacy visual `d125-132.jpg`, `d125-132-1.jpg` suffix scheme, with natural names reserved first. Interrupted network delivery after streaming starts is outside pre-delivery atomicity.

## Merged in main, not yet released: all-matching selection (PR #19)

The merged PR #19 implementation supersedes **only the independent selection boundary**, not the already-published v2.1.0 ZIP described above. It retains manually checked Entries and adds a clearly labeled **All matching Entries (Flat ZIP only)** control for Gravity Forms filters/search across pagination. For all-matching downloads, the candidate observes the final supported native Entry List criteria after documented hooks, validates the observer ordering, signs a short-lived form/user/URL-scoped capture, and replays the documented hooks in POST to reject changed criteria before resolving a consistent GFAPI snapshot. Unsupported or uncertain hook query shapes fail closed for global mode. The previously approved expected Entry-count comparison is retained; the server still uses the unchanged ZIP planner and writer. Download filenames and HTTP headers report distinct selected Entry and eligible file counts. See [operator flow and evidence limits](docs/FLAT_ZIP_ALL_MATCHING.md).

**Not yet published:** PR #19 merged into `main` on 2026-10-23, but the public v2.1.0 Tag/Release ZIP remains page-only; no deployment of the new selection path on the Owner's site is verified. A separately Owner-authorized release is required to distribute the change. Incremental NEW/acknowledgment is not part of PR #19.

## Per-form naming (EXT-001 first batch; included in v2.1.0)

Settings → GF Bulk Rename now contains a separate **Per-form naming (independent flat ZIP only)** section. Choose a form, open its rule, then save **Legacy**, **Single Field**, or **Composite**. Composite fields use explicit consecutive order numbers and a configurable separator. Only ordinary single-value text, textarea and number fields are offered. **Use existing Legacy defaults** removes the per-form override. Saving one form does not replace another form's rule.

The original `gf_bulk_rename_options`, Bulk Download hook and native download are unchanged. New rules live separately in `gf_bulk_rename_per_form_rules`. No rule (including all upgraded installations without this new option) keeps the exact existing effective Legacy behavior. Explicit Legacy also uses the original target-form/counter settings; it does not invent a new per-form counter configuration. Rollback to v2.0.6 ignores the new option without overwriting the old option.

New strategies run **only for the independent flat ZIP action**. The original extension remains; the existing ZIP collision reservation and verified writer still apply. The numeric value `0` is a valid field value for new methods (unlike the original Legacy counter-zero fallback). Missing, empty, non-scalar, obsolete or unsafe naming fields abort ZIP preparation visibly **before any archive is sent**; the plugin does not silently guess a name. Selected values become visible in downloaded filenames, so **avoid personal/sensitive information**.

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
| `includes/class-flat_zip_download.php` | Separate explicit-page / scoped all-matching ZIP download, source planner, deleted-state validator, archive writer/verifier |
| `includes/class-form_naming_rules.php` | Independent per-form rule persistence and validation |
| `includes/class-naming_strategy.php` | Strict Single Field / Composite filename candidates for independent ZIP only |
| `includes/class-settings_page.php` | WordPress Settings API page and option sanitization |
| `uninstall.php` | Deletes only both GFBRC-owned options on explicit uninstall (not deactivation or update) |
| `languages/` | Extracted gettext source-string catalog (no translations) |
| `readme.txt` | Supplied WordPress plugin readme and changelog |
| `tests/verify.php` and `tests/rename-handler-test.php` | Canonical dependency-free PHP lint and isolated regressions |
| `tests/integration/` | Synthetic WordPress / Gravity Forms fixtures, genuine Bulk Download ZIP checks, and negative integrity control |
| `.github/workflows/native-zip-integration.yml` | Repeatable native ZIP qualification CI, separate from source verification |

The merged PR #19 implementation shows the display-effective matching Entry count, warns about the 500-Entry bound, and rejects changed effective criteria independently of its preserved expected-count check. Captures expire after ten minutes. Unverifiable late hooks or unsupported form/sorting/paging changes disable only the independent global mode. The public v2.1.0 release remains page-scoped. See [merged all-matching behavior](docs/FLAT_ZIP_ALL_MATCHING.md).

The plugin began from an owner-supplied v2.0.6 ZIP. The published v2.1.0 adds the independent ZIP and per-form naming strategies, while preserving Legacy. The approved Author is **Reza Hashemi Hosseini**; the Plugin URI points to this repository. Author URI is intentionally omitted and no unverified WordPress.org contributor username is claimed. Publication was through GitHub Releases, **not** WordPress.org.

## EXT-001 qualification evidence

The native WP/GF/Bulk Download CI additionally provisions three separate forms after running the existing Legacy and independent-ZIP baseline. It exercises Settings API persistence, changes and rejected saves, authentic Chromium per-form configuration, the actual selected-Entry ZIP download, member count/path/name and SHA-256 source readbacks. Existing native controls are exercised before per-form rules are provisioned. Green results are scoped to pinned CI; live site and future hosts remain unproven.

## Canonical verification

Prerequisite: PHP CLI 8.0 or newer. No Composer dependencies are required for the current checks.

```sh
php tests/verify.php
```

The command runs PHP syntax checks across the repository's PHP files and a small, isolated regression suite for the renaming handler with controlled WordPress / Gravity Forms stubs. Run this same command locally, in AI-assisted changes and in CI. **Passing this command alone does not establish WordPress/Gravity Forms/Bulk Download integration or ZIP integrity.** Refer to the [published v2.1.0 qualification evidence](docs/POST_RELEASE_V2.1.0_INTEGRITY_QUALIFICATION.md) for actual CI run IDs and limits.

### Authentic native ZIP qualification

The supplemental [Native WordPress ZIP qualification workflow](.github/workflows/native-zip-integration.yml) installs real WordPress 6.8.3, Gravity Forms 3.1.1.1, Bulk Download for Gravity Forms 4.2.0, and the source-built installable plugin ZIP. It retains `php tests/verify.php` as the canonical source check, then provisions synthetic forms, Entries and uploads through WordPress/Gravity Forms APIs. It tests native single/multi-entry cases, the separate authenticated flat ZIP, actual Chromium entry-list use, per-form settings and naming, source hashes, preservation, failure paths, and negative controls. Some *native* collision scenarios intentionally report observed integrity violations; a green diagnostic CI is **not** a PASS for those ZIPs.

The tests **do not certify the production environment**, all custom Bulk Download folder configurations, or every filename collision. Native host scenarios use the genuine Bulk Download action; the independent path has separately tested browser selection. **Known native-host integrity defect:** when two sources resolve to the same final member path (including within one Entry or in a static shared folder), native Bulk Download 4.2.0 may preserve only one. The same loss was reproduced with this plugin deactivated. Entry-ID folder settings mitigate exercised cross-entry unchanged-basename cases only. The independent flat ZIP resolves the *tested* name collisions by reserving output names and verifying all planned source occurrences; it does not modify or repair native Bulk Download. See [native configuration qualification](docs/NATIVE_ZIP_CONFIGURATION_MITIGATION_QUALIFICATION.md) and [post-release evidence](docs/POST_RELEASE_V2.1.0_INTEGRITY_QUALIFICATION.md).

## Known behavior and limits (released v2.1.0)

- The Legacy hook emits flat filename candidates. The independent action creates a separate flat, verified ZIP; native Bulk Download still owns its own archive paths.
- Non-empty counter values are stringified and sanitized as filenames; they are not validated as numeric IDs. The literal value `0` is treated as empty and keeps the original name.
- The original Legacy hook's duplicate handling sees only names emitted by its handler and depends on call order; the independent flat ZIP instead reserves natural names and verifies its final archive.
- The Legacy handler calls `GFAPI::get_entry()` for each file and does not add an entry cache.
- The integration relies on `BDFGF_VERSION` and a three-argument `bdfgf_entry_filename` filter; the installed bulk-download plugin must provide that contract.
- The POT tracks current gettext source strings, but does not contain translations. The WordPress.org readme still contains inherited publisher placeholders and its compatibility claims are not a production certification.

## Change and release boundary

Preserve runtime behavior unless a separately scoped fix and tests justify a change. Open a reviewable PR, run `php tests/verify.php`, inspect the diff and report integration checks separately. The first GitHub Release **v2.1.0 has already been published**; the existing Owner-controlled workflows prepare and publish future versions only after explicit authorization and qualifying CI. No WordPress.org publication, production-site deployment or unattended release is established by this repository. Do not overwrite or move the existing v2.1.0 Tag or assets.

**Licensing:** the supplied PHP plugin header and WordPress readme declare `GPL-2.0-or-later`; this repository foundation does not make a new licensing decision.
