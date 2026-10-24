# NEW — operator workflow candidate (unmerged)

This document describes the implementation **in Draft PR #24**, not the public v2.1.1 release. This candidate must not be merged or published until its exact-head WordPress/Chromium and source verification pass.

## Operator instructions

1. Open **Forms → Entries** for the intended Gravity Form. The separate **NEW — newly created Entries** panel appears alongside native Apply. It does not use checked rows, page number, or current search filters: NEW is always whole-form, active Entries. Legacy, manually selected Flat ZIP, native Bulk Download and independent all-matching remain untouched.
2. Click **Check NEW**. The server takes a single consistent GF Entry snapshot, enumerating *all existing identities including inactive Entries* and independently cross-checking active identity selection with GFAPI. It displays the actual new eligible **Entry count** and distinct eligible **file count**. It checks each planned source with the existing Flat ZIP planner.
3. For a nonzero file count, click **Download verified ZIP**. A short-lived, user/form-bound operation revalidates the same frozen Entries, archive names and source hashes. The existing ZIP writer produces a flat archive and verifies its members and hashes before sending. The browser receives the bytes and verifies SHA-256, then prompts for manual verification. **A browser hash match does not prove the ZIP was actually retained on disk.**
4. Inspect your locally saved ZIP. Only then check the separate confirmation checkbox and click **Confirm receipt and advance NEW**. No selection, preparation, HTTP 200 or browser download automatically advances coverage.
5. If the operation contains **new eligible Entries but zero files**, no fake empty ZIP is sent: explicitly review the no-file outcome, check the box and confirm. If there are *zero NEW Entries after initialization*, the check is read-only and needs no repeated acknowledgment.
6. If the browser/download is interrupted, files changed or confirmation is absent, run **Check NEW** again. There is no permanent advancement without a valid, operation-bound acknowledgment.

## Exact identity contract

The non-autoloaded, per-form `gfbrc_new_coverage_{form}` option uses lossless sorted integer ranges. It distinguishes `known` (identity existed in the last confirmed pre-download snapshot, regardless of active/spam/trash status) from `covered` (eligible Entry identity actually included in the acknowledged operation). Entries that were previously known but become eligible or acquire uploads later do **not** reappear as NEW. Entries newly visible since the previous confirmed snapshot, including backdated GFAPI inserts and delayed lower-ID commits, remain discoverable without timestamp or maximum-ID shortcuts.

One 15-minute `gfbrc_new_pending_{form}` WordPress transient holds the immutable operation identity, user/form, old-state SHA, frozen exact IDs, source manifest fingerprint and verified ZIP hash after preparation. It contains **no stored ZIP bytes**. A second tab preparing NEW replaces the pending operation; an old tab's confirmation fails explicitly. The persistent option update uses a single `BINARY option_value` conditional SQL update (compare-and-swap) and WordPress option-cache invalidation/readback. No multi-user ledger is used.

The **first run** proposes all currently eligible existing Entries as an unacknowledged initial batch. The initial empty persisted option stores *no acknowledged coverage*; identities are recorded only after explicit confirmation. Selected and current all-matching are independent and never move NEW coverage, even when the operator downloads their ZIP. No new dedicated ALL mode is introduced by this PR.

## Resource envelope and limits

The candidate deliberately fails closed above 500 NEW eligible Entries per operation, 50,000 currently stored historical Entry identities, 1 MiB canonical coverage JSON, or 128 MiB verified ZIP. It does **not** silently divide or cover unexported portions, and does not promise availability for extremely large forms. It requires the installed GF Entry table, InnoDB consistent snapshot and local upload files. Remote/offloaded files, external persistent object cache, direct SQL import/restore identity rewriting, storage engines other than the pinned MariaDB/InnoDB or other host versions remain **NOT_PROVEN**. A WordPress transient expires automatically; without any download there is no temporary ZIP artifact to leak.

## Verification boundary

The existing pinned CI installs the source-built target ZIP into WordPress 6.8.3, Gravity Forms 3.1.1.1, Bulk Download 4.2.0, PHP 8.2, MariaDB 11.4.8 and authentic Chromium. `tests/production-candidate/new-browser.cjs` extends that exact journey for newly added Entry IDs, browser ZIP save, explicit acknowledgment and durable next selection. `new-assert.py` independently compares saved ZIP members and SHA-256 to source-fixture hashes. The previous PR #18 delayed-commit and concurrent CAS findings remain separate evidence; a new test in this PR must not be claimed PASS before its exact-head CI log is examined. `tests/experimental-new/coverage-stress.php` tests real storage fragmentation but is not proof of user download.

The original publisher's post-tag verification defect remains a **separate future publication repair**. No PR merge, GitHub Release, tag, public asset change, or Owner-site deployment is authorized here.
