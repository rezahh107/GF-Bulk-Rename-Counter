# Independent Flat ZIP — selection across Gravity Forms pages

This correction applies only to the independent **Download Flat ZIP with Rename** bulk action. Gravity Forms, native Bulk Download, existing Legacy rename hooks, stored Entries/uploads, options, and the public v2.1.0 release remain independent.

## Operator flow

On **Forms → Entries**, open the intended form and apply Gravity Forms' normal search/filter controls.

- **Explicit selection:** check individual Entry rows, choose **Download Flat ZIP with Rename**, then Apply. Only those explicitly checked Entry IDs on that displayed page are requested. This does not pretend selections from previously visited pages are retained.
- **All matching results:** check **All matching Entries (Flat ZIP only)** next to the top Apply button, choose the independent action, and Apply. If native Gravity Forms provides a working all-results selection switch, that switch is also understood. The confirmation distinguishes all-matching selection from manually checked rows.
- **Result:** the server resolves the current form/search/filter query and prepares the complete verified archive before any ZIP response headers. The downloaded filename reports `<N>-entries-<M>-files`, and the response contains `X-GFBRC-Selected-Entries` and `X-GFBRC-ZIP-Files`. The numbers may differ: an Entry can contain zero or multiple eligible upload occurrences.
- **Failure:** a scope that cannot be proved, a missing upload, a selection larger than the bounded 500-Entry limit, or zero eligible files returns an error page **without** a purported partial ZIP. Adjust the native filters and retry. No Entry, original upload, or downloaded-state acknowledgment is modified.

The per-form naming rules continue to apply **inside** the ZIP. The ZIP itself is flat; identical candidates receive distinct filenames without losing original upload occurrences.

## Selection authority and limitations

The **installed WordPress 6.8.3 / Gravity Forms 3.1.1.1 / Bulk Download 4.2.0** CI path uses the pinned host's `GF_Entry_List_Table::get_search_criteria()` to apply its native Entry List criteria (active/spam/trash status, search field, operator and supported filter rules). A per-user, per-form HMAC seal binds the Entry List URL scope shown to the operator; the separate POST also requires the existing form-specific nonce and GF view-entries capability. Untrusted posted Entry IDs/counts cannot determine the all-matching set.

The server enumerates GF Entry IDs in a MariaDB/InnoDB repeatable-read consistent snapshot, compares the total and unique IDs, and fails rather than truncating. The actual ZIP planner and verified writer are reused unchanged. The snapshot stabilizes **selection membership**, not the future existence of mutable uploads: later missing/corrupt sources still cause failure before delivery. Browser receipt/saving is not proved by a successful HTTP response.

The pinned CI host rendered a different Entry List checkbox column class (`.column-column_selector`) from the class its built-in all-results-banner handler listens to (`.column-cb`). This caused the *native banner* not to appear in that lab despite GF's server-side `all_entries` support. The small independent, explicitly labeled all-matching toggle ensures the required product workflow remains usable without patching Gravity Forms. The native route remains supported when available.

This release **does not** implement incremental NEW, persistent acknowledgment, or a selection that is not precisely expressible by the host's Entry List criteria. No guarantee is made for arbitrarily many Entries or very large bytes: the limit is 500 selected Entries per request, with additional practical PHP memory, execution-time, disk and transport limits.

## Verification boundary

Authentic GitHub Actions CI installs the reviewed plugin from its own exact-Head release-build ZIP into pinned WordPress + GF + MariaDB and exercises Chromium → native Entry List → independent POST → ZIP save → source-name/size/SHA-256 comparison. Scenarios include explicitly checked selections, 154 Entries over native 20-row pages yielding 155 files, a filtered 77-Entry result yielding 78 files, a file-free Entry, an empty archive selection, scope/nonce/form/permission attacks, host/native regressions and size bounds. Transaction-race tests also use a separate real GF client connection to mutate membership while the real HTTP selection enumerates.

CI proves only the versions, fixtures, requests and byte sizes it actually executes, **not** Owner-site configuration, persistent object caches, very large-file capacity, or that the browser actually saved outside the disposable CI environment. Review the exact-Head CI results before considering merge.

## Release boundary

No merge, public tag, release modification, deployment, or v2.1.0 replacement is authorized by this change. NEW/acknowledged-coverage remains a separate later batch; experimental PR #18 is not a production base.
