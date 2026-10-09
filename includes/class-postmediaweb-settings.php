<?php
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
        'delete_pagebuilder'   => true,
        'delete_acf'           => true,
        'delete_woocommerce'   => true,
    );

    public static function get( $key ) {
        if ( null === self::$cache ) {
            $saved = get_option( POSTMEDIAWEB_OPTION_KEY, array() );

            // A corrupted / non-array option must fall back to defaults instead of
            // being passed to wp_parse_args() (which would parse a string as a query string).
            if ( ! is_array( $saved ) ) {
                $saved = array();
            }

            self::$cache = wp_parse_args( $saved, self::$defaults );
        }

        return isset( self::$cache[ $key ] ) ? self::$cache[ $key ] : null;
    }

    public static function is_enabled() {
        return (bool) self::get( 'enabled' );
    }

    /**
     * Forget the in-memory copy. Hooked to option add/update so that a save made during the
     * same request (or by another plugin) is picked up immediately.
     */
    public static function clear_cache() {
        self::$cache = null;
    }
}
