# Staged Entry Folders → Flatten in the SAME Open ZIP (test-only)

**Decision: `TARGET_ONLY_METHOD_FALSIFIED` for the Owner-locked end-to-end integrity contract.**

The **bounded happy path works**, but public filters/actions cannot ensure every
eligible original reaches the writer, protect staging from later filename filters,
prevent a competing final-hook callback from modifying the archive, or guarantee
that a failed flatten prevents the host from streaming an unsafe ZIP.

## Authority, isolation and exact runtime

- Started from `main@ccb1baff9e2b5dbc45683037100fd02b710abb32`.
- Independent draft PR #7. PR #6 / Method C is **not** changed, closed or merged.
- Native CI: WordPress 6.8.3, PHP 8.2.34, MariaDB 11.4.8,
  Gravity Forms 3.1.1.1, Bulk Download 4.2.0, GFBRC 2.0.6.
- Dependency archives, versions and installed BulkDownload.php identity are
  checksum-verified by the inherited native integration workflow.
- Production PHP, settings, GF Entries, WordPress core and installed
  Bulk Download package remain unchanged.
- All experimental adapter code is under `tests/experimental-staged-flatten/`.
  The existing production installable ZIP builder allowlists production source,
  so it excludes these tests. The CI ZIP build is **dry-run only**.
- No extraction/repackaging of a downloaded ZIP, additional ZIP for delivery,
  new database schema, stored settings or permanent upload copies.

## Native ZipArchive probe

`tests/experimental-staged-flatten/probe-zip.php` executes real ext-zip
with PHP 8.2.34 / libzip 1.7.3 and reopens its test ZIPs after `close()`.
Observed: successful multiple `renameIndex()` calls before close, indices
and `locateName()` updated correctly, final member count and bytes preserved,
duplicate references to one physical source preserved. Renaming one member
to an occupied name returned `false`; both original members survived that
particular probe. This is a primitive feasibility proof, not product safety.

## Test-only adapter (two existing public hook points)

The real native `bdfgf_entry_filename` filter runs after GFBRC's priority
10 rename handler. Our test-only priority 1000 filter gives **every callback
occurrence** a unique ASCII staged path `entry-id/gfb-stage-S-O.bin`.
A running serial and per-Entry ordinal disambiguate duplicate physical
paths, multiple files within one Entry, equal names across Entries, fallback
and pass-through filenames. The host's *unchanged* private sanitizer follows
that filter, then native `addFile()` adds the staged files to the *same* ZIP.

At `bdfgf_after_uploaded_files` priority 10, the adapter inspects the
ZipArchive it was handed, compares recorded stage callbacks with the
`uploaded_files` array provided by the host, checks staged member
names and counts, reserves all naturally occurring final basenames
(case-insensitive), allocates provisional deterministic suffixes for
collisions, and calls `renameIndex()` on the **same open ZIP**. It does not
build another archive. Its source journal is test evidence, not persistence.

Noncolliding existing `d{counter}.{extension}` Legacy names are retained.
The new suffix scheme does **not** decide behavior for empty/zero/textual/
malformed counters and is NOT approved for production.

## Positive evidence: 18 authentic runtime cases

The workflow first completes the existing unpatched native baseline
(including genuine GF Entries/uploads, native action, authenticated single
entry HTTP request, host-only collision findings, invalid/negative ZIP
checks, and source immutability). A later experiment-only step executes
the same registered native `gform_entry_list_action` with test callbacks.

18 distinct positive cases include one source; repeated counters;
multiple attachments in one Entry; equal upload basenames within one Entry
and across Entries; identical physical path referenced twice; zero/missing
counter pass-through; renamed/unchanged mixtures; post-sanitization
collisions; priority 999 late filename filter (BEFORE staging);
custom host folder setting; case-fold collisions; deleted-URL exclusion;
and 50 independently uploaded ~64KiB files.

The **independent** Python ZIP reader runs after native close and response.
It compares the source-occurrence path+SHA256 multiset to the plan,
member count/paths, unique case-fold-safe flat paths, stream-read member
SHA256 and unchanged original file content. Digest reading uses 1MiB
chunks, not a whole-file PHP read. For the 50-file fixture:
50 originals -> 50 ZIP members, ZIP ~3,282,622 bytes;
WP-CLI process measured RSS about 120MiB and elapsed about 0.3–0.5 seconds
across CI runners. These figures include WordPress bootstrap; isolated
adapter CPU/memory and peak temporary disk overhead are NOT measured.
At a minimum the host must hold roughly the ZIP output size temporarily.

