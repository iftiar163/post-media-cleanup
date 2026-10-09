<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Postmediaweb_ACF_Handler {

    /**
     * Get all attachment IDs stored in ACF image / file / gallery fields for a post.
     *
     * Reads ACF's stored "_field_name => field_key" reference rows and resolves each one
     * through the shared reference checker. That covers every nesting level (repeaters
     * inside flexible content inside groups ...) and does not touch ACF's global
     * have_rows() / the_row() loop state, which the previous implementation corrupted
     * when a post was deleted from inside a theme loop.
     *
     * Entry point called from Postmediaweb_Media_Handler.
     *
     * @param int $post_id
     * @return int[]
     */
    public static function get_attachment_ids( $post_id ) {

        if ( ! function_exists( 'acf_get_field' ) ) {
            return array();
        }

        return Postmediaweb_Reference_Checker::get_acf_media_ids_for_post( $post_id );
    }
}
