<?php
/**
 * Plugin Name:       Post Media Cleanup
 * Plugin URI:        https://wordpress.org/plugins/post-media-cleanup/
 * Description:       Automatically deletes all associated media files when a post is permanently deleted.
 * Version:           1.0.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            Iftiar Hossain
 * Author URI:        https://iftiarhossain.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       post-media-cleanup
 * Domain Path:       /languages
 *
 * @package PostMediaCleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'POSTMEDIAWEB_VERSION', '1.0.0' );
define( 'POSTMEDIAWEB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'POSTMEDIAWEB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'POSTMEDIAWEB_OPTION_KEY', 'postmediaweb_settings' );

require_once POSTMEDIAWEB_PLUGIN_DIR . 'includes/class-postmediaweb-media-handler.php';
require_once POSTMEDIAWEB_PLUGIN_DIR . 'includes/class-postmediaweb-settings.php';
require_once POSTMEDIAWEB_PLUGIN_DIR . 'includes/class-postmediaweb-core.php';
require_once POSTMEDIAWEB_PLUGIN_DIR . 'admin/class-postmediaweb-admin.php';

register_activation_hook( __FILE__, 'postmediaweb_activate' );
register_deactivation_hook( __FILE__, 'postmediaweb_deactivate' );

function postmediaweb_activate() {
    $defaults = [
        'enabled'                   => true,
        'delete_featured'           => true,
        'delete_content_media'      => true,
        'delete_gallery'            => true,
        'skip_shared'               => true,
        'post_types'                => ['post', 'page']
    ];
    update_option( POSTMEDIAWEB_OPTION_KEY, $defaults );
}

function postmediaweb_deactivate() {
    // No cleanup needed on deactivation, but you could choose to delete the settings if desired.
}

add_action('plugins_loaded', 'postmediaweb_init');

function postmediaweb_init() {
    Postmediaweb_Core::get_instance();

    if(is_admin()) {
        Postmediaweb_Admin::get_instance();
    }
}

