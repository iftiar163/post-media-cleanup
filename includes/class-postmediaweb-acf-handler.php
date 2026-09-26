<?php
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

        // Raw values avoid a second ACF lookup for every deleted post.
        $fields = acf_get_field_objects( $post_id, array( 'format_value' => false ) );

        if( empty($fields) || ! is_array($fields) ) {
            return array();
        }

        $ids = [];

        foreach( $fields as $field ) {
            $collected = self::extract_ids_from_field( $field, $post_id );
            $ids = array_merge( $ids, $collected );
        }

        return $ids;
    }

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

    private static function get_simple_media_field( $field, $post_id ) {
        $value = isset( $field['value'] ) ? $field['value'] : null;

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

    private static function get_gallery_field( $field, $post_id ) {
        $images = isset( $field['value'] ) ? $field['value'] : null;

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

        return $ids;
    }

     private static function get_repeater_field( $field, $post_id ) {
        $ids = array();

        if( ! function_exists('have_rows') ) {
            return $ids;
        }

        if( ! have_rows( $field['name'], $post_id ) ) {
            return $ids;
        }

        while( have_rows( $field['name'], $post_id ) ) {
            the_row();

            if( ! empty( $field['sub_fields'] ) ) {
                foreach( $field['sub_fields'] as $sub_field ) {
                    $sub_value = get_sub_field( $sub_field['name'] );
                    $ids = array_merge(
                        $ids,
                        self::extract_ids_from_value( $sub_field['type'], $sub_value )
                    );
                }
            }
        }
        
        return $ids;
     }

     private static function get_flexible_content_field( $field, $post_id ) {
        $ids = [];

        if( ! function_exists('have_rows') ) {
            return $ids;
        }

        if( ! have_rows( $field['name'], $post_id ) ) {
            return $ids;
        }

        while( have_rows( $field['name'], $post_id ) ) {
            the_row();

            $layout_name = get_row_layout();

            if( empty($field['layouts']) ) {
                continue;
            }

            foreach( $field['layouts'] as $layout ) {

                if( $layout['name'] !== $layout_name ) {
                    continue;
                }

                if( empty($layout['sub_fields']) ) {
                    continue;
                }

                foreach( $layout['sub_fields'] as $sub_field ) {
                    $sub_value = get_sub_field( $sub_field['name'] );
                    $ids = array_merge(
                        $ids,
                        self::extract_ids_from_value( $sub_field['type'], $sub_value )
                    );
                }
            }
        }

        return $ids;
     }

    private static function get_group_field( $field, $post_id ) {
        $ids = [];
        $group = isset( $field['value'] ) ? $field['value'] : null;

        if ( empty( $group ) || ! is_array( $group ) ) {
            return $ids;
        }

        if( empty($field['sub_fields']) ) {
            return $ids;
        }

        foreach( $field['sub_fields'] as $sub_field ) {
            $value = isset( $group[ $sub_field['name'] ] ) ? $group[ $sub_field['name'] ] : null;
            $ids = array_merge(
                $ids,
                self::extract_ids_from_value( $sub_field['type'], $value )
            );
        }

        return $ids;
    }

    private static function extract_ids_from_value( $type, $value ) {

        if( empty($value) ) {
            return array();
        }

        if( 'image' === $type || 'file' === $type ) {

            if( is_numeric( $value ) ) {
                return array( (int) $value );
            }

            if( is_array( $value ) && isset($value['ID']) ) {
                return array( (int) $value['ID'] );
            }
        }

        if( 'gallery' === $type && is_array( $value ) ) {
            $ids = [];
            foreach( $value as $item ) {
                if( is_numeric( $item ) ) {
                    $ids[] = (int) $item;
                } elseif( is_array( $item ) && isset( $item['ID'] ) ) {
                    $ids[] = (int) $item['ID'];
                }
            }

            return $ids;
        }

        return array();
    }

}