<?php
/**
 * Media Handler — finds all attachments for a post.
 *
 * @package PostMediaCleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PMC_Media_Handler {
    
    public static function get_all_attachment_ids( $post_id ) {
        $ids = [];

        if( PMC_Settings::get( 'delete_featured' ) ) {
            $ids = array_merge( $ids, self::get_featured_image( $post_id ) );
        }

        if( PMC_Settings::get( 'delete_content_media' ) ) {
            $ids = array_merge( $ids, self::get_content_media( $post_id ) );
        }

        if( PMC_Settings::get( 'delete_gallery' ) ) {
            $ids = array_merge( $ids, self::get_child_attachments( $post_id ) );
        }

        return array_unique( array_filter( array_map( 'absint', $ids ) ) );
    }
}