<?php
/**
 * Plugin Name:       Media Janitor
 * Plugin URI:        https://github.com/wisnuub/media-janitor
 * Description:       Find and safely remove unused media files. Scans pages, posts, custom fields, widgets, theme settings and page builders to show exactly where each file is used — so you can clean up with confidence.
 * Version:           1.1.0
 * Author:            Wisnu A. Kurniawan
 * Author URI:        https://github.com/wisnuub
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       media-janitor
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MEDIA_JANITOR_VERSION', '1.1.0' );
define( 'MEDIA_JANITOR_DB_VERSION', '1.1' );
define( 'MEDIA_JANITOR_FILE', __FILE__ );
define( 'MEDIA_JANITOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEDIA_JANITOR_URL', plugin_dir_url( __FILE__ ) );
define( 'MEDIA_JANITOR_BASENAME', plugin_basename( __FILE__ ) );

require_once MEDIA_JANITOR_DIR . 'includes/class-media-janitor-scanner.php';
require_once MEDIA_JANITOR_DIR . 'includes/class-media-janitor-admin.php';
require_once MEDIA_JANITOR_DIR . 'includes/class-media-janitor-ajax.php';

/**
 * Initialize the plugin.
 */
function media_janitor_init() {
    media_janitor_maybe_upgrade();

    if ( is_admin() ) {
        new Media_Janitor_Admin();
        new Media_Janitor_Ajax();
    }
}
add_action( 'plugins_loaded', 'media_janitor_init' );

/**
 * Enqueue the frontend highlighter when an admin opens "Find on page".
 */
function media_janitor_frontend_highlighter() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
    if ( ! isset( $_GET['media_janitor_highlight'] ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    wp_enqueue_script(
        'media-janitor-highlighter',
        MEDIA_JANITOR_URL . 'assets/js/highlighter.js',
        array(),
        MEDIA_JANITOR_VERSION,
        true
    );
}
add_action( 'wp_enqueue_scripts', 'media_janitor_frontend_highlighter' );

/**
 * Drop cached hashes when an attachment's file is regenerated or replaced.
 *
 * @param mixed $data          Attachment metadata.
 * @param int   $attachment_id Attachment ID.
 * @return mixed
 */
function media_janitor_clear_hash( $data, $attachment_id ) {
    delete_post_meta( (int) $attachment_id, Media_Janitor_Scanner::HASH_META );
    return $data;
}
add_filter( 'wp_update_attachment_metadata', 'media_janitor_clear_hash', 10, 2 );

/**
 * Create or update the usage reference table.
 */
function media_janitor_install() {
    global $wpdb;

    $table   = Media_Janitor_Scanner::table_name();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        attachment_id bigint(20) unsigned NOT NULL,
        source_type varchar(50) NOT NULL,
        source_id bigint(20) unsigned NOT NULL DEFAULT 0,
        source_label text NOT NULL,
        source_url varchar(2083) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        KEY attachment_id (attachment_id),
        KEY source_type (source_type)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'media_janitor_db_version', MEDIA_JANITOR_DB_VERSION );
}
register_activation_hook( __FILE__, 'media_janitor_install' );

/**
 * Activation hooks don't run on updates, so install/migrate on version change.
 */
function media_janitor_maybe_upgrade() {
    if ( get_option( 'media_janitor_db_version' ) === MEDIA_JANITOR_DB_VERSION ) {
        return;
    }

    media_janitor_install();

    // 1.0 used short "mj_" names; drop them. A fresh scan is required anyway.
    global $wpdb;
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mj_media_usage" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- removing the 1.0 table.
    foreach ( array( 'mj_db_version', 'mj_last_scan', 'mj_duplicates' ) as $old_option ) {
        delete_option( $old_option );
    }
    delete_transient( 'mj_scan_progress' );
    delete_post_meta_by_key( '_mj_dhash' );
}

/**
 * Deactivation — nothing persistent to undo; data is removed on uninstall.
 */
function media_janitor_deactivate() {
    $state = Media_Janitor_Scanner::get_state();
    if ( 'running' === $state['status'] ) {
        $state['status'] = 'aborted';
        update_option( Media_Janitor_Scanner::STATE_OPTION, $state, false );
    }
}
register_deactivation_hook( __FILE__, 'media_janitor_deactivate' );

/**
 * "Donate" link under the plugin's description on the Plugins screen.
 *
 * @param array  $links Row meta links.
 * @param string $file  Plugin basename.
 * @return array
 */
function media_janitor_donate_link( $links, $file ) {
    if ( plugin_basename( __FILE__ ) === $file ) {
        $links[] = '<a href="https://paypal.me/toast415" target="_blank" rel="noopener">' . esc_html__( 'Donate', 'media-janitor' ) . '</a>';
    }
    return $links;
}
add_filter( 'plugin_row_meta', 'media_janitor_donate_link', 10, 2 );
