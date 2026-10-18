# Native Bulk Download 4.2.0: configuration-only collision qualification

**Scope:** qualification only. GFBRC 2.0.6 production PHP is unchanged. No
WordPress setting is migrated. Do not equate a diagnostic finding with ZIP
integrity PASS.

## Exact environment and required execution

Use `.github/workflows/native-zip-integration.yml` on the **exact PR Head**.
It pins WordPress 6.8.3, PHP 8.2.34, Gravity Forms 3.1.1.1, authentic
Bulk Download 4.2.0 distributable (SHA-256
`f42355c61535c9408c2668081f86e542912752b98ea1f301aa967f91c18592a4`),
and the installed action Git blob `77df109a1ad6d74c019f37bd419d178cae64d9e5`.
The existing nine ordinary ZIP cases and exact PR #4 host-only diagnostic
must remain admitted by their **different** gates.

The fixture creates real Gravity Forms form/entry values and independent
`wp_upload_bits()` upload bytes. The ZIP checker demands the exact final
member-path-to-uncompressed-SHA-256 mapping, original-source SHA-256
**multiset**, original-file count, and ZIP member count for ordinary PASS.
The post-download `verify-sources.php` re-reads all entry fields and original
file bytes. The host-only controls deactivate GFBRC in disposable CI and
repeat the original upload-based comparisons.

## Configuration matrix (run-time results must be read from the PR CI)

| Scenario | Setup | Test's required outcome |
| --- | --- | --- |
| `default_equal_entries` | 2 entries, same original `same.jpg`, no counters; host default Entry ID folders | PASS: `<id1>/same.jpg`, `<id2>/same.jpg`, 2 hashes; repeat **without GFBRC** |
| `entry_folder_equal_entries` | Same inputs; host custom `shared-{entry_id}` | PASS: `shared-<id1>/same.jpg`, `shared-<id2>/same.jpg`; repeat **without GFBRC** |
| `default_equal_within_entry` | 1 entry, single field plus multi-upload field with different physical `same.jpg` sources | Diagnostic: two sources compete for `<id>/same.jpg`. This is **not** an integrity PASS |
| `entry_folder_equal_within_entry` | Same-entry duplicate basenames; `shared-{entry_id}` custom folder | Diagnostic: both compete for `shared-<id>/same.jpg`. This is **not** an integrity PASS |
| `shared_sanitized_upload_collision` | Input names `a+b.jpg` and `ab.jpg`, distinct month directories, no counter, shared custom folder | Diagnostic: original names converge at **WordPress upload sanitization** to `ab.jpg`, then at host final path `shared/ab.jpg` |
| `entry_folder_mixed_multi` | 3 entries, 2 renamed attachments across two fields and 3 unchanged, including two identical original basenames in *different* entries | PASS: exact flat `d125*` names for renamed files plus unique custom Entry ID directories for unchanged files; 5 hashes |

These outcomes are **expected test assertions**, not claims that CI has already
executed successfully. A newly conserving ZIP in one of the diagnostic cases
returns `NOT_REPRODUCED` and **fails the evidence gate**, requiring
requalification instead of silently blessing a changed host.

Host-only controls are explicitly required for the two cross-entry
configuration mitigations. They must match target-enabled ZIP member-to-hash
maps exactly. All four diagnostic cases have negative controls using genuinely
read source bytes: reject wrong path, extra/missing/duplicate member, unrelated
content, malformed ZIP, incomplete source provenance, and wrong folder;
a deliberately fully conserving ZIP must cause `NOT_REPRODUCED` rather than
a diagnostic success. The original PR #4 diagnostic remains its own named case.

## Interpretation and operational boundary

**Temporary configuration-only mitigation:** keep original, unchanged file
names in the host's default Entry ID folders or use a supported custom folder
with the native `{entry_id}` merge tag. This separates **cross-entry**
same-basename source files in the exercised cases. It does not promise unique
ZIP paths for two attachments with equal basenames in a **single entry**.
Further, GFBRC's counter-renamed return value is flat (e.g. `d125.jpg`),
so turning on custom folders does **not** place all renamed files in those
folders. Its current per-instance naming registry only covers files that
it actually renames. Any further filename filter can also create collisions.
Do not claim universal archive safety, even when these bounded cases pass.

This is *not* a fix for the host ZIP writer. Existing 2.0.6 counter names,
including cross-entry suffixing, are not changed by this qualification.

## Exact 4.2.0 host ownership and minimum correction

The examined installed action is
`lib/Actions/BulkDownload.php` in the exact WordPress.org 4.2.0 ZIP,
byte-bound to the [upstream source reference](https://github.com/VCATconsulting/bulk-download-for-gravity-forms/blob/7cfc67ac0728ecbfb4c12cf812f9a0f70230ec79/lib/Actions/BulkDownload.php).
`zip_uploaded_files()` builds entry-ID or custom-folder names, applies
`bdfgf_entry_filename`, calls `sanitize_zip_entry_filename()`, and finally
calls `ZipArchive::addFile(source, final_member_path)` without checking the
already occupied final member path. PHP's `ZipArchive::addFile` defaults to
`ZipArchive::FL_OVERWRITE`, making its boolean success inadequate evidence
of source conservation. The native `ZipArchive::locateName` supports
exact member-name lookup; a separate archive backend is unnecessary.

**Recommended complete host-level repair direction (not implemented here):**
at the archive writer, compute candidate member paths **after all filename
filters and the final sanitizer**, then preserve nonconflicting paths. Reserve
all original final candidates before assigning collision suffixes, so a
collision-generated name cannot steal a later noncolliding filename. Give
only colliding occurrences deterministic sanitized suffixes (Entry ID when
useful, plus a bounded numeric disambiguator **also within the same entry**);
recheck uniqueness after sanitization against reserved/assigned paths and
`ZipArchive::locateName` before `addFile`. Fail the whole archive visibly
on unresolvable/unsupported cases or add/close failures; never emit a
partially conserving ZIP as successful. Validate full source conservation
with separate authentic same-entry/cross-entry tests before deployment.

A simpler exact `locateName` guard plus failure is a useful **fail-closed
containment**, preventing silent replacement, but is **not a complete
content-preserving repair**, because it cannot produce a successful ZIP
containing both files. A one-pass suffix-only algorithm also risks altering a
later noncolliding name, hence the original-candidate reservation. No
external plugin fork or target-side filename rewriting is authorized here.

## Still not proven by this bounded fixture

Arbitrary supplied files, unsupported/invalid merge tags or their fallback
paths, case-folding differences across extraction platforms, archive members
added by other plugins through `bdfgf_after_uploaded_files`, filenames
modified by third-party filters after GFBRC, full production traffic, and
browser-driven multi-entry selection. Every claim must be tied to exact CI
head, observed report, and separately verified original GF/uploads.

**Next implementation recommendation:** if CI proves cross-entry mitigation
and same-entry loss, offer the Entry ID folder setting as a narrowly scoped
temporary operating constraint and pursue the collision-safe **upstream
host-owned ZIP writer** correction as the next production change,
with tests for both entry scopes and post-sanitization collision behavior.
No GFBRC production change, release, or settings migration in this PR.
