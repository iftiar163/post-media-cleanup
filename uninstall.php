<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * @package PostMediaCleanup
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Single site — delete our option.
delete_option( 'postmediaweb_settings' );

// Multisite — clean every sub-site.
if ( is_multisite() ) {
    $postmediaweb_sites = get_sites( array(
        'number' => 0,
        'fields' => 'ids',
    ) );

    foreach ( $postmediaweb_sites as $postmediaweb_site_id ) {
        switch_to_blog( $postmediaweb_site_id );
        delete_option( 'postmediaweb_settings' );
        restore_current_blog();
    }
}