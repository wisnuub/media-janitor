<?php
/**
 * Media Janitor — Scanner
 *
 * Builds a map of where every attachment is used. The scan runs in resumable,
 * time-boxed slices (one per AJAX request) so large sites never hit the PHP
 * time limit:
 *
 *   1. content — post_content + post_excerpt of every post type
 *   2. meta    — post meta (featured images, galleries, page builders, custom fields)
 *   3. misc    — term meta, user meta, widgets, theme mods, options
 *
 * Until a scan reaches "complete" the usage table is partial, so deletion is
 * refused (see Media_Janitor_Ajax::handle_delete()).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Media_Janitor_Scanner {

    const STATE_OPTION     = 'media_janitor_scan_state';
    const DUP_STATE_OPTION = 'media_janitor_dup_state';
    const DUP_OPTION       = 'media_janitor_duplicates';
    const HASH_META        = '_media_janitor_hash';

    /** Seconds of work per request — well under typical 30s limits. */
    const TIME_BUDGET = 8;

    /** Visual (dHash) matching is O(n²); above this many candidates it is skipped. */
    const VISUAL_MAX = 4000;

    /** Post types whose content never references media (or is handled elsewhere). */
    const SKIP_POST_TYPES = array( 'attachment', 'revision', 'nav_menu_item', 'oembed_cache', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'customize_changeset' );

    /** Post meta keys that never hold media references. */
    const SKIP_META_KEYS = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_attached_file', '_wp_attachment_metadata', '_wp_page_template', '_elementor_css', '_elementor_version', '_elementor_edit_mode' );

    /** @var wpdb */
    private $db;

    /** @var string */
    private $table;

    /** @var string  Path fragment that precedes every upload in a URL, e.g. "wp-content/uploads/". */
    private $uploads_marker;

    /** @var array|null  Relative upload path (original, sizes, original_image) => attachment ID. */
    private $path_map = null;

    /** @var array|null  Attachment ID => true. */
    private $id_set = null;

    /** @var array  Per-request de-dupe of recorded references. */
    private $recorded = array();

    /** @var array  Per-request cache of post ID => URL. */
    private $url_cache = array();

    /** @var array|null  Usage rows keyed by attachment ID, primed to avoid N+1 queries. */
    private $usage_cache = null;

    public function __construct() {
        global $wpdb;
        $this->db    = $wpdb;
        $this->table = self::table_name();

        $upload_info = wp_get_upload_dir();
        $path        = trim( (string) wp_parse_url( $upload_info['baseurl'], PHP_URL_PATH ), '/' );
        if ( '' === $path ) {
            $path = wp_basename( $upload_info['basedir'] );
        }
        $this->uploads_marker = $path . '/';
    }

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'media_janitor_usage';
    }

    /* ------------------------------------------------------------------
     *  Scan state
     * ----------------------------------------------------------------*/

    public static function get_state(): array {
        $state = get_option( self::STATE_OPTION );
        return is_array( $state ) ? $state : array( 'status' => 'none' );
    }

    public static function is_complete(): bool {
        $state = self::get_state();
        return 'complete' === $state['status'];
    }

    /**
     * Reset the usage table and start a new scan.
     */
    public function start_scan(): array {
        $this->db->query( "TRUNCATE TABLE {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $state = array(
            'status'      => 'running',
            'step'        => 'content',
            'cursor'      => 0,
            'posts_total' => (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->posts} WHERE post_type NOT IN (" . $this->skip_types_sql() . ") AND post_status NOT IN ('auto-draft','trash')" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            'posts_done'  => 0,
            'meta_max'    => (int) $this->db->get_var( "SELECT MAX(meta_id) FROM {$this->db->postmeta}" ),
            'started'     => time(),
        );
        update_option( self::STATE_OPTION, $state, false );
        return $state;
    }

    /**
     * Run one time-boxed slice of the scan and return the updated state.
     */
    public function run_slice(): array {
        $state = self::get_state();
        if ( 'running' !== $state['status'] ) {
            return $state;
        }

        $this->load_maps();
        $deadline = microtime( true ) + self::TIME_BUDGET;

        while ( 'running' === $state['status'] && microtime( true ) < $deadline ) {
            if ( 'content' === $state['step'] ) {
                $state = $this->scan_content_batch( $state );
            } elseif ( 'meta' === $state['step'] ) {
                $state = $this->scan_meta_batch( $state );
            } else {
                $this->scan_misc();
                $state['status']    = 'complete';
                $state['completed'] = time();
                update_option( 'media_janitor_last_scan', $state['completed'] );
            }
            update_option( self::STATE_OPTION, $state, false );
        }

        return $state;
    }

    /**
     * Rough 0–100 progress figure for the UI.
     */
    public static function progress( array $state ): int {
        if ( 'complete' === $state['status'] ) {
            return 100;
        }
        if ( 'running' !== $state['status'] ) {
            return 0;
        }
        if ( 'content' === $state['step'] ) {
            $total = max( 1, (int) $state['posts_total'] );
            return (int) min( 40, 40 * $state['posts_done'] / $total );
        }
        if ( 'meta' === $state['step'] ) {
            $total = max( 1, (int) $state['meta_max'] );
            return 40 + (int) min( 55, 55 * $state['cursor'] / $total );
        }
        return 97;
    }

    /* ------------------------------------------------------------------
     *  Results
     * ----------------------------------------------------------------*/

    /**
     * Get categorized media results.
     *
     * @param string $filter   'all' | 'used' | 'unused'
     * @param string $type     'all' | 'image' | 'document' | 'video' | 'audio'
     * @param string $search   Search term for filename.
     * @param int    $paged    Page number.
     * @param int    $per_page Items per page.
     * @return array { items: array, total: int, pages: int }
     */
    public function get_results( string $filter = 'all', string $type = 'all', string $search = '', int $paged = 1, int $per_page = 40 ): array {
        $where = array( "p.post_type = 'attachment'", "p.post_status = 'inherit'" );
        $join  = '';

        $mime_clause = $this->mime_clause( $type );
        if ( $mime_clause ) {
            $where[] = $mime_clause;
        }

        if ( $search ) {
            $like    = '%' . $this->db->esc_like( $search ) . '%';
            $where[] = $this->db->prepare( '(p.post_title LIKE %s OR p.guid LIKE %s)', $like, $like );
        }

        if ( 'used' === $filter ) {
            $join = "INNER JOIN {$this->table} u ON u.attachment_id = p.ID";
        } elseif ( 'unused' === $filter ) {
            $join    = "LEFT JOIN {$this->table} u ON u.attachment_id = p.ID";
            $where[] = 'u.id IS NULL';
        }

        $where_sql = implode( ' AND ', $where );
        $per_page  = max( 1, min( 1000, $per_page ) );
        $paged     = max( 1, $paged );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $this->db->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$this->db->posts} p {$join} WHERE {$where_sql}" );
        $ids   = $this->db->get_col( $this->db->prepare(
            "SELECT DISTINCT p.ID FROM {$this->db->posts} p {$join}
             WHERE {$where_sql}
             ORDER BY p.post_date DESC
             LIMIT %d OFFSET %d",
            $per_page,
            ( $paged - 1 ) * $per_page
        ) );
        // phpcs:enable

        $ids = array_map( 'intval', $ids );
        $this->prime_usage_cache( $ids );
        $items = array_map( array( $this, 'build_item' ), $ids );
        $this->usage_cache = null;

        return array(
            'items' => $items,
            'total' => $total,
            'pages' => max( 1, (int) ceil( $total / $per_page ) ),
        );
    }

    /**
     * Return the IDs of every unused attachment in a category.
     */
    public function get_unused_ids( string $type = 'all' ): array {
        $mime_clause = $this->mime_clause( $type );
        $extra       = $mime_clause ? " AND {$mime_clause}" : '';

        return array_map( 'intval', $this->db->get_col(
            "SELECT p.ID FROM {$this->db->posts} p
             LEFT JOIN {$this->table} u ON u.attachment_id = p.ID
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND u.id IS NULL{$extra}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        ) );
    }

    /**
     * Get usage details for a single attachment.
     */
    public function get_usage( int $attachment_id ): array {
        if ( null !== $this->usage_cache ) {
            $rows = $this->usage_cache[ $attachment_id ] ?? array();
        } else {
            $rows = $this->db->get_results( $this->db->prepare(
                "SELECT * FROM {$this->table} WHERE attachment_id = %d ORDER BY source_type, source_label", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $attachment_id
            ) );
        }

        // When one post references the same file several ways, show the most specific.
        $priority = array(
            'elementor'      => 0,
            'featured_image' => 1,
            'woo_gallery'    => 2,
        );
        $get_priority = function ( string $type ) use ( $priority ): int {
            return $priority[ $type ] ?? 10;
        };

        $by_source_id = array();
        $zero_source  = array();

        foreach ( $rows as $row ) {
            $source_id = (int) $row->source_id;

            // Skip posts trashed or deleted since the scan.
            if ( $source_id > 0 ) {
                $status = get_post_status( $source_id );
                if ( ! $status || 'trash' === $status ) {
                    continue;
                }
            }

            if ( 0 === $source_id ) {
                $zero_source[] = $row;
            } elseif ( ! isset( $by_source_id[ $source_id ] ) || $get_priority( $row->source_type ) < $get_priority( $by_source_id[ $source_id ]->source_type ) ) {
                $by_source_id[ $source_id ] = $row;
            }
        }

        $usage = array();
        foreach ( array_merge( array_values( $by_source_id ), $zero_source ) as $row ) {
            $usage[] = array(
                'type'  => $row->source_type,
                'label' => $row->source_label,
                'url'   => $row->source_url,
            );
        }
        return $usage;
    }

    /**
     * Whether the last scan recorded any reference to this attachment.
     */
    public function has_usage( int $attachment_id ): bool {
        return (bool) $this->db->get_var( $this->db->prepare(
            "SELECT 1 FROM {$this->table} WHERE attachment_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $attachment_id
        ) );
    }

    /**
     * Last-second safety net before deleting: look for references added to
     * content (or featured images set) after the last scan started.
     */
    public function has_new_reference( int $attachment_id ): bool {
        $state = self::get_state();
        $since = gmdate( 'Y-m-d H:i:s', (int) ( $state['started'] ?? 0 ) );

        $is_thumb = $this->db->get_var( $this->db->prepare(
            "SELECT 1 FROM {$this->db->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1",
            (string) $attachment_id
        ) );
        if ( $is_thumb ) {
            return true;
        }

        $file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
        $stem = preg_replace( '/(-scaled)?\.[^.\/]+$/', '', $file ); // matches original and every size
        $class_like = '%' . $this->db->esc_like( 'wp-image-' . $attachment_id ) . '%';
        $stem_like  = $stem ? '%' . $this->db->esc_like( $stem ) . '%' : $class_like;

        return (bool) $this->db->get_var( $this->db->prepare(
            "SELECT 1 FROM {$this->db->posts}
             WHERE post_modified_gmt >= %s
             AND post_type NOT IN ('attachment','revision')
             AND post_status NOT IN ('auto-draft','trash')
             AND ( post_content LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s )
             LIMIT 1",
            $since,
            $stem_like,
            $class_like,
            $stem_like
        ) );
    }

    /**
     * Summary counts by category.
     */
    public function get_summary(): array {
        $out = array(
            'total'       => 0,
            'used'        => 0,
            'unused'      => 0,
            'categories'  => array(),
            'unused_size' => 0,
        );

        foreach ( array( 'image', 'video', 'audio', 'document' ) as $cat ) {
            $where = "p.post_type = 'attachment' AND p.post_status = 'inherit' AND " . $this->mime_clause( $cat );

            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $cat_total  = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->posts} p WHERE {$where}" );
            $cat_unused = (int) $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->db->posts} p
                 LEFT JOIN {$this->table} u ON u.attachment_id = p.ID
                 WHERE {$where} AND u.id IS NULL"
            );
            // phpcs:enable

            $out['categories'][ $cat ] = array(
                'total'  => $cat_total,
                'used'   => $cat_total - $cat_unused,
                'unused' => $cat_unused,
            );
            $out['total']  += $cat_total;
            $out['unused'] += $cat_unused;
        }

        $out['used'] = $out['total'] - $out['unused'];

        foreach ( $this->get_unused_ids() as $uid ) {
            $out['unused_size'] += $this->disk_size( $uid );
        }

        return $out;
    }

    /* ------------------------------------------------------------------
     *  Scan steps
     * ----------------------------------------------------------------*/

    private function scan_content_batch( array $state ): array {
        $batch = 100;
        $rows  = $this->db->get_results( $this->db->prepare(
            "SELECT ID, post_title, post_type, post_content, post_excerpt
             FROM {$this->db->posts}
             WHERE ID > %d
             AND post_type NOT IN (" . $this->skip_types_sql() . ")
             AND post_status NOT IN ('auto-draft','trash')
             ORDER BY ID ASC
             LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            (int) $state['cursor'],
            $batch
        ) );

        foreach ( $rows as $post ) {
            $text = $post->post_content . "\n" . $post->post_excerpt;
            if ( '' !== trim( $text ) ) {
                $source = $this->describe_post( $post );
                foreach ( array_keys( $this->find_ids_in_text( $text, true ) ) as $att_id ) {
                    $this->record_usage( $att_id, $source['type'], (int) $post->ID, $source['label'], $source['url'] );
                }
            }
            $state['cursor'] = (int) $post->ID;
            $state['posts_done']++;
        }

        if ( count( $rows ) < $batch ) {
            $state['step']   = 'meta';
            $state['cursor'] = 0;
        }
        return $state;
    }

    private function scan_meta_batch( array $state ): array {
        $batch        = 500;
        $placeholders = implode( ',', array_fill( 0, count( self::SKIP_META_KEYS ), '%s' ) );

        $rows = $this->db->get_results( $this->db->prepare(
            "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type
             FROM {$this->db->postmeta} pm
             INNER JOIN {$this->db->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_id > %d
             AND p.post_type NOT IN ('attachment','revision')
             AND p.post_status NOT IN ('auto-draft','trash')
             AND pm.meta_key NOT IN ({$placeholders})
             AND pm.meta_value != ''
             ORDER BY pm.meta_id ASC
             LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_merge( array( (int) $state['cursor'] ), self::SKIP_META_KEYS, array( $batch ) )
        ) );

        foreach ( $rows as $row ) {
            $state['cursor'] = (int) $row->meta_id;
            $key             = (string) $row->meta_key;
            $post_id         = (int) $row->post_id;

            switch ( $key ) {
                case '_thumbnail_id':
                    $type = 'featured_image';
                    break;
                case '_product_image_gallery':
                    $type = 'woo_gallery';
                    break;
                case '_elementor_data':
                    $type = 'elementor';
                    break;
                case '_menu_item_url':
                    $type = 'nav_menu';
                    break;
                default:
                    if ( 'nav_menu_item' === $row->post_type ) {
                        continue 2; // only the URL of a menu item can point at media
                    }
                    $type = 'meta:' . $key;
            }

            $ids = $this->find_ids_in_meta( $key, (string) $row->meta_value );
            if ( ! $ids ) {
                continue;
            }

            if ( 'nav_menu' === $type ) {
                $label = sprintf( /* translators: %s: menu item title */ __( 'Menu Link: %s', 'media-janitor' ), $row->post_title ?: '#' . $post_id );
                $url   = admin_url( 'nav-menus.php' );
            } else {
                $source = $this->describe_post( $row, $post_id );
                $label  = $source['label'];
                $url    = $source['url'];
            }

            foreach ( array_keys( $ids ) as $att_id ) {
                $this->record_usage( $att_id, $type, $post_id, $label, $url );
            }
        }

        if ( count( $rows ) < $batch ) {
            $state['step']   = 'misc';
            $state['cursor'] = 0;
        }
        return $state;
    }

    /**
     * Everything that isn't post content or post meta. Small enough for one request.
     */
    private function scan_misc(): void {
        $this->scan_term_meta();
        $this->scan_user_meta();
        $this->scan_widgets();
        $this->scan_theme_mods();
        $this->scan_options();
    }

    /**
     * Term meta — e.g. WooCommerce product category thumbnails.
     */
    private function scan_term_meta(): void {
        $rows = $this->db->get_results(
            "SELECT tm.term_id, tm.meta_key, tm.meta_value, t.name, tt.taxonomy
             FROM {$this->db->termmeta} tm
             INNER JOIN {$this->db->terms} t ON t.term_id = tm.term_id
             LEFT JOIN {$this->db->term_taxonomy} tt ON tt.term_id = tm.term_id
             WHERE tm.meta_value != ''"
        );

        foreach ( $rows as $row ) {
            $ids = $this->find_ids_in_meta( (string) $row->meta_key, (string) $row->meta_value );
            if ( ! $ids ) {
                continue;
            }
            $label = sprintf( /* translators: 1: term name, 2: taxonomy */ __( 'Term: %1$s (%2$s)', 'media-janitor' ), $row->name, $row->taxonomy );
            $url   = $row->taxonomy ? admin_url( 'term.php?taxonomy=' . rawurlencode( $row->taxonomy ) . '&tag_ID=' . (int) $row->term_id ) : '';
            foreach ( array_keys( $ids ) as $att_id ) {
                $this->record_usage( $att_id, 'term', 0, $label, $url );
            }
        }
    }

    /**
     * User meta — local avatar plugins, author profile images.
     */
    private function scan_user_meta(): void {
        $rows = $this->db->get_results( $this->db->prepare(
            "SELECT um.user_id, um.meta_key, um.meta_value, u.display_name
             FROM {$this->db->usermeta} um
             INNER JOIN {$this->db->users} u ON u.ID = um.user_id
             WHERE um.meta_value LIKE %s
             OR um.meta_key LIKE '%%avatar%%' OR um.meta_key LIKE '%%image%%'
             OR um.meta_key LIKE '%%photo%%' OR um.meta_key LIKE '%%picture%%'",
            '%' . $this->db->esc_like( $this->uploads_marker ) . '%'
        ) );

        foreach ( $rows as $row ) {
            foreach ( array_keys( $this->find_ids_in_meta( (string) $row->meta_key, (string) $row->meta_value ) ) as $att_id ) {
                $this->record_usage(
                    $att_id,
                    'user',
                    0,
                    sprintf( /* translators: %s: user display name */ __( 'User: %s', 'media-janitor' ), $row->display_name ),
                    admin_url( 'user-edit.php?user_id=' . (int) $row->user_id )
                );
            }
        }
    }

    private function scan_widgets(): void {
        $rows = $this->db->get_results(
            "SELECT option_name, option_value FROM {$this->db->options} WHERE option_name LIKE 'widget\_%'"
        );

        foreach ( $rows as $row ) {
            $value = (string) $row->option_value;
            // Block widgets hold block markup, so read the raw value like post content too.
            $ids = $this->find_ids_in_text( $value, true ) + $this->find_ids_in_meta( '', $value );
            foreach ( array_keys( $ids ) as $att_id ) {
                $this->record_usage(
                    $att_id,
                    'widget',
                    0,
                    sprintf( /* translators: %s: widget type */ __( 'Widget: %s', 'media-janitor' ), substr( $row->option_name, 7 ) ),
                    admin_url( 'widgets.php' )
                );
            }
        }
    }

    private function scan_theme_mods(): void {
        $mods = get_theme_mods();
        if ( ! is_array( $mods ) ) {
            return;
        }

        foreach ( $mods as $key => $val ) {
            $ids = array();
            if ( is_scalar( $val ) ) {
                $ids = $this->find_ids_in_meta( (string) $key, (string) $val );
            } else {
                $this->collect_ids_from_structure( $val, $ids, (string) $key );
                $ids += $this->find_url_ids( (string) wp_json_encode( $val ) );
            }

            $label = 'custom_logo' === $key ? __( 'Site Logo', 'media-janitor' ) : sprintf( /* translators: %s: setting name */ __( 'Customizer: %s', 'media-janitor' ), $key );
            foreach ( array_keys( $ids ) as $att_id ) {
                $this->record_usage( $att_id, 'theme_mod', 0, $label, admin_url( 'customize.php' ) );
            }
        }
    }

    private function scan_options(): void {
        // Options that hold a bare attachment ID (site icon, block-theme logo, Woo placeholder…).
        $id_options = $this->db->get_results(
            "SELECT option_name, option_value FROM {$this->db->options}
             WHERE option_name NOT LIKE '\_%'
             AND option_name NOT LIKE 'theme\_mods\_%'
             AND option_name NOT LIKE 'widget\_%'
             AND ( option_name LIKE '%logo%' OR option_name LIKE '%icon%' OR option_name LIKE '%image%'
                   OR option_name LIKE '%placeholder%' OR option_name LIKE '%banner%' OR option_name LIKE '%background%' )
             AND option_value REGEXP '^[0-9]+$'"
        );

        // Options that mention an uploads path anywhere in their value.
        $url_options = $this->db->get_results( $this->db->prepare(
            "SELECT option_name, option_value FROM {$this->db->options}
             WHERE option_value LIKE %s
             AND option_name NOT LIKE '\_%%'
             AND option_name NOT LIKE 'theme\_mods\_%%'
             AND option_name NOT LIKE 'widget\_%%'
             AND option_name NOT LIKE 'media\_janitor\_%%'",
            '%' . $this->db->esc_like( $this->uploads_marker ) . '%'
        ) );

        foreach ( array_merge( $id_options, $url_options ) as $row ) {
            $ids = $this->find_ids_in_meta( (string) $row->option_name, (string) $row->option_value );
            $known = array(
                'site_icon'                     => __( 'Site Icon', 'media-janitor' ),
                'site_logo'                     => __( 'Site Logo', 'media-janitor' ),
                'woocommerce_placeholder_image' => __( 'WooCommerce Placeholder Image', 'media-janitor' ),
            );
            foreach ( array_keys( $ids ) as $att_id ) {
                $label = $known[ $row->option_name ] ?? sprintf( /* translators: %s: option name */ __( 'Option: %s', 'media-janitor' ), $row->option_name );
                $this->record_usage( $att_id, 'option', 0, $label, admin_url( 'options-general.php' ) );
            }
        }
    }

    /* ------------------------------------------------------------------
     *  Reference extraction
     * ----------------------------------------------------------------*/

    /**
     * Build the path => ID map once per request.
     */
    private function load_maps(): void {
        if ( null !== $this->path_map ) {
            return;
        }
        $this->path_map = array();
        $this->id_set   = array();

        $rows = $this->db->get_results(
            "SELECT p.ID, f.meta_value AS file, m.meta_value AS meta
             FROM {$this->db->posts} p
             LEFT JOIN {$this->db->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
             LEFT JOIN {$this->db->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata'
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'"
        );

        foreach ( $rows as $row ) {
            $id                  = (int) $row->ID;
            $this->id_set[ $id ] = true;

            $file = (string) $row->file;
            if ( '' === $file ) {
                continue;
            }
            $this->path_map[ $file ] = $id;

            $meta = maybe_unserialize( $row->meta );
            if ( ! is_array( $meta ) ) {
                continue;
            }
            $dir = dirname( $file );
            $dir = '.' === $dir ? '' : $dir . '/';
            if ( ! empty( $meta['original_image'] ) ) {
                $this->path_map[ $dir . $meta['original_image'] ] = $id;
            }
            if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
                foreach ( $meta['sizes'] as $size ) {
                    if ( ! empty( $size['file'] ) ) {
                        $this->path_map[ $dir . $size['file'] ] = $id;
                    }
                }
            }
        }
    }

    /**
     * Attachment IDs referenced by uploads URLs in a string (O(length), not O(attachments)).
     *
     * @return array ID => true
     */
    private function find_url_ids( string $text ): array {
        $found = array();
        if ( false === strpos( $text, $this->uploads_marker ) && false === strpos( $text, str_replace( '/', '\\/', $this->uploads_marker ) ) ) {
            return $found;
        }

        $text = str_replace( '\\/', '/', $text ); // JSON-escaped slashes (Elementor, block attrs)
        if ( preg_match_all( '#' . preg_quote( $this->uploads_marker, '#' ) . '([^\s"\'<>()\\\\,;?\#]+)#i', $text, $m ) ) {
            foreach ( $m[1] as $rel ) {
                $rel = rawurldecode( $rel );
                if ( isset( $this->path_map[ $rel ] ) ) {
                    $found[ $this->path_map[ $rel ] ] = true;
                }
            }
        }
        return $found;
    }

    /**
     * Attachment IDs referenced by post-content-like text: URLs, wp-image-N classes,
     * block attributes ({"id":N}, {"ids":[…]}, {"mediaId":N}) and shortcode
     * attributes ([gallery ids="…"], WPBakery image="N").
     *
     * @return array ID => true
     */
    private function find_ids_in_text( string $text, bool $content = false ): array {
        $found = $this->find_url_ids( $text );
        if ( ! $content ) {
            return $found;
        }

        $candidates = array();

        if ( preg_match_all( '/wp-image-(\d+)/', $text, $m ) ) {
            $candidates = array_merge( $candidates, $m[1] );
        }

        if ( preg_match_all( '/<!--\s+wp:\S+\s+(\{.*?\})\s+\/?-->/s', $text, $m ) ) {
            foreach ( $m[1] as $json ) {
                $attrs = json_decode( $json, true );
                if ( is_array( $attrs ) ) {
                    $ids = array();
                    $this->collect_ids_from_structure( $attrs, $ids );
                    $candidates = array_merge( $candidates, array_keys( $ids ) );
                }
            }
        }

        if ( preg_match_all( '/\b(?:ids|id|image|images|include|image_id|img_id|attachment_id|attachment)\s*=\s*["\']?(\d+(?:\s*,\s*\d+)*)/i', $text, $m ) ) {
            foreach ( $m[1] as $list ) {
                $candidates = array_merge( $candidates, preg_split( '/\s*,\s*/', $list ) );
            }
        }

        foreach ( $candidates as $id ) {
            $id = (int) $id;
            if ( isset( $this->id_set[ $id ] ) ) {
                $found[ $id ] = true;
            }
        }
        return $found;
    }

    /**
     * Attachment IDs referenced by a meta/option value: bare IDs, comma lists,
     * serialized arrays (ACF galleries, Beaver Builder), JSON (Elementor) and URLs.
     *
     * @return array ID => true
     */
    private function find_ids_in_meta( string $key, string $value ): array {
        $found = $this->find_url_ids( $value );
        $value = trim( $value );

        if ( preg_match( '/^\d+$/', $value ) ) {
            // Bare numbers are everywhere (_price, _stock…). Only trust public keys
            // (ACF image fields use the field name) or keys that look media-related.
            $id = (int) $value;
            if ( isset( $this->id_set[ $id ] ) && ( '' === $key || '_' !== $key[0] || $this->is_media_key( $key ) ) ) {
                $found[ $id ] = true;
            }
            return $found;
        }

        if ( preg_match( '/^\d+(\s*,\s*\d+)+$/', $value ) ) {
            foreach ( preg_split( '/\s*,\s*/', $value ) as $id ) {
                if ( isset( $this->id_set[ (int) $id ] ) ) {
                    $found[ (int) $id ] = true;
                }
            }
            return $found;
        }

        $data = null;
        if ( is_serialized( $value ) ) {
            $data = @unserialize( $value, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
        } elseif ( '{' === ( $value[0] ?? '' ) || '[' === ( $value[0] ?? '' ) ) {
            $data = json_decode( $value, true );
        }

        if ( is_array( $data ) || is_object( $data ) ) {
            $this->collect_ids_from_structure( $data, $found, $key );
        }
        return $found;
    }

    /**
     * Walk a decoded structure collecting numeric leaves that are attachment IDs,
     * either under a media-looking key or inside a pure list of numbers.
     */
    private function collect_ids_from_structure( $data, array &$found, string $hint = '', int $depth = 0 ): void {
        if ( $depth > 40 ) {
            return;
        }
        if ( is_object( $data ) ) {
            $data = (array) $data;
        }
        if ( ! is_array( $data ) ) {
            return;
        }

        $is_id_list = ! empty( $data ) && array_keys( $data ) === range( 0, count( $data ) - 1 );
        foreach ( $data as $v ) {
            if ( ! is_int( $v ) && ! ( is_string( $v ) && preg_match( '/^\d+$/', $v ) ) ) {
                $is_id_list = false;
                break;
            }
        }

        foreach ( $data as $k => $v ) {
            $key = is_int( $k ) ? $hint : (string) $k;
            if ( is_array( $v ) || is_object( $v ) ) {
                $this->collect_ids_from_structure( $v, $found, $key, $depth + 1 );
            } elseif ( is_int( $v ) || ( is_string( $v ) && preg_match( '/^\d+$/', $v ) ) ) {
                $id = (int) $v;
                if ( isset( $this->id_set[ $id ] ) && ( $is_id_list || $this->is_media_key( $key ) ) ) {
                    $found[ $id ] = true;
                }
            }
        }
    }

    private function is_media_key( string $key ): bool {
        return (bool) preg_match( '/(^|[_-])ids?$|^id$|mediaid|imageid|image|img|photo|picture|gallery|logo|icon|media|attachment|thumb|background|bg|video|audio|poster|file|avatar|banner|slide|cover/i', $key );
    }

    /* ------------------------------------------------------------------
     *  Duplicates
     * ----------------------------------------------------------------*/

    public static function get_dup_state(): array {
        $state = get_option( self::DUP_STATE_OPTION );
        return is_array( $state ) ? $state : array( 'status' => 'none' );
    }

    public function start_duplicates(): array {
        $state = array(
            'status' => 'running',
            'cursor' => 0,
            'done'   => 0,
            'total'  => (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->db->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'" ),
        );
        update_option( self::DUP_STATE_OPTION, $state, false );
        return $state;
    }

    /**
     * Hash a time-boxed slice of attachments; once all are hashed, group them.
     */
    public function run_duplicates_slice(): array {
        $state = self::get_dup_state();
        if ( 'running' !== $state['status'] ) {
            return $state;
        }

        $deadline = microtime( true ) + self::TIME_BUDGET;
        do {
            $ids = array_map( 'intval', $this->db->get_col( $this->db->prepare(
                "SELECT ID FROM {$this->db->posts} WHERE post_type = 'attachment' AND post_status = 'inherit' AND ID > %d ORDER BY ID ASC LIMIT 20",
                (int) $state['cursor']
            ) ) );
            foreach ( $ids as $id ) {
                $this->get_file_hashes( $id );
                $state['cursor'] = $id;
                $state['done']++;
            }
        } while ( count( $ids ) === 20 && microtime( true ) < $deadline );

        if ( count( $ids ) < 20 ) {
            $groups = $this->group_duplicates();
            update_option( self::DUP_OPTION, $groups, false );
            $state['status'] = 'complete';
        }

        update_option( self::DUP_STATE_OPTION, $state, false );
        return $state;
    }

    /**
     * MD5 + dHash + scale-stripped name for an attachment, cached in post meta
     * and invalidated when the file's size or mtime changes.
     */
    private function get_file_hashes( int $id ): ?array {
        $file = get_attached_file( $id );
        if ( ! $file || ! file_exists( $file ) ) {
            return null;
        }

        $mtime  = (int) filemtime( $file );
        $size   = (int) filesize( $file );
        $cached = get_post_meta( $id, self::HASH_META, true );
        if ( is_array( $cached ) && ( $cached['mtime'] ?? 0 ) === $mtime && ( $cached['size'] ?? 0 ) === $size ) {
            return $cached;
        }

        $mime      = (string) get_post_mime_type( $id );
        $is_raster = 0 === strpos( $mime, 'image/' ) && 'image/svg+xml' !== $mime;

        $hashes = array(
            'md5'       => md5_file( $file ),
            'base_name' => $this->strip_scale_suffix( wp_basename( $file ) ),
            'dhash'     => $is_raster ? $this->compute_dhash( $file, $mime ) : null,
            'mtime'     => $mtime,
            'size'      => $size,
        );
        update_post_meta( $id, self::HASH_META, $hashes );
        return $hashes;
    }

    /**
     * Group hashed attachments into exact, scale-variant and visual duplicates.
     * Stored as ID arrays only; items are built on read.
     */
    private function group_duplicates(): array {
        $rows = $this->db->get_results( $this->db->prepare(
            "SELECT pm.post_id, pm.meta_value FROM {$this->db->postmeta} pm
             INNER JOIN {$this->db->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND p.post_type = 'attachment' AND p.post_status = 'inherit'",
            self::HASH_META
        ) );

        $data = array();
        foreach ( $rows as $row ) {
            $h = maybe_unserialize( $row->meta_value );
            if ( is_array( $h ) ) {
                $data[ (int) $row->post_id ] = $h;
            }
        }

        $assigned = array();

        // 1. Exact (MD5).
        $exact = $this->group_by( $data, 'md5', $assigned );

        // 2. Scale / name variants (icon@2x.png ~ icon.png).
        $scale = $this->group_by( $data, 'base_name', $assigned );

        // 3. Visual (dHash, Hamming distance ≤ 10), union-find for transitive groups.
        $candidates = array();
        foreach ( $data as $id => $info ) {
            if ( ! isset( $assigned[ $id ] ) && ! empty( $info['dhash'] ) ) {
                $candidates[ $id ] = $this->dhash_words( $info['dhash'] );
            }
        }

        $visual         = array();
        $visual_skipped = count( $candidates ) > self::VISUAL_MAX;
        if ( ! $visual_skipped && count( $candidates ) > 1 ) {
            $visual = $this->group_visual( $candidates );
        }

        return array(
            'exact'          => $exact,
            'scale'          => $scale,
            'visual'         => $visual,
            'visual_skipped' => $visual_skipped,
        );
    }

    private function group_by( array $data, string $field, array &$assigned ): array {
        $map = array();
        foreach ( $data as $id => $info ) {
            if ( ! isset( $assigned[ $id ] ) && ! empty( $info[ $field ] ) ) {
                $map[ $info[ $field ] ][] = $id;
            }
        }
        $groups = array();
        foreach ( $map as $ids ) {
            if ( count( $ids ) > 1 ) {
                $groups[] = $ids;
                foreach ( $ids as $id ) {
                    $assigned[ $id ] = true;
                }
            }
        }
        return $groups;
    }

    private function group_visual( array $candidates ): array {
        static $popcount = null;
        if ( null === $popcount ) {
            $popcount = array( 0 );
            for ( $i = 1; $i < 65536; $i++ ) {
                $popcount[ $i ] = ( $i & 1 ) + $popcount[ $i >> 1 ];
            }
        }

        $ids    = array_keys( $candidates );
        $words  = array_values( $candidates );
        $count  = count( $ids );
        $parent = range( 0, $count - 1 );
        $find   = function ( int $i ) use ( &$parent ): int {
            while ( $parent[ $i ] !== $i ) {
                $parent[ $i ] = $parent[ $parent[ $i ] ];
                $i            = $parent[ $i ];
            }
            return $i;
        };

        for ( $i = 0; $i < $count; $i++ ) {
            $a = $words[ $i ];
            for ( $j = $i + 1; $j < $count; $j++ ) {
                $b = $words[ $j ];
                $d = $popcount[ $a[0] ^ $b[0] ] + $popcount[ $a[1] ^ $b[1] ];
                if ( $d > 10 ) {
                    continue;
                }
                $d += $popcount[ $a[2] ^ $b[2] ] + $popcount[ $a[3] ^ $b[3] ];
                if ( $d <= 10 ) {
                    $ri = $find( $i );
                    $rj = $find( $j );
                    if ( $ri !== $rj ) {
                        $parent[ $ri ] = $rj;
                    }
                }
            }
        }

        $components = array();
        for ( $i = 0; $i < $count; $i++ ) {
            $components[ $find( $i ) ][] = $ids[ $i ];
        }
        return array_values( array_filter( $components, function ( array $g ): bool {
            return count( $g ) > 1;
        } ) );
    }

    /**
     * Split a 16-hex-char dHash into four 16-bit integers.
     */
    private function dhash_words( string $hex ): array {
        $hex = str_pad( $hex, 16, '0' );
        return array(
            hexdec( substr( $hex, 0, 4 ) ),
            hexdec( substr( $hex, 4, 4 ) ),
            hexdec( substr( $hex, 8, 4 ) ),
            hexdec( substr( $hex, 12, 4 ) ),
        );
    }

    /**
     * Stored duplicate results with full items, or null if never scanned.
     */
    public function get_duplicate_results(): ?array {
        $stored = get_option( self::DUP_OPTION, null );
        if ( ! is_array( $stored ) ) {
            return null;
        }

        $sanitize = function ( $groups ): array {
            return array_map( function ( $g ) {
                return array_map( 'intval', (array) $g );
            }, (array) $groups );
        };
        $exact  = $sanitize( $stored['exact'] ?? array() );
        $scale  = $sanitize( $stored['scale'] ?? array() );
        $visual = $sanitize( $stored['visual'] ?? array() );

        $all = array();
        foreach ( array_merge( $exact, $scale, $visual ) as $group ) {
            $all = array_merge( $all, $group );
        }
        $this->prime_usage_cache( array_unique( $all ) );

        // Drop attachments deleted since the scan; drop groups left with one member.
        $build = function ( array $groups ): array {
            $out = array();
            foreach ( $groups as $ids ) {
                $ids = array_values( array_filter( $ids, function ( int $id ): bool {
                    $post = get_post( $id );
                    // get_post_status() reports an attachment's parent status, so read the row itself.
                    return $post && 'attachment' === $post->post_type && 'inherit' === $post->post_status;
                } ) );
                if ( count( $ids ) > 1 ) {
                    $out[] = array_map( array( $this, 'build_item' ), $ids );
                }
            }
            return $out;
        };

        $result = array(
            'exact'          => $build( $exact ),
            'scale'          => $build( $scale ),
            'visual'         => $build( $visual ),
            'visual_skipped' => ! empty( $stored['visual_skipped'] ),
        );

        $this->usage_cache = null;
        return $result;
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    private function skip_types_sql(): string {
        return "'" . implode( "','", array_map( 'esc_sql', self::SKIP_POST_TYPES ) ) . "'";
    }

    /**
     * Label, type and link for a post-backed reference.
     *
     * @param object $post  Row with post_title and post_type (ID optional).
     */
    private function describe_post( $post, int $post_id = 0 ): array {
        $post_id = $post_id ?: (int) $post->ID;
        $title   = $post->post_title ?: '#' . $post_id;

        switch ( $post->post_type ) {
            case 'custom_css':
                return array( 'type' => 'custom_css', 'label' => __( 'Additional CSS', 'media-janitor' ), 'url' => admin_url( 'customize.php' ) );
            case 'wp_global_styles':
                return array( 'type' => 'wp_global_styles', 'label' => __( 'Global Styles', 'media-janitor' ), 'url' => admin_url( 'site-editor.php' ) );
            case 'wp_template':
            case 'wp_template_part':
                return array( 'type' => $post->post_type, 'label' => $title, 'url' => admin_url( 'site-editor.php' ) );
        }

        return array( 'type' => $post->post_type, 'label' => $title, 'url' => $this->get_post_url( $post_id ) );
    }

    /**
     * Front-end URL for public posts (home URL for the static front page, since
     * get_permalink() can return ?p=ID during AJAX), admin edit URL otherwise —
     * the modal hides "Find on page" for admin links.
     */
    private function get_post_url( int $post_id ): string {
        if ( isset( $this->url_cache[ $post_id ] ) ) {
            return $this->url_cache[ $post_id ];
        }

        $url = '';
        if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $post_id ) {
            $url = home_url( '/' );
        } else {
            $post = get_post( $post_id );
            if ( $post ) {
                $pto = get_post_type_object( $post->post_type );
                $url = ( $pto && $pto->public && 'publish' === $post->post_status )
                    ? (string) get_permalink( $post_id )
                    : admin_url( 'post.php?post=' . $post_id . '&action=edit' );
            }
        }

        $this->url_cache[ $post_id ] = $url;
        return $url;
    }

    private function record_usage( int $attachment_id, string $source_type, int $source_id, string $label, string $url ): void {
        $key = $attachment_id . '|' . $source_type . '|' . $source_id . '|' . ( $source_id ? '' : $label );
        if ( isset( $this->recorded[ $key ] ) ) {
            return;
        }
        $this->recorded[ $key ] = true;

        $this->db->insert( $this->table, array(
            'attachment_id' => $attachment_id,
            'source_type'   => substr( $source_type, 0, 50 ),
            'source_id'     => $source_id,
            'source_label'  => $label,
            'source_url'    => $url,
        ), array( '%d', '%s', '%d', '%s', '%s' ) );
    }

    private function prime_usage_cache( array $ids ): void {
        $this->usage_cache = array();
        if ( empty( $ids ) ) {
            return;
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows         = $this->db->get_results( $this->db->prepare(
            "SELECT * FROM {$this->table} WHERE attachment_id IN ({$placeholders}) ORDER BY source_type, source_label", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_values( $ids )
        ) );

        foreach ( $rows as $row ) {
            $this->usage_cache[ (int) $row->attachment_id ][] = $row;
        }
    }

    /**
     * WHERE fragment filtering by MIME category ('' for all).
     */
    private function mime_clause( string $type ): string {
        switch ( $type ) {
            case 'image':
                return "p.post_mime_type LIKE 'image/%'";
            case 'video':
                return "p.post_mime_type LIKE 'video/%'";
            case 'audio':
                return "p.post_mime_type LIKE 'audio/%'";
            case 'document':
                return "p.post_mime_type NOT LIKE 'image/%' AND p.post_mime_type NOT LIKE 'video/%' AND p.post_mime_type NOT LIKE 'audio/%'";
            default:
                return '';
        }
    }

    /**
     * Bytes freed by deleting an attachment: the file, its original and every size.
     */
    private function disk_size( int $attachment_id ): int {
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! file_exists( $file ) ) {
            return 0;
        }

        $total = (int) filesize( $file );
        $meta  = wp_get_attachment_metadata( $attachment_id );
        if ( is_array( $meta ) ) {
            $dir = dirname( $file );
            if ( ! empty( $meta['original_image'] ) && file_exists( $dir . '/' . $meta['original_image'] ) ) {
                $total += (int) filesize( $dir . '/' . $meta['original_image'] );
            }
            foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
                if ( ! empty( $size['filesize'] ) ) {
                    $total += (int) $size['filesize'];
                } elseif ( ! empty( $size['file'] ) && file_exists( $dir . '/' . $size['file'] ) ) {
                    $total += (int) filesize( $dir . '/' . $size['file'] );
                }
            }
        }
        return $total;
    }

    /**
     * Difference hash: resize to 9×8 grayscale, encode left-vs-right comparisons
     * as 64 bits (16 hex chars). Format-specific GD loaders avoid reading the
     * whole file into a PHP string.
     */
    private function compute_dhash( string $file, string $mime ): ?string {
        $loaders = array(
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png'  => 'imagecreatefrompng',
            'image/gif'  => 'imagecreatefromgif',
            'image/webp' => 'imagecreatefromwebp',
            'image/avif' => 'imagecreatefromavif',
        );
        $loader = $loaders[ $mime ] ?? null;
        if ( ! $loader || ! function_exists( $loader ) || ! function_exists( 'imagecreatetruecolor' ) ) {
            return null;
        }

        // Decoding a huge bitmap can exhaust memory (uncatchable fatal) — skip it.
        $dims = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $dims || $dims[0] * $dims[1] > 40000000 ) {
            return null;
        }

        $img = @$loader( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $img ) {
            return null;
        }

        $small = imagecreatetruecolor( 9, 8 );
        imagecopyresampled( $small, $img, 0, 0, 0, 0, 9, 8, imagesx( $img ), imagesy( $img ) );
        unset( $img ); // imagedestroy() is a no-op since PHP 8 and deprecated in 8.5
        imagefilter( $small, IMG_FILTER_GRAYSCALE );

        $bits = '';
        for ( $y = 0; $y < 8; $y++ ) {
            for ( $x = 0; $x < 8; $x++ ) {
                $left  = ( imagecolorat( $small, $x, $y ) >> 16 ) & 0xFF;
                $right = ( imagecolorat( $small, $x + 1, $y ) >> 16 ) & 0xFF;
                $bits .= $left > $right ? '1' : '0';
            }
        }
        unset( $small );

        $hex = '';
        foreach ( str_split( $bits, 4 ) as $nibble ) {
            $hex .= base_convert( $nibble, 2, 16 );
        }
        return $hex;
    }

    /**
     * Strip scale suffixes and extension: "icon@2x.png" → "icon", "hero-3x.jpg" → "hero".
     */
    private function strip_scale_suffix( string $filename ): string {
        $name = strtolower( pathinfo( $filename, PATHINFO_FILENAME ) );
        return (string) preg_replace( '/(@|[-_])?[2-9]x$/', '', $name );
    }

    /**
     * Single media item for the front end.
     */
    private function build_item( int $attachment_id ): array {
        $post  = get_post( $attachment_id );
        $file  = get_attached_file( $attachment_id );
        $size  = $file && file_exists( $file ) ? (int) filesize( $file ) : 0;
        $usage = $this->get_usage( $attachment_id );
        $mime  = $post ? (string) $post->post_mime_type : '';

        $category = 'document';
        foreach ( array( 'image', 'video', 'audio' ) as $cat ) {
            if ( 0 === strpos( $mime, $cat . '/' ) ) {
                $category = $cat;
            }
        }

        return array(
            'id'       => $attachment_id,
            'title'    => $post ? $post->post_title : '',
            'filename' => $file ? wp_basename( $file ) : '',
            'url'      => (string) wp_get_attachment_url( $attachment_id ),
            'thumb'    => (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
            'edit_url' => (string) get_edit_post_link( $attachment_id, 'raw' ),
            'mime'     => $mime,
            'category' => $category,
            'size'     => $size,
            'size_hr'  => size_format( $size ),
            'date'     => $post ? $post->post_date : '',
            'usage'    => $usage,
            'used'     => ! empty( $usage ),
        );
    }
}
