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

define( 'PMC_VERSION', '1.0.0' );
define( 'PMC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PMC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PMC_OPTION_KEY', 'post_media_cleanup_settings' );

require_once PMC_PLUGIN_DIR . 'includes/class-pmc-media-handler.php';
require_once PMC_PLUGIN_DIR . 'includes/class-pmc-settings.php';
require_once PMC_PLUGIN_DIR . 'includes/class-pmc-core.php';
require_once PMC_PLUGIN_DIR . 'admin/class-pmc-admin.php';

register_activation_hook( __FILE__, 'pmc_activate' );
register_deactivation_hook( __FILE__, 'pmc_deactivate' );

function pmc_activate() {
    $defaults = [
        'enabled'                   => true,
        'delete_featured'           => true,
        'delete_content_media'      => true,
        'delete_gallery'            => true,
        'skip_shared'               => true,
        'post_types'                => ['post', 'page']
    ];
    update_option( PMC_OPTION_KEY, $defaults );
}

function pmc_deactivate() {
    // No cleanup needed on deactivation, but you could choose to delete the settings if desired.
}

add_action('plugin_loaded', 'pmc_init');

function pmc_init() {
    load_plugin_textdomain( 'post-media-cleanup', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    PMC_Admin::get_instance();

    if(is_admin()) {
        PMC_Admin::get_instance();
    }
}

