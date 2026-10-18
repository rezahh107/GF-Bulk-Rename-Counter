# Native ZIP integration qualification (first bounded lab)

This lab is a **supplemental integration lane**, not a new release authority. The existing
canonical source entrypoint remains `php tests/verify.php` and also runs inside the
integration workflow. Source PASS is not native ZIP PASS.

## Authority and version boundary

- Target: exact PR Head of `rezahh107/GF-Bulk-Rename-Counter`; v2.0.6 production files unchanged.
- WordPress 6.8.3 / PHP 8.2.34 / MariaDB 11.4.8 (WU21-style container and WP-CLI primitives).
- WP-CLI 2.12.0, checksum checked; Gravity Forms 3.1.1.1 owner-supplied GPP package:
  `rezahh107/Gravity-Presentation-Profiles@fd661f562e52b68542ea12126842709ea8916114`
  in `tests/fixtures/wu21-packages/`, 5,300,290 bytes,
  SHA-256 `542f56ae0747f3661d1474996527298027db3fb8ed3e6469a6391aaabf61069b`.
- Bulk Download for Gravity Forms: WordPress.org installable *version-specific*
  `https://downloads.wordpress.org/plugin/bulk-download-for-gravity-forms.4.2.0.zip`.
  WordPress.org installable package identity was observed in authentic CI acquisition:
  56,915 bytes, SHA-256
  `f42355c61535c9408c2668081f86e542912752b98ea1f301aa967f91c18592a4`.
  These exact bytes and SHA-256 are now checked **before extraction**.
  This is a distribution-package identity, not the upstream GitHub source commit identity. The upstream GitHub commit
  `7cfc67ac0728ecbfb4c12cf812f9a0f70230ec79` is a **source reference**,
  not a substitute for the WordPress.org distributable.
- No licensed Gravity Forms package is committed to this repository. The GPP
  fixture is referenced remotely by exact Git commit, byte count and checksum,
  and rejected before unzipping if identity differs.

## Tested interfaces and scenarios

WordPress core and authentic plugins initialize normally. A fresh native GF readback and filename-filter probe confirms that numeric field IDs and the configured counter survived synthetic fixture creation. The setup code asserts
the actual registered `BDFGF\Actions\BulkDownload` action and
`GFBulkRename\Rename_Handler` filename filter, then creates four synthetic
forms and entries via `GFAPI::add_form()` / `GFAPI::add_entry()` and synthetic
uploads via `wp_upload_bits()`. It does not write to real site data.

| Scenario | Intended default ZIP paths | Requested evidence |
| --- | --- | --- |
| `single` | `d125.jpg` | Authenticated native single-entry HTTP download, correct bytes |
| `shared` | `d125.jpg`, `d125-{second_entry_id}.jpg` | Two entries with the same counter; native host bulk action |
| `multiple` | `d125.jpg`, `d125-{entry_id}.jpg`, `d125-{entry_id}-1.jpg` | One entry with a single file and a multiple-upload field |
| `collision_attempt` | `d125.jpg`, `{second_entry_id}/d125.jpg` | Counter 125 renamed vs counter 0 unrenamed under **native default folder behavior** |

The collision attempt must not be described as observed file loss: the native
host places the unchanged filename under its default entry-ID folder, and a
target-side flattened renamed path is therefore different. No test-only filter
is added to fabricate a collision.

The ZIP checker independently compares complete member paths, count and
SHA-256 of **uncompressed member bytes** with the synthetic originals. It
fails on missing/overwritten members, duplicate paths or changed content.
The negative control deliberately corrupts an expected digest and requires
the *same checker* to reject it. A final native `GFAPI::get_entry()` read-back
and original-file checksum comparison ensure bulk download did not mutate
entries or uploads.

## How to run / interpret results

Open a pull request touching this branch. `Source verification` and
`Native WordPress ZIP qualification` run independently. The integration
lane is also manually dispatchable. Inspect the exact PR Head, workflow job,
`package-identities.txt` data printed in the CI log, the native HTTP headers
and the per-scenario ZIP evidence in the job summary. The multiple-entry
scenarios dispatch the actual native `gform_entry_list_action` callback from
WP-CLI, **not** an authenticated browser list selection; the single-entry
case is an actual authenticated HTTP route with nonce and short-lived WP
session cookies. Authentication material lives only in ephemeral /tmp files,
not exported artifacts.

