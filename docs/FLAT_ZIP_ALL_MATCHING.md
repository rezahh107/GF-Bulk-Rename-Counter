# Independent Flat ZIP — selection across Gravity Forms pages

This correction applies only to the independent **Download Flat ZIP with Rename** bulk action. Gravity Forms, native Bulk Download, existing Legacy rename hooks, stored Entries/uploads, options, and the public v2.1.0 release remain independent.

## Operator flow

On **Forms → Entries**, open the intended form and apply Gravity Forms' normal search/filter controls.

- **Explicit selection:** check individual Entry rows, choose **Download Flat ZIP with Rename**, then Apply. Only those explicitly checked Entry IDs on that displayed page are requested. This does not pretend selections from previously visited pages are retained.
- **All matching results:** check **All matching Entries (Flat ZIP only; maximum 500)** next to the top Apply button, choose the independent action, and Apply. If native Gravity Forms provides a working all-results selection switch, that switch is also understood. The confirmation shows the native matching Entry count at page render and explains that checked rows do not narrow this mode. Uncheck the companion toggle to return to independent manual-row selection. The server rejects a changed count when it receives the browser's expected count, rather than silently substituting a different-sized selection.
- **Result:** the server resolves the current form/search/filter query and prepares the complete verified archive before any ZIP response headers. The downloaded filename reports `<N>-entries-<M>-files`, and the response contains `X-GFBRC-Selected-Entries` and `X-GFBRC-ZIP-Files`. The numbers may differ: an Entry can contain zero or multiple eligible upload occurrences.
- **Failure:** a scope that cannot be proved, a missing upload, a selection larger than the bounded 500-Entry limit, a changed count, or zero eligible files returns an error page **without** a purported partial ZIP. For more than 500 matching Entries the browser warns before submitting. Adjust the native filters and retry. No Entry, original upload, or downloaded-state acknowledgment is modified.

The per-form naming rules continue to apply **inside** the ZIP. The ZIP itself is flat; identical candidates receive distinct filenames without losing original upload occurrences.

## Selection authority and limitations

The **installed WordPress 6.8.3 / Gravity Forms 3.1.1.1 / Bulk Download 4.2.0** CI path captures the final supported search criteria from the real `gform_get_entries_args_entry_list` display pipeline (including the form-specific hook), instead of trusting `GF_Entry_List_Table::get_search_criteria()` alone. Boundary observers are placed first on the generic hook and last on the form-specific hook; their position is verified after the native list executes. If a later callback bypasses the last observer, global mode is disabled, not silently widened. The user/form/URL scope, display-effective criteria and ten-minute expiry are cryptographically sealed. POST checks permission, nonce, seal, identity and expiry; reconstructs the native criteria, replays the documented hooks under the sealed query scope, and rejects differences in the **canonical effective criteria**, not only matching count or matching IDs. Unknown criteria shapes and changes to the native form/sorting/paging arguments fail closed for the independent global mode. Manual selection and native Bulk Download remain separate.

The server enumerates GF Entry IDs in a MariaDB/InnoDB repeatable-read consistent snapshot, compares the total and unique IDs, and fails rather than truncating. The count displayed for confirmation is observed at page render, and may change before submission; this is not an immutable pre-download identity snapshot. Browser submissions retain the previously accepted expected-count failure contract: a changed count fails visibly. A same-count membership change caused by different criteria also fails before ZIP generation; ordinary data mutation under unchanged criteria still follows the current server snapshot, subject to the existing expected-count rejection. The actual ZIP planner and verified writer are reused unchanged. The snapshot stabilizes **selection membership**, not the future existence of mutable uploads: later missing/corrupt sources still cause failure before delivery. Browser receipt/saving is not proved by a successful HTTP response.

The pinned CI host rendered a different Entry List checkbox column class (`.column-column_selector`) from the class its built-in all-results-banner handler listens to (`.column-cb`). This caused the *native banner* not to appear in that lab despite GF's server-side `all_entries` support. The small independent, explicitly labeled all-matching toggle ensures the required product workflow remains usable without patching Gravity Forms. The native route remains supported when available.

This unmerged candidate **does not** implement incremental NEW, persistent acknowledgment, or arbitrary context-dependent callback compatibility. Supported native query shapes are bounded to same-form, criteria-expressible documented filters with intact native sorting/paging; callbacks that cannot be replayed equivalently fail visibly. No guarantee is made for arbitrarily many Entries or very large bytes: the limit is 500 selected Entries per request, with additional practical PHP memory, execution-time, disk and transport limits.

## Verification boundary

Authentic GitHub Actions CI installs the reviewed plugin from its own exact-Head release-build ZIP into pinned WordPress + GF + MariaDB and exercises Chromium → native Entry List → independent POST → ZIP save → source-name/size/SHA-256 comparison. Scenarios include explicitly checked selections, 154 Entries over native 20-row pages yielding 155 files, a filtered 77-Entry result yielding 78 files, a file-free Entry, an empty archive selection, scope/nonce/form/permission attacks, host/native regressions and size bounds. Transaction-race tests also use a separate real GF client connection to mutate membership while the real HTTP selection enumerates.

CI proves only the versions, fixtures, requests and byte sizes it actually executes, **not** Owner-site configuration, persistent object caches, very large-file capacity, or that the browser actually saved outside the disposable CI environment. Review the exact-Head CI results before considering merge.

## Release boundary

No merge, public tag, release modification, deployment, or v2.1.0 replacement is authorized by this change. NEW/acknowledged-coverage remains a separate later batch; experimental PR #18 is not a production base.
