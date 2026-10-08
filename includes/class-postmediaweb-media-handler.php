<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Postmediaweb_Media_Handler {
    
    public static function get_all_attachment_ids( $post_id ) {
        $ids = [];

        if( Postmediaweb_Settings::get( 'delete_featured' ) ) {
            $ids = array_merge( $ids, self::get_featured_image( $post_id ) );
        }

        if( Postmediaweb_Settings::get( 'delete_content_media' ) ) {
            $ids = array_merge( $ids, self::get_content_media( $post_id ) );
        }

        if( Postmediaweb_Settings::get( 'delete_gallery' ) ) {
            $ids = array_merge( $ids, self::get_child_attachments( $post_id ) );
        }

        if( Postmediaweb_Settings::get( 'delete_pagebuilder' ) ) {
            $ids = array_merge( $ids, self::get_pagebuilder_media( $post_id ) );
        }

        if( Postmediaweb_Settings::get( 'delete_acf' ) ) {
            $ids = array_merge( $ids, Postmediaweb_ACF_Handler::get_attachment_ids($post_id) );
        }

        if ( Postmediaweb_Settings::get( 'delete_woocommerce' ) ) {
            $post = get_post( $post_id );
            if ( $post && 'product' === $post->post_type ) {
                $ids = array_merge(
                    $ids,
                    Postmediaweb_Woocommerce_Handler::get_attachment_ids( $post_id )
                );
            }
        }

        return array_unique( array_filter( array_map( 'absint', $ids ) ) );
    }

    private static function get_pagebuilder_media( $post_id ) {
        $ids = [];
        if( defined( 'ELEMENTOR_VERSION' ) ) {
            $ids = array_merge($ids, self::get_elementor_media( $post_id ));
        }

        if( defined( 'ET_BUILDER_VERSION' ) ) {
            $ids = array_merge($ids, self::get_divi_media( $post_id ));
        }

        if( defined( 'WPB_VC_VERSION' ) ) {
            $ids = array_merge($ids, self::get_wpbakery_media( $post_id ));
        }
        return $ids;
    }

    private static function get_elementor_media( $post_id ) {
        $ids = [];
        $data = get_post_meta( $post_id, '_elementor_data', true );
        if( empty($data) ) {
            return $ids;
        }

        $elements = json_decode( $data, true );
        if( ! is_array($elements) ) {
            return $ids;
        }

        self::walk_elementor_elements( $elements, $ids );
        return $ids;
    }

    private static function walk_elementor_elements( $elements, &$ids ) {
        foreach( $elements as $element ){
            if( ! empty($element['elements']) && is_array($element['elements']) ) {
                self::walk_elementor_elements( $element['elements'], $ids );
            }

            if( empty($element['settings']) || ! is_array($element['settings']) ) {
                continue;
            }

            $settings = $element['settings'];

            foreach ( $settings as $key => $value ) {
                    if( is_array( $value ) && isset($value['url'], $value['id']) && is_numeric($value['id']) && $value['id'] > 0 ) {
                        $ids[] = (int) $value['id'];
                    }

                    if( is_array($value) && isset( $value['background_image']['id'] )) {
                        $bg_id = (int) $value['background_image']['id'];
                        if( $bg_id > 0 ) {
                            $ids[] = $bg_id;
                        }
                    }

                    if( is_array($value) && isset($value[0]) && is_array($value[0]) && isset($value[0]['id']) ) {
                        foreach($value as $gallery_item) {
                            if(isset($gallery_item['id']) && (int) $gallery_item['id'] > 0) {
                                $ids[] = (int) $gallery_item['id'];
                            }
                        }
                    }
                }
        }
    }

    private static function get_divi_media( $post_id ) {
        $ids = [];
        $post = get_post( $post_id );
        if( ! $post || empty( $post->post_content ) ) {
            return $ids;
        }

        preg_match_all(
            '/\[et_pb_[^\]]+\ssrc=["\']([^"\']+)["\']/',
            $post->post_content,
            $src_matches
        );

        preg_match_all(
            '/\[et_pb_[^\]]+\sbackground_url=["\']([^"\']+)["\']/',
            $post->post_content,
            $bg_matches
        );

        $urls = array_merge(
            !empty( $src_matches[1] ) ? $src_matches[1] : [],
            !empty( $bg_matches[1] ) ? $bg_matches[1] : []
        );

        foreach( array_unique( $urls ) as $url ) {
            if( ! self::is_upload_url( $url ) ) {
                continue;
            }

            $url = self::strip_size_suffix( $url );
            $id = attachment_url_to_postid( $url );

            if( $id > 0 ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private static function get_wpbakery_media( $post_id ) {
        $ids = [];
        $post = get_post( $post_id );

        if( ! $post || empty( $post->post_content ) ) {
            return $ids;
        }

        preg_match_all(
            '/\[vc_[^\]]+\simage=["\'](\d+)["\']/',
            $post->post_content,
            $single_matches
        );

        preg_match_all(
            '/\[vc_[^\]]+\simages=["\']([0-9,]+)["\']/',
            $post->post_content,
            $gallery_matches
        );

        if(!empty($single_matches[1])){
            foreach($single_matches[1] as $id_string) {
                $parts = explode(',', $id_string);
                foreach($parts as $id) {
                    $ids[] = (int)  trim($id);
                }
            }
        }

        if(!empty($gallery_matches[1])){
            foreach($gallery_matches[1] as $id_string) {
                $parts = explode(',', $id_string);
                foreach($parts as $id) {
                    $ids[] = (int)  trim($id);
                }
            }
        }

        return $ids;
    }

    private static function get_featured_image( $post_id ) {
        $id = get_post_thumbnail_id( $post_id );
        return $id > 0 ? array( $id ) : array();
    }

    private static function get_child_attachments( $post_id ) {
        return get_posts([
            'post_type'      => 'attachment',
            'post_parent'    => $post_id,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
    }

    private static function get_content_media( $post_id ) {
        $post = get_post( $post_id );

        if ( ! $post ) {
            return array();
        }

        $ids = self::extract_urls_from_content( $post->post_content );

        // Product excerpts are handled by the WooCommerce setting so that
        // disabling product cleanup also disables short-description cleanup.
        if ( 'product' !== $post->post_type ) {
            $ids = array_merge( $ids, self::extract_urls_from_content( $post->post_excerpt ) );
        }

        return array_values( array_unique( $ids ) );
    }

    private static function strip_size_suffix( $url ) {
        $url = strtok( $url, '?' );
        return preg_replace('/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $url );
    }

    private static function is_upload_url( $url ) {
        $upload_dir = wp_upload_dir();
        $base       = preg_replace( '#^https?://#', '//', $upload_dir['baseurl'] );
        $url_clean  = preg_replace( '#^https?://#', '//', $url );
        return strpos( $url_clean, $base ) === 0; 
    }

    public static function extract_urls_from_content( $content ) {

        if ( empty( $content ) ) {
            return array();
        }

        $ids  = array();
        $urls = array();

        libxml_use_internal_errors( true );

        $dom = new DOMDocument();
        $dom->loadHTML(
            '<meta http-equiv="content-type" content="text/html; charset=utf-8">'
            . $content
        );

        libxml_clear_errors();

        foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
            $src = $img->getAttribute( 'src' );
            if ( $src ) {
                $urls[] = self::strip_size_suffix( $src );
            }

            $srcset = $img->getAttribute( 'srcset' );
            if ( $srcset ) {
                foreach ( explode( ',', $srcset ) as $part ) {
                    $bits = preg_split( '/\s+/', trim( $part ) );
                    if ( ! empty( $bits[0] ) ) {
                        $urls[] = self::strip_size_suffix( $bits[0] );
                    }
                }
            }
        }

        foreach ( $dom->getElementsByTagName( 'a' ) as $anchor ) {
            $href = $anchor->getAttribute( 'href' );
            if ( $href && self::is_upload_url( $href ) ) {
                $urls[] = $href;
            }
        }

        foreach ( array_unique( $urls ) as $url ) {
            if ( ! self::is_upload_url( $url ) ) {
                continue;
            }
            $id = attachment_url_to_postid( $url );
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
