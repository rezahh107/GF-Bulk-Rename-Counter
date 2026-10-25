# F-01 ALL existing-Entry file-state race qualification

This test harness is **excluded from the canonical installable plugin ZIP**. Its disposable
installed-copy instrumentation is restored byte-for-byte at the end of the CI step.

Source: PR #28 at `13cbdd7483025793544f2a6c9d1a3f86ffcb18e3`.
Prior falsification: draft PR #29 / authentic native CI `40131548592`.
Repair: same authoritative `All_Download::manifest()` / `Flat_Zip_Download::plan()`
is evaluated immediately after `write_verified_zip()` and before HTTP headers.
No second independent file-eligibility algorithm is introduced.

The genuine WordPress/Gravity Forms native CI runs separate authenticated HTTP
requests with deterministic synchronization barriers in **only the installed copy**:

- Source A-to-B mutation with readable A after accepted pre-write manifest.
- Deletion metadata mutation after a completed verified ZIP; A+B becomes B.
- Repeated-source mutation after a completed verified ZIP; changing the file field
  changes one occurrence, while duplicate A and B occurrences remain represented.
- No-mutation positive control, after-write barrier, and a physically distinct
  eight-megabyte source hash/ZIP control.
- Independent source SHA-256 and occurrence multiplicity comparisons on fresh ZIPs.
- Failed stale responses must be HTTP 422, non-ZIP, without Content-Disposition
  or X-GFBRC-ALL headers, and their temporary ZIP must no longer exist.
- Existing native CI exercises forged nonce/scope, membership, zero/missing
  sources, 503 Entry pagination, NEW ACK → ALL → NEW and Legacy/Selected/native.

The timer added **only to the disposable installed copy** reports runtime seconds
for each successful final authoritative replan. These results measure the pinned
CI host and tested bytes; they cannot prove gigabyte-scale host capacity.

Rechecking before headers establishes a last observed state **cutoff**.
It does not make cross-request DB/filesystem state globally atomic,
nor prevent changes that happen **after** that final comparison.
