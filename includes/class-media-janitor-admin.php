<?php
/**
 * Media Janitor — Admin UI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Media_Janitor_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_filter( 'plugin_action_links_' . MEDIA_JANITOR_BASENAME, array( $this, 'action_links' ) );
    }

    /**
     * Register admin menu page.
     */
    public function add_menu(): void {
        add_media_page(
            __( 'Media Janitor', 'media-janitor' ),
            __( 'Media Janitor', 'media-janitor' ),
            'manage_options',
            'media-janitor',
            array( $this, 'render_page' )
        );
    }

    /**
     * Add "Scan Media" link on the Plugins page.
     */
    public function action_links( array $links ): array {
        $url  = admin_url( 'upload.php?page=media-janitor' );
        $link = '<a href="' . esc_url( $url ) . '">' . __( 'Scan Media', 'media-janitor' ) . '</a>';
        array_unshift( $links, $link );
        return $links;
    }

    /**
     * Enqueue admin CSS & JS on our page only.
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'media_page_media-janitor' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'media-janitor-admin',
            MEDIA_JANITOR_URL . 'assets/css/admin.css',
            array(),
            MEDIA_JANITOR_VERSION
        );

        wp_enqueue_script(
            'media-janitor-admin',
            MEDIA_JANITOR_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            MEDIA_JANITOR_VERSION,
            true
        );

        $state     = Media_Janitor_Scanner::get_state();
        $last_scan = (int) get_option( 'media_janitor_last_scan', 0 );
        $new_since = 0;
        if ( $last_scan > 0 ) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off count shown on the admin page.
            $new_since = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = 'attachment' AND post_status = 'inherit'
                 AND post_date_gmt > %s",
                gmdate( 'Y-m-d H:i:s', $last_scan )
            ) );
        }

        $data = array(
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'media_janitor' ),
            'scanStatus'       => $state['status'],
            'lastScan'         => $last_scan,
            'newSinceLastScan' => $new_since,
            'i18n'             => array(
                'scanning'         => __( 'Scanning…', 'media-janitor' ),
                'scanStep'         => array(
                    'content' => __( 'Scanning posts and pages…', 'media-janitor' ),
                    'meta'    => __( 'Scanning custom fields and page builders…', 'media-janitor' ),
                    'misc'    => __( 'Scanning widgets, menus and settings…', 'media-janitor' ),
                ),
                'scanComplete'     => __( 'Scan complete!', 'media-janitor' ),
                'scanFailed'       => __( 'The scan stopped before finishing. Results are incomplete, so deleting is disabled until a full scan completes.', 'media-janitor' ),
                'scanIncomplete'   => __( 'The last scan did not finish. Results are incomplete, so deleting is disabled.', 'media-janitor' ),
                'rescan'           => __( 'Rescan now', 'media-janitor' ),
                /* translators: %s: date and time */
                'lastScan'         => __( 'Last scan: %s', 'media-janitor' ),
                /* translators: %d: number of files */
                'newUploads'       => __( '%d file(s) uploaded since the last scan — rescan to include them.', 'media-janitor' ),
                /* translators: %d: number of files */
                'confirmDelete'    => __( 'Permanently delete %d selected file(s)? This cannot be undone. Make sure you have a backup.', 'media-janitor' ),
                /* translators: 1: number of files, 2: category name */
                'confirmAll'       => __( 'Permanently delete all %1$d unused %2$s? This cannot be undone. Make sure you have a backup.', 'media-janitor' ),
                /* translators: %d: number of files */
                'confirmUsed'      => __( '%d of the selected files are still in use. Deleting them will break the pages that show them. Delete anyway?', 'media-janitor' ),
                'deleting'         => __( 'Deleting…', 'media-janitor' ),
                /* translators: 1: done, 2: total */
                'deletingProgress' => __( 'Deleting %1$d / %2$d…', 'media-janitor' ),
                /* translators: %d: number of files */
                'deleted'          => __( '%d file(s) deleted.', 'media-janitor' ),
                /* translators: %d: number of files */
                'skipped'          => __( '%d file(s) were skipped because they are in use or were just added to content. Rescan to refresh.', 'media-janitor' ),
                'deleteSelected'   => __( 'Delete Selected', 'media-janitor' ),
                'deleteAllUnused'  => __( 'Delete All Unused', 'media-janitor' ),
                'categories'       => array(
                    'all'      => __( 'files', 'media-janitor' ),
                    'image'    => __( 'images', 'media-janitor' ),
                    'document' => __( 'documents', 'media-janitor' ),
                    'video'    => __( 'videos', 'media-janitor' ),
                    'audio'    => __( 'audio files', 'media-janitor' ),
                ),
                'noUnused'         => __( 'No unused media found. Your library is clean!', 'media-janitor' ),
                'noUsed'           => __( 'No media in use found in this category.', 'media-janitor' ),
                'noMatch'          => __( 'No files match your search.', 'media-janitor' ),
                'noFiles'          => __( 'No files in this category.', 'media-janitor' ),
                'error'            => __( 'An error occurred. Please try again.', 'media-janitor' ),
                'used'             => __( 'Used', 'media-janitor' ),
                'unused'           => __( 'Unused', 'media-janitor' ),
                /* translators: %d: number of references (always 1) */
                'reference'        => __( '%d reference', 'media-janitor' ),
                /* translators: %d: number of references */
                'references'       => __( '%d references', 'media-janitor' ),
                'notUsedAnywhere'  => __( 'This file is not used anywhere the scanner can see. Files referenced only from theme code, hard-coded CSS or external sites are not detected.', 'media-janitor' ),
                'findOnPage'       => __( 'Find on page', 'media-janitor' ),
                'findOnPageTitle'  => __( 'Open the page and scroll to this file', 'media-janitor' ),
                /* translators: %d: number of items */
                'items'            => __( '%d items', 'media-janitor' ),
                /* translators: 1: done, 2: total */
                'hashing'          => __( 'Checking files %1$d / %2$d…', 'media-janitor' ),
                'dupScanComplete'  => __( 'Duplicate scan complete!', 'media-janitor' ),
                'dupNoneFound'     => __( 'None found.', 'media-janitor' ),
                'visualSkipped'    => __( 'Visual matching was skipped because the library has too many images to compare in one go.', 'media-janitor' ),
                /* translators: %d: group number */
                'group'            => __( 'Group %d', 'media-janitor' ),
                /* translators: %d: number of files */
                'files'            => __( '%d files', 'media-janitor' ),
                /* translators: %s: custom field name */
                'customField'      => __( 'Custom field: %s', 'media-janitor' ),
                'sourceTypes'      => array(
                    'page'             => __( 'Page', 'media-janitor' ),
                    'post'             => __( 'Post', 'media-janitor' ),
                    'product'          => __( 'Product', 'media-janitor' ),
                    'featured_image'   => __( 'Featured Image', 'media-janitor' ),
                    'woo_gallery'      => __( 'Product Gallery', 'media-janitor' ),
                    'widget'           => __( 'Widget', 'media-janitor' ),
                    'theme_mod'        => __( 'Customizer', 'media-janitor' ),
                    'option'           => __( 'Site Option', 'media-janitor' ),
                    'nav_menu'         => __( 'Menu', 'media-janitor' ),
                    'elementor'        => __( 'Elementor', 'media-janitor' ),
                    'custom_css'       => __( 'Custom CSS', 'media-janitor' ),
                    'term'             => __( 'Category / Term', 'media-janitor' ),
                    'user'             => __( 'User Profile', 'media-janitor' ),
                    'wp_block'         => __( 'Synced Pattern', 'media-janitor' ),
                    'wp_template'      => __( 'Template', 'media-janitor' ),
                    'wp_template_part' => __( 'Template Part', 'media-janitor' ),
                    'wp_global_styles' => __( 'Global Styles', 'media-janitor' ),
                    'wp_navigation'    => __( 'Navigation', 'media-janitor' ),
                ),
            ),
        );

        /**
         * Filters the data passed to the Media Janitor admin script (strings, settings).
         *
         * @param array $data Localized data.
         */
        wp_localize_script( 'media-janitor-admin', 'mediaJanitor', apply_filters( 'media_janitor_admin_data', $data ) );

        /**
         * Fires after Media Janitor's admin assets are enqueued, so add-ons can add their own.
         */
        do_action( 'media_janitor_enqueue_assets' );
    }

    /**
     * Render the main admin page.
     */
    public function render_page(): void {
        ?>
        <div class="wrap mj-wrap">

            <!-- Banner -->
            <div class="mj-banner">
                <div class="mj-banner-inner">
                    <span class="mj-banner-icon">🧹</span>
                    <div>
                        <div class="mj-banner-title"><?php esc_html_e( 'Media Janitor', 'media-janitor' ); ?></div>
                        <div class="mj-banner-sub"><?php esc_html_e( 'Scan, audit, and clean up unused media files from your library', 'media-janitor' ); ?></div>
                    </div>
                    <span class="mj-version-badge">v<?php echo esc_html( MEDIA_JANITOR_VERSION ); ?></span>
                </div>
            </div>

            <div class="mj-backup-note">
                <span class="dashicons dashicons-warning"></span>
                <span>
                    <?php esc_html_e( 'Deleted files cannot be recovered. Back up your site before deleting anything.', 'media-janitor' ); ?>
                    <?php esc_html_e( 'Want an undo? Media Janitor Pro keeps removed files for 30 days so you can restore them.', 'media-janitor' ); ?>
                    <a href="<?php echo esc_url( MEDIA_JANITOR_PRO_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get Pro', 'media-janitor' ); ?></a>
                </span>
            </div>

            <div id="mj-notices"></div>

            <!-- Summary Cards -->
            <div id="mj-summary" class="mj-summary" style="display:none;">
                <div class="mj-card mj-card--total">
                    <div class="mj-card__number" id="mj-total">—</div>
                    <div class="mj-card__label"><?php esc_html_e( 'Total Media', 'media-janitor' ); ?></div>
                </div>
                <div class="mj-card mj-card--used">
                    <div class="mj-card__number" id="mj-used">—</div>
                    <div class="mj-card__label"><?php esc_html_e( 'Used', 'media-janitor' ); ?></div>
                </div>
                <div class="mj-card mj-card--unused">
                    <div class="mj-card__number" id="mj-unused">—</div>
                    <div class="mj-card__label"><?php esc_html_e( 'Unused', 'media-janitor' ); ?></div>
                </div>
                <div class="mj-card mj-card--size">
                    <div class="mj-card__number" id="mj-size">—</div>
                    <div class="mj-card__label"><?php esc_html_e( 'Space Recoverable', 'media-janitor' ); ?></div>
                </div>
            </div>

            <!-- Scan button area -->
            <div class="mj-actions">
                <button id="mj-scan-btn" class="button button-primary button-hero">
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e( 'Scan Media Library', 'media-janitor' ); ?>
                </button>
                <span id="mj-scan-status" class="mj-scan-status"></span>
                <span id="mj-last-scan" class="mj-last-scan"></span>
            </div>

            <!-- Progress bar -->
            <div id="mj-progress" class="mj-progress" style="display:none;">
                <div class="mj-progress__bar">
                    <div class="mj-progress__fill" id="mj-progress-fill"></div>
                </div>
                <div class="mj-progress__text" id="mj-progress-text"></div>
            </div>

            <!-- Results area -->
            <div id="mj-results" style="display:none;">

                <!-- Category tabs -->
                <div class="mj-tabs">
                    <button class="mj-tab mj-tab--active" data-type="all">
                        <?php esc_html_e( 'All', 'media-janitor' ); ?>
                        <span class="mj-tab__count" id="mj-count-all"></span>
                    </button>
                    <button class="mj-tab" data-type="image">
                        <span class="dashicons dashicons-format-image"></span>
                        <?php esc_html_e( 'Images', 'media-janitor' ); ?>
                        <span class="mj-tab__count" id="mj-count-image"></span>
                    </button>
                    <button class="mj-tab" data-type="document">
                        <span class="dashicons dashicons-media-document"></span>
                        <?php esc_html_e( 'Documents', 'media-janitor' ); ?>
                        <span class="mj-tab__count" id="mj-count-document"></span>
                    </button>
                    <button class="mj-tab" data-type="video">
                        <span class="dashicons dashicons-video-alt3"></span>
                        <?php esc_html_e( 'Videos', 'media-janitor' ); ?>
                        <span class="mj-tab__count" id="mj-count-video"></span>
                    </button>
                    <button class="mj-tab" data-type="audio">
                        <span class="dashicons dashicons-format-audio"></span>
                        <?php esc_html_e( 'Audio', 'media-janitor' ); ?>
                        <span class="mj-tab__count" id="mj-count-audio"></span>
                    </button>
                    <button class="mj-tab mj-tab--duplicates" data-type="duplicates">
                        <span class="dashicons dashicons-images-alt2"></span>
                        <?php esc_html_e( 'Duplicates', 'media-janitor' ); ?>
                        <span class="mj-tab__count" id="mj-count-duplicates"></span>
                    </button>
                </div>

                <!-- Filters row -->
                <div class="mj-filters">
                    <div class="mj-filters__left">
                        <select id="mj-filter-status" class="mj-select">
                            <option value="all"><?php esc_html_e( 'All Status', 'media-janitor' ); ?></option>
                            <option value="used"><?php esc_html_e( 'Used', 'media-janitor' ); ?></option>
                            <option value="unused" selected><?php esc_html_e( 'Unused', 'media-janitor' ); ?></option>
                        </select>
                        <input type="search" id="mj-search" class="mj-search"
                               placeholder="<?php esc_attr_e( 'Search by filename…', 'media-janitor' ); ?>">
                    </div>
                    <div class="mj-filters__right">
                        <button id="mj-select-all" class="button"><?php esc_html_e( 'Select All', 'media-janitor' ); ?></button>
                        <button id="mj-delete-selected" class="button button-link-delete" disabled>
                            <span class="dashicons dashicons-trash" style="margin-top:4px;"></span>
                            <?php esc_html_e( 'Delete Selected', 'media-janitor' ); ?>
                        </button>
                        <button id="mj-delete-all-unused" class="button button-link-delete">
                            <?php esc_html_e( 'Delete All Unused', 'media-janitor' ); ?>
                        </button>
                    </div>
                </div>

                <!-- Duplicates pane (visible only when Duplicates tab is active) -->
                <div id="mj-duplicates-pane" style="display:none;">

                    <div class="mj-dup-actions">
                        <button id="mj-scan-dup-btn" class="button button-primary">
                            <span class="dashicons dashicons-images-alt2"></span>
                            <?php esc_html_e( 'Scan for Duplicates', 'media-janitor' ); ?>
                        </button>
                        <span id="mj-dup-status" class="mj-scan-status"></span>
                    </div>

                    <div id="mj-dup-loading" style="display:none;padding:20px 0;font-size:14px;color:#646970;">
                        <span class="spinner is-active" style="float:none;margin:0 8px 0 0;vertical-align:middle;"></span>
                        <span id="mj-dup-loading-text"><?php esc_html_e( 'Scanning…', 'media-janitor' ); ?></span>
                    </div>

                    <div id="mj-dup-not-scanned" class="mj-empty" style="display:none;">
                        <span class="dashicons dashicons-images-alt2"></span>
                        <p><?php esc_html_e( 'No duplicate scan run yet. Click "Scan for Duplicates" to begin.', 'media-janitor' ); ?></p>
                    </div>

                    <div id="mj-dup-results" style="display:none;">

                        <div class="mj-dup-section">
                            <div class="mj-dup-section__head">
                                <h3><?php esc_html_e( 'Exact Duplicates', 'media-janitor' ); ?></h3>
                                <span class="mj-tab__count" id="mj-dup-exact-count">0</span>
                            </div>
                            <p class="mj-dup-section__desc"><?php esc_html_e( 'Byte-identical files — same content uploaded more than once.', 'media-janitor' ); ?></p>
                            <div id="mj-dup-exact-groups"></div>
                        </div>

                        <div class="mj-dup-section">
                            <div class="mj-dup-section__head">
                                <h3><?php esc_html_e( 'Scale / Name Variants', 'media-janitor' ); ?></h3>
                                <span class="mj-tab__count" id="mj-dup-scale-count">0</span>
                            </div>
                            <p class="mj-dup-section__desc"><?php esc_html_e( 'Same base filename after stripping @2x / @3x / -2x suffixes — likely Figma scale exports.', 'media-janitor' ); ?></p>
                            <div id="mj-dup-scale-groups"></div>
                        </div>

                        <div class="mj-dup-section">
                            <div class="mj-dup-section__head">
                                <h3><?php esc_html_e( 'Visual Duplicates', 'media-janitor' ); ?></h3>
                                <span class="mj-tab__count" id="mj-dup-visual-count">0</span>
                            </div>
                            <p class="mj-dup-section__desc"><?php esc_html_e( 'Visually similar images regardless of filename — different format, resolution, or slight edits.', 'media-janitor' ); ?></p>
                            <p id="mj-dup-visual-skipped" class="mj-dup-section__desc" style="display:none;"></p>
                            <div id="mj-dup-visual-groups"></div>
                        </div>

                        <div class="mj-dup-footer">
                            <button id="mj-dup-delete-selected" class="button button-link-delete" disabled>
                                <span class="dashicons dashicons-trash" style="margin-top:4px;"></span>
                                <?php esc_html_e( 'Delete Selected', 'media-janitor' ); ?>
                            </button>
                        </div>

                    </div><!-- #mj-dup-results -->
                </div><!-- #mj-duplicates-pane -->

                <!-- Media grid -->
                <div id="mj-grid" class="mj-grid"></div>

                <!-- Empty / loading state (rendered outside the grid) -->
                <div id="mj-empty-state" class="mj-empty" style="display:none;"></div>

                <!-- Pagination -->
                <div id="mj-pagination" class="mj-pagination"></div>
            </div>

            <!-- Usage detail modal -->
            <div id="mj-modal" class="mj-modal" style="display:none;">
                <div class="mj-modal__overlay"></div>
                <div class="mj-modal__content" role="dialog" aria-modal="true" aria-labelledby="mj-modal-title">
                    <button type="button" class="mj-modal__close" aria-label="<?php esc_attr_e( 'Close', 'media-janitor' ); ?>">&times;</button>
                    <div class="mj-modal__header">
                        <div class="mj-modal__thumb" id="mj-modal-thumb"></div>
                        <div class="mj-modal__info">
                            <h2 id="mj-modal-title"></h2>
                            <p id="mj-modal-meta"></p>
                        </div>
                    </div>
                    <div class="mj-modal__body">
                        <h3><?php esc_html_e( 'Used In', 'media-janitor' ); ?></h3>
                        <ul id="mj-modal-usage" class="mj-usage-list"></ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
