<?php
/**
 * Media Janitor — Scanner
 *
 * Scans every known content location in WordPress for media references
 * and records WHERE each attachment is used.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Media_Janitor_Scanner {

    /** @var wpdb */
    private $db;

    /** @var string  Usage table name. */
    private $table;

    /** @var string  Uploads base URL (without trailing slash). */
    private $uploads_url;

    /** @var string  Uploads base directory. */
    private $uploads_dir;

    /** @var array|null  Per-request usage cache primed by build_duplicate_groups() to avoid N+1 queries. */
    private $usage_cache = null;

    public function __construct() {
        global $wpdb;
        $this->db    = $wpdb;
        $this->table = $wpdb->prefix . 'mj_media_usage';

        $upload_info       = wp_get_upload_dir();
        $this->uploads_url = trailingslashit( $upload_info['baseurl'] );
        $this->uploads_dir = trailingslashit( $upload_info['basedir'] );
    }

    /* ------------------------------------------------------------------
     *  Public API
     * ----------------------------------------------------------------*/

    /**
     * Run a full scan.  Called in batches via AJAX.
     *
     * @param int $offset  Current offset into attachment IDs.
     * @param int $limit   Batch size.
     * @return array { total: int, scanned: int, done: bool }
     */
    public function scan_batch( int $offset = 0, int $limit = 50 ): array {
        // On the very first batch, rebuild the whole reference table.
        if ( 0 === $offset ) {
            $this->db->query( "TRUNCATE TABLE {$this->table}" );
            $this->scan_all_sources();
        }

        $total = (int) $this->db->get_var(
            "SELECT COUNT(*) FROM {$this->db->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
        );

        return array(
            'total'   => $total,
            'scanned' => min( $offset + $limit, $total ),
            'done'    => true,
        );
    }

    /**
     * Get categorized media results.
     *
     * @param string $filter  'all' | 'used' | 'unused'
     * @param string $type    'all' | 'image' | 'document' | 'video' | 'audio'
     * @param string $search  Search term for filename.
     * @param int    $paged   Page number.
     * @param int    $per_page Items per page.
     * @return array { items: array, total: int, pages: int }
     */
    public function get_results( string $filter = 'all', string $type = 'all', string $search = '', int $paged = 1, int $per_page = 40 ): array {
        $where = array( "p.post_type = 'attachment'", "p.post_status = 'inherit'" );
        $join  = '';

        // Type filter.
        $mime_clauses = $this->mime_clause( $type );
        if ( $mime_clauses ) {
            $where[] = $mime_clauses;
        }

        // Search.
        if ( $search ) {
            $like    = '%' . $this->db->esc_like( $search ) . '%';
            $where[] = $this->db->prepare( 'p.post_title LIKE %s', $like );
        }

        // Used / unused filter.
        if ( 'used' === $filter ) {
            $join    = "INNER JOIN {$this->table} u ON u.attachment_id = p.ID";
            $where[] = '1=1'; // join is enough
        } elseif ( 'unused' === $filter ) {
            $join    = "LEFT JOIN {$this->table} u ON u.attachment_id = p.ID";
            $where[] = 'u.id IS NULL';
        }

        $where_sql = implode( ' AND ', $where );

        // Count.
        $count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$this->db->posts} p {$join} WHERE {$where_sql}";
        $total     = (int) $this->db->get_var( $count_sql );

        // Paginate.
        $pages     = max( 1, (int) ceil( $total / $per_page ) );
        $offset    = ( $paged - 1 ) * $per_page;

        $rows = $this->db->get_results( $this->db->prepare(
            "SELECT DISTINCT p.ID FROM {$this->db->posts} p {$join}
             WHERE {$where_sql}
             ORDER BY p.post_date DESC
             LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ) );

        $items = array();
        foreach ( $rows as $row ) {
            $items[] = $this->build_item( (int) $row->ID );
        }

        return compact( 'items', 'total', 'pages' );
    }

    /**
     * Get usage details for a single attachment.
     */
    public function get_usage( int $attachment_id ): array {
        if ( null !== $this->usage_cache ) {
            $rows = $this->usage_cache[ $attachment_id ] ?? array();
        } else {
            $rows = $this->db->get_results( $this->db->prepare(
                "SELECT * FROM {$this->table} WHERE attachment_id = %d ORDER BY source_type, source_label",
                $attachment_id
            ) );
        }

        // Type priority: lower number = shown over higher when same source_id conflicts.
        $priority = array(
            'elementor'      => 0,
            'featured_image' => 1,
            'woo_gallery'    => 2,
        );
        $get_priority = function ( string $type ) use ( $priority ): int {
            return $priority[ $type ] ?? 10;
        };

        // Group post-backed entries by source_id; source_id=0 (widgets, options, etc.) are kept as-is.
        $by_source_id = array();
        $zero_source  = array();

        foreach ( $rows as $row ) {
            $source_id = (int) $row->source_id;

            // Skip trashed / deleted source posts.
            if ( $source_id > 0 ) {
                $status = get_post_status( $source_id );
                if ( ! $status || 'trash' === $status ) {
                    continue;
                }
            }

            if ( $source_id === 0 ) {
                $zero_source[] = $row;
            } elseif ( ! isset( $by_source_id[ $source_id ] ) ) {
                $by_source_id[ $source_id ] = $row;
            } else {
                // Keep the entry with the better (lower) priority type.
                $existing = $by_source_id[ $source_id ];
                if ( $get_priority( $row->source_type ) < $get_priority( $existing->source_type ) ) {
                    $by_source_id[ $source_id ] = $row;
                }
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
     * Get summary counts by category.
     */
    public function get_summary(): array {
        $cats = array( 'image', 'video', 'audio', 'document' );
        $out  = array(
            'total'       => 0,
            'used'        => 0,
            'unused'      => 0,
            'categories'  => array(),
            'unused_size' => 0,
        );

        foreach ( $cats as $cat ) {
            $mime  = $this->mime_clause( $cat );
            $where = "p.post_type = 'attachment' AND p.post_status = 'inherit'" . ( $mime ? " AND {$mime}" : '' );

            $cat_total = (int) $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->db->posts} p WHERE {$where}"
            );

            $cat_unused = (int) $this->db->get_var(
                "SELECT COUNT(*) FROM {$this->db->posts} p
                 LEFT JOIN {$this->table} u ON u.attachment_id = p.ID
                 WHERE {$where} AND u.id IS NULL"
            );

            $out['categories'][ $cat ] = array(
                'total'  => $cat_total,
                'used'   => $cat_total - $cat_unused,
                'unused' => $cat_unused,
            );

            $out['total']  += $cat_total;
            $out['unused'] += $cat_unused;
        }

        $out['used'] = $out['total'] - $out['unused'];

        // Calculate total size of unused files.
        $unused_ids = $this->db->get_col(
            "SELECT p.ID FROM {$this->db->posts} p
             LEFT JOIN {$this->table} u ON u.attachment_id = p.ID
             WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND u.id IS NULL"
        );

        foreach ( $unused_ids as $uid ) {
            $file = get_attached_file( (int) $uid );
            if ( $file && file_exists( $file ) ) {
                $out['unused_size'] += filesize( $file );
            }
        }

        return $out;
    }

    /**
     * Scan for duplicate media across three strategies:
     *   1. Exact  — byte-identical files (MD5 hash)
     *   2. Scale  — same base filename after stripping @2x / -2x / _2x suffixes
     *   3. Visual — perceptually similar raster images (dHash, Hamming distance ≤ 10)
     *
     * Results are stored in wp_options (mj_duplicates) and returned.
     */
    public function scan_duplicates(): array {
        @set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        $attachments = $this->get_all_attachments();
        $data        = array();
        $assigned    = array();

        foreach ( $attachments as $att ) {
            $id   = (int) $att->ID;
            $file = get_attached_file( $id );
            if ( ! $file || ! file_exists( $file ) ) {
                continue;
            }
            $mime      = get_post_mime_type( $id ) ?: '';
            $is_raster = str_starts_with( $mime, 'image/' ) && 'image/svg+xml' !== $mime;

            $data[ $id ] = array(
                'md5'       => md5_file( $file ),
                'base_name' => $this->strip_scale_suffix( wp_basename( $file ) ),
                // Bug 2 fix: pass $file so compute_dhash doesn't call get_attached_file() again
                // and uses format-specific GD loaders instead of file_get_contents().
                'dhash'     => $is_raster ? $this->compute_dhash( $id, $file ) : null,
            );
        }

        // 1. Exact duplicates (MD5).
        $exact   = array();
        $md5_map = array();
        foreach ( $data as $id => $info ) {
            $md5_map[ $info['md5'] ][] = $id;
        }
        foreach ( $md5_map as $ids ) {
            if ( count( $ids ) < 2 ) {
                continue;
            }
            $exact[] = $ids;
            foreach ( $ids as $id ) {
                $assigned[ $id ] = true;
            }
        }

        // 2. Scale / name variants (same base name, not already exact-matched).
        $scale    = array();
        $base_map = array();
        foreach ( $data as $id => $info ) {
            if ( isset( $assigned[ $id ] ) || ! $info['base_name'] ) {
                continue;
            }
            $base_map[ $info['base_name'] ][] = $id;
        }
        foreach ( $base_map as $ids ) {
            if ( count( $ids ) < 2 ) {
                continue;
            }
            $scale[] = $ids;
            foreach ( $ids as $id ) {
                $assigned[ $id ] = true;
            }
        }

        // 3. Visual duplicates — full pairwise pass + union-find for correct
        //    transitive grouping (Bug 1 fix: the old single-pivot approach silently
        //    dropped images that were only similar to an already-grouped image).
        $visual     = array();
        $candidates = array();
        foreach ( $data as $id => $info ) {
            if ( ! isset( $assigned[ $id ] ) && null !== $info['dhash'] ) {
                $candidates[ $id ] = $info['dhash'];
            }
        }

        if ( ! empty( $candidates ) ) {
            $ids_list = array_keys( $candidates );
            $cnt      = count( $ids_list );

            // Collect all similar pairs first.
            $pairs = array();
            for ( $i = 0; $i < $cnt; $i++ ) {
                for ( $j = $i + 1; $j < $cnt; $j++ ) {
                    if ( $this->hamming_distance( $candidates[ $ids_list[ $i ] ], $candidates[ $ids_list[ $j ] ] ) <= 10 ) {
                        $pairs[] = array( $ids_list[ $i ], $ids_list[ $j ] );
                    }
                }
            }

            // Union-find: every node starts as its own root.
            $parent = array_combine( $ids_list, $ids_list );
            $find   = null;
            $find   = function ( int $id ) use ( &$parent, &$find ): int {
                if ( $parent[ $id ] !== $id ) {
                    $parent[ $id ] = $find( $parent[ $id ] ); // path compression
                }
                return $parent[ $id ];
            };
            foreach ( $pairs as list( $a, $b ) ) {
                $ra = $find( $a );
                $rb = $find( $b );
                if ( $ra !== $rb ) {
                    $parent[ $ra ] = $rb;
                }
            }

            // Collect connected components with 2+ members.
            $components = array();
            foreach ( $ids_list as $id ) {
                $components[ $find( $id ) ][] = $id;
            }
            $visual = array_values(
                array_filter( $components, fn( array $g ) => count( $g ) >= 2 )
            );
        }

        // Bug 6 fix: store only ID arrays — not full item objects — to keep the
        // option small.  Full items are built on read in build_duplicate_groups().
        update_option( 'mj_duplicates', array(
            'exact'  => $exact,
            'scale'  => $scale,
            'visual' => $visual,
        ), false );

        return $this->build_duplicate_groups( $exact, $scale, $visual );
    }

    /**
     * Return stored duplicate scan results, or null if never scanned.
     * Stored data contains only ID arrays; full items are built here on read.
     */
    public function get_duplicate_results(): ?array {
        $stored = get_option( 'mj_duplicates', null );
        if ( ! is_array( $stored ) ) {
            return null;
        }

        // Detect old format where full item arrays were stored instead of IDs
        // (written by the version before Bug 6 was fixed).  Force re-scan.
        $first_group = $stored['exact'][0] ?? $stored['scale'][0] ?? $stored['visual'][0] ?? null;
        if ( ! empty( $first_group ) && is_array( reset( $first_group ) ) ) {
            delete_option( 'mj_duplicates' );
            return null;
        }

        return $this->build_duplicate_groups(
            array_map( fn( $g ) => array_map( 'intval', $g ), $stored['exact']  ?? array() ),
            array_map( fn( $g ) => array_map( 'intval', $g ), $stored['scale']  ?? array() ),
            array_map( fn( $g ) => array_map( 'intval', $g ), $stored['visual'] ?? array() )
        );
    }

    /**
     * Build full item payloads for a set of ID-only groups.
     * Primes a batch usage cache first so get_usage() needs only one query total.
     */
    private function build_duplicate_groups( array $exact, array $scale, array $visual ): array {
        $all_ids = array();
        foreach ( array_merge( $exact, $scale, $visual ) as $group ) {
            foreach ( $group as $id ) {
                $all_ids[] = (int) $id;
            }
        }

        if ( ! empty( $all_ids ) ) {
            $this->prime_usage_cache( array_unique( $all_ids ) );
        }

        $build = function ( array $groups ): array {
            return array_map( function ( array $ids ): array {
                return array_map( fn( int $id ) => $this->build_item( $id ), $ids );
            }, $groups );
        };

        $result = array(
            'exact'  => $build( $exact ),
            'scale'  => $build( $scale ),
            'visual' => $build( $visual ),
        );

        $this->usage_cache = null; // release after use
        return $result;
    }

    /**
     * Batch-fetch all usage rows for the given attachment IDs in a single query
     * and store them keyed by attachment_id so get_usage() can skip per-row SELECTs.
     */
    private function prime_usage_cache( array $ids ): void {
        $this->usage_cache = array();

        if ( empty( $ids ) ) {
            return;
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows         = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->table} WHERE attachment_id IN ({$placeholders}) ORDER BY source_type, source_label",
                $ids
            )
        );

        foreach ( $rows as $row ) {
            $this->usage_cache[ (int) $row->attachment_id ][] = $row;
        }
    }

    /* ------------------------------------------------------------------
     *  Scanning sources
     * ----------------------------------------------------------------*/

    /**
     * Scan every known content source and populate the usage table.
     */
    private function scan_all_sources(): void {
        $this->scan_post_content();
        $this->scan_post_meta();
        $this->scan_featured_images();
        $this->scan_woocommerce_galleries();
        $this->scan_widgets();
        $this->scan_theme_mods();
        $this->scan_options();
        $this->scan_nav_menus();
        $this->scan_elementor();
        $this->scan_custom_css();
    }

    /**
     * Scan all post_content fields for media URLs.
     */
    private function scan_post_content(): void {
        $attachments = $this->get_all_attachments();

        // Build a URL map: relative_path => attachment_id.
        $url_map = array();
        foreach ( $attachments as $att ) {
            $file = get_post_meta( $att->ID, '_wp_attached_file', true );
            if ( $file ) {
                $url_map[ $file ] = (int) $att->ID;

                // Also index thumbnail sizes.
                $meta = wp_get_attachment_metadata( $att->ID );
                if ( ! empty( $meta['sizes'] ) ) {
                    $dir = dirname( $file );
                    foreach ( $meta['sizes'] as $size ) {
                        $sized_path = ( '.' === $dir ) ? $size['file'] : $dir . '/' . $size['file'];
                        $url_map[ $sized_path ] = (int) $att->ID;
                    }
                }
            }
        }

        if ( empty( $url_map ) ) {
            return;
        }

        // Scan posts in chunks.
        // Skip Elementor-built posts here — they are handled by scan_elementor()
        // which reads the _elementor_data meta directly and is more precise.
        $elementor_post_ids = $this->db->get_col(
            "SELECT DISTINCT post_id FROM {$this->db->postmeta} WHERE meta_key = '_elementor_data'"
        );

        $batch = 200;
        $offset = 0;

        do {
            $posts = $this->db->get_results( $this->db->prepare(
                "SELECT ID, post_title, post_content, post_type
                 FROM {$this->db->posts}
                 WHERE post_type NOT IN ('attachment','revision','auto-draft','nav_menu_item')
                 AND post_status NOT IN ('auto-draft','trash')
                 AND post_content != ''
                 LIMIT %d OFFSET %d",
                $batch,
                $offset
            ) );

            foreach ( $posts as $post ) {
                // Skip Elementor-managed posts to avoid duplicate references.
                if ( in_array( (string) $post->ID, $elementor_post_ids, true ) ) {
                    continue;
                }
                $content = $post->post_content;
                foreach ( $url_map as $path => $att_id ) {
                    if ( false !== strpos( $content, $path ) ) {
                        $this->record_usage(
                            $att_id,
                            $post->post_type,
                            (int) $post->ID,
                            $post->post_title ?: "(#{$post->ID})",
                            $this->get_post_url( (int) $post->ID )
                        );
                    }
                }
            }

            $offset += $batch;
        } while ( count( $posts ) === $batch );
    }

    /**
     * Scan postmeta values for attachment URLs / IDs.
     */
    private function scan_post_meta(): void {
        $attachments = $this->get_all_attachments();
        $id_list     = wp_list_pluck( $attachments, 'ID' );

        if ( empty( $id_list ) ) {
            return;
        }

        // Build URL fragments for searching.
        $url_fragments = array();
        foreach ( $attachments as $att ) {
            $file = get_post_meta( $att->ID, '_wp_attached_file', true );
            if ( $file ) {
                $url_fragments[ $file ] = (int) $att->ID;
            }
        }

        // Skip known internal meta keys that we handle elsewhere.
        $skip_keys = array(
            '_thumbnail_id',
            '_wp_attached_file',
            '_wp_attachment_metadata',
            '_product_image_gallery',
            '_elementor_data',
        );
        $skip_placeholders = implode( ',', array_fill( 0, count( $skip_keys ), '%s' ) );

        $batch  = 500;
        $offset = 0;

        do {
            $query = $this->db->prepare(
                "SELECT pm.meta_id, pm.post_id, pm.meta_key, pm.meta_value
                 FROM {$this->db->postmeta} pm
                 INNER JOIN {$this->db->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type != 'attachment'
                 AND p.post_type != 'revision'
                 AND p.post_status NOT IN ('auto-draft','trash')
                 AND pm.meta_key NOT IN ({$skip_placeholders})
                 AND pm.meta_value != ''
                 LIMIT %d OFFSET %d",
                array_merge( $skip_keys, array( $batch, $offset ) )
            );

            $rows = $this->db->get_results( $query );

            foreach ( $rows as $row ) {
                $val = $row->meta_value;

                // Check if the meta value IS an attachment ID.
                if ( is_numeric( $val ) && in_array( (int) $val, $id_list, true ) ) {
                    $post = get_post( $row->post_id );
                    $this->record_usage(
                        (int) $val,
                        'meta:' . $row->meta_key,
                        (int) $row->post_id,
                        $post ? ( $post->post_title ?: "(#{$post->ID})" ) : "(#{$row->post_id})",
                        $post ? ( $this->get_post_url( (int) $post->ID ) ) : ''
                    );
                    continue;
                }

                // Check if the value contains media URLs.
                foreach ( $url_fragments as $path => $att_id ) {
                    if ( false !== strpos( $val, $path ) ) {
                        $post = get_post( $row->post_id );
                        $this->record_usage(
                            $att_id,
                            'meta:' . $row->meta_key,
                            (int) $row->post_id,
                            $post ? ( $post->post_title ?: "(#{$post->ID})" ) : "(#{$row->post_id})",
                            $post ? ( $this->get_post_url( (int) $post->ID ) ) : ''
                        );
                    }
                }
            }

            $offset += $batch;
        } while ( count( $rows ) === $batch );
    }

    /**
     * Scan featured images (_thumbnail_id).
     */
    private function scan_featured_images(): void {
        $rows = $this->db->get_results(
            "SELECT pm.meta_value AS att_id, p.ID AS post_id, p.post_title, p.post_type
             FROM {$this->db->postmeta} pm
             INNER JOIN {$this->db->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_thumbnail_id'
             AND pm.meta_value > 0
             AND p.post_status NOT IN ('auto-draft','trash')"
        );

        foreach ( $rows as $row ) {
            $this->record_usage(
                (int) $row->att_id,
                'featured_image',
                (int) $row->post_id,
                $row->post_title ?: "(#{$row->post_id})",
                $this->get_post_url( (int) $row->post_id )
            );
        }
    }

    /**
     * Scan WooCommerce product gallery images.
     */
    private function scan_woocommerce_galleries(): void {
        $rows = $this->db->get_results(
            "SELECT pm.meta_value, p.ID AS post_id, p.post_title
             FROM {$this->db->postmeta} pm
             INNER JOIN {$this->db->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_product_image_gallery'
             AND pm.meta_value != ''
             AND p.post_status NOT IN ('auto-draft','trash')"
        );

        foreach ( $rows as $row ) {
            $ids = array_filter( array_map( 'intval', explode( ',', $row->meta_value ) ) );
            foreach ( $ids as $att_id ) {
                $this->record_usage(
                    $att_id,
                    'woo_gallery',
                    (int) $row->post_id,
                    $row->post_title ?: "(#{$row->post_id})",
                    $this->get_post_url( (int) $row->post_id )
                );
            }
        }
    }

    /**
     * Scan widget data stored in options.
     */
    private function scan_widgets(): void {
        $attachments = $this->get_all_attachments();
        $url_fragments = array();
        foreach ( $attachments as $att ) {
            $file = get_post_meta( $att->ID, '_wp_attached_file', true );
            if ( $file ) {
                $url_fragments[ $file ] = (int) $att->ID;
            }
        }

        // Get all widget options.
        $widget_options = $this->db->get_results(
            "SELECT option_name, option_value FROM {$this->db->options}
             WHERE option_name LIKE 'widget_%'"
        );

        foreach ( $widget_options as $opt ) {
            $val = $opt->option_value;
            foreach ( $url_fragments as $path => $att_id ) {
                if ( false !== strpos( $val, $path ) ) {
                    $this->record_usage(
                        $att_id,
                        'widget',
                        0,
                        'Widget: ' . str_replace( 'widget_', '', $opt->option_name ),
                        admin_url( 'widgets.php' )
                    );
                }
            }

            // Also check for numeric attachment IDs in serialized widget data.
            $data = maybe_unserialize( $val );
            if ( is_array( $data ) ) {
                $this->scan_widget_array( $data, $opt->option_name, $attachments );
            }
        }
    }

    /**
     * Recursively scan a widget data array for attachment IDs.
     */
    private function scan_widget_array( array $data, string $option_name, array $attachments ): void {
        $id_list = wp_list_pluck( $attachments, 'ID' );

        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                $this->scan_widget_array( $value, $option_name, $attachments );
            } elseif ( is_numeric( $value ) && in_array( (int) $value, $id_list, true ) ) {
                $this->record_usage(
                    (int) $value,
                    'widget',
                    0,
                    'Widget: ' . str_replace( 'widget_', '', $option_name ),
                    admin_url( 'widgets.php' )
                );
            }
        }
    }

    /**
     * Scan theme mods (customizer settings).
     */
    private function scan_theme_mods(): void {
        $mods = get_theme_mods();
        if ( ! is_array( $mods ) ) {
            return;
        }

        $attachments   = $this->get_all_attachments();
        $id_list       = wp_list_pluck( $attachments, 'ID' );
        $url_fragments = array();
        foreach ( $attachments as $att ) {
            $file = get_post_meta( $att->ID, '_wp_attached_file', true );
            if ( $file ) {
                $url_fragments[ $file ] = (int) $att->ID;
            }
        }

        foreach ( $mods as $key => $val ) {
            $val_str = is_scalar( $val ) ? (string) $val : wp_json_encode( $val );

            // Check numeric ID.
            if ( is_numeric( $val ) && in_array( (int) $val, $id_list, true ) ) {
                $this->record_usage(
                    (int) $val,
                    'theme_mod',
                    0,
                    "Customizer: {$key}",
                    admin_url( 'customize.php' )
                );
            }

            // Check URL fragments.
            foreach ( $url_fragments as $path => $att_id ) {
                if ( false !== strpos( $val_str, $path ) ) {
                    $this->record_usage(
                        $att_id,
                        'theme_mod',
                        0,
                        "Customizer: {$key}",
                        admin_url( 'customize.php' )
                    );
                }
            }
        }

        // Site icon & custom logo.
        $site_icon = get_option( 'site_icon' );
        if ( $site_icon ) {
            $this->record_usage( (int) $site_icon, 'option', 0, 'Site Icon', admin_url( 'customize.php' ) );
        }
        $custom_logo = get_theme_mod( 'custom_logo' );
        if ( $custom_logo ) {
            $this->record_usage( (int) $custom_logo, 'theme_mod', 0, 'Site Logo', admin_url( 'customize.php' ) );
        }
    }

    /**
     * Scan key wp_options entries that might hold media references.
     */
    private function scan_options(): void {
        $attachments   = $this->get_all_attachments();
        $url_fragments = array();
        foreach ( $attachments as $att ) {
            $file = get_post_meta( $att->ID, '_wp_attached_file', true );
            if ( $file ) {
                $url_fragments[ $file ] = (int) $att->ID;
            }
        }

        // Scan all non-transient options that contain upload paths.
        $upload_subdir = wp_basename( $this->uploads_dir );

        $options = $this->db->get_results( $this->db->prepare(
            "SELECT option_name, option_value FROM {$this->db->options}
             WHERE option_value LIKE %s
             AND option_name NOT LIKE %s
             AND option_name NOT LIKE 'widget_%%'",
            '%' . $this->db->esc_like( $upload_subdir ) . '%',
            '_transient%'
        ) );

        foreach ( $options as $opt ) {
            foreach ( $url_fragments as $path => $att_id ) {
                if ( false !== strpos( $opt->option_value, $path ) ) {
                    $this->record_usage(
                        $att_id,
                        'option',
                        0,
                        "Option: {$opt->option_name}",
                        admin_url( 'options.php' )
                    );
                }
            }
        }
    }

    /**
     * Scan navigation menu items.
     */
    private function scan_nav_menus(): void {
        $attachments   = $this->get_all_attachments();
        $url_fragments = array();
        foreach ( $attachments as $att ) {
            $file = get_post_meta( $att->ID, '_wp_attached_file', true );
            if ( $file ) {
                $url_fragments[ $file ] = (int) $att->ID;
            }
        }

        $menu_items = $this->db->get_results(
            "SELECT p.ID, p.post_title, pm.meta_value AS url
             FROM {$this->db->posts} p
             INNER JOIN {$this->db->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_menu_item_url'
             WHERE p.post_type = 'nav_menu_item'"
        );

        foreach ( $menu_items as $item ) {
            foreach ( $url_fragments as $path => $att_id ) {
                if ( false !== strpos( $item->url, $path ) ) {
                    $this->record_usage(
                        $att_id,
                        'nav_menu',
                        (int) $item->ID,
                        'Menu Link: ' . ( $item->post_title ?: "(#{$item->ID})" ),
                        admin_url( 'nav-menus.php' )
                    );
                }
            }
        }
    }

    /**
     * Scan Elementor page builder data.
     */
    private function scan_elementor(): void {
        $rows = $this->db->get_results(
            "SELECT pm.post_id, pm.meta_value, p.post_title
             FROM {$this->db->postmeta} pm
             INNER JOIN {$this->db->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_elementor_data'
             AND pm.meta_value != ''
             AND p.post_status NOT IN ('auto-draft','trash')"
        );

        $attachments   = $this->get_all_attachments();
        $id_list       = wp_list_pluck( $attachments, 'ID' );
        $url_fragments = array();
        foreach ( $attachments as $att ) {
            $file = get_post_meta( $att->ID, '_wp_attached_file', true );
            if ( ! $file ) {
                continue;
            }
            $url_fragments[ $file ] = (int) $att->ID;

            // Also map every thumbnail size so Elementor's resized image URLs
            // (e.g. photo-1024x768.jpg) are caught, not just the original.
            $meta = wp_get_attachment_metadata( $att->ID );
            if ( ! empty( $meta['sizes'] ) ) {
                $dir = dirname( $file );
                foreach ( $meta['sizes'] as $size ) {
                    $sized_path = ( '.' === $dir ) ? $size['file'] : $dir . '/' . $size['file'];
                    $url_fragments[ $sized_path ] = (int) $att->ID;
                }
            }
        }

        foreach ( $rows as $row ) {
            $data = $row->meta_value;

            // Check URLs.
            foreach ( $url_fragments as $path => $att_id ) {
                if ( false !== strpos( $data, $path ) ) {
                    $this->record_usage(
                        $att_id,
                        'elementor',
                        (int) $row->post_id,
                        $row->post_title ?: "(#{$row->post_id})",
                        $this->get_post_url( (int) $row->post_id )
                    );
                }
            }

            // Check IDs in Elementor JSON (e.g. "id":"123").
            foreach ( $id_list as $att_id ) {
                if ( preg_match( '/"id"\s*:\s*"?' . $att_id . '"?/', $data ) ) {
                    $this->record_usage(
                        (int) $att_id,
                        'elementor',
                        (int) $row->post_id,
                        $row->post_title ?: "(#{$row->post_id})",
                        $this->get_post_url( (int) $row->post_id )
                    );
                }
            }
        }
    }

    /**
     * Scan Additional CSS (Customizer custom CSS).
     */
    private function scan_custom_css(): void {
        $custom_css = wp_get_custom_css();
        if ( ! $custom_css ) {
            return;
        }

        $attachments   = $this->get_all_attachments();
        $url_fragments = array();
        foreach ( $attachments as $att ) {
            $file = get_post_meta( $att->ID, '_wp_attached_file', true );
            if ( $file ) {
                $url_fragments[ $file ] = (int) $att->ID;
            }
        }

        foreach ( $url_fragments as $path => $att_id ) {
            if ( false !== strpos( $custom_css, $path ) ) {
                $this->record_usage(
                    $att_id,
                    'custom_css',
                    0,
                    'Additional CSS (Customizer)',
                    admin_url( 'customize.php' )
                );
            }
        }
    }

    /* ------------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------*/

    /**
     * Get the canonical URL for a post.
     *
     * - Returns home_url('/') for the static front page (get_permalink() can
     *   return ?p=ID during AJAX because rewrites aren't initialised).
     * - Returns the admin edit URL for non-public post types (e.g. Elementor
     *   templates / elementor_library) so "Find on page" is suppressed in the
     *   modal rather than opening a 404.
     * - Returns the normal permalink for everything else.
     */
    private function get_post_url( int $post_id ): string {
        if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $post_id ) {
            return home_url( '/' );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return '';
        }

        $pto = get_post_type_object( $post->post_type );
        if ( ! $pto || ! $pto->public ) {
            // Non-public: link to the admin editor — the modal will show the
            // link but the isAdmin check will suppress "Find on page".
            return admin_url( 'post.php?post=' . $post_id . '&action=edit' );
        }

        return get_permalink( $post_id ) ?: '';
    }

    /**
     * Record a single usage reference (de-duplicated).
     */
    private function record_usage( int $attachment_id, string $source_type, int $source_id, string $label, string $url ): void {
        // Prevent duplicates.
        $exists = $this->db->get_var( $this->db->prepare(
            "SELECT id FROM {$this->table} WHERE attachment_id = %d AND source_type = %s AND source_id = %d LIMIT 1",
            $attachment_id,
            $source_type,
            $source_id
        ) );

        if ( $exists ) {
            return;
        }

        $this->db->insert( $this->table, array(
            'attachment_id' => $attachment_id,
            'source_type'   => $source_type,
            'source_id'     => $source_id,
            'source_label'  => $label,
            'source_url'    => $url,
        ), array( '%d', '%s', '%d', '%s', '%s' ) );
    }

    /**
     * Get all attachment posts.
     */
    private function get_all_attachments(): array {
        static $cache = null;
        if ( null === $cache ) {
            $cache = $this->db->get_results(
                "SELECT ID FROM {$this->db->posts}
                 WHERE post_type = 'attachment' AND post_status = 'inherit'"
            );
        }
        return $cache;
    }

    /**
     * Return a WHERE clause fragment filtering by MIME type category.
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
                return "(p.post_mime_type LIKE 'application/%' OR p.post_mime_type LIKE 'text/%')";
            default:
                return '';
        }
    }

    /**
     * Compute a difference hash (dHash) for a raster image.
     * Resizes to 9×8, converts to grayscale, then encodes left-vs-right pixel
     * comparisons as a 64-bit value (16 hex chars).  Cached in post meta.
     *
     * @param string $file  Optional pre-resolved file path; avoids a redundant
     *                      get_attached_file() call when the caller already has it.
     */
    private function compute_dhash( int $attachment_id, string $file = '' ): ?string {
        $cached = get_post_meta( $attachment_id, '_mj_dhash', true );
        if ( $cached ) {
            return $cached;
        }

        if ( ! $file ) {
            $file = get_attached_file( $attachment_id );
        }
        if ( ! $file || ! file_exists( $file ) ) {
            return null;
        }

        // Use format-specific GD loaders (Bug 2 fix): avoids loading the entire
        // file into a PHP string via file_get_contents(), which can exhaust memory
        // on large images.  Fall back to imagecreatefromstring() for exotic formats.
        $mime = get_post_mime_type( $attachment_id );
        $img  = false;
        switch ( $mime ) {
            case 'image/jpeg':
                if ( function_exists( 'imagecreatefromjpeg' ) ) {
                    $img = @imagecreatefromjpeg( $file );
                }
                break;
            case 'image/png':
                if ( function_exists( 'imagecreatefrompng' ) ) {
                    $img = @imagecreatefrompng( $file );
                }
                break;
            case 'image/gif':
                if ( function_exists( 'imagecreatefromgif' ) ) {
                    $img = @imagecreatefromgif( $file );
                }
                break;
            case 'image/webp':
                if ( function_exists( 'imagecreatefromwebp' ) ) {
                    $img = @imagecreatefromwebp( $file );
                }
                break;
            default:
                if ( function_exists( 'imagecreatefromstring' ) ) {
                    $raw = @file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                    if ( $raw ) {
                        $img = @imagecreatefromstring( $raw );
                    }
                }
        }

        if ( ! $img ) {
            return null;
        }

        $small = imagecreatetruecolor( 9, 8 );
        imagecopyresampled( $small, $img, 0, 0, 0, 0, 9, 8, imagesx( $img ), imagesy( $img ) );
        imagedestroy( $img );
        imagefilter( $small, IMG_FILTER_GRAYSCALE );

        $bits = '';
        for ( $y = 0; $y < 8; $y++ ) {
            for ( $x = 0; $x < 8; $x++ ) {
                $left  = ( imagecolorat( $small, $x,     $y ) >> 16 ) & 0xFF;
                $right = ( imagecolorat( $small, $x + 1, $y ) >> 16 ) & 0xFF;
                $bits .= $left > $right ? '1' : '0';
            }
        }
        imagedestroy( $small );

        $hex = '';
        foreach ( str_split( $bits, 4 ) as $nibble ) {
            $hex .= base_convert( $nibble, 2, 16 );
        }

        update_post_meta( $attachment_id, '_mj_dhash', $hex );
        return $hex;
    }

    /**
     * Count differing bits (Hamming distance) between two hex-encoded hashes.
     */
    private function hamming_distance( string $hex1, string $hex2 ): int {
        $distance = 0;
        $len      = min( strlen( $hex1 ), strlen( $hex2 ) );
        for ( $i = 0; $i < $len; $i++ ) {
            $xor       = hexdec( $hex1[ $i ] ) ^ hexdec( $hex2[ $i ] );
            $distance += substr_count( decbin( $xor ), '1' );
        }
        return $distance;
    }

    /**
     * Strip Figma-style scale suffixes and the file extension from a filename.
     * "icon@2x.png" → "icon"  |  "hero-3x.jpg" → "hero"  |  "logo_2x.png" → "logo"
     */
    private function strip_scale_suffix( string $filename ): string {
        $name = strtolower( pathinfo( $filename, PATHINFO_FILENAME ) );
        $name = preg_replace( '/@[2-9]x$/', '', $name );
        $name = preg_replace( '/[-_][2-9]x$/', '', $name );
        $name = preg_replace( '/[2-9]x$/', '', $name );
        return $name;
    }

    /**
     * Build a single media item array for the front-end.
     */
    private function build_item( int $attachment_id ): array {
        $post  = get_post( $attachment_id );
        $meta  = wp_get_attachment_metadata( $attachment_id );
        $file  = get_attached_file( $attachment_id );
        $size  = $file && file_exists( $file ) ? filesize( $file ) : 0;
        $url   = wp_get_attachment_url( $attachment_id );
        $thumb = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
        $usage = $this->get_usage( $attachment_id );
        $mime  = $post ? $post->post_mime_type : '';

        // Determine category.
        if ( str_starts_with( $mime, 'image/' ) ) {
            $category = 'image';
        } elseif ( str_starts_with( $mime, 'video/' ) ) {
            $category = 'video';
        } elseif ( str_starts_with( $mime, 'audio/' ) ) {
            $category = 'audio';
        } else {
            $category = 'document';
        }

        return array(
            'id'        => $attachment_id,
            'title'     => $post ? $post->post_title : '',
            'filename'  => $file ? wp_basename( $file ) : '',
            'url'       => $url,
            'thumb'     => $thumb ?: '',
            'mime'      => $mime,
            'category'  => $category,
            'size'      => $size,
            'size_hr'   => size_format( $size ),
            'date'      => $post ? $post->post_date : '',
            'usage'     => $usage,
            'used'      => ! empty( $usage ),
        );
    }
}
