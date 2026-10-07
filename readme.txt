=== Media Janitor ===
Contributors: wisnuub
Tags: media, unused media, cleanup, duplicate images, media library
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find unused and duplicate media, see exactly where every file is used, and clean up your library with confidence.

== Description ==

Media Janitor scans your whole site to work out which media files are actually used — and **where**. Instead of a bare "unused" label, every file shows the pages, products, widgets and settings that reference it, with a **Find on page** button that opens the page, scrolls to the image and highlights it.

**Please back up your site before deleting anything. Deleted files cannot be recovered.**

= What it scans =

* Post, page and product content and excerpts (any post type), including block attributes, `wp-image-123` classes and gallery shortcodes
* Featured images and WooCommerce product galleries
* Custom fields, including ACF image and gallery fields
* Elementor page data
* WooCommerce product category thumbnails and other term images
* Widgets (classic and block), menus, Customizer settings, site logo and site icon
* Block theme templates, template parts and global styles
* Additional CSS
* User profile images

= Safety first =

* Scans run in small steps, so large libraries never time out — and deleting stays disabled until a scan has fully finished.
* Files the scan found in use are never deleted by "Delete All Unused".
* Right before deleting, Media Janitor re-checks content edited since the scan, so an image you just added to a page is skipped.
* Every delete asks for confirmation.

= Duplicate finder =

The Duplicates tab finds:

* **Exact duplicates** — byte-identical files uploaded more than once
* **Scale variants** — `icon.png`, `icon@2x.png`, `icon-3x.png`
* **Visual duplicates** — the same picture in a different size, format or with small edits

Each file shows whether it is used, so you can keep the one your pages rely on.

= What it can't see =

Files referenced only from theme or plugin PHP code, hard-coded in theme CSS files, or linked from other websites are not detected. Check the "Find on page" result before deleting anything you are unsure about.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/media-janitor/`, or install it from **Plugins → Add New**.
2. Activate it.
3. Go to **Media → Media Janitor** and click **Scan Media Library**.

== Frequently Asked Questions ==

= Is it safe? =

Media Janitor only deletes files you select, after confirming, and only after a complete scan. It cannot see every possible reference (see "What it can't see"), so always keep a backup.

= Does it work with page builders? =

Elementor is scanned directly. Gutenberg, Classic Editor, WPBakery and Divi content is read from the post content, which covers image URLs and the image IDs those builders store.

= Will it slow down my site? =

No. Scanning only happens when you click Scan in the admin. Nothing runs on the front end except the "Find on page" highlighter, and only for logged-in administrators who opened a page from Media Janitor.

= What happens to my data if I uninstall? =

Uninstalling removes Media Janitor's own table and settings. Your media files are not touched.

== Screenshots ==

1. Summary and media grid filtered to unused files
2. "Used in" details with Find on page
3. A file highlighted on the front end
4. Duplicate finder

== Changelog ==

= 1.1.0 =
* Scans now run in resumable steps with real progress, so large sites no longer time out.
* Deleting is disabled until a scan fully completes, and files found in use are skipped unless you confirm.
* Re-checks content edited since the scan right before deleting.
* Detects images referenced by ID: gallery shortcodes, image/gallery/cover blocks, ACF galleries, WPBakery, block-theme site logo, WooCommerce category thumbnails and placeholder.
* Scans product short descriptions, templates, template parts, global styles, term meta and user meta.
* Duplicate scan runs in steps and caches file hashes.
* "Space recoverable" now includes thumbnails.
* Summary figures load correctly when returning to the page.
* Selections reset when switching tabs, filters or searching, so hidden files can't be deleted by accident.
* Escape closes the details window; all interface text is translatable.
* Works on PHP 7.4.

= 1.0 =
* Initial release.
