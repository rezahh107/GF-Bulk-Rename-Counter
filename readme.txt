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
- Fully translation-ready.
- No external dependencies, pure direct array access.
- Handles duplicate filenames inside the same ZIP.
- Clean uninstall.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin.
3. Go to **Settings → GF Bulk Rename** and configure.
4. Use bulk download in Gravity Forms entries.

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
