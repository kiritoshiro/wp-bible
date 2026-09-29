<?php
/**
 * Plugin Name: Bible
 * Plugin URI:  https://github.com/kiritoshiro/wp-bible
 * Description: Automatically detects Bible verse references on your site and shows a popup with the verse text. Supports Lithuanian-style references. Upload your own Bible module (SQLite3).
 * Version:     1.1.3
 * Author:      Bible Plugin
 * Text Domain: bible
 * Update URI:  https://github.com/kiritoshiro/wp-bible
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BIBLE_PLUGIN_VERSION', '1.1.3' );
define( 'BIBLE_PLUGIN_FILE', __FILE__ );
define( 'BIBLE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BIBLE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BIBLE_MODULES_DIR', BIBLE_PLUGIN_DIR . 'modules/' );

require_once BIBLE_PLUGIN_DIR . 'includes/class-bible-db.php';
require_once BIBLE_PLUGIN_DIR . 'includes/class-bible-admin.php';
require_once BIBLE_PLUGIN_DIR . 'includes/class-bible-frontend.php';
require_once BIBLE_PLUGIN_DIR . 'includes/class-bible-ajax.php';
require_once BIBLE_PLUGIN_DIR . 'includes/class-bible-github-updater.php';

new Bible_GitHub_Updater();

/**
 * Activation hook
 */
function bible_plugin_activate() {
    Bible_DB::create_tables();

    // Import default module if it exists and tables are empty
    $default_module = BIBLE_MODULES_DIR . 'LTRK.SQLite3';
    if ( file_exists( $default_module ) && Bible_DB::is_empty() ) {
        Bible_DB::import_sqlite_module( $default_module, 'LTRK' );
    }

    // Preserve customized aliases when reactivating or updating.
    add_option( 'bible_custom_aliases', Bible_DB::get_default_aliases() );

    // Set default settings
    if ( false === get_option( 'bible_settings' ) ) {
        update_option( 'bible_settings', array(
            'popup_trigger'  => 'hover',
            'enabled'        => '1',
            'popup_maxwidth' => '450',
        ) );
    }

    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'bible_plugin_activate' );

function bible_plugin_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'bible_plugin_deactivate' );

function bible_plugin_init() {
    if ( is_admin() ) {
        new Bible_Admin();
    }
    new Bible_Frontend();
    new Bible_Ajax();
}
add_action( 'plugins_loaded', 'bible_plugin_init' );
