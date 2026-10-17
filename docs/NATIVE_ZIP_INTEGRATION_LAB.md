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
  Its ZIP SHA-256/size are computed and logged by the workflow after download;
  a stable immutable checksum has **not yet** been promoted into the workflow
  until confirmed from a successful authentic run. The upstream GitHub commit
  `7cfc67ac0728ecbfb4c12cf812f9a0f70230ec79` is a **source reference**,
  not a substitute for the WordPress.org distributable.
- No licensed Gravity Forms package is committed to this repository. The GPP
  fixture is referenced remotely by exact Git commit, byte count and checksum,
  and rejected before unzipping if identity differs.

## Tested interfaces and scenarios

WordPress core and authentic plugins initialize normally. The setup code asserts
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
