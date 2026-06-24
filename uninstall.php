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
delete_option( 'post_media_cleanup_settings' );

// Multisite — clean every sub-site.
if ( is_multisite() ) {
    $sites = get_sites( array(
        'number' => 0,
        'fields' => 'ids',
    ) );

    foreach ( $sites as $site_id ) {
        switch_to_blog( $site_id );
        delete_option( 'post_media_cleanup_settings' );
        restore_current_blog();
    }
}