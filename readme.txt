=== Bible ===
Contributors: bibleplugin
Tags: bible, verses, popup, lithuanian, references
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.6
License: GPLv2 or later

Automatically detects Bible verse references on your WordPress site and shows a popup with the verse text.

== Description ==

Bible plugin scans your site content for Bible references and makes them interactive with popups.

= Supported reference formats =

* `Pr 11,22` — Book chapter,verse
* `Pr 25,7-8` — Verse range
* `2 Kar 23,35-24,7` — Cross-chapter verse range
* `1 Sam 14` — Whole chapter
* `1 Samuelio knygos 4-6 skyriuose` — Chapter range
* `(2 Kar 15:27-31; 16:5-9)` — Continuation (same book)
* `2 Kar 23:34; 2 Met 36:4` — Separate references
* `Mal 3,1-6; 3,13-4,3` — Continuation with cross-chapter range
* `Teisėjų 3,14` — Full Lithuanian names

== Changelog ==

= 1.1.6 =
* Smaller downloads: the plugin's script and stylesheets are minified in the release package (the verse popup script is about 3 KB compressed instead of 6 KB).

= 1.1.5 =
* Performance: the verse popup script loads with `defer` (still in the footer, after its settings), so it no longer holds up the page.

= 1.1.4 =
* Hardening: the admin screens and the public verse lookup unslash and sanitize the request values they read, and the export links and alias screen output are escaped.
* The GitHub updater token is optional; it only raises the API rate limit.
* Declare the WordPress 6.2 and PHP 7.4 minimums in the plugin header (the database queries use the %i placeholder).

= 1.1.3 =
* Security: sanitize verse HTML, enforce admin permissions and validate uploads/settings.
* Preserve Bible data on failed imports using InnoDB transactions.
* Bound public queries and harden GitHub update downloads.
* Preserve custom aliases on reactivation.

= 1.1.2 =
* Added updates from GitHub Releases. Private repositories require a read-only GitHub token in wp-config.php.

= 1.1.0 =
* Cross-chapter verse ranges (e.g. 2 Kar 23,35-24,7)
* Fixed semicolon handling: new book after ; recognized correctly
* Fixed continuation cross-chapter ranges (e.g. Mal 3,1-6; 3,13-4,3)
* Redesigned patterns page with book grouping and sorting
* Updated default module

= 1.0.0 =
* Initial release
