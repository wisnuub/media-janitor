<?php
/**
 * Plugin Name: Media Janitor
 * Plugin URI:  https://github.com/wisnuub/media-janitor
 * Description: Find and safely remove unused media files. Scans all pages, posts, widgets, theme settings, and page builders to identify where each media file is used — so you can clean up with confidence.
 * Version:     1.0
 * Author:      Wisnu
 * Author URI:  https://github.com/wisnuub
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: media-janitor
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'JEJEKIN_MJ_VERSION', '1.0' );
define( 'JEJEKIN_MJ_FILE', __FILE__ );
define( 'JEJEKIN_MJ_DIR', plugin_dir_path( __FILE__ ) );
define( 'JEJEKIN_MJ_URL', plugin_dir_url( __FILE__ ) );
define( 'JEJEKIN_MJ_BASENAME', plugin_basename( __FILE__ ) );

require_once JEJEKIN_MJ_DIR . 'includes/class-media-janitor-scanner.php';
require_once JEJEKIN_MJ_DIR . 'includes/class-media-janitor-admin.php';
require_once JEJEKIN_MJ_DIR . 'includes/class-media-janitor-ajax.php';

/**
 * Initialize the plugin.
 */
function jejekin_mj_init() {
    if ( is_admin() ) {
        new Media_Janitor_Admin();
        new Media_Janitor_Ajax();
    }
}
add_action( 'plugins_loaded', 'jejekin_mj_init' );

/**
 * Enqueue the frontend highlighter script when ?mj_highlight is present.
 * Only loads for logged-in admins previewing where media is used.
 */
function jejekin_mj_frontend_highlighter() {
    if ( ! isset( $_GET['mj_highlight'] ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    wp_enqueue_script(
        'media-janitor-highlighter',
        JEJEKIN_MJ_URL . 'assets/js/highlighter.js',
        array(),
        JEJEKIN_MJ_VERSION,
        true
    );
}
add_action( 'wp_enqueue_scripts', 'jejekin_mj_frontend_highlighter' );

/**
 * Activation hook — create the usage reference table.
 */
function jejekin_mj_activate() {
    global $wpdb;

    $table   = $wpdb->prefix . 'mj_media_usage';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        attachment_id bigint(20) unsigned NOT NULL,
        source_type varchar(50) NOT NULL,
        source_id bigint(20) unsigned NOT NULL DEFAULT 0,
        source_label text NOT NULL,
        source_url varchar(2083) NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        KEY attachment_id (attachment_id),
        KEY source_type (source_type)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    update_option( 'mj_db_version', '1.0' );
    update_option( 'mj_last_scan', 0 );
}
register_activation_hook( __FILE__, 'jejekin_mj_activate' );

/**
 * Deactivation hook — clean up transients.
 */
function jejekin_mj_deactivate() {
    delete_transient( 'mj_scan_progress' );
}
register_deactivation_hook( __FILE__, 'jejekin_mj_deactivate' );
