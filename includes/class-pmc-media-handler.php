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

        if( ! $post || empty( $post->post_content ) ) {
            return array();
        }

        $ids = array();
        $urls = array();

        libxml_use_internal_errors( true );

        $dom = new DOMDocument();
        $dom->loadHTML('<meta http-equiv="content-type" content="text/html; charset=utf-8">' . $post->post_content
        );

        libxml_clear_errors();

        foreach( $dom->getElementsByTagName('img') as $img ) {
            $src = $img->getAttribute('src');
            if( $src ) {
                $urls[] = self::strip_size_suffix( $src );
            }

            $srcset = $img->getAttribute('srcset');
            if( $srcset ) {
                foreach( explode( ',', $srcset ) as $part ) {
                    $bits = preg_split('/\s+/', trim($part));
                    if( !empty($bits[0]) ) {
                        $urls[] = self::strip_size_suffix( $bits[0] );
                    }
                }
            }
        }

        foreach( $dom->getElementsByTagName('a') as $anchor ) {
            $href = $anchor->getAttribute('href');
            if( $href && self::is_upload_url($href) ) {
                $urls[] = $href;
            }
        }

        foreach( array_unique( $urls ) as $url ) {
            if( ! self::is_upload_url( $url ) ) {
                continue;
            }

            $id = attachment_url_to_postid( $url );

            if( $id > 0 ) {
                $ids[] = $id;
            }
        }

        return $ids;


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
}