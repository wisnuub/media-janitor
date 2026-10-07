<?php
/**
 * Media Janitor — AJAX Handlers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Media_Janitor_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_media_janitor_scan', array( $this, 'handle_scan' ) );
        add_action( 'wp_ajax_media_janitor_summary', array( $this, 'handle_summary' ) );
        add_action( 'wp_ajax_media_janitor_results', array( $this, 'handle_results' ) );
        add_action( 'wp_ajax_media_janitor_unused_ids', array( $this, 'handle_unused_ids' ) );
        add_action( 'wp_ajax_media_janitor_delete', array( $this, 'handle_delete' ) );
        add_action( 'wp_ajax_media_janitor_scan_duplicates', array( $this, 'handle_scan_duplicates' ) );
        add_action( 'wp_ajax_media_janitor_get_duplicates', array( $this, 'handle_get_duplicates' ) );
    }

    private function authorize(): void {
        check_ajax_referer( 'media_janitor', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'You do not have permission to do this.', 'media-janitor' ), 403 );
        }
    }

    /**
     * Start (restart=1) or continue the usage scan. The client calls this
     * repeatedly until status is "complete".
     */
    public function handle_scan(): void {
        $this->authorize();

        $scanner = new Media_Janitor_Scanner();
        if ( ! empty( $_POST['restart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
            $scanner->start_scan();
        }

        $state    = $scanner->run_slice();
        $response = array(
            'status'   => $state['status'],
            'step'     => $state['step'] ?? '',
            'progress' => Media_Janitor_Scanner::progress( $state ),
        );

        if ( 'complete' === $state['status'] ) {
            $response['summary']  = $scanner->get_summary();
            $response['lastScan'] = (int) $state['completed'];
        }

        wp_send_json_success( $response );
    }

    public function handle_summary(): void {
        $this->authorize();
        $scanner = new Media_Janitor_Scanner();
        wp_send_json_success( $scanner->get_summary() );
    }

    /**
     * Paginated, filtered results.
     */
    public function handle_results(): void {
        $this->authorize();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorize().
        $filter   = isset( $_POST['filter'] ) ? sanitize_key( wp_unslash( $_POST['filter'] ) ) : 'all';
        $type     = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'all';
        $search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $paged    = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 40;
        // phpcs:enable

        if ( ! in_array( $filter, array( 'all', 'used', 'unused' ), true ) ) {
            $filter = 'all';
        }
        if ( ! in_array( $type, array( 'all', 'image', 'document', 'video', 'audio' ), true ) ) {
            $type = 'all';
        }

        $scanner = new Media_Janitor_Scanner();
        wp_send_json_success( $scanner->get_results( $filter, $type, $search, $paged, $per_page ) );
    }

    /**
     * IDs for "Delete all unused" in the current category.
     */
    public function handle_unused_ids(): void {
        $this->authorize();

        $type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! in_array( $type, array( 'all', 'image', 'document', 'video', 'audio' ), true ) ) {
            $type = 'all';
        }

        $scanner = new Media_Janitor_Scanner();
        wp_send_json_success( $scanner->get_unused_ids( $type ) );
    }

    /**
     * Permanently delete attachments.
     *
     * Refused until a full scan has completed. Attachments the scan found in
     * use, or that content edited since the scan now references, are skipped
     * unless force=1 (sent only after the user confirms deleting used files
     * from the Duplicates view).
     */
    public function handle_delete(): void {
        $this->authorize();

        if ( ! Media_Janitor_Scanner::is_complete() ) {
            wp_send_json_error( __( 'Run a full scan before deleting. The last scan did not finish, so its results are incomplete.', 'media-janitor' ) );
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorize().
        $ids   = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) : array();
        $force = ! empty( $_POST['force'] );
        // phpcs:enable

        if ( empty( $ids ) ) {
            wp_send_json_error( __( 'No files selected.', 'media-janitor' ) );
        }

        global $wpdb;
        $scanner = new Media_Janitor_Scanner();
        $deleted = array();
        $skipped = array();

        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post || 'attachment' !== $post->post_type ) {
                $skipped[] = array( 'id' => $id, 'reason' => __( 'not found', 'media-janitor' ) );
                continue;
            }

            if ( ! $force ) {
                if ( $scanner->has_usage( $id ) ) {
                    $skipped[] = array( 'id' => $id, 'reason' => __( 'in use', 'media-janitor' ) );
                    continue;
                }
                if ( $scanner->has_new_reference( $id ) ) {
                    $skipped[] = array( 'id' => $id, 'reason' => __( 'used in content edited since the last scan', 'media-janitor' ) );
                    continue;
                }
            }

            /**
             * Short-circuit deletion, e.g. to move the file to a quarantine instead.
             *
             * @param null|bool|WP_Error $handled Null to delete normally; true if handled; WP_Error to skip.
             * @param int                $id      Attachment ID.
             */
            $handled = apply_filters( 'media_janitor_pre_delete', null, $id );
            if ( is_wp_error( $handled ) ) {
                $skipped[] = array( 'id' => $id, 'reason' => $handled->get_error_message() );
                continue;
            }

            if ( true === $handled || wp_delete_attachment( $id, true ) ) {
                $wpdb->delete( Media_Janitor_Scanner::table_name(), array( 'attachment_id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin's own table.
                $deleted[] = $id;
            } else {
                $skipped[] = array( 'id' => $id, 'reason' => __( 'delete failed', 'media-janitor' ) );
            }
        }

        wp_send_json_success( array(
            'deleted' => $deleted,
            'skipped' => $skipped,
        ) );
    }

    /**
     * Start (restart=1) or continue the duplicate scan.
     */
    public function handle_scan_duplicates(): void {
        $this->authorize();

        $scanner = new Media_Janitor_Scanner();
        if ( ! empty( $_POST['restart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $scanner->start_duplicates();
        }

        $state = $scanner->run_duplicates_slice();
        $data  = array(
            'status' => $state['status'],
            'done'   => (int) ( $state['done'] ?? 0 ),
            'total'  => (int) ( $state['total'] ?? 0 ),
        );
        if ( 'complete' === $state['status'] ) {
            $data['results'] = $scanner->get_duplicate_results();
        }

        wp_send_json_success( $data );
    }

    /**
     * Stored duplicate results (no re-scan).
     */
    public function handle_get_duplicates(): void {
        $this->authorize();

        $scanner = new Media_Janitor_Scanner();
        $result  = $scanner->get_duplicate_results();

        if ( null === $result ) {
            wp_send_json_error( 'not_scanned' );
        }
        wp_send_json_success( $result );
    }
}
