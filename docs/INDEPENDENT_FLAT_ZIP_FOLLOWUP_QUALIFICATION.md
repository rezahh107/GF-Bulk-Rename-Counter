# Independent flat-ZIP: follow-up qualification of PR #8

**Scope:** test-only research/prototype under `tests/experimental-independent/`, the pinned native CI workflow and this report. No production GFBRC PHP, Bulk Download files, release artifact contents, installation or deployment changed. PR #6/#7 were not used or edited.

**Baseline inspected:** `main@ccb1baff9e2b5dbc45683037100fd02b710abb32`; PR #8 before follow-up `8f6f974c05645724f536be21da7c7af7b1f1c4f6`. PR #8 remains Draft, unmerged. Pinned runtime: WP 6.8.3, PHP 8.2.34, MariaDB 11.4.8, GF 3.1.1.1, Bulk Download 4.2.0, GFBRC 2.0.6, real libzip and Chromium.

## A — authentic unmodified GF pagination

The earlier 99/100/101 selected-row tests remain intact under a temporary 160-row **laboratory** page-size setting, which does **not** alter GF's native background threshold. A separate browser case temporarily disables that override, clicks the genuine next/previous GF pagination controls and compares checkbox selection with the exact Entry IDs in the authenticated POST and the downloaded ZIP's source hashes.

- Actual default page size in this pinned CI: **20 rows** on page 1 and 20 on page 2.
- A checked page-1 Entry did **not** appear selected on page 2; on returning through native Previous page, its checkbox was **not retained**.
- With a distinct page-2 Entry checked, the actual browser POST contained only that visible Entry's ID (neither the earlier page-1 selection nor other rows); the corresponding ZIP was independently verified against the selected GF Entry.
- **Decision:** recommend a simple, explicit **current-page-only** action rather than an unneeded cross-page selection manager. The test-only dropdown names this scope and the user must accept a confirmation displaying the on-page selected count, stating selections from other pages are not included. Canceling this confirmation has not been separately browser-qualified.
- Do not claim multi-page persistent selection or “select every result across all pages”. If Owner requires a single ZIP spanning pages, that is a separate product decision/design.

## B — deleted-file state

Examined the actual **Bulk Download 4.2.0** source and SHA-pinned WordPress.org distribution, without patching it. `BulkDelete::bulk_delete` uses `gform_update_meta($entry_id, 'bdfgf_files_deleted', true)` and `gform_update_meta($entry_id, 'bdfgf_deleted_file_urls', $deleted_urls)`. `BulkDownload::get_uploaded_files` uses `gform_get_meta(..., 'bdfgf_deleted_file_urls')` and suppresses matching URL strings. Our independent source scanner accesses these values only through the public **Gravity Forms metadata transport**, not Bulk Download's writer/collector.

The CI adds a dedicated host-owned **synthetic** Entry/upload, enables the host deletion option **only temporarily in the disposable WordPress lab**, calls the original host deletion code, asserts that the source is physically removed and both deletion metadata values recorded, then restores the form settings. The independent plan and HTTP endpoint must reject that deleted-only Entry without returning a ZIP. A separate deliberately inconsistent deletion flag with no URL list must also fail without a ZIP. The original integration uploads remain untouched and are rehashed after downloads.

The metadata **schema is still private to Bulk Download**, not a stable cross-version public contract. The test-only adapter now requires `BDFGF_VERSION === '4.2.0'` and rejects malformed/inconsistent deletion metadata; new host versions **must be reviewed/requalified before adoption**, not automatically handled by silently treating unknown state as no deletions. There is no proven public API that independently supplies the host's deleted URL inventory with future-version guarantees. This is a deliberate compatibility boundary, not a new framework.

## C — actual Legacy rename semantics

Execute the real enabled `GFBulkRename\\Rename_Handler` next to the independent plan against authentic WP/GF Entry values. Five singleton fixtures show parity for noncolliding `d{counter}.{extension}`, zero, empty, missing and sanitized/malformed counters with flat basename as the incoming filename. Repeated/colliding files reveal **103 naming differences** across the tested fixtures: Legacy uses `d125-<EntryID>.jpg`, then `d125-<EntryID>-1.jpg`; the experimental planner uses `-e<EntryID>-f<FieldID>-o<ordinal>-<n>`. This suffix was **never an approved product naming rule**. The experimental planner is deterministic and conserves occurrences, but not Legacy suffix parity.

Legacy returns the original incoming `entry_filename` *unchanged* on pass-through (possibly a host-supplied folder/path); any flat-ZIP successor must intentionally remove path components while retaining the original basename when unambiguous. This is a flatness requirement, not a reason to rewrite the working Legacy action.

**Owner choice if needed:** (1) preferred: keep Legacy-style suffixes where safe and reserve all natural file names before deciding unique collision suffixes; (2) explicitly approve a new deterministic suffix scheme. Both must preserve every source and never overwrite a member. The simplest production design is an isolated current-page action, a small GF Entry scanner, a single closed-ZIP writer/validator, and the pinned deleted-file schema check. No extra database, framework or cross-page selection layer.

## Verification boundaries

Positive authenticated HTTP/browser ZIP cases, member SHA-256/count/path verification, negative HTTP no-ZIP responses, writer fault injections, original Entry/upload preservation, and unchanged native Bulk Download browser action remain in the same pinned native CI. An automated test's own raw GF deletion-flag snapshot may differ in **PHP type** between the creating process's in-memory metadata cache and a later persisted read; comparing semantically as a boolean removes that *test-only false positive*, while exact URL-list metadata and source-byte readbacks remain strict.

No production-sized ZIP or hosting memory/disk/time guarantees, offloaded files, actual disk failures or mid-stream network interruption have been established. This qualification supports a **separately authorized** production successor; it is not production admission. **Do not merge, release, deploy or edit host code.**