A failing ZIP checksum/count test is a qualification finding, **not** an
invitation to relax the assertion or silently modify the plugin. Dependency
download failures, missing ZIP vendor code, native route failures, or fixture
identity conflicts are `NOT_PROVEN / ENVIRONMENT_UNAVAILABLE` until genuinely
repaired or properly supplied.

Passing proves the exercised synthetic paths in the identified runtime only;
it does not prove real user data, the production site, external hosting
configuration, or arbitrary filename/collision cases. No merge, tag, release
or deployment belongs to this lane.

## Follow-up: collision and archive-conservation qualification

This bounded successor **extends the same native WordPress lab**; it does not
change production v2.0.6 PHP or authorize a naming-policy change.

### Host contract examined

The WordPress.org **4.2.0** ZIP is pinned by byte size and SHA-256 above.
Its installed `lib/Actions/BulkDownload.php` file is checked for existence,
its SHA-256 is logged, and a fresh WP-CLI bootstrap reflects the actual loaded
`BDFGF\\Actions\\BulkDownload` class. The upstream
[4.2.0 source-reference implementation](https://github.com/VCATconsulting/bulk-download-for-gravity-forms/blob/7cfc67ac0728ecbfb4c12cf812f9a0f70230ec79/lib/Actions/BulkDownload.php)
was inspected separately; **upstream commit identity is not substituted for
the pinned distributed ZIP**.

In the examined host code, each file starts as
`{entry-id}/{sanitized-basename}`. With per-form
`bulkDownloadSettings.customFoldername = true`, a valid static/merge-tagged
`downloadFoldername` instead supplies the single folder component (basename
sanitized). Then `gf_apply_filters(['bdfgf_entry_filename', form-id],
entry-path, entry-id, absolute-upload-path)` runs, followed by a second
host-side path sanitizer and `ZipArchive::addFile(path, member-path)`.
No explicit final-member-path uniqueness check is visible before `addFile`.
The host owns archive creation/finalization and the single-entry HTTP and
`gform_entry_list_action` bulk lifecycles. GFBRC's handler keeps its
`used_filenames` array per instantiated object; it tracks renamed returns
only. This source reading identifies a collision **hypothesis** until the
actual ZIP data below is inspected.

### Added authentic scenarios

| Scenario | Real input/configuration | Independent integrity requirement |
| --- | --- | --- |
| `sanitized_equal` | Distinct counters `A?B`, `AB` | `dAB.jpg` and `dAB-{second-entry-id}.jpg`, both distinct bytes |
| `multi_entry_files` | Repeated counter across two entries with 3+2 attachments | Exactly five suffix-resolved member paths and five source hashes |
| `custom_folder_mixed` | Host static folder `shared`, renamed vs unrenamed | Flat `d125.jpg` plus `shared/folder-original.jpg` |
| `host_folder_collision` | Two unrenamed files with basename `same.jpg`, uploaded by genuine `wp_upload_bits` into separate month directories; host static folder `shared` | **Both** original source hashes and **two** unique ZIP members required; no approved winner/suffix policy |
| `independent_form_a`, `independent_form_b` | Two distinct GF forms, separate native bulk-action invocations | Each ZIP contains only that form's original payload with `d125.jpg` |
| Existing `single`, `shared`, `multiple`, `collision_attempt` | Original authentic baseline | All prior exact member paths remain checked |

The two dated WordPress upload directories are supported inputs to
`wp_upload_bits`, not a fake filename filter. The real GF form-settings
metadata is persisted and read back before testing the custom-folder cases.

For **every** archive, the existing checker now also compares the complete
multiset of original upload SHA-256 values and the number of original uploads,
not merely the key/value map of expected paths. This protects against two
different source files being collapsed into one ZIP member. Non-collision
scenarios additionally check every exact member path and the standard
incorrect-digest negative control. A genuine host-folder collision is
**diagnostic-only**: the checker reports
`NATIVE_ZIP_INTEGRITY_VIOLATION_OBSERVED` with actual paths and counts,
without changing the expected sources or accepting an overwritten file as a
correct archive. A green workflow with that state is **not** a green integrity
result for the diagnostic case. Original GF entries and uploads are read back
after all downloads as before.

### Decision boundary

If the custom-folder archive loses an unchanged file, the reproducible
condition is overlapping *host-generated* internal paths, rather than a
counter-based renaming collision. Whether to change host settings, request an
upstream uniqueness guard, or deliberately apply a new target-side naming
policy is an **Owner decision**. The v2.0.6 handler must not silently start
renaming unaffected originals or altering folder semantics in this test PR.
A passing bounded scenario never proves universal collision safety.
