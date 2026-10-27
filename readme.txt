=== Gravity Forms Bulk Rename Counter ===
Tags: gravity forms, bulk download, rename files, counter
Requires at least: 5.8
Tested up to: 6.8
Stable tag: 2.3.2
Requires PHP: 8.0
License: GPL-2.0-or-later

Rename Gravity Forms downloads with legacy counters or per-form rules using a separate verified flat ZIP.

== Description ==

The original **Bulk Download for Gravity Forms** action keeps its existing `d[COUNTER_VALUE].extension` Legacy behavior. Separate verified flat ZIP actions support **Selected**, **All matching Entries**, **NEW**, and **ALL**. These are different scopes, not interchangeable download-history controls. Independent ZIPs support per-form Legacy, Single Field and Composite naming rules. Original uploads and Gravity Forms Entries are not modified.

**Features:**
- Settings page to select target form (optional) and counter field ID.
- Uses WordPress Settings API.
- Uses WordPress gettext for interface messages and bundles Persian (`fa_IR`) PO/MO translations.
- Requires Gravity Forms and Bulk Download for Gravity Forms; does not bundle them.
- Preserves the original Legacy filename hook and its existing duplicate handling.
- **Selected:** use **Download Flat ZIP with Rename** on explicitly checked Entry rows on the visible page. Selections from other pages are not retained automatically.
- **All matching Entries:** use **All matching Entries (Flat ZIP only; maximum 500)** with the same independent bulk action to include the current form's supported search/filter matches across pages, regardless of checked rows.
- **NEW:** Check NEW for only eligible Entries *newly created since the last acknowledged coverage snapshot*. The first check includes existing active Entries as an initial, unacknowledged batch. Files attached later to previously known Entries do not make them NEW.
- **ALL:** Check ALL to preview all currently active Entries in the whole form, ignoring search/filters, pagination, selected rows and NEW history. Download ALL ZIP can be repeated independently of NEW; it never advances NEW coverage.
- Shows Entry, eligible file and file-free Entry counts separately; one Entry can contain zero or multiple eligible files. Missing/unsafe uploads, changed source content or unverified scope fail visibly rather than knowingly providing a partial verified ZIP.
- Offers per-form Legacy / Single Field / Composite naming only for independent flat ZIP downloads.
- Removes its own settings and NEW coverage/pending options only on explicit uninstall, not deactivation.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate Gravity Forms and Bulk Download for Gravity Forms, then activate this plugin.
3. Go to **Settings → GF Bulk Rename** to configure the Legacy counter and optional per-form rules.
4. Open **Forms → Entries** for a form. For the unchanged Legacy workflow, use native Bulk Download. To download chosen rows, check rows on the visible page, select **Download Flat ZIP with Rename** and click Apply. For filtered results across pages, tick **All matching Entries (Flat ZIP only; maximum 500)** beside Apply, select that same independent action and Apply. This option uses the current supported search/filters, not the checked rows.
5. For incremental downloads, click **Check NEW** in the separate **NEW — newly created Entries** panel. Review the Entry/file/file-free counts. If there are files, click **Download verified ZIP**, save it and independently check the saved file. Only after verifying receipt, tick the acknowledgment checkbox and click **Confirm receipt and advance NEW**. Downloading, HTTP success and browser hash verification do **not** acknowledge receipt or advance coverage. A new check with no NEW Entries needs no acknowledgment. For an eligible NEW batch with no files, review the file-free result and explicitly confirm it; there is no empty ZIP. NEW confirmations are bound to a short-lived (15-minute) operation; repeat Check NEW if it expires or files/status change.
6. To download the entire current form regardless of NEW history, click **Check ALL** in the separate **ALL — Download all eligible files** panel. Review its whole-form counts, then **Download ALL ZIP** and confirm. This is repeatable, includes all current active Entries with eligible files across pages, and ignores current search/filters and checked rows. ALL has a short-lived (10-minute) signed preview and never confirms NEW receipt, changes NEW coverage or resets its history. A file-free form produces no empty ZIP.

**Safety and hosting limitations:** Selected/all-matching Flat ZIP requests are limited to 500 Entries; NEW is limited to 500 eligible Entries per operation, 50,000 enumerated Entry identities, 1 MiB stored coverage and 128 MiB verified ZIP. NEW does not silently split oversized batches or mark undownloaded Entries covered. ALL has **no 500-Entry cutoff**, but is a single synchronous ZIP operation, not an unlimited-capacity promise. ALL checks available PHP memory, execution-time budget and temporary disk before writing; host/proxy timeouts, large files or disk exhaustion may still prevent completion. A blocked full-form operation fails rather than deliberately returning only the first 500. Local upload files, transactional Gravity Forms Entry storage (InnoDB), PHP ZipArchive and writable WordPress temporary storage are required; remote/offloaded uploads, external persistent object caches and untested hosts are not certified. ZIP contents are verified before response headers where supported; interrupted network delivery after streaming starts and actual retention on your device cannot be guaranteed.

