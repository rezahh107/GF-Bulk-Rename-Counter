# Agent instructions — GF Bulk Rename Counter

- Purpose: preserve and maintain the owner-supplied WordPress plugin; the bulk-download filter changes returned filenames, not original uploads or entries.
- Source of truth: runtime PHP in repository root and `includes/`; `README.md` explains setup, current behavior, limitations, and testing. Treat the original v2.0.6 behavior as baseline, not as proof of integration compatibility.
- Verification: from repository root run `php tests/verify.php` (requires PHP CLI >= 8.0). This exercises PHP lint and isolated handler regressions only.
- When changing filename logic, add focused tests and inspect duplicate/counter/ZIP-path behavior. Do not silently change ZIP folder semantics, field lookup, or compatibility expectations.
- Do not edit stored uploads, Gravity Forms entries, or WordPress database in repository tests. Do not introduce real credentials, auto-publish a release, or modify plugin version/author/license metadata without an explicit, reviewed reason.
- In PR reports separate source-level PASS from WordPress/Gravity Forms/Bulk Download integration `NOT_PROVEN` unless actually tested. Prose instructions and green CI are not themselves production evidence.
