<?php
/**
 * Core deletion engine.
 *
 * @package PostMediaCleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PMC_Core {

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
        if( !PMC_Settings::is_enabled() ) {
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

        $allowed = (array) PMC_Settings::get( 'post_types' );

        if( ! in_array( $post->post_type, $allowed, true ) ) {
            return;
        }

        $ids = PMC_Media_Handler::get_all_attachment_ids( $post_id );

        if( empty($ids) ) {
            return;
        }

        $this->pending[ $post_id ] = $ids;
    }

    public function cleanup( $post_id, $post ) {

        if( empty( $this->pending[ $post_id ] ) ) {
            return;
        }

        foreach ( $this->pending[ $post_id ] as $att_id ) {
            wp_delete_attachment( $att_id, true );
        }

        unset( $this->pending[ $post_id ] );

    }
}