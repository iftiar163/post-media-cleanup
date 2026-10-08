<?php
/**
 * WooCommerce Media Handler.
 *
 * Finds all attachment IDs associated with a WooCommerce product.
 * Handles product gallery, short description, and variation images.
 *
 * Called from PMC_Media_Handler when the post type is 'product'.
 *
 * @package PostMediaCleanup
 */

if (! defined('ABSPATH')) {
    exit;
}

class Postmediaweb_Woocommerce_Handler {

    /**
     * Get all attachment IDs for a WooCommerce product.
     *
     * @param  int   $post_id  Product post ID.
     * @return int[]
     */

    public static function get_attachment_ids( $post_id ) {

        if( ! function_exists( 'wc_get_product' ) ) {
            return array();
        }

        $ids = array();

        $ids = array_merge( $ids, self::get_product_gallery( $post_id ) );
        $ids = array_merge( $ids, self::get_short_description_media( $post_id ) );
        $ids = array_merge( $ids, self::get_variation_images( $post_id ) );

        return $ids;

    }

    /**
     * Get attachment IDs from the product gallery.
     *
     * @param  int   $post_id  Product post ID.
     * @return int[]
     */

    private static function get_product_gallery( $post_id ) {

        $gallery_meta = get_post_meta( $post_id, '_product_image_gallery', true );

        if( empty( $gallery_meta ) ) {
            return array();
        }

        // Explode the comma-separated string into an array.
        // trim() handles any accidental whitespace around IDs.
        $ids = array_filter(
            array_map(
                'absint',
                array_map( 'trim', explode( ',', $gallery_meta ) )
            )
        );

        return array_values( $ids ); // Reindex the array to ensure sequential keys.
    }

    /**
     * Get attachment IDs from the product short description.
     *
     * @param  int   $post_id  Product post ID.
     * @return int[]
     */

    private static function get_short_description_media( $post_id ) {

        $post = get_post( $post_id );

        if( ! $post || empty( $post->post_excerpt ) ) {
            return array();
        }

        return Postmediaweb_Media_Handler::extract_urls_from_content( $post->post_excerpt );
    }

    /**
     * Get attachment IDs from product variations.
     *
     * @param  int   $post_id  Product post ID.
     * @return int[]
     */

    private static function get_variation_images( $post_id ) {

        // Get all variations for the product.
        $variation_ids = get_posts( array(
            'post_type'      => 'product_variation',
            'post_parent'    => $post_id,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',        // IDs only — fastest query.
            'no_found_rows'  => true,         // Skip COUNT(*).
        ) );

        if( empty( $variation_ids ) ) {
            return array();
        }

        $ids = array();

        // Reach each variation and get its featured image ID.
        foreach( $variation_ids as $variation_id ) {
            $thumb_id = (int) get_post_thumbnail_id( $variation_id );
            if ( $thumb_id > 0 ) {
                $ids[] = $thumb_id;
            }
        }
        return $ids;
    }


}