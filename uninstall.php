<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'postmediaweb_settings' );

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