**Privacy:** Values selected for Single Field / Composite naming appear in ZIP filenames. Do not choose personal or sensitive fields unless sharing those values with recipients is intended. Invalid or missing naming values stop the independent ZIP with an error.

== Changelog ==

= 2.3.2 =
* Make the WordPress Settings page more compact and beginner-friendly, with clear separate actions to load a form's settings, save Legacy settings, and save a per-form naming rule.
* Present the two practical download/naming decisions first; clarify normal naming status and add short, illustrative Legacy, Single Field and Composite filename examples in Persian and English.
* Improve the responsive Gravity Forms Entries guide and NEW/ALL panels with clearer first-use descriptions and balanced card sizing, while keeping the complete irreversible NEW receipt warning visible.
* Preserve all existing download scopes, original uploads/Entries, filename generation, ZIP verification, independent Settings persistence, NEW coverage/ACK and ALL isolation; extend authentic browser tests for RTL, keyboard use and a 14-field Composite form.

= 2.3.1 =
* Improve the WordPress Settings interface with clearer section hierarchy, tighter label/control alignment and responsive Single Field / Composite configuration.
* Align the Gravity Forms Entries guide and independent NEW / ALL cards with consistent headings, action placement, spacing and RTL/mobile layout.
* Add short English and Persian filename examples to explain Legacy counter, Single Field and ordered Composite naming to first-time operators.
* Preserve existing settings persistence, host Bulk Download, Selected / All matching, NEW with separate irreversible receipt acknowledgment, ALL, ZIP integrity and original uploaded files / Entries.

= 2.3.0 =
* Improve WordPress-native administration with direct Settings and Gravity Forms navigation, clear effective per-form naming status, and guidance for inherited Legacy defaults versus Single Field / Composite rules.
* Add contextual Entries help for Legacy, Selected, filtered all-matching, NEW with separate irreversible receipt acknowledgment, and independent ALL, including explanations of unchanged filenames and file-free Entries.
* Bundle complete Persian (`fa_IR`) gettext PO/MO translations for administration, download controls, statuses and errors, including Owner-approved NEW/ACK safety wording.
* Preserve existing naming, ZIP integrity, authorization, NEW coverage and ALL isolation behavior without modifying stored uploads or Gravity Forms Entries.

= 2.2.0 =
* Add creation-only **NEW** incremental flat ZIP download with separate explicit receipt acknowledgment and atomic confirmed coverage advancement; previously known Entries do not become NEW when files are added later.
* Add independent, repeatable **ALL** whole-form flat ZIP download, ignoring filters and NEW history, with signed preview and full-source verification; no 500-Entry truncation.
* Preserve native Legacy, manual Selected, filtered all-matching, per-form naming and original uploaded files/Entries. Fail visibly for unsafe, changed or unserviceable download scopes.

= 2.1.1 =
* Fix independent Flat ZIP selection across Gravity Forms Entry List pages with an explicit All matching Entries option for the current form, search and filters (maximum 500 Entries per request).
* Preserve manually checked current-page selection and the existing Legacy, Single Field, Composite and native Bulk Download workflows.
* Reject invalid or changed matching scopes and oversized requests before sending a partial ZIP; distinguish selected Entry count from ZIP file count.

= 2.1.0 =
* Add a separate, verified flat ZIP download with integrity checks and safe filename collision handling.
* Add per-form Legacy, Single Field and ordered Composite filename rules for the independent ZIP.
* Preserve the original Bulk Download Legacy workflow and original uploaded files/Entries.
* Verify clean uninstall, deactivation, rollback and independent download behavior in authentic WordPress CI.

= 2.0.6 =
* Removed all dependencies on `rgar()` and any helper functions. Direct array access is used for maximum compatibility.

= 2.0.5 =
* Added duplicate filename handling and opcache reset on activation.

= 2.0.4 =
* Fixed rgar() undefined function error.

= 2.0.3 =
* Fixed dependency check using BDFGF_VERSION.

= 2.0.2 =
* Fixed autoloader file naming.

= 2.0.1 =
* Fixed private const issue.

= 2.0.0 =
* Complete rewrite.
