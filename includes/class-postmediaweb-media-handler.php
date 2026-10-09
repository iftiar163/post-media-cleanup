<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Postmediaweb_Media_Handler {

    /**
     * Collect every attachment ID that belongs to a post, honouring the plugin settings.
     *
     * @param int $post_id
     * @return int[]
     */
    public static function get_all_attachment_ids( $post_id ) {
        $ids = array();

        if ( Postmediaweb_Settings::get( 'delete_featured' ) ) {
            $ids = array_merge( $ids, self::get_featured_image( $post_id ) );
        }

        if ( Postmediaweb_Settings::get( 'delete_content_media' ) ) {
            $ids = array_merge( $ids, self::get_content_media( $post_id ) );
        }

        if ( Postmediaweb_Settings::get( 'delete_gallery' ) ) {
            $ids = array_merge( $ids, self::get_child_attachments( $post_id ) );
        }

        if ( Postmediaweb_Settings::get( 'delete_pagebuilder' ) ) {
            $ids = array_merge( $ids, self::get_pagebuilder_media( $post_id ) );
        }

        if ( Postmediaweb_Settings::get( 'delete_acf' ) ) {
            $ids = array_merge( $ids, Postmediaweb_ACF_Handler::get_attachment_ids( $post_id ) );
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

        return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
    }

    private static function get_pagebuilder_media( $post_id ) {
        $ids = array();

        if ( defined( 'ELEMENTOR_VERSION' ) ) {
            $ids = array_merge( $ids, self::get_elementor_media( $post_id ) );
        }

        if ( defined( 'ET_BUILDER_VERSION' ) ) {
            $ids = array_merge( $ids, self::get_divi_media( $post_id ) );
        }

        if ( defined( 'WPB_VC_VERSION' ) ) {
            $ids = array_merge( $ids, self::get_wpbakery_media( $post_id ) );
        }

        return $ids;
    }

    /**
     * Elementor media for a post.
     *
     * Delegates to the shared extractor so that repeater items (slides, tabs, icon
     * lists ...), galleries and backgrounds nested at any depth are all found, and so
     * the "what does this post use" and "is this still used elsewhere" answers always agree.
     */
    private static function get_elementor_media( $post_id ) {
        $data = get_post_meta( $post_id, '_elementor_data', true );

        if ( empty( $data ) ) {
            return array();
        }

        return Postmediaweb_Reference_Checker::extract_elementor_ids( $data );
    }

    private static function get_divi_media( $post_id ) {
        $ids  = array();
        $post = get_post( $post_id );

        if ( ! $post || empty( $post->post_content ) ) {
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
            ! empty( $src_matches[1] ) ? $src_matches[1] : array(),
            ! empty( $bg_matches[1] ) ? $bg_matches[1] : array()
        );

        foreach ( array_unique( $urls ) as $url ) {
            if ( ! self::is_upload_url( $url ) ) {
                continue;
            }

            $id = self::url_to_attachment_id( $url );

            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private static function get_wpbakery_media( $post_id ) {
        $ids  = array();
        $post = get_post( $post_id );

        if ( ! $post || empty( $post->post_content ) ) {
            return $ids;
        }

        foreach ( array( 'image', 'images' ) as $attribute ) {
            if ( preg_match_all( '/\[vc_[^\]]+\s' . $attribute . '=["\']([0-9,\s]+)["\']/', $post->post_content, $matches ) ) {
                foreach ( $matches[1] as $id_string ) {
                    foreach ( explode( ',', $id_string ) as $id ) {
                        $ids[] = (int) trim( $id );
                    }
                }
            }
        }

        return $ids;
    }

    private static function get_featured_image( $post_id ) {
        $id = (int) get_post_thumbnail_id( $post_id );
        return $id > 0 ? array( $id ) : array();
    }

    private static function get_child_attachments( $post_id ) {
        return get_posts( array(
            'post_type'        => 'attachment',
            'post_parent'      => $post_id,
            // 'any' skips the trash status, which attachments use when MEDIA_TRASH is enabled.
            'post_status'      => array( 'any', 'trash' ),
            'posts_per_page'   => -1,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
        ) );
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

    /**
     * Remove a "-300x200" size suffix and any query string / fragment.
     */
    private static function strip_size_suffix( $url ) {
        $url = self::clean_url( $url );
        return preg_replace( '/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $url );
    }

    /**
     * Remove query string and fragment.
     */
    private static function clean_url( $url ) {
        return preg_replace( '/[?#].*$/', '', (string) $url );
    }

    private static function is_upload_url( $url ) {
        $upload_dir = wp_upload_dir( null, false );
        $base       = preg_replace( '#^https?://#', '//', $upload_dir['baseurl'] );
        $url_clean  = preg_replace( '#^https?://#', '//', $url );
        return 0 === strpos( $url_clean, $base );
    }

    /**
     * Resolve an upload URL to an attachment ID.
     *
     * The URL is tried exactly as written first so a file that genuinely is named
     * like "banner-1920x1080.jpg" still resolves; only then is a "-WxH" size suffix stripped.
     */
    private static function url_to_attachment_id( $url ) {
        $clean = self::clean_url( $url );
        $id    = (int) attachment_url_to_postid( $clean );

        if ( $id > 0 ) {
            return $id;
        }

        $unsized = self::strip_size_suffix( $clean );

        if ( $unsized !== $clean ) {
            return (int) attachment_url_to_postid( $unsized );
        }

        return 0;
    }

    /**
     * Pull every candidate media URL out of an HTML fragment.
     *
     * Uses DOMDocument when the PHP DOM extension exists (it is optional on some hosts) and
     * falls back to a regex scan otherwise, so deleting a post can never fatally error here.
     *
     * @param string $content
     * @return string[]
     */
    private static function collect_urls( $content ) {
        $urls = array();

        $attributes = array(
            'img'    => array( 'src', 'data-src', 'srcset', 'data-srcset' ),
            'a'      => array( 'href' ),
            'video'  => array( 'src', 'poster' ),
            'audio'  => array( 'src' ),
            'source' => array( 'src', 'srcset' ),
            'embed'  => array( 'src' ),
            'object' => array( 'data' ),
            'iframe' => array( 'src' ),
        );

        if ( class_exists( 'DOMDocument' ) ) {
            $previous = libxml_use_internal_errors( true );

            $dom = new DOMDocument();
            $dom->loadHTML(
                '<meta http-equiv="content-type" content="text/html; charset=utf-8">' . $content,
                LIBXML_NONET
            );

            libxml_clear_errors();
            libxml_use_internal_errors( $previous );

            foreach ( $attributes as $tag => $attrs ) {
                foreach ( $dom->getElementsByTagName( $tag ) as $node ) {
                    foreach ( $attrs as $attr ) {
                        $value = $node->getAttribute( $attr );
                        if ( '' !== $value ) {
                            $urls = array_merge( $urls, self::split_attribute_urls( $attr, $value ) );
                        }
                    }
                }
            }

            return $urls;
        }

        // Fallback without ext-dom.
        $pattern = '/\b(?:src|data-src|srcset|data-srcset|href|poster|data)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i';
        if ( preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $value = '' !== $match[1] ? $match[1] : ( isset( $match[2] ) ? $match[2] : '' );
                if ( '' !== $value ) {
                    $urls = array_merge( $urls, self::split_attribute_urls( 'srcset', $value ) );
                }
            }
        }

        return $urls;
    }

    /**
     * A srcset holds several "url descriptor" pairs; every other attribute holds one URL.
     */
    private static function split_attribute_urls( $attribute, $value ) {
        if ( 'srcset' !== $attribute && 'data-srcset' !== $attribute ) {
            return array( trim( $value ) );
        }

        $urls = array();
        foreach ( explode( ',', $value ) as $part ) {
            $bits = preg_split( '/\s+/', trim( $part ) );
            if ( ! empty( $bits[0] ) ) {
                $urls[] = $bits[0];
            }
        }

        return $urls;
    }

    /**
     * Attachment IDs of local uploads referenced from an HTML fragment.
     *
     * @param string $content
     * @return int[]
     */
    public static function extract_urls_from_content( $content ) {

        if ( empty( $content ) || ! is_string( $content ) ) {
            return array();
        }

        $ids = array();

        foreach ( array_unique( self::collect_urls( $content ) ) as $url ) {
            if ( ! self::is_upload_url( $url ) ) {
                continue;
            }

            $id = self::url_to_attachment_id( $url );

            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }

        return array_values( array_unique( $ids ) );
    }
}
