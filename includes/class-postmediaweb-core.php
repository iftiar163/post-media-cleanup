<?php
/**
 * Core deletion engine.
 *
 * @package PostMediaCleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Postmediaweb_Core {

    private static $instance = null;

    /**
     * Stores attachment IDs between the two hook phases.
     * Keyed by post ID so it works correctly if WordPress
     * deletes multiple posts in the same request.
     *
     * @var array
     */
    private $pending = array();

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
       add_action('before_delete_post', [$this, 'collect'], 5);
       add_action('after_delete_post', [$this, 'cleanup'], 10, 2);
    }

    public function collect( $post_id ) {

        if( !Postmediaweb_Settings::is_enabled() ) {
            return;
        }

        if( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        $post = get_post( $post_id );

        if ( ! $post ) {
            return;
        }

        if( 'attachment' === $post->post_type ) {
            return;
        }

        $allowed = (array) Postmediaweb_Settings::get( 'post_types' );

        if( ! in_array( $post->post_type, $allowed, true ) ) {
            return;
        }

        $ids = Postmediaweb_Media_Handler::get_all_attachment_ids( $post_id );

        if( empty($ids) ) {
            return;
        }

        $this->pending[ $post_id ] = $ids;
    }

    public function cleanup( $post_id, $post ) {

        if( empty( $this->pending[ $post_id ] ) ) {
            return;
        }

        $ids = $this->pending[ $post_id ];
        $ids = apply_filters( 'postmediaweb_attachment_ids_to_delete', $ids, $post_id );

        foreach ( $ids as $att_id ) {

            $should_delete = true;
            $should_delete = apply_filters( 'postmediaweb_should_delete_attachment', $should_delete, $att_id, $post_id );

            if( ! $should_delete ) {
                continue;
            }

            if( Postmediaweb_Settings::get( 'skip_shared' ) && $this->is_shared( $att_id, $post_id ) ) {
                continue;
            }
            wp_delete_attachment( $att_id, true );
        }

        unset( $this->pending[ $post_id ] );

    }

    private function is_shared( $att_id, $excluding_post_id ) {
        global $wpdb;
        $cache_key = 'postmediaweb_featured_' . $att_id . '_' . $excluding_post_id;
        $featured_count = wp_cache_get( $cache_key, 'postmediaweb_cache' );

        if ( false === $featured_count ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $featured_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta}
                WHERE meta_key   = '_thumbnail_id'
                AND   meta_value = %d
                AND   post_id   != %d",
                $att_id,
                $excluding_post_id
            ) );
            wp_cache_set( $cache_key, $featured_count, 'postmediaweb_cache', HOUR_IN_SECONDS );
        }

        if( $featured_count > 0 ) {
            return true;
        }

        // Check 2 - Does its URL appear in another posts content?
        $url = wp_get_attachment_url( $att_id );

        if( ! $url ) {
            return false;
        }

        $url_no_protocol = preg_replace( '#^https?://#', '', $url );
        $url_base        = preg_replace( '/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $url_no_protocol );

        $cache_key_content = 'postmediaweb_content_' . md5( $url_base . '_' . $excluding_post_id );
        $content_count = wp_cache_get( $cache_key_content, 'postmediaweb_cache' );

        if ( false === $content_count ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $content_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                WHERE  post_status NOT IN ('trash', 'auto-draft')
                AND    ID          != %d
                AND    post_content LIKE %s",
                $excluding_post_id,
                '%' . $wpdb->esc_like( $url_base ) . '%'
            ) );
            wp_cache_set( $cache_key_content, $content_count, 'postmediaweb_cache', HOUR_IN_SECONDS );
        }

        return $content_count > 0;

    }
}
