# v2.1.0 post-release integrity qualification (bounded evidence)

Status: **published, bounded runtime evidence; not universal ZIP/extraction certification**.
Date: 2026-10-20. This record is documentation/evidence only; it does not
change the published v2.1.0 Tag/Release, production PHP, native host code or
the product's intended destination.

## Published artifact and source identity

- GitHub Release: [v2.1.0](https://github.com/rezahh107/GF-Bulk-Rename-Counter/releases/tag/v2.1.0), published 2026-10-20 12:07:53 UTC (Release ID 418355016).
- Annotated `v2.1.0` Tag object `1a749d79c7b9d1382879a123e5ed2118b65a4307` points to source commit `f2009eddae0b9b863e7aadde85cc29252b73f69a`, tree `5be90145c6e72d9543d94cdd6d873bce09bf8813`.
- Published asset `gf-bulk-rename-counter-2.1.0.zip`: 19,090 bytes; GitHub-provided SHA-256 `7d82300bf38d41cb3db0a94970050100b1c22b4e7296b0aaab4d8bf1e2e3d5e1`. Published Manifest and checksum are separate Release assets.
- The publication run [39419857317](https://github.com/rezahh107/GF-Bulk-Rename-Counter/actions/runs/39419857317) built the allowlisted nine-file ZIP from that exact source and recorded the same SHA-256, committed source and tree in its Manifest. Its consumer step downloaded all three assets, compared them byte-for-byte to its locally built outputs, validated the downloaded ZIP and activated it in a fresh real WordPress installation with authentic dependencies. The publication workflow completed successfully. These are **observed CI results**, not a second independent post-publication download in this documentation pass.
- After publication, `main` advanced to `26df9c94bec60d82c079e284158e4386c26a1ba3`, solely removing the consumed `release/candidate.json` marker; no shipped file changed in that commit.

## Audited behavior and preservation (exact bounded lab)

The qualified pre-publication candidate Head was `6fd1265f9c4b41adb5bf1f73cec2102c9db25a1a`.
Its production ZIP contents are bound to the published source tree by the release
workflow's reviewed-tree equivalence gate. Evidence:
[Native integration 39415685357](https://github.com/rezahh107/GF-Bulk-Rename-Counter/actions/runs/39415685357),
[release dry-run 39415682508](https://github.com/rezahh107/GF-Bulk-Rename-Counter/actions/runs/39415682508)
and [source verification 39415685484](https://github.com/rezahh107/GF-Bulk-Rename-Counter/actions/runs/39415685484), all successful.

- **Independent flat ZIP / audit and download:** genuine WP 6.8.3 / PHP 8.2.34 / GF 3.1.1.1 / Bulk Download 4.2.0, with authenticated POSTs, original-byte SHA-256, exact member paths and source-occurrence counts; passing single/multiple, 99/100/101 selection, collisions, repeated physical URLs, missing/zero counters and previously deleted items. Authentic Chromium exercised the entry-list UI, top/bottom action isolation, cancellation, page-scoped selection and a separate native bulk action.
- **Preservation:** genuine GF Entry and original upload readback remained unchanged in exercised fixtures; Legacy output parity, native controls, per-form isolation, persisted settings, deactivate/reactivate, update, rollback and explicit uninstall ownership were checked. The independent path did not replace the native writer.
- **EXT-001:** real Settings API and browser Single Field / ordered Composite rule tests, selected Entry downloads and source hashes passed. New naming methods operate only on the independent ZIP. An invalid slash/backslash separator and invalid Windows device names were rejected; HTTP invalid-name cases returned 422 and no attachment. Existing Legacy naming is unchanged.
- **Failure/negative controls:** unauthenticated request, invalid nonce, subscriber without entry-view capability, nonexistent or mismatched source, malformed deletion metadata, nonremovable output buffers, addFile/closed-ZIP mutations (missing, extra, overwritten, corrupt), and intentional SHA mismatches were exercised by existing bounded tests. The exact failure classes and success claims are constrained by their recorded test cases; they do not prove all arbitrary bad inputs.
- **Resource envelope:** the lab includes 24-file browser downloads, 99/100/101 selected-entry cases and measured Linux PHP process footprints. A 500-entry production-scale download, very large files, limited disk space, PHP request timeouts and a mid-stream client disconnect were **not** established safe by those results.

## Host-native archive collision: known observed defect, not independent ZIP failure

Authentic Native integration recorded `NATIVE_ZIP_INTEGRITY_VIOLATION_OBSERVED`
for host-created final-member-name collisions (including same-entry and shared-folder
inputs). In the pinned host code, `ZipArchive::addFile` can replace an earlier member
with the same final path. The host-only control **deactivated GFBRC in the disposable
lab** and reproduced the same `shared/same.jpg` source loss; its source hash/member
map matched the GFBRC-enabled case. The ZIP diagnostic's negative controls reject
wrong/missing/extra entries, wrong bytes, malformed ZIPs and deliberately fully
conserving archives; the last case must report `NOT_REPRODUCED`, not a false PASS.

The native host's Entry-ID folder option or a custom folder containing `{entry_id}`
conserved distinct *cross-entry* same-basename originals in the qualified cases.
It does not solve same-entry equal final paths; it does not put counter-renamed
flat names back into entry folders. This is a bounded operational mitigation,
not general archive conservation and not a repair to third-party host code.
For the exercised collisions use the independent verified flat ZIP when its
semantics and input contract are suitable; do **not** label native Bulk Download
ZIP integrity as fully solved. See
[NATIVE_ZIP_CONFIGURATION_MITIGATION_QUALIFICATION.md](NATIVE_ZIP_CONFIGURATION_MITIGATION_QUALIFICATION.md).

## Unicode / Windows extraction: what the evidence does and does not say

The independent Linux-hosted real HTTP ZIP contained valid Persian UTF-8
filenames (e.g. `شیراز.jpg`). Windows reserved device-name candidates such as
`CON`, `NUL`, `COM1`, `LPT9` and their tested extensions were rejected for
**new Single Field/Composite strategies** before the ZIP response. This does
not retrofit Legacy or arbitrary unchanged upload basenames.

The valid diagnostic archive also contained **both** `Ж.jpg` and `ж.jpg`.
They have distinct ZIP member bytes/names but compare equal under Unicode
casefold. The CI ran on Linux and opened the archive with Python; it did **not**
extract the ZIP to a Windows filesystem. **Windows Explorer/NTFS extraction
collision, overwrite/error behavior, Unicode normalization, long-path handling
and case/locale-dependent extraction remain NOT_PROVEN.** Windows-compatible
filenames and one-file-per-source behavior on Windows may therefore require
a separately authorized, authentic Windows extraction qualification and then
an evidence-led repair if a material defect reproduces.

## Remaining material destination gaps

- **NOT_PROVEN:** Windows extraction of casefold-equivalent and other platform-sensitive member names.
- **KNOWN LIMITATION:** native host collisions, including same-entry and static shared-folder paths; host ownership retained.
- **NOT_PROVEN:** unsupported/changed host deletion metadata, other plugin filename filters, external/offloaded storage, arbitrary path/URL encodings, and all remote site policies.
- **NOT_PROVEN:** large download resource limits, disk exhaustion, network interruption after HTTP headers, or broad compatibility across versions/production deployments.
- **BOUNDARY:** a generic `gravityforms_view_entries` capability and form-ID ownership are checked by the independent request; fine-grained external authorization schemes were not exhaustively exercised. Do not interpret authentication fixture PASS as an audit of arbitrary role plugins.

No additional artificial scenarios were created merely to raise a test count.
Documentation repairs on the follow-up PR are separate from the already
published v2.1.0 artifact; its original release identity remains unchanged.
