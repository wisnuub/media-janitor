<?php
/**
 * Media Janitor — remove everything the plugin stored. Media files are untouched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

function media_janitor_uninstall() {
    global $wpdb;

    // phpcs:disable WordPress.DB.DirectDatabaseQuery -- dropping the plugin's own table.
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}media_janitor_usage" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mj_media_usage" );
    // phpcs:enable

    foreach ( array(
        'media_janitor_db_version',
        'media_janitor_last_scan',
        'media_janitor_scan_state',
        'media_janitor_dup_state',
        'media_janitor_duplicates',
        'mj_db_version',
        'mj_last_scan',
        'mj_duplicates',
    ) as $option ) {
        delete_option( $option );
    }

    delete_post_meta_by_key( '_media_janitor_hash' );
    delete_post_meta_by_key( '_mj_dhash' );
}

media_janitor_uninstall();