## Critical negative runtime observations

### 1. A filename filter *after* staging defeats the pre-add uniqueness

At priority 1001 a third-party `bdfgf_entry_filename` callback returns
`collapsed.jpg` twice. The native writer's default `addFile()` replaces
the first member BEFORE the final action: both source callbacks ran,
but the final hook was handed one member instead of two. In the actual
native WP action, the source-compromised archive was returned.
The finalizer cannot reconstruct a source overwritten before it runs
and this prototype never tries to.

### 2. The final-hook's attempted rejection does not reliably abort delivery

A priority 5 callback adds an extra member: the adapter was invoked,
observed 3 ZIP members versus 2 planned, and did not mark flatten complete.
Nevertheless the native action streamed the staging ZIP plus extra member.

The injected renameIndex failure is **real**: the adapter requests a
rename to another existing staged member, `renameIndex()` returns `false`
and the adapter aborts. The native action nevertheless streamed a ZIP still
containing the original staged folders. No safe error/non-delivery contract
can be claimed via this public action.

The observed runtime outcome is decisive; the exact internal component
absorbing the callback exception is not established from this test and
must not be guessed.

### 3. Third-party action mutation and post-close provenance

A competing final-hook callback can overwrite a staged member early, or
add/overwrite a root member after flatten. The independent checker can
reject changed bytes, missing, extra or unexpected members **after close**,
but this readback is only in the test harness, NOT a native host
pre-response admission gate. A later callback may overwrite a planned
member with exactly the same bytes: final path/count/digest checks cannot
prove the original causal writer attribution.

### 4. Collector completeness is not observable by filename hooks

`get_uploaded_files()` itself skips unreadable/unresolved input URLs
before the staging filter is invoked. The `bdfgf_single_entry_uploaded_files`
filter is also freely extensible with no mandatory occurrence registry.
Comparing the plan with `uploaded_files` only proves parity with *collector
output*, NOT with every eligible original source in the GF Entry.

## Method C comparison

| Dimension | Staged public-hook method (this PR) | Experimental Method C (PR #6) |
| --- | --- | --- |
| Native primary-writer flat output, bounded fixtures | 18 positive cases preserve paths/count/bytes | 16 positive cases preserve paths/count/bytes |
| Host source modification | None | Installed host writer patched in disposable CI |
| Host update coupling | Depends on public hook timing, final sanitizer and host behavior | Explicit pinned host source SHA; updates need patch requalification |
| Code complexity | Small callback adapter plus journals and adversarial qualification | Host writer mutation, source ledger and post-close verification |
| Memory/performance | No extraction; staged indices renamed in place, cost of WordPress and ZIP writer remains | No extraction; host writer and verification; comparable workloads not directly benchmarked |
| Recovery and fail-closed | **Falsified:** attempted pre-response abort can still stream unsafe ZIP | Host-owned post-close check rejects changed/missing/extra; real close failure not independently triggered |
| Third-party final-hook same-byte overwrite | Undetectable provenance; no host post-close gate | Undetectable provenance even after host-owned post-close gate |
| Collector source completeness | Unproved; native collector may silently skip | Modified disposable host collector handles missing/unreadable, but undeclared collector extensions remain unproven |

Neither method is production-admissible as tested. Method C has stronger
host-owned error enforcement and post-close integrity checking, but demands
host changes and still cannot prove independent provenance under arbitrary
post-hook mutation. The staged public-hook method is simpler and host-edit
free, but **materially less safe under adversarial extensions**.

## Decision and smallest next step

**`TARGET_ONLY_METHOD_FALSIFIED`** for end-to-end safe flat ZIP with the
available public hooks alone.

Do not "fix" the test by disabling other callbacks, relaxing ZIP checks,
injecting an extra archive, or reconstructing prehook losses. Do not merge
this nonproduction PR into the installable plugin.

The smallest justified Owner decision is whether to pursue an
**upstream Bulk Download ZIP-writer/finalization API change** that controls
all source occurrence admissions, preserves uniqueness *before addFile*,
handles registered third-party writes and enforces **post-close integrity
before response**. That is a different implementation authority; this PR
does not make it.

Limits: no deployment, live website, full browser bulk-selection flow,
real GF email token download, universal Unicode/custom naming policy, or
independently measured peak transient disk/memory scalability. Observed
tests are only for the pinned disposable runtime above.

**DO NOT MERGE / RELEASE / DEPLOY.**
