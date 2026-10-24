# NEW download — bounded safety findings and owner decisions

This is **not an operator-facing NEW feature**. Source: latest `main` after public v2.1.1 recovery; PR #18 is an unmerged non-production experiment.

## Proven and rejected

* A commit-delayed GF Entry may appear below a previously visible maximum Entry ID. `id > max_acknowledged_id` can permanently omit it. A creation timestamp or fixed lookback also lacks a universal lossless guarantee. See PR #18's actual delayed-commit evidence.
* A consistent GFAPI/InnoDB snapshot can preserve enumerated membership in the pinned lab, but does not know which lower IDs may commit **later**.
* The simplest known lossless candidate for this environment is one per-form, non-autoloaded exact acknowledged-identity set represented by disjoint integer ranges and updated by SQL compare-and-swap. That is a **candidate**, not an admitted product state schema.
* A user clicking Download, an HTTP 200, and server-side ZIP verification never prove the user has saved the ZIP. Require a separate, explicit operation-bound acknowledgement after the operator has checked their saved file.

## What the focused test can and cannot prove

`tests/experimental-new/coverage-stress.php` is a **disposable manual native-lab test** for the WordPress option store. It tests revision CAS rejection/retry, hole preservation, per-form isolation, non-autoload option behavior, corrupted history rejection, and worst-case fragmented synthetic identity sets with 1,000/5,000/20,000 disjoint intervals. It reports bytes, elapsed time and peak PHP memory; it cleans its two disposable option keys.

Run **only** in the existing disposable WordPress/GF/MariaDB lab after dependency activation:

```sh
php "$BRC_WP_CLI" --path="$BRC_WP_PATH" --user=bootstrap_admin eval-file tests/experimental-new/coverage-stress.php
```

This test does **not** prove authentic file receipt, browser interaction, GF creation/import semantics, real concurrent OS-process races (previous PR #18 did those), external persistent object caches, or production-site operation. Its 1 MiB encoded history ceiling is test-local, not a promised capacity or cleanup policy. Do **not** run on the Owner site.

## Owner decisions required before enabling NEW

1. **First run:** recommend offering the **current eligible existing Entries as the initial NEW batch**, with zero automatic checkpoint movement before explicit confirmation. Alternative: explicitly mark existing records as baseline without downloading. Neither is silently selected.
2. **Older Entry becoming eligible:** recommend **do not reclassify an Entry as newly created solely because an upload was added later**. This requires history of observed pre-existing Entries, not just an acknowledged-eligible identity set. Define what to do with status changes, restored/deleted Entries, and imports with backdated dates/IDs. This decision materially changes storage/query cost.
3. **Selected/ALL effects:** recommend **no implicit advancement of NEW from Selected downloads**; for dedicated ALL, offer an explicit *also mark these exact Entries as covered* option only when the complete archive and acknowledgment correspond to an operation-bound server snapshot. Default should not silently consume NEW coverage.
4. **Zero-file Entries:** recommend showing their Entry count separately and requiring explicit operator acknowledgment of a complete operation (not pretending a ZIP contains a file). Define whether a pure zero-file batch constitutes an acknowledged download.
5. **Resource/retention:** when exact history approaches a measurable storage or CPU ceiling, fail closed with an actionable notice; never truncate ranges or expire unresolved ID gaps. A justified ceiling needs measured real lab behavior and realistic form sizes.

## Smallest remaining implementation/verification path

After the above product decisions, integrate a separate operation-bound NEW request into the **existing** `Flat_Zip_Download::plan()` / `write_verified_zip()` route, freeze exact Entry IDs for each operation, verify ZIP source bytes/member names/hashes, and persist a short-lived server-bound operation record before delivery. Only a separate authorized nonce-protected confirmation may CAS-union its exact IDs into per-form history; duplicate, stale, missing and corrupted operations must not advance coverage. Authenticated real Chromium interaction and negative tests belong in the existing pinned native CI.

**Publication boundary:** no tag/release/merge/deployment is implied. Before a future publication, separately repair the original publisher's post-tag verification failure (v2.1.1 was recovered by the reviewed one-time consumer workflow, not by proving the original publisher universally correct).
