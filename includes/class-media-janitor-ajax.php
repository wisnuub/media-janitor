<?php
/**
 * Media Janitor — AJAX Handlers
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Media_Janitor_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_mj_scan', array( $this, 'handle_scan' ) );
        add_action( 'wp_ajax_mj_results', array( $this, 'handle_results' ) );
        add_action( 'wp_ajax_mj_delete', array( $this, 'handle_delete' ) );
    }

    /**
     * Run the scanner (one batch).
     */
    public function handle_scan(): void {
        check_ajax_referer( 'mj_scan', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $scanner = new Media_Janitor_Scanner();
        $result  = $scanner->scan_batch( $offset );

        if ( $result['done'] ) {
            update_option( 'mj_last_scan', time() );

            // Also return summary.
            $result['summary'] = $scanner->get_summary();
        }

        wp_send_json_success( $result );
    }

    /**
     * Fetch paginated, filtered results.
     */
    public function handle_results(): void {
        check_ajax_referer( 'mj_results', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $filter   = isset( $_POST['filter'] ) ? sanitize_text_field( wp_unslash( $_POST['filter'] ) ) : 'all';
        $type     = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'all';
        $search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        $paged    = isset( $_POST['paged'] ) ? absint( $_POST['paged'] ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 40;

        // Validate inputs.
        $allowed_filters = array( 'all', 'used', 'unused' );
        $allowed_types   = array( 'all', 'image', 'document', 'video', 'audio' );

        if ( ! in_array( $filter, $allowed_filters, true ) ) {
            $filter = 'all';
        }
        if ( ! in_array( $type, $allowed_types, true ) ) {
            $type = 'all';
        }

        $scanner = new Media_Janitor_Scanner();
        $data    = $scanner->get_results( $filter, $type, $search, $paged, $per_page );

        wp_send_json_success( $data );
    }

    /**
     * Delete selected attachments.
     */
    public function handle_delete(): void {
        check_ajax_referer( 'mj_delete', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
        $ids = array_filter( $ids );

        if ( empty( $ids ) ) {
            wp_send_json_error( 'No IDs provided.' );
        }

        $deleted = 0;
        $errors  = array();

        foreach ( $ids as $id ) {
            // Verify the attachment exists and is really an attachment.
            $post = get_post( $id );
            if ( ! $post || 'attachment' !== $post->post_type ) {
                $errors[] = "#{$id}: not found";
                continue;
            }

            // Force-delete (skip trash — attachments don't use trash by default).
            $result = wp_delete_attachment( $id, true );
            if ( $result ) {
                // Also remove from our usage table.
                global $wpdb;
                $wpdb->delete( $wpdb->prefix . 'mj_media_usage', array( 'attachment_id' => $id ), array( '%d' ) );
                $deleted++;
            } else {
                $errors[] = "#{$id}: delete failed";
            }
        }

        wp_send_json_success( array(
            'deleted' => $deleted,
            'errors'  => $errors,
        ) );
    }
}
