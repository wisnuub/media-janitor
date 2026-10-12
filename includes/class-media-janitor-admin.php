<?php
/**
 * Wisnoob Media Cleanup — Admin UI
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
            __( 'Wisnoob Media Cleanup', 'wisnoob-media-cleanup' ),
            __( 'Wisnoob Media Cleanup', 'wisnoob-media-cleanup' ),
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
        $link = '<a href="' . esc_url( $url ) . '">' . __( 'Scan Media', 'wisnoob-media-cleanup' ) . '</a>';
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
                'scanning'         => __( 'Scanning…', 'wisnoob-media-cleanup' ),
                'scanStep'         => array(
                    'content' => __( 'Scanning posts and pages…', 'wisnoob-media-cleanup' ),
                    'meta'    => __( 'Scanning custom fields and page builders…', 'wisnoob-media-cleanup' ),
                    'misc'    => __( 'Scanning widgets, menus and settings…', 'wisnoob-media-cleanup' ),
                ),
                'scanComplete'     => __( 'Scan complete!', 'wisnoob-media-cleanup' ),
                'scanFailed'       => __( 'The scan stopped before finishing. Results are incomplete, so deleting is disabled until a full scan completes.', 'wisnoob-media-cleanup' ),
                'scanIncomplete'   => __( 'The last scan did not finish. Results are incomplete, so deleting is disabled.', 'wisnoob-media-cleanup' ),
                'rescan'           => __( 'Rescan now', 'wisnoob-media-cleanup' ),
                /* translators: %s: date and time */
                'lastScan'         => __( 'Last scan: %s', 'wisnoob-media-cleanup' ),
                /* translators: %d: number of files */
                'newUploads'       => __( '%d file(s) uploaded since the last scan — rescan to include them.', 'wisnoob-media-cleanup' ),
                /* translators: %d: number of files */
                'confirmDelete'    => __( 'Permanently delete %d selected file(s)? This cannot be undone. Make sure you have a backup.', 'wisnoob-media-cleanup' ),
                /* translators: 1: number of files, 2: category name */
                'confirmAll'       => __( 'Permanently delete all %1$d unused %2$s? This cannot be undone. Make sure you have a backup.', 'wisnoob-media-cleanup' ),
                /* translators: %d: number of files */
                'confirmUsed'      => __( '%d of the selected files are still in use. Deleting them will break the pages that show them. Delete anyway?', 'wisnoob-media-cleanup' ),
                'deleting'         => __( 'Deleting…', 'wisnoob-media-cleanup' ),
                /* translators: 1: done, 2: total */
                'deletingProgress' => __( 'Deleting %1$d / %2$d…', 'wisnoob-media-cleanup' ),
                /* translators: %d: number of files */
                'deleted'          => __( '%d file(s) deleted.', 'wisnoob-media-cleanup' ),
                /* translators: %d: number of files */
                'skipped'          => __( '%d file(s) were skipped because they are in use or were just added to content. Rescan to refresh.', 'wisnoob-media-cleanup' ),
                'deleteSelected'   => __( 'Delete Selected', 'wisnoob-media-cleanup' ),
                'deleteAllUnused'  => __( 'Delete All Unused', 'wisnoob-media-cleanup' ),
                'categories'       => array(
                    'all'      => __( 'files', 'wisnoob-media-cleanup' ),
                    'image'    => __( 'images', 'wisnoob-media-cleanup' ),
                    'document' => __( 'documents', 'wisnoob-media-cleanup' ),
                    'video'    => __( 'videos', 'wisnoob-media-cleanup' ),
                    'audio'    => __( 'audio files', 'wisnoob-media-cleanup' ),
                ),
                'noUnused'         => __( 'No unused media found. Your library is clean!', 'wisnoob-media-cleanup' ),
                'noUsed'           => __( 'No media in use found in this category.', 'wisnoob-media-cleanup' ),
                'noMatch'          => __( 'No files match your search.', 'wisnoob-media-cleanup' ),
                'noFiles'          => __( 'No files in this category.', 'wisnoob-media-cleanup' ),
                'error'            => __( 'An error occurred. Please try again.', 'wisnoob-media-cleanup' ),
                'used'             => __( 'Used', 'wisnoob-media-cleanup' ),
                'unused'           => __( 'Unused', 'wisnoob-media-cleanup' ),
                /* translators: %d: number of references (always 1) */
                'reference'        => __( '%d reference', 'wisnoob-media-cleanup' ),
                /* translators: %d: number of references */
                'references'       => __( '%d references', 'wisnoob-media-cleanup' ),
                'notUsedAnywhere'  => __( 'This file is not used anywhere the scanner can see. Files referenced only from theme code, hard-coded CSS or external sites are not detected.', 'wisnoob-media-cleanup' ),
                'findOnPage'       => __( 'Find on page', 'wisnoob-media-cleanup' ),
                'findOnPageTitle'  => __( 'Open the page and scroll to this file', 'wisnoob-media-cleanup' ),
                /* translators: %d: number of items */
                'items'            => __( '%d items', 'wisnoob-media-cleanup' ),
                /* translators: 1: done, 2: total */
                'hashing'          => __( 'Checking files %1$d / %2$d…', 'wisnoob-media-cleanup' ),
                'dupScanComplete'  => __( 'Duplicate scan complete!', 'wisnoob-media-cleanup' ),
                'dupNoneFound'     => __( 'None found.', 'wisnoob-media-cleanup' ),
                'visualSkipped'    => __( 'Visual matching was skipped because the library has too many images to compare in one go.', 'wisnoob-media-cleanup' ),
                /* translators: %d: group number */
                'group'            => __( 'Group %d', 'wisnoob-media-cleanup' ),
                /* translators: %d: number of files */
                'files'            => __( '%d files', 'wisnoob-media-cleanup' ),
                /* translators: %s: custom field name */
                'customField'      => __( 'Custom field: %s', 'wisnoob-media-cleanup' ),
                'sourceTypes'      => array(
                    'page'             => __( 'Page', 'wisnoob-media-cleanup' ),
                    'post'             => __( 'Post', 'wisnoob-media-cleanup' ),
                    'product'          => __( 'Product', 'wisnoob-media-cleanup' ),
                    'featured_image'   => __( 'Featured Image', 'wisnoob-media-cleanup' ),
                    'woo_gallery'      => __( 'Product Gallery', 'wisnoob-media-cleanup' ),
                    'widget'           => __( 'Widget', 'wisnoob-media-cleanup' ),
                    'theme_mod'        => __( 'Customizer', 'wisnoob-media-cleanup' ),
                    'option'           => __( 'Site Option', 'wisnoob-media-cleanup' ),
                    'nav_menu'         => __( 'Menu', 'wisnoob-media-cleanup' ),
                    'elementor'        => __( 'Elementor', 'wisnoob-media-cleanup' ),
                    'custom_css'       => __( 'Custom CSS', 'wisnoob-media-cleanup' ),
                    'term'             => __( 'Category / Term', 'wisnoob-media-cleanup' ),
                    'user'             => __( 'User Profile', 'wisnoob-media-cleanup' ),
                    'wp_block'         => __( 'Synced Pattern', 'wisnoob-media-cleanup' ),
                    'wp_template'      => __( 'Template', 'wisnoob-media-cleanup' ),
                    'wp_template_part' => __( 'Template Part', 'wisnoob-media-cleanup' ),
                    'wp_global_styles' => __( 'Global Styles', 'wisnoob-media-cleanup' ),
                    'wp_navigation'    => __( 'Navigation', 'wisnoob-media-cleanup' ),
                ),
            ),
        );

        /**
         * Filters the data passed to the Wisnoob Media Cleanup admin script (strings, settings).
         *
         * @param array $data Localized data.
         */
        wp_localize_script( 'media-janitor-admin', 'mediaJanitor', apply_filters( 'media_janitor_admin_data', $data ) );

        /**
         * Fires after Wisnoob Media Cleanup's admin assets are enqueued, so add-ons can add their own.
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
                        <div class="mj-banner-title"><?php esc_html_e( 'Wisnoob Media Cleanup', 'wisnoob-media-cleanup' ); ?></div>
                        <div class="mj-banner-sub"><?php esc_html_e( 'Scan, audit, and clean up unused media files from your library', 'wisnoob-media-cleanup' ); ?></div>
                    </div>
                    <span class="mj-version-badge">v<?php echo esc_html( MEDIA_JANITOR_VERSION ); ?></span>
                </div>
            </div>

            <div class="mj-backup-note">
                <span class="dashicons dashicons-warning"></span>
                <span>
                    <?php esc_html_e( 'Deleted files cannot be recovered. Back up your site before deleting anything.', 'wisnoob-media-cleanup' ); ?>
                    <?php esc_html_e( 'Want an undo? Wisnoob Media Cleanup Pro keeps removed files for 30 days so you can restore them.', 'wisnoob-media-cleanup' ); ?>
                    <a href="<?php echo esc_url( MEDIA_JANITOR_PRO_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get Pro', 'wisnoob-media-cleanup' ); ?></a>
                </span>
            </div>

            <div id="mj-notices"></div>

            <!-- Summary Cards -->
            <div id="mj-summary" class="mj-summary" style="display:none;">
                <div class="mj-card mj-card--total">
                    <div class="mj-card__number" id="mj-total">—</div>
                    <div class="mj-card__label"><?php esc_html_e( 'Total Media', 'wisnoob-media-cleanup' ); ?></div>
                </div>
                <div class="mj-card mj-card--used">
                    <div class="mj-card__number" id="mj-used">—</div>
                    <div class="mj-card__label"><?php esc_html_e( 'Used', 'wisnoob-media-cleanup' ); ?></div>
                </div>
                <div class="mj-card mj-card--unused">
                    <div class="mj-card__number" id="mj-unused">—</div>
                    <div class="mj-card__label"><?php esc_html_e( 'Unused', 'wisnoob-media-cleanup' ); ?></div>
                </div>
                <div class="mj-card mj-card--size">
                    <div class="mj-card__number" id="mj-size">—</div>
                    <div class="mj-card__label"><?php esc_html_e( 'Space Recoverable', 'wisnoob-media-cleanup' ); ?></div>
                </div>
            </div>

            <!-- Scan button area -->
            <div class="mj-actions">
                <button id="mj-scan-btn" class="button button-primary button-hero">
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e( 'Scan Media Library', 'wisnoob-media-cleanup' ); ?>
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
                        <?php esc_html_e( 'All', 'wisnoob-media-cleanup' ); ?>
                        <span class="mj-tab__count" id="mj-count-all"></span>
                    </button>
                    <button class="mj-tab" data-type="image">
                        <span class="dashicons dashicons-format-image"></span>
                        <?php esc_html_e( 'Images', 'wisnoob-media-cleanup' ); ?>
                        <span class="mj-tab__count" id="mj-count-image"></span>
                    </button>
                    <button class="mj-tab" data-type="document">
                        <span class="dashicons dashicons-media-document"></span>
                        <?php esc_html_e( 'Documents', 'wisnoob-media-cleanup' ); ?>
                        <span class="mj-tab__count" id="mj-count-document"></span>
                    </button>
                    <button class="mj-tab" data-type="video">
                        <span class="dashicons dashicons-video-alt3"></span>
                        <?php esc_html_e( 'Videos', 'wisnoob-media-cleanup' ); ?>
                        <span class="mj-tab__count" id="mj-count-video"></span>
                    </button>
                    <button class="mj-tab" data-type="audio">
                        <span class="dashicons dashicons-format-audio"></span>
                        <?php esc_html_e( 'Audio', 'wisnoob-media-cleanup' ); ?>
                        <span class="mj-tab__count" id="mj-count-audio"></span>
                    </button>
                    <button class="mj-tab mj-tab--duplicates" data-type="duplicates">
                        <span class="dashicons dashicons-images-alt2"></span>
                        <?php esc_html_e( 'Duplicates', 'wisnoob-media-cleanup' ); ?>
                        <span class="mj-tab__count" id="mj-count-duplicates"></span>
                    </button>
                </div>

                <!-- Filters row -->
                <div class="mj-filters">
                    <div class="mj-filters__left">
                        <select id="mj-filter-status" class="mj-select">
                            <option value="all"><?php esc_html_e( 'All Status', 'wisnoob-media-cleanup' ); ?></option>
                            <option value="used"><?php esc_html_e( 'Used', 'wisnoob-media-cleanup' ); ?></option>
                            <option value="unused" selected><?php esc_html_e( 'Unused', 'wisnoob-media-cleanup' ); ?></option>
                        </select>
                        <input type="search" id="mj-search" class="mj-search"
                               placeholder="<?php esc_attr_e( 'Search by filename…', 'wisnoob-media-cleanup' ); ?>">
                    </div>
                    <div class="mj-filters__right">
                        <button id="mj-select-all" class="button"><?php esc_html_e( 'Select All', 'wisnoob-media-cleanup' ); ?></button>
                        <button id="mj-delete-selected" class="button button-link-delete" disabled>
                            <span class="dashicons dashicons-trash" style="margin-top:4px;"></span>
                            <?php esc_html_e( 'Delete Selected', 'wisnoob-media-cleanup' ); ?>
                        </button>
                        <button id="mj-delete-all-unused" class="button button-link-delete">
                            <?php esc_html_e( 'Delete All Unused', 'wisnoob-media-cleanup' ); ?>
                        </button>
                    </div>
                </div>

                <!-- Duplicates pane (visible only when Duplicates tab is active) -->
                <div id="mj-duplicates-pane" style="display:none;">

                    <div class="mj-dup-actions">
                        <button id="mj-scan-dup-btn" class="button button-primary">
                            <span class="dashicons dashicons-images-alt2"></span>
                            <?php esc_html_e( 'Scan for Duplicates', 'wisnoob-media-cleanup' ); ?>
                        </button>
                        <span id="mj-dup-status" class="mj-scan-status"></span>
                    </div>

                    <div id="mj-dup-loading" style="display:none;padding:20px 0;font-size:14px;color:#646970;">
                        <span class="spinner is-active" style="float:none;margin:0 8px 0 0;vertical-align:middle;"></span>
                        <span id="mj-dup-loading-text"><?php esc_html_e( 'Scanning…', 'wisnoob-media-cleanup' ); ?></span>
                    </div>

                    <div id="mj-dup-not-scanned" class="mj-empty" style="display:none;">
                        <span class="dashicons dashicons-images-alt2"></span>
                        <p><?php esc_html_e( 'No duplicate scan run yet. Click "Scan for Duplicates" to begin.', 'wisnoob-media-cleanup' ); ?></p>
                    </div>

                    <div id="mj-dup-results" style="display:none;">

                        <div class="mj-dup-section">
                            <div class="mj-dup-section__head">
                                <h3><?php esc_html_e( 'Exact Duplicates', 'wisnoob-media-cleanup' ); ?></h3>
                                <span class="mj-tab__count" id="mj-dup-exact-count">0</span>
                            </div>
                            <p class="mj-dup-section__desc"><?php esc_html_e( 'Byte-identical files — same content uploaded more than once.', 'wisnoob-media-cleanup' ); ?></p>
                            <div id="mj-dup-exact-groups"></div>
                        </div>

                        <div class="mj-dup-section">
                            <div class="mj-dup-section__head">
                                <h3><?php esc_html_e( 'Scale / Name Variants', 'wisnoob-media-cleanup' ); ?></h3>
                                <span class="mj-tab__count" id="mj-dup-scale-count">0</span>
                            </div>
                            <p class="mj-dup-section__desc"><?php esc_html_e( 'Same base filename after stripping @2x / @3x / -2x suffixes — likely Figma scale exports.', 'wisnoob-media-cleanup' ); ?></p>
                            <div id="mj-dup-scale-groups"></div>
                        </div>

                        <div class="mj-dup-section">
                            <div class="mj-dup-section__head">
                                <h3><?php esc_html_e( 'Visual Duplicates', 'wisnoob-media-cleanup' ); ?></h3>
                                <span class="mj-tab__count" id="mj-dup-visual-count">0</span>
                            </div>
                            <p class="mj-dup-section__desc"><?php esc_html_e( 'Visually similar images regardless of filename — different format, resolution, or slight edits.', 'wisnoob-media-cleanup' ); ?></p>
                            <p id="mj-dup-visual-skipped" class="mj-dup-section__desc" style="display:none;"></p>
                            <div id="mj-dup-visual-groups"></div>
                        </div>

                        <div class="mj-dup-footer">
                            <button id="mj-dup-delete-selected" class="button button-link-delete" disabled>
                                <span class="dashicons dashicons-trash" style="margin-top:4px;"></span>
                                <?php esc_html_e( 'Delete Selected', 'wisnoob-media-cleanup' ); ?>
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
                    <button type="button" class="mj-modal__close" aria-label="<?php esc_attr_e( 'Close', 'wisnoob-media-cleanup' ); ?>">&times;</button>
                    <div class="mj-modal__header">
                        <div class="mj-modal__thumb" id="mj-modal-thumb"></div>
                        <div class="mj-modal__info">
                            <h2 id="mj-modal-title"></h2>
                            <p id="mj-modal-meta"></p>
                        </div>
                    </div>
                    <div class="mj-modal__body">
                        <h3><?php esc_html_e( 'Used In', 'wisnoob-media-cleanup' ); ?></h3>
                        <ul id="mj-modal-usage" class="mj-usage-list"></ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
