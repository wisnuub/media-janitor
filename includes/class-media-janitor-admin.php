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
        add_filter( 'plugin_action_links_' . JEJEKIN_MJ_BASENAME, array( $this, 'action_links' ) );
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
            JEJEKIN_MJ_URL . 'assets/css/admin.css',
            array(),
            JEJEKIN_MJ_VERSION
        );

        wp_enqueue_script(
            'media-janitor-admin',
            JEJEKIN_MJ_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            JEJEKIN_MJ_VERSION,
            true
        );

        wp_localize_script( 'media-janitor-admin', 'mjData', array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonceScan'   => wp_create_nonce( 'mj_scan' ),
            'nonceResults'=> wp_create_nonce( 'mj_results' ),
            'nonceDelete' => wp_create_nonce( 'mj_delete' ),
            'lastScan'    => (int) get_option( 'mj_last_scan', 0 ),
            'i18n'        => array(
                'scanning'       => __( 'Scanning…', 'media-janitor' ),
                'scanComplete'   => __( 'Scan complete!', 'media-janitor' ),
                'confirmDelete'  => __( 'Are you sure you want to permanently delete the selected media files? This cannot be undone.', 'media-janitor' ),
                'confirmAll'     => __( 'Are you sure you want to permanently delete ALL unused media files? This cannot be undone.', 'media-janitor' ),
                'deleting'       => __( 'Deleting…', 'media-janitor' ),
                'deleted'        => __( 'Deleted successfully.', 'media-janitor' ),
                'noUnused'       => __( 'No unused media found. Your library is clean!', 'media-janitor' ),
                'error'          => __( 'An error occurred. Please try again.', 'media-janitor' ),
            ),
        ) );
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
                    <span class="mj-version-badge">v<?php echo esc_html( JEJEKIN_MJ_VERSION ); ?></span>
                </div>
            </div>

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
                    <span class="dashicons dashicons-search" style="margin-top:4px;margin-right:4px;"></span>
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

                <!-- Media grid -->
                <div id="mj-grid" class="mj-grid"></div>

                <!-- Pagination -->
                <div id="mj-pagination" class="mj-pagination"></div>
            </div>

            <!-- Usage detail modal -->
            <div id="mj-modal" class="mj-modal" style="display:none;">
                <div class="mj-modal__overlay"></div>
                <div class="mj-modal__content">
                    <button class="mj-modal__close">&times;</button>
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
