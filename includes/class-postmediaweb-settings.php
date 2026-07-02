<?php
/**
 * Settings helper.
 *
 * @package PostMediaCleanupWebxperthub
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Postmediaweb_Settings {

    private static $cache = null;

    private static $defaults = array(
        'enabled'              => true,
        'delete_featured'      => true,
        'delete_content_media' => true,
        'delete_gallery'       => true,
        'skip_shared'          => true,
        'post_types'           => array( 'post', 'page' ),
    );

    public static function get( $key ) {
        if ( null === self::$cache ) {
            $saved       = get_option( POSTMEDIAWEB_OPTION_KEY, array() );
            self::$cache = wp_parse_args( $saved, self::$defaults );
        }

        return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
    }

    public static function is_enabled() {
        return (bool) self::get( 'enabled' );
    }
}
