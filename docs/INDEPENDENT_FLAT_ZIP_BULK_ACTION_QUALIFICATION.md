# GFBRC independent flat-ZIP bulk action: bounded qualification

**Authority:** Test-only prototype; no GFBRC 2.0.6 production PHP/settings changes, Bulk Download patch, Gravity Forms core change, production package addition, release, merge or deployment.

**Base:** fresh main ccb1baff9e2b5dbc45683037100fd02b710abb32. Branch test/independent-flat-zip-bulk-action starts directly from main; PR #6 (experimental host-writer) and PR #7 (staged-folder flatten) remain separate and unmerged.

**Pinned lab:** WordPress 6.8.3, PHP 8.2.34, MariaDB 11.4.8, Gravity Forms 3.1.1.1, Bulk Download 4.2.0, GFBRC 2.0.6, real ZipArchive. Existing workflow checks dependency archive digests, installed host source, original native action behavior, and the exact installable plugin allowlist before installing the separate experiment as a disposable CI mu-plugin.

## Delivery-path decision

Gravity Forms documents gform_entry_list_bulk_actions for adding dropdown choices, gform_entry_list_action for selected Entry IDs and form ID, and gform_bulk_action_threshold (default 100) for background bulk processing. A background hook cannot stream ZIP bytes to the original browser request. The prototype does not change this threshold.

The independent choice appears beside native Bulk Download. Its isolated admin-list JavaScript intercepts the custom Apply click only, then submits a form-scoped, nonce-protected POST containing the selected Entry IDs to the authenticated WordPress admin-post route. Selected IDs are never placed in a shareable download URL. Native Bulk Download remains untouched. The endpoint uses the real GFCommon Entry-view capability check rather than raw WordPress current_user_can. Authentic runtime observed that a valid GF administrator can have GF Entry-view permission even when the raw WordPress capability predicate returns false.

Native GF action callbacks for 99, 100 and 101 entries were not independently exercised as browser ZIP streams: at and above 100, the documented background boundary makes original-response streaming inappropriate. The separate POST endpoint ran successfully with exact 1, 99, 100 and 101 selected IDs, each delivering a binary ZIP attachment with 1, 99, 100 and 101 distinct flat members. Both the endpoint's own filename/occurrence plan and a separately authored source-manifest checksum/conservation checker verified these downloads. Direct POST tests establish transport and authorization, not necessarily cross-page checkbox selection in the native list.

## Source ownership and ZIP invariants

Read actual GFAPI form file-upload fields and selected GFAPI Entry values; support single URL strings and multi-file JSON arrays, retaining each occurrence independently, including repeated physical paths. Exclude source URLs present in bdfgf_deleted_file_urls using GF Entry metadata API. This metadata key is a compatibility dependency on Bulk Download 4.2.0's recorded deletion semantics; it is not a private writer method, but future host upgrades require requalification.

Resolve only allowed local WordPress or GF upload roots with host/scheme matching and realpath containment. Reject traversal, remote URLs, unreadable or missing sources rather than silently omitting them. Form-ID/Entry-ID checks, nonce, authentication and authorization are separate validation boundaries.

Preserve existing noncolliding Legacy counter names. Sanitize and reserve all natural names before generating deterministic experimental suffixes for collisions; then require unique root-only paths before ZipArchive addFile. Hold source paths/size/hash/filename plan in memory, not file bodies. Close the archive and reopen before any attachment headers, validate exact member count, names, unique flat paths, uncompressed SHA-256/size and original source SHA-256. Stream archive bytes in bounded chunks. Clean up temporary ZIP files on both success and failures, before wp_die when necessary. Errors before attachment transmission deliver an understandable non-ZIP response.

This is a pre-response integrity promise, not transactional guarantee against network disconnections after streaming begins. No separate persistent database and no source/Entry mutation.

## Authentic positive and adversarial evidence

