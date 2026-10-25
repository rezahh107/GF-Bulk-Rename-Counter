# Dedicated ALL — whole-form flat ZIP (source candidate; not public v2.1.1)

## Operator workflow

On Forms → Entries, open the target Gravity Form. The independent **ALL — Download all eligible files** panel appears alongside NEW and the native bulk actions.

1. Click **Check ALL**. Server computes the count of all active Entries across pagination, all eligible upload occurrences, and Entries without files. Current search/filter, checked rows, native all-matching selection and NEW history are ignored.
2. Click **Download ALL ZIP**, confirm the entire-form scope and the three counts, and save the attachment. ALL is repeatable after a successful NEW acknowledgment.
3. Server ignores browser IDs/filter parameters. It re-enumerates the entire active form and rebuilds the full source/name/hash plan. A user/form-bound 10-minute HMAC preview must match exact Entry identities, file counts, names, sizes and SHA-256 digests. A closed archive is verified before HTTP ZIP headers; the complete authoritative eligible-file manifest (including active Entry IDs, field/URL occurrences, resolved filenames, sizes, hashes, and deletion effects) is rebuilt and compared again **after ZIP verification and before any response headers**. Divergence fails visibly and the temporary ZIP is removed.
4. Zero eligible files are reported but never represented by an empty ZIP. Missing or unsafe uploads fail with no purported partial archive.

ALL is **distinct from filtered all-matching**. Existing manual Selected and filtered all-matching, NEW known/covered/pending/ACK state, native Bulk Download, Legacy and per-form naming rules stay separate.

## Completeness and resource boundary

ALL does not inherit the 500-Entry limit. It enumerates all current active Entries in a consistent InnoDB GFAPI snapshot, checking exact reported total and uniqueness instead of returning just the first page(s). Selection membership is rechecked after planning, and the full source/eligibility plan is recomputed and compared after the ZIP is verified, before its HTTP headers. This adds another source-hashing and Entry-metadata traversal; the last pre-header comparison is the bounded consistency cutoff, not a single global transaction snapshot. The existing flat planner and verified writer retain all planned source occurrences and resolve name collisions. Immediately before that final replan, ALL invalidates only the in-request Gravity Forms cache keys for Bulk Download's deleted-file URL list and deletion flag on the accepted Entries. This is necessary because another process can mutate those values while the verified ZIP is being written; re-reading a cached value would incorrectly approve a stale archive. The final replan still uses the same GF APIs and single source-eligibility algorithm, not a parallel implementation. The final consistency-only replan does **not** repeat the disk-space admission for creating another ZIP: the existing verified ZIP already occupies temporary space. Entry enumeration, source hashing, integer overflow, and PHP memory/time checks remain; Check ALL and the initial download manifest still enforce the ZIP-creation disk admission. A real shortage before ZIP creation still fails closed.

This is a **single-ZIP synchronous PHP** operation, not an unbounded capacity guarantee. The server performs admission checks for PHP memory headroom, temporary filesystem free space and configured execution-time budget using current Entry count and original source bytes. It fails visibly on detected insufficient resources. External web-server/proxy timeouts, very large files, remote storage, concurrent changes after the last successful pre-header manifest check, ZIP transport interruption, Owner-site storage and host settings remain unproven. If a hosting limit prevents full single-ZIP preparation, it must fail rather than deliver a partial ZIP. For larger deployments a separately scoped batching/multiple-ZIP method may be needed.

No persistent ALL history, new tables, ALL-to-NEW coverage transition, public release, Tag or deployment is part of this change.

## CI and evidence boundary

The authentic lab builds the actual source-bound installable ZIP and runs pinned WordPress 6.8.3, Gravity Forms 3.1.1.1, Bulk Download 4.2.0, MariaDB/InnoDB, PHP 8.2 and Chromium. ALL tests cover a filtered 503-active-Entry form (504 file occurrences including a multi-file Entry and an Entry without files), repeated downloads, source byte lengths and hashes, no-file forms, changed membership, forged scope/nonce, authentication denial and missing files. The separate NEW browser test checks NEW acknowledgment → ALL → next NEW selection, comparing the stored coverage and pending operation before/after ALL. Existing Legacy, Selected, all-matching, native and NEW tests remain.

CI PASS proves the exercised fixture and runtime path only; it cannot prove arbitrary large-form capacity or actual saved-file retention on the Owner device.
