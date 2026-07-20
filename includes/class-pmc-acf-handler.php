<?php
/**
 * ACF Media Handler.
 *
 * Finds all attachment IDs stored in ACF fields on a post.
 * Supports ACF Free and ACF Pro field types.
 *
 * @package PostMediaCleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Postmediaweb_ACF_Handler {

    /**
     * Get all attachment IDs stored in ACF fields for a post.
     *
     * Entry point called from PMC_Media_Handler.
     *
     * @param int $post_id
     * @return int[]
     */

    public static function get_attachment_ids( $post_id ) {

        if( ! function_exists( 'acf_get_field_objects' ) ) {
            return array();
        }

        $fields = acf_get_field_objects( $post_id );

        if( empty($fields) || ! is_array($fields) ) {
            return array();
        }

        $ids = [];

        foreach( $fields as $field ) {
            $collected = self::collect_attachment_ids( $field, $post_id );
            $ids = array_merge( $ids, $collected );
        }

        return $ids;
    }

    /**
     * Recursively collect attachment IDs from a field.
     *
     * @param array $field
     * @param int $post_id
     * @return int[]
     */

    private static function extract_ids_from_field( $field, $post_id ) {
        $type = isset( $field['type'] ) ? $field['type'] : '';

        switch( $type ) {
            case 'image':
            case 'file' :
                return self::get_simple_media_field($field, $post_id);

            case 'gallery':
                return self::get_gallery_field($field, $post_id);

            case 'repeater':
                return self::get_repeater_field($field, $post_id);

            case 'flexible_content':
                return self::get_flexible_content_field($field, $post_id);

            case 'group':
                return self::get_group_field($field, $post_id);
            
            default:
                return array();
        }
    }

    /**
     * Extract ID from a simple image or file field.
     *
     * @param array $field
     * @param int   $post_id
     * @return int[]
     */

    private static function get_simple_media_field( $field, $post_id ) {
        $value = get_field( $field['name'], $post_id );

        if( empty($value) ) {
            return array();
        }

        if( is_numeric($value) ) {
            return array( (int) $value );
        }

        if( is_array( $value ) && isset( $value['ID'] ) ){
            return array( (int) $value['ID'] );
        }

        if( is_string($value) && filter_var($value, FILTER_VALIDATE_URL) ) {
            $id = attachment_url_to_postid( $value );
            return $id > 0 ? array($id) : array();
        }

        return array();
    }

    /**
     * Extract IDs from a gallery field.
     *
     * ACF gallery returns an array of images.
     * Each image follows the same three formats as simple media fields.
     *
     * @param array $field
     * @param int   $post_id
     * @return int[]
     */

    private static function get_gallery_field( $field, $post_id ) {
        $images = get_field( $field['name'], $post_id );

        if( empty( $images ) || ! is_array( $images ) ) {
            return array();
        }

        $ids = array();

        foreach( $images as $image ) {
            if( is_numeric($image) ) {
                $ids[] = (int) $image;
            } elseif( is_array($image) && isset( $image['ID'] ) ) {
                $ids[] = (int) $image['ID'];
            }
        }
    }


}