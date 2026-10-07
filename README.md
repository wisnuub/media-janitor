# Media Janitor

> **v1.1.0** — Find and safely remove unused media files from your WordPress site.

> ⚠️ **PLEASE BACK UP YOUR SITE BEFORE USING THIS PLUGIN.** Deleted media files cannot be recovered.

A free, lightweight WordPress plugin that scans your entire site to identify which media files are actually used — and where — so you can confidently clean up the unused ones without breaking anything.

## How It Works

Instead of blindly flagging media as "unused", the plugin:

1. Scans every content source (posts, excerpts, custom fields, page builders, terms, users, widgets, theme settings, options) to build a complete usage map. Large sites are scanned in resumable steps, so it never times out.
2. Records **exactly where** each media file is referenced — with clickable links.
3. Lets you click **"Find on page"** to open the actual page and auto-scroll + highlight the media element, so you can visually confirm before deleting.

## Features

- Scans post/page content (all post types)
- Scans post meta & custom fields
- Scans featured images
- Scans WooCommerce product galleries
- Scans widgets
- Scans Customizer / theme mods (site logo, site icon, etc.)
- Scans options table
- Scans navigation menu items (PDF links, etc.)
- Scans Elementor page builder data
- Scans Additional CSS (background images)
- Categorized view: Images, Documents, Videos, Audio
- Filter by Used / Unused status
- Search by filename
- "Find on page" — opens the page and scrolls to the exact media element with a pulsing highlight
- Navigation arrows when multiple matches exist on a page
- Summary dashboard with total, used, unused counts & recoverable space
- Bulk delete selected or all unused media
- Clean admin UI under **Media → Media Janitor**

## Requirements

- WordPress 5.8+
- PHP 7.4+

## Installation

1. Clone or download this repo
2. Upload the folder to `/wp-content/plugins/`
3. Activate in **Plugins**
4. Go to **Media → Media Janitor** and hit **Scan Media Library**

## Changelog

### v1.1.0
- Resumable, time-boxed scans with real progress (about 11× faster on a 5,000-image test library)
- Deleting is disabled until a scan fully completes; files in use are skipped unless confirmed
- Re-checks content edited since the scan right before deleting
- Detects ID-only references: gallery shortcodes, image/gallery/cover blocks, ACF galleries, WPBakery, block-theme logo, WooCommerce category thumbnails and placeholder
- Scans product short descriptions, templates, template parts, global styles, term meta and user meta
- Duplicate scan runs in steps and caches hashes
- Summary loads on revisit, selections reset on filter changes, Escape closes the modal, all UI text translatable
- PHP 7.4 compatible; WordPress.org-ready (readme.txt, uninstall cleanup, unique prefixes)

### v1.0
- Initial release
- Full media usage scanner (10 content sources)
- Categorized media grid with filters & search
- Usage detail modal with "Find on page" highlighting
- Bulk & selective deletion
- Summary dashboard with space recovery estimate

## Roadmap

- [x] Scan posts, meta, widgets, theme mods, menus, Elementor, custom CSS
- [x] "Find on page" with scroll & highlight
- [x] Categorized view (images, documents, videos, audio)
- [ ] Beaver Builder / Divi / WPBakery support
- [x] Scan Gutenberg block attributes (reusable blocks)
- [ ] Scheduled automatic scans

## License

GPL-2.0+
