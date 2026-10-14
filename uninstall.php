<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-postmediaweb-logger.php';

delete_option( 'postmediaweb_settings' );
delete_option( 'postmediaweb_db_version' );

Postmediaweb_Logger::drop_table();

if ( is_multisite() ) {
    $sites = get_sites( array( 'number' => 0, 'fields' => 'ids' ) );
    foreach ( $sites as $site_id ) {
        switch_to_blog( $site_id );
        delete_option( 'postmediaweb_settings' );
        delete_option( 'postmediaweb_db_version' );
        Postmediaweb_Logger::drop_table();
        restore_current_blog();
    }
}