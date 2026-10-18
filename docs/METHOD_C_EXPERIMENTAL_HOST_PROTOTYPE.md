# Experimental Method C — host ZIP boundary (NO PRODUCTION ADMISSION)

## Authority and isolation

This is a CI-only mutation of the **installed, pinned** Bulk Download 4.2.0
\`lib/Actions/BulkDownload.php\`; it is **not** an edit of GFBRC production PHP
or a replacement WordPress plugin. Before patching, the workflow runs the
existing authentic ZIP scenarios, host-only collision diagnostics, source
immutability checks and negative controls unchanged.

The patch refuses any host source whose SHA-256 differs from
\`5fd74e4e842eac2a40ee2395d07f55d85b8aa5499db47e10b0582d09dfa423f3\`
(the exact Bulk Download 4.2.0 installed file, whose Git blob was already
verified as \`77df109a1ad6d74c019f37bd419d178cae64d9e5\`).
The WordPress.org ZIP itself is checked against SHA-256
\`f42355c61535c9408c2668081f86e542912752b98ea1f301aa967f91c18592a4\`.

The release builder \`scripts/release/release.py\` has a strict production
file allowlist; no file under \`tests/experimental-method-c/\` can enter
the installable artifact. There are no persistent settings or new plugins.

## Exact observed host boundary (inspection, not a presumed hook)

- Authorization: \`GFCommon::current_user_can_any('gravityforms_view_entries')\`
  plus the host \`bdfgf_download_permission\` filter and the unchanged single
  entry nonce / signed-email link boundary.
- \`GFAPI::get_entry\` form validation and \`get_uploaded_files()\` parse
  \`fileupload\` values, exclude \`bdfgf_deleted_file_urls\`, resolve URLs to
  readable paths and invoke \`bdfgf_single_entry_uploaded_files\`.
- \`zip_uploaded_files()\` constructs default \`{entry_id}/{basename}\` or
  host-configured folder, applies \`bdfgf_entry_filename\`, invokes its
  private final \`sanitize_zip_entry_filename\`, then \`ZipArchive::addFile\`
  **without checking final-member-path uniqueness**.
- \`bdfgf_after_uploaded_files\` receives the **mutable ZipArchive**, with
  no obligatory source-occurrence identity or provenance declaration.
- Native \`ZipArchive::close()\`, temporary-file cleanup in \`finally\`,
  private response headers and streaming remain owned by the host.

## Experimental mechanics

The host's **own private sanitizer** runs after normal filename filters;
the experimental writer then flattens every candidate to a sanitized basename.
It records \`(entry_id, occurrence_ordinal, absolute_source_path,
source_sha256, candidate)\`. Separate occurrences of the **same physical file**
stay separate records. It reserves all natural candidate names before suffix
selection, preserves the first collision candidate, and gives later collisions
a bounded deterministic \`-e{entry}-o{ordinal}-{index}\` suffix. This suffix is
a **test-only disambiguator**, not an approved production naming policy.

The actual \`ZipArchive\` insertion checks its occupied member names with
\`locateName()\`, checks a per-write assigned-name set, and sets explicit
\`addFile\` flags \`0\` rather than the default \`FL_OVERWRITE\`.
After the existing final hook and real \`close()\`, a new ZIP reader verifies
every planned path, uncompressed SHA-256, count and original file SHA-256
**before response headers or successful streaming**. The original file
collection is changed to visibly reject missing/unreadable eligible uploads
instead of silently skipping them; deleted URLs remain excluded.

The independent \`assert-case.py\` separately compares the original GF
fixture upload-occurrence multiset, journal occurrence identity, final flat
ZIP, content SHA-256 and historic noncolliding \`d+Counter\` exact filenames.
Wrong content, missing, extra, duplicate and misattributed members must all
be rejected. Existing \`verify-sources.php\` checks GF values and original
upload bytes after all actions, including repeated physical path cases.

## The unsatisfied public hook contract

The final \`bdfgf_after_uploaded_files\` hook is **unrestricted**. It can add
unknown members, delete/replace planned members, or overwrite one planned
member with the same bytes. Post-close checks detect extra, missing and
changed-byte members, but **cannot distinguish a same-byte replacement from
an untouched archived source occurrence**. A source occurrence's original
hash, member count and filename cannot prove the causal provenance of a
writer outside the recorded plan.

Thus even if the synthetic same-byte replacement passes all final integrity
checks, this is a **negative provenance demonstration**, not a successful
qualification. Safe production support would require a host-owned post-hook
registration contract for additions or a new trusted finalization boundary
after all third-party writers (plus policy for undeclared effects); silently
disabling the native hook is NOT authorized.

**Required decision on that evidence:**
\`NOT_ADMISSIBLE_WITH_UNDECLARED_POST_HOOK\`. The local collision-prevention
experiment may work for the recorded writer inputs, but Method C cannot
honestly promise end-to-end source-occurrence provenance with this unbounded
public hook. Do not merge this into production naming behavior.

## Experimental limitations

- Fault injection before add and immediately after close tests error
  propagation, ZIP non-delivery and \`finally\` cleanup; it does **not**
  prove a real filesystem/libzip close failure was triggered.
- The host's native \`bdfgf_single_entry_uploaded_files\` filter remains
  executable but has no mandatory declaration of files it adds/removes.
  Provenance for undeclared changes before the writer remains limited.
- The existing native UI/HTTP baseline runs unmodified before experimental
  patching. Full browser bulk-selection, email token workflow, bulk-delete
  behavior, arbitrary custom naming filters, Unicode and production
  equivalence are not independently exercised by the prototype.
- Empty/missing/zero counters retain the GFBRC v2.0.6 filename-filter
  behavior; new collision-only suffix policy is provisional. Arbitrary text
  and Unicode naming decisions remain Owner-owned.
- The experiment's normal success does not prove universal ZIP safety.

## Decision comparison and next action

A strict host-owned pre-final hook source register plus non-overwritable
archive membership guard could support a production implementation, but would
change the third-party extension contract and require upstream coordination.
A simpler documented folder-per-entry configuration can mitigate **only**
cross-entry collisions and cannot satisfy the Owner's mandatory flat ZIP.
A target-side final filter alone cannot guard later filters, sanitization or
the final hook. The smallest plausible permanent fix belongs to the Bulk
Download host ZIP writer/finalization contract, not to GFBRC's own handler.

No tag, release, deployment, settings mutation, fork or production PHP change.
