<?php
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
        add_action( 'before_delete_post', array( $this, 'collect' ), 5 );
        add_action( 'after_delete_post', array( $this, 'cleanup' ), 10, 2 );
    }

    /**
     * Phase 1 (before the post is removed): remember which attachments belong to it.
     *
     * Never allowed to throw: a bug or a missing PHP extension here must not stop WordPress
     * from deleting the post itself.
     *
     * @param int $post_id
     */
    public function collect( $post_id ) {
        try {
            $this->do_collect( $post_id );
        } catch ( \Throwable $e ) {
            unset( $this->pending[ $post_id ] );
            $this->log_error( 'collect', $post_id, $e );
        }
    }

    private function do_collect( $post_id ) {

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

        $excluded_post_ids = array( $post_id );
        if (
            'product' === $post->post_type
            && Postmediaweb_Settings::get( 'delete_woocommerce' )
            && function_exists( 'wc_get_product' )
        ) {
            $excluded_post_ids = array_merge(
                $excluded_post_ids,
                Postmediaweb_Woocommerce_Handler::get_variation_ids( $post_id )
            );
        }

        $this->pending[ $post_id ] = array(
            'attachment_ids'    => $ids,
            'excluded_post_ids' => array_values( array_unique( array_map( 'absint', $excluded_post_ids ) ) ),
        );
    }

    /**
     * Phase 2 (after the post is gone): delete the collected attachments that are not in use elsewhere.
     *
     * $post is only passed by WordPress 5.5+, so it must stay optional (plugin supports WP 5.0+).
     *
     * @param int          $post_id
     * @param WP_Post|null $post
     */
    public function cleanup( $post_id, $post = null ) {
        try {
            $this->do_cleanup( $post_id );
        } catch ( \Throwable $e ) {
            $this->log_error( 'cleanup', $post_id, $e );
        }

        unset( $this->pending[ $post_id ] );
    }

    private function do_cleanup( $post_id ) {

        if( empty( $this->pending[ $post_id ] ) ) {
            return;
        }

        $pending = $this->pending[ $post_id ];
        $ids     = apply_filters( 'postmediaweb_attachment_ids_to_delete', $pending['attachment_ids'], $post_id );

        // A filter can return anything; only positive integers are acceptable.
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );

        $enforce_caps = (bool) apply_filters( 'postmediaweb_enforce_capabilities', true, $post_id );

        foreach ( $ids as $att_id ) {

            // Never touch anything that is not really an attachment.
            if ( 'attachment' !== get_post_type( $att_id ) ) {
                continue;
            }

            // A logged-in user may only destroy media they are allowed to delete themselves
            // (an Author deleting their own post must not wipe an Administrator's upload).
            // Cron, WP-CLI and other user-less contexts are not restricted.
            if ( $enforce_caps && is_user_logged_in() && ! current_user_can( 'delete_post', $att_id ) ) {
                continue;
            }

            $should_delete = true;
            $should_delete = apply_filters( 'postmediaweb_should_delete_attachment', $should_delete, $att_id, $post_id );

            if( ! $should_delete ) {
                continue;
            }

            if (
                Postmediaweb_Settings::get( 'skip_shared' )
                && $this->is_shared( $att_id, $pending['excluded_post_ids'] )
            ) {
                continue;
            }

            wp_delete_attachment( $att_id, true );
        }
    }

    private function is_shared( $att_id, $excluding_post_ids ) {
        return Postmediaweb_Reference_Checker::is_referenced( $att_id, $excluding_post_ids );
    }

    /**
     * Log an internal failure without ever interrupting the request.
     */
    private function log_error( $stage, $post_id, $e ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( sprintf( '[post-media-cleanup] %s failed for post %d: %s', $stage, (int) $post_id, $e->getMessage() ) );
        }
    }
}