Executed real loopback HTTP downloads:
- 1 / 99 / 100 / 101 Entries with matching 1 / 99 / 100 / 101 root ZIP members;
- single and multiple upload fields, 2+ Entries, duplicate original basenames and counters, sanitization collisions, changed/unchanged mixture, same physical source referenced three times, zero/empty/missing/malformed counters;
- host custom-folder configurations ignored for the independently owned flat ZIP;
- deletion-marked sources excluded without excluding other eligible files;
- a 24-file workload (24 × 256KiB = 6MiB uncompressed source), 6,295,272-byte ZIP, ~0.24s loopback HTTP call on tested CI.

Independently read every delivered ZIP, compare complete expected occurrences and exact member names to GF source records, and compute streamed uncompressed SHA-256; assert unique flat paths. The original native Bulk Download and GFBRC baseline runs before the experiment.

Real HTTP error responses did not deliver incomplete ZIPs for all-deleted, valid+missing, unreadable, traversal, foreign remote URL, foreign-form Entry and duplicate selected Entry ID. Invalid nonce, unauthenticated request and an authenticated subscriber with its own valid nonce also yielded no ZIP. WordPress's anonymous admin-post route returned status 400, not a ZIP.

Real closed-ZIP mutation tests rejected missing, extra, and changed-byte overwritten ZIP members. Test-induced add failure, finalization failure and corrupt archive were rejected with temporary-archive cleanup. These injections are not a genuine libzip close I/O failure. Existing GF Entries and uploads and experimental Entry snapshots/source files were verified unchanged after running positive and negative cases.

**Resource measurement (actual CI):** 24 × 256 KiB = 6 MiB uncompressed; validated temporary ZIP **6,295,272 bytes**; direct loopback HTTP request about **0.21–0.25 seconds**. The PHP process recorded **4 MiB peak allocated PHP heap**, plus **122,748 KiB Linux ru_maxrss** at the 24-file POST and **132,612 KiB cumulative process max RSS** after the browser's 25-member request. These RSS values include the broader WP/PHP runtime; they are **not incremental writer memory**, and additional filesystem scratch/ZIP compression overhead was not measured independently. External hosting performance and capacity remain unqualified.

## Comparison with PR #6 and PR #7

| Boundary | PR #6 Method C | PR #7 staged flatten | Independent action |
| --- | --- | --- | --- |
| Host writer modification | Yes, in disposable CI | No | No |
| Host writer/API coupling | Direct host code change | Public host hooks + collector | None |
| Source completeness | Bounded host collector | Can lose before finalizer | Own GF Entry scan and strict errors |
| Pre-delivery ZIP verification | Requires host patch | Not enforceable at response boundary | Own closed ZIP pre-header check |
| Native Bulk Download effect | Host patch required | Uses native host writer | Untouched |
| Update maintenance | Reapply/upstream writer change | Requalify host hooks | Requalify deletion metadata schema |
| Complexity | Modified host writer | Staging + rename hooks | Own endpoint + parser + writer |
| Future naming strategy | Host writer integration | Host callback integration | Owner-controlled naming planner |

No fair comparative performance benchmark has been run. Native host has not been altered, but independent code still relies on the host-specific deleted-file metadata key. No production approval or future host-version compatibility is implied.

## Outstanding proof and decisions

**Real Chromium acceptance passed:** opened the authenticated Gravity Forms Entry list, selected one genuine Entry by checkbox, selected the independent action and clicked Apply. Chromium downloaded a 6,295,272-byte ZIP; independent PHP re-read the actual 24-member ZIP against the selected GF Entry. Repeated with two genuine checked Entry rows (25 members, 6,295,437 bytes), and independently verified their distinct Entry selection and hashes. The browser seam is therefore proven for one and two visible rows. **Cross-page selection behavior and clicking 99/100/101 checked rows in the native list remain NOT_PROVEN**; direct POST at those counts is not a substitute.  Offloaded/remote uploads, all customized GF upload roots, arbitrary site integrations and real libzip I/O failures remain unverified.

Smallest Owner decision after qualification: whether to promote this **owned independent action architecture** into a separately approved production design, explicitly accepting or replacing the Bulk Download deletion-metadata compatibility dependency and specifying collision naming and bulk UI selection policy. Do not merge, release or deploy this qualification PR.
