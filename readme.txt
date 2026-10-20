=== Gravity Forms Bulk Rename Counter ===
Contributors: yourname
Tags: gravity forms, bulk download, rename files, counter
Requires at least: 5.8
Tested up to: 6.5
Stable tag: 2.0.6
Requires PHP: 8.0
License: GPL-2.0-or-later

Automatically renames uploaded files in Gravity Forms bulk downloads by prefixing a unique counter number with 'd'.

== Description ==

This plugin integrates with **Gravity Forms** and **Bulk Download for Gravity Forms** to rename files during bulk download.  
Each file will be renamed to `d[COUNTER_VALUE].extension`, where `[COUNTER_VALUE]` is taken from a specified field in your form.

**Features:**
- Settings page to select target form (optional) and counter field ID.
- Uses WordPress Settings API.
- Uses WordPress gettext for available interface messages; translations are not bundled.
- Requires Gravity Forms and Bulk Download for Gravity Forms; does not bundle them.
- Preserves the original Legacy filename hook and its existing duplicate handling.
- Provides a separate verified, flat ZIP download for selected entries on the current page only.
- Offers per-form Legacy / Single Field / Composite naming only for that separate ZIP.
- Removes only its own two settings options on explicit uninstall, not deactivation.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate Gravity Forms and Bulk Download for Gravity Forms, then activate this plugin.
3. Go to **Settings → GF Bulk Rename** to configure the Legacy counter and optional per-form rules.
4. Use native Bulk Download for the unchanged Legacy workflow, or **Download Flat ZIP with Rename** for the independent current-page workflow.

**Privacy:** Values selected for Single Field / Composite naming appear in ZIP filenames. Do not choose personal or sensitive fields unless sharing those values with recipients is intended. Invalid or missing naming values stop the independent ZIP with an error.

== Changelog ==

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
