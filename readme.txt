=== Gravity Forms Bulk Rename Counter ===
Tags: gravity forms, bulk download, rename files, counter
Requires at least: 5.8
Tested up to: 6.8
Stable tag: 2.1.0
Requires PHP: 8.0
License: GPL-2.0-or-later

Rename Gravity Forms downloads with legacy counters or per-form rules using a separate verified flat ZIP.

== Description ==

The original **Bulk Download for Gravity Forms** action keeps its existing `d[COUNTER_VALUE].extension` Legacy behavior. A separate **Download Flat ZIP with Rename** action creates a verified flat archive. You can select individual Entry rows on the visible page, or explicitly choose **All matching Entries (Flat ZIP only; maximum 500)** to include all Entries matching the current Gravity Forms form/search/filters across pages. This independent download supports per-form Legacy, Single Field and Composite naming rules. Original uploads and Entries are not modified.

**Features:**
- Settings page to select target form (optional) and counter field ID.
- Uses WordPress Settings API.
- Uses WordPress gettext for available interface messages; translations are not bundled.
- Requires Gravity Forms and Bulk Download for Gravity Forms; does not bundle them.
- Preserves the original Legacy filename hook and its existing duplicate handling.
- Provides a separate verified, flat ZIP download for selected rows on the current page or all matching Entries across pages (up to 500 Entries per request).
- Distinguishes selected Entry count from ZIP file count. Invalid scopes, missing uploads, changed result counts or requests exceeding the limit fail visibly before a ZIP is sent.
- Offers per-form Legacy / Single Field / Composite naming only for that separate ZIP.
- Removes only its own two settings options on explicit uninstall, not deactivation.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate Gravity Forms and Bulk Download for Gravity Forms, then activate this plugin.
3. Go to **Settings → GF Bulk Rename** to configure the Legacy counter and optional per-form rules.
4. Open a form's Gravity Forms Entries list. Use native Bulk Download for the unchanged Legacy workflow. For the separate **Download Flat ZIP with Rename** bulk action, either check the individual rows to download on the visible page, or tick **All matching Entries (Flat ZIP only; maximum 500)** next to Apply to include the current search/filter matches across all pages. Then choose the independent action and Apply; the all-matching option does not narrow to checked rows.

**Privacy:** Values selected for Single Field / Composite naming appear in ZIP filenames. Do not choose personal or sensitive fields unless sharing those values with recipients is intended. Invalid or missing naming values stop the independent ZIP with an error.

== Changelog ==

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
