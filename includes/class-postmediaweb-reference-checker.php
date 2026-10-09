<?php
/**
 * Reference Checker.
 *
 * Single source of truth for "is this attachment actually used somewhere?".
 * Used by BOTH the real-time "Skip Shared Media" check (when a post is
 * permanently deleted) and the full-site Bulk Orphan scanner, so the two code
 * paths cannot disagree with each other.
 *
 * Design rules
 * ------------
 * 1. A false "unused" verdict deletes real, in-use media. A false "used"
 *    verdict merely keeps a file. Every ambiguity therefore resolves to "used".
 * 2. URLs are matched by their path relative to the uploads folder
 *    (e.g. "2024/05/photo"), never by scheme/host. That keeps matching correct
 *    after domain changes, http/https mixes, CDN / S3-offload URL rewriting and
 *    multisite "sites/N/" prefixes.
 * 3. Bare numbers that merely equal an attachment ID are NOT trusted on their
 *    own; only structures positively identified as media storage count
 *    (WooCommerce, Elementor media controls, ACF image/file/gallery fields,
 *    Gutenberg media blocks, gallery shortcodes ...).
 *
 * @package PostMediaCleanup
 */

if (! defined('ABSPATH')) {
    exit;
}

class Postmediaweb_Reference_Checker
{
    /**
     * Rows fetched per internal batch when scanning large tables.
     */
    const BATCH_SIZE = 500;

    /**
     * Runtime cache of ACF field key => field type.
     *
     * @var array
     */
    private static $acf_type_cache = array();

    /* ------------------------------------------------------------------
     * PUBLIC API
     * ---------------------------------------------------------------- */

    /**
     * Real-time, single-attachment check: is $attachment_id referenced
     * anywhere other than inside $exclude_post_ids?
     *
     * Always queries the database fresh. It must never rely on a previously
     * built set, because its job is to catch changes made after a scan.
     *
     * @param int       $attachment_id
     * @param int|int[] $exclude_post_ids Post IDs whose references are ignored
     *                                    (e.g. the product being deleted and its variations).
     * @return bool
     */
    public static function is_referenced($attachment_id, $exclude_post_ids = 0)
    {
        $attachment_id    = (int) $attachment_id;
        $exclude_post_ids = array_values(
            array_unique(
                array_filter(
                    array_map('absint', (array) $exclude_post_ids)
                )
            )
        );

        if ($attachment_id <= 0) {
            return false;
        }

        if (self::has_featured_image_reference($attachment_id, $exclude_post_ids)) {
            return true;
        }

        if (self::has_woocommerce_gallery_reference($attachment_id, $exclude_post_ids)) {
            return true;
        }

        if (self::has_elementor_reference($attachment_id, $exclude_post_ids)) {
            return true;
        }

        if (self::has_acf_reference($attachment_id, $exclude_post_ids)) {
            return true;
        }

        if (self::has_content_reference($attachment_id, $exclude_post_ids)) {
            return true;
        }

        if (self::has_url_reference_outside_content($attachment_id, $exclude_post_ids)) {
            return true;
        }

        if (self::has_term_meta_reference($attachment_id)) {
            return true;
        }

        if (self::has_site_option_reference($attachment_id)) {
            return true;
        }

        return false;
    }

    /**
     * Build a lookup of everything referenced anywhere on the site, for the
     * full-library orphan scan. Batched/efficient equivalent of calling
     * is_referenced() on every attachment.
     *
     * @return array {
     *     @type array $ids   Hash set: attachment ID (int) => true.
     *     @type array $paths Hash set: normalized upload-relative path keys => true
     *                        (see get_attachment_match_keys()).
     * }
     */
    public static function build_global_reference_set()
    {
        global $wpdb;

        $referenced = array();
        $used_paths = array();

        // Featured images (every post status, trash included: restoring a post must not lose its image).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $thumb_ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value != ''"
        );
        foreach ($thumb_ids as $id) {
            if ((int) $id > 0) {
                $referenced[(int) $id] = true;
            }
        }

        // WooCommerce galleries.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $gallery_values = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery' AND meta_value != ''"
        );
        foreach ($gallery_values as $value) {
            foreach (self::split_id_list($value) as $id) {
                $referenced[$id] = true;
            }
        }

        // Elementor data (batched).
        $last_meta_id = 0;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_id, meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key = '_elementor_data'
                     AND meta_id > %d
                     ORDER BY meta_id ASC
                     LIMIT %d",
                    $last_meta_id,
                    self::BATCH_SIZE
                )
            );

            foreach ($rows as $row) {
                $last_meta_id = max($last_meta_id, (int) $row->meta_id);
                foreach (self::extract_elementor_ids($row->meta_value) as $id) {
                    $referenced[$id] = true;
                }
            }

            $fetched = count($rows);
        } while ($fetched === self::BATCH_SIZE);

        // ACF image / file / gallery fields on posts, terms, users and options pages.
        if (function_exists('acf_get_field')) {
            foreach (self::acf_contexts() as $cfg) {
                $last_pk = 0;
                do {
                    $rows = self::fetch_acf_reference_batch($cfg, $last_pk);

                    foreach ($rows as $row) {
                        $last_pk = max($last_pk, (int) $row->pk);
                        foreach (self::get_acf_media_ids($row->field_key, $row->v) as $id) {
                            $referenced[$id] = true;
                        }
                    }

                    $fetched = count($rows);
                } while ($fetched === self::BATCH_SIZE);
            }
        }

        // Post content / excerpts of every non-revision, non-attachment post
        // (trash included, so restoring a trashed post never loses its media).
        $last_id = 0;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
                     WHERE post_status != 'auto-draft'
                     AND post_type NOT IN ('attachment', 'revision')
                     AND ID > %d
                     ORDER BY ID ASC
                     LIMIT %d",
                    $last_id,
                    self::BATCH_SIZE
                )
            );

            foreach ($rows as $row) {
                $last_id = max($last_id, (int) $row->ID);

                foreach (array($row->post_content, $row->post_excerpt) as $text) {
                    if (empty($text)) {
                        continue;
                    }

                    foreach (self::extract_upload_path_keys($text) as $key => $unused) {
                        $used_paths[$key] = true;
                    }

                    foreach (self::extract_content_ids($text) as $id) {
                        $referenced[$id] = true;
                    }
                }
            }

            $fetched = count($rows);
        } while ($fetched === self::BATCH_SIZE);

        // Upload URLs stored in post meta, term meta, user meta and options
        // (page builders, widgets, theme mods, menus, avatars, ...).
        foreach (self::get_url_sources() as $source) {
            $last_pk = 0;
            do {
                $rows = self::fetch_url_source_batch($source, $last_pk);

                foreach ($rows as $row) {
                    $last_pk = max($last_pk, (int) $row->pk);
                    foreach (self::extract_upload_path_keys($row->v) as $key => $unused) {
                        $used_paths[$key] = true;
                    }
                }

                $fetched = count($rows);
            } while ($fetched === self::BATCH_SIZE);
        }

        // Taxonomy thumbnails (e.g. WooCommerce category images).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $term_thumb_ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->termmeta}
             WHERE meta_key = 'thumbnail_id' AND meta_value != ''"
        );
        foreach ($term_thumb_ids as $id) {
            if ((int) $id > 0) {
                $referenced[(int) $id] = true;
            }
        }

        foreach (self::get_site_option_ids() as $id) {
            $referenced[$id] = true;
        }

        return array(
            'ids'   => $referenced,
            'paths' => $used_paths,
        );
    }

    /**
     * Is any of this attachment's files present in a build_global_reference_set()
     * path lookup?
     *
     * @param int   $attachment_id
     * @param array $used_paths    The 'paths' hash set.
     * @return bool
     */
    public static function is_path_used($attachment_id, array $used_paths)
    {
        $keys = array_keys(self::get_attachment_match_keys($attachment_id));

        // No usable file path (e.g. an externally hosted attachment): we cannot prove it is
        // unused, so treat it as used rather than risk deleting it.
        if (empty($keys)) {
            return true;
        }

        foreach ($keys as $key) {
            if (isset($used_paths[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Attachment IDs of every ACF image/file/gallery value stored for a post
     * (handles repeaters, flexible content, groups and clones uniformly because it
     * reads ACF's own stored reference rows instead of walking field objects).
     *
     * @param int $post_id
     * @return int[]
     */
    public static function get_acf_media_ids_for_post($post_id)
    {
        global $wpdb;

        if (! function_exists('acf_get_field')) {
            return array();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.meta_value AS field_key, t.meta_value AS v
                 FROM {$wpdb->postmeta} AS r
                 INNER JOIN {$wpdb->postmeta} AS t
                    ON t.post_id = r.post_id AND t.meta_key = SUBSTRING(r.meta_key, 2)
                 WHERE r.post_id = %d
                 AND r.meta_key LIKE %s
                 AND r.meta_value LIKE %s",
                (int) $post_id,
                $wpdb->esc_like('_') . '%',
                $wpdb->esc_like('field_') . '%'
            )
        );

        $ids = array();
        foreach ($rows as $row) {
            $ids = array_merge($ids, self::get_acf_media_ids($row->field_key, $row->v));
        }

        return $ids;
    }

    /**
     * Extract every attachment ID referenced inside an Elementor _elementor_data
     * JSON blob. Media controls are recognised by shape ({"url":..., "id":N}) at
     * ANY depth, so repeater items (slides, tabs, icon lists ...), galleries,
     * backgrounds and nested containers are all covered.
     *
     * @param string|array $raw
     * @return int[]
     */
    public static function extract_elementor_ids($raw)
    {
        if (empty($raw)) {
            return array();
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);

        if (! is_array($decoded) && is_string($raw)) {
            $decoded = json_decode(wp_unslash($raw), true);
        }

        $ids = array();

        if (is_array($decoded)) {
            self::walk_media_controls($decoded, $ids);
            return array_values(array_unique($ids));
        }

        // Undecodable blob: fall back to media-shaped id/url pairs (either key order).
        if (is_string($raw)) {
            if (preg_match_all('/"id"\s*:\s*"?(\d+)"?\s*,\s*"url"/', $raw, $matches)) {
                foreach ($matches[1] as $id) {
                    $ids[] = (int) $id;
                }
            }
            if (preg_match_all('/"url"\s*:\s*"[^"]*"\s*,\s*"id"\s*:\s*"?(\d+)"?/', $raw, $matches)) {
                foreach ($matches[1] as $id) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Attachment IDs referenced directly by ID (not URL) inside post content:
     * Gutenberg media blocks, [gallery ids="..."], WPBakery image= / images=,
     * and the classic editor's "wp-image-N" class.
     *
     * @param string $content
     * @return int[]
     */
    public static function extract_content_ids($content)
    {
        $ids = array();

        if (empty($content) || ! is_string($content)) {
            return $ids;
        }

        $block_pattern = '/<!--\s*wp:(?:image|cover|media-text|gallery|file|video|audio)\s+(\{.*?\})\s*(?:\/)?-->/s';
        if (preg_match_all($block_pattern, $content, $matches)) {
            foreach ($matches[1] as $json) {
                $data = json_decode($json, true);
                if (! is_array($data)) {
                    continue;
                }
                foreach (array('id', 'mediaId') as $key) {
                    if (isset($data[$key]) && is_numeric($data[$key])) {
                        $ids[] = (int) $data[$key];
                    }
                }
                if (isset($data['ids']) && is_array($data['ids'])) {
                    foreach ($data['ids'] as $id) {
                        if (is_numeric($id)) {
                            $ids[] = (int) $id;
                        }
                    }
                }
            }
        }

        if (preg_match_all('/\[gallery[^\]]*\b(?:ids|include)=["\']([0-9,\s]+)["\']/i', $content, $matches)) {
            foreach ($matches[1] as $id_list) {
                $ids = array_merge($ids, self::split_id_list($id_list));
            }
        }

        if (preg_match_all('/\[vc_[^\]]+\s(?:image|images)=["\']([0-9,\s]+)["\']/i', $content, $matches)) {
            foreach ($matches[1] as $id_list) {
                $ids = array_merge($ids, self::split_id_list($id_list));
            }
        }

        if (preg_match_all('/\bwp-image-(\d+)\b/', $content, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Build the set of path keys under which an attachment may appear in content.
     *
     * Keys are lower-case, upload-relative and cover: the file itself, the file
     * without a "-WxH" size suffix, the extension-less stem (so a .webp/.avif
     * sibling still counts), and the original un-scaled / un-rotated image.
     *
     * @param int $attachment_id
     * @return array Hash set key => true.
     */
    public static function get_attachment_match_keys($attachment_id)
    {
        $keys = array();

        foreach (self::get_attachment_rel_paths($attachment_id) as $rel) {
            $rel = self::normalize_path_key($rel);
            if ('' === $rel) {
                continue;
            }

            $unsized = self::strip_size_suffix($rel);

            $keys[$rel]                                   = true;
            $keys[$unsized]                               = true;
            $keys['noext:' . self::strip_extension($unsized)] = true;
        }

        return $keys;
    }

    /**
     * Extension-less, size-less stems for an attachment (used for SQL pre-filtering).
     *
     * @param int $attachment_id
     * @return string[]
     */
    public static function get_attachment_stems($attachment_id)
    {
        $stems = array();

        foreach (self::get_attachment_rel_paths($attachment_id) as $rel) {
            $rel = self::normalize_path_key($rel);
            if ('' === $rel) {
                continue;
            }
            $stem = self::strip_extension(self::strip_size_suffix($rel));
            if ('' !== $stem) {
                $stems[$stem] = true;
            }
        }

        return array_keys($stems);
    }

    /* ------------------------------------------------------------------
     * REAL-TIME CHECKS
     * ---------------------------------------------------------------- */

    private static function has_featured_image_reference($attachment_id, $exclude_post_ids)
    {
        global $wpdb;

        $exclude_clause = self::get_exclusion_clause('post_id', $exclude_post_ids);
        $query          = "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id'
             AND meta_value = %d{$exclude_clause}";
        $args           = array_merge(array($attachment_id), $exclude_post_ids);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $count = (int) $wpdb->get_var(self::prepare_query($query, $args));

        return $count > 0;
    }

    private static function has_woocommerce_gallery_reference($attachment_id, $exclude_post_ids)
    {
        global $wpdb;

        $exclude_clause = self::get_exclusion_clause('post_id', $exclude_post_ids);
        // Whole-token match inside the comma separated list, so ID 5 never matches "15" or "52".
        $query = "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery'
             AND meta_value REGEXP %s{$exclude_clause}";
        $args  = array_merge(
            array('(^|,)' . (int) $attachment_id . '(,|$)'),
            $exclude_post_ids
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var(self::prepare_query($query, $args)) > 0;
    }

    private static function has_elementor_reference($attachment_id, $exclude_post_ids)
    {
        global $wpdb;

        $id = (string) $attachment_id;

        // Narrow SQL pre-filter on media-control id shapes; exact verification happens in PHP.
        $likes = array(
            '%' . $wpdb->esc_like('"id":' . $id . ',') . '%',
            '%' . $wpdb->esc_like('"id":' . $id . '}') . '%',
            '%' . $wpdb->esc_like('"id":"' . $id . '"') . '%',
            '%' . $wpdb->esc_like('\\"id\\":' . $id) . '%',
        );

        $exclude_clause = self::get_exclusion_clause('post_id', $exclude_post_ids);
        $query          = "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_elementor_data'
             AND (meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s){$exclude_clause}";
        $args           = array_merge($likes, $exclude_post_ids);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_col(self::prepare_query($query, $args));

        foreach ($rows as $value) {
            if (in_array($attachment_id, self::extract_elementor_ids($value), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ACF image/file/gallery values pointing at this attachment, on posts, terms,
     * users and options pages. The SQL join keeps only rows that really have an
     * ACF field-key reference row, so unrelated numeric meta is never counted.
     */
    private static function has_acf_reference($attachment_id, $exclude_post_ids)
    {
        global $wpdb;

        if (! function_exists('acf_get_field')) {
            return false;
        }

        $id_str   = (string) $attachment_id;
        $like_str = '%"' . $wpdb->esc_like($id_str) . '"%';
        $like_int = '%' . $wpdb->esc_like('i:' . $id_str . ';') . '%';

        foreach (self::acf_contexts() as $context => $cfg) {
            $join_owner = $cfg['owner'] ? "t.{$cfg['owner']} = r.{$cfg['owner']} AND " : '';
            $owner_col  = $cfg['owner'] ? "t.{$cfg['owner']}" : '0';

            $sql  = "SELECT r.{$cfg['val']} AS field_key, t.{$cfg['val']} AS v
                     FROM {$cfg['table']} AS r
                     INNER JOIN {$cfg['table']} AS t
                        ON {$join_owner}t.{$cfg['key']} = SUBSTRING(r.{$cfg['key']}, 2)
                     WHERE r.{$cfg['key']} LIKE %s
                     AND r.{$cfg['val']} LIKE %s
                     AND (t.{$cfg['val']} = %s OR t.{$cfg['val']} LIKE %s OR t.{$cfg['val']} LIKE %s)";
            $args = array(
                $wpdb->esc_like('_') . '%',
                $wpdb->esc_like('field_') . '%',
                $id_str,
                $like_str,
                $like_int,
            );

            if ('post' === $context && ! empty($exclude_post_ids)) {
                $sql  .= self::get_exclusion_clause($owner_col, $exclude_post_ids);
                $args  = array_merge($args, $exclude_post_ids);
            }

            if ('option' === $context) {
                $sql   .= ' AND t.option_name NOT LIKE %s AND t.option_name NOT LIKE %s';
                $args[] = $wpdb->esc_like('_transient_') . '%';
                $args[] = $wpdb->esc_like('_site_transient_') . '%';
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(self::prepare_query($sql, $args));

            foreach ($rows as $row) {
                if (in_array($attachment_id, self::get_acf_media_ids($row->field_key, $row->v), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * URL / ID references inside post_content and post_excerpt of other posts.
     */
    private static function has_content_reference($attachment_id, $exclude_post_ids)
    {
        global $wpdb;

        $match_keys = self::get_attachment_match_keys($attachment_id);
        $stems      = self::get_attachment_stems($attachment_id);

        // 1) URL references, matched by upload-relative path.
        if (! empty($stems) && ! empty($match_keys)) {
            foreach (array('post_content', 'post_excerpt') as $column) {
                list($like_sql, $like_args) = self::build_stem_like($column, $stems);

                $exclude_clause = self::get_exclusion_clause('ID', $exclude_post_ids);
                $query          = "SELECT {$column} FROM {$wpdb->posts}
                     WHERE post_status != 'auto-draft'
                     AND post_type NOT IN ('attachment', 'revision')
                     {$exclude_clause}
                     AND {$like_sql}";
                $args           = array_merge($exclude_post_ids, $like_args);

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
                $texts = $wpdb->get_col(self::prepare_query($query, $args));

                foreach ($texts as $text) {
                    if (self::text_matches_keys($text, $match_keys)) {
                        return true;
                    }
                }
            }
        }

        // 2) ID references. Narrow SQL pre-filter; exact verification in PHP.
        $id = (string) $attachment_id;
        $id_patterns = array(
            '%' . $wpdb->esc_like('"id":' . $id) . '%',
            '%' . $wpdb->esc_like('"mediaId":' . $id) . '%',
            '%' . $wpdb->esc_like('"ids":') . '%' . $wpdb->esc_like($id) . '%',
            '%' . $wpdb->esc_like('ids=') . '%' . $wpdb->esc_like($id) . '%',
            '%' . $wpdb->esc_like('include=') . '%' . $wpdb->esc_like($id) . '%',
            '%' . $wpdb->esc_like('image=') . '%' . $wpdb->esc_like($id) . '%',
            '%' . $wpdb->esc_like('images=') . '%' . $wpdb->esc_like($id) . '%',
            '%' . $wpdb->esc_like('wp-image-' . $id) . '%',
        );

        foreach (array('post_content', 'post_excerpt') as $column) {
            $likes = array();
            foreach ($id_patterns as $unused) {
                $likes[] = "{$column} LIKE %s";
            }

            $exclude_clause = self::get_exclusion_clause('ID', $exclude_post_ids);
            $query          = "SELECT {$column} FROM {$wpdb->posts}
                 WHERE post_status != 'auto-draft'
                 AND post_type NOT IN ('attachment', 'revision')
                 {$exclude_clause}
                 AND (" . implode(' OR ', $likes) . ')';
            $args           = array_merge($exclude_post_ids, $id_patterns);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            $texts = $wpdb->get_col(self::prepare_query($query, $args));

            foreach ($texts as $text) {
                if (in_array($attachment_id, self::extract_content_ids($text), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Upload URLs stored outside post content: post meta (page builders, Elementor
     * page settings, custom fields), term meta, user meta and options (widgets,
     * theme mods, menus, ...).
     */
    private static function has_url_reference_outside_content($attachment_id, $exclude_post_ids)
    {
        global $wpdb;

        $match_keys = self::get_attachment_match_keys($attachment_id);
        $stems      = self::get_attachment_stems($attachment_id);

        if (empty($match_keys) || empty($stems)) {
            return false;
        }

        foreach (self::get_url_sources() as $source) {
            list($like_sql, $like_args) = self::build_stem_like($source['val'], $stems);

            $query = "SELECT {$source['val']} FROM {$source['table']} WHERE {$like_sql} AND {$source['extra_sql']}";
            $args  = array_merge($like_args, $source['extra_args']);

            if ($source['owner_post'] && ! empty($exclude_post_ids)) {
                $query .= self::get_exclusion_clause($source['owner_post'], $exclude_post_ids);
                $args   = array_merge($args, $exclude_post_ids);
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            $values = $wpdb->get_col(self::prepare_query($query, $args));

            foreach ($values as $value) {
                if (self::text_matches_keys($value, $match_keys)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function has_term_meta_reference($attachment_id)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->termmeta}
             WHERE meta_key = 'thumbnail_id'
             AND meta_value = %d",
            $attachment_id
        ));

        return $count > 0;
    }

    private static function has_site_option_reference($attachment_id)
    {
        return in_array($attachment_id, self::get_site_option_ids(), true);
    }

    /**
     * Attachment IDs stored in well-known media options: custom logo (for the
     * active theme AND every other theme's saved mods), site icon, block-theme
     * site logo, WooCommerce placeholder, header image data and the media
     * widgets (image / gallery / audio / video).
     *
     * @return int[]
     */
    private static function get_site_option_ids()
    {
        global $wpdb;

        $ids = array();

        $logo_id = get_theme_mod('custom_logo');
        if ($logo_id) {
            $ids[] = (int) $logo_id;
        }

        foreach (array('site_icon', 'site_logo', 'woocommerce_placeholder_image') as $option) {
            $value = get_option($option);
            if ($value && is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $theme_mods = $wpdb->get_col($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('theme_mods_') . '%'
        ));
        foreach ($theme_mods as $raw) {
            $mods = maybe_unserialize($raw);
            if (! is_array($mods)) {
                continue;
            }
            if (! empty($mods['custom_logo']) && is_numeric($mods['custom_logo'])) {
                $ids[] = (int) $mods['custom_logo'];
            }
            if (! empty($mods['header_image_data'])) {
                $header = (array) $mods['header_image_data'];
                if (! empty($header['attachment_id']) && is_numeric($header['attachment_id'])) {
                    $ids[] = (int) $header['attachment_id'];
                }
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $widgets = $wpdb->get_col($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('widget_media_') . '%'
        ));
        foreach ($widgets as $raw) {
            $instances = maybe_unserialize($raw);
            if (! is_array($instances)) {
                continue;
            }
            foreach ($instances as $instance) {
                if (! is_array($instance)) {
                    continue;
                }
                if (! empty($instance['attachment_id']) && is_numeric($instance['attachment_id'])) {
                    $ids[] = (int) $instance['attachment_id'];
                }
                if (! empty($instance['ids'])) {
                    $list = is_array($instance['ids']) ? $instance['ids'] : self::split_id_list($instance['ids']);
                    foreach ($list as $id) {
                        if (is_numeric($id)) {
                            $ids[] = (int) $id;
                        }
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /* ------------------------------------------------------------------
     * INTERNAL HELPERS
     * ---------------------------------------------------------------- */

    /**
     * Tables that can hold ACF image/file/gallery values.
     *
     * Table / column names here are internal constants, never user input.
     *
     * @return array
     */
    private static function acf_contexts()
    {
        global $wpdb;

        return array(
            'post'   => array('table' => $wpdb->postmeta, 'pk' => 'meta_id',   'owner' => 'post_id', 'key' => 'meta_key',    'val' => 'meta_value'),
            'term'   => array('table' => $wpdb->termmeta, 'pk' => 'meta_id',   'owner' => 'term_id', 'key' => 'meta_key',    'val' => 'meta_value'),
            'user'   => array('table' => $wpdb->usermeta, 'pk' => 'umeta_id',  'owner' => 'user_id', 'key' => 'meta_key',    'val' => 'meta_value'),
            'option' => array('table' => $wpdb->options,  'pk' => 'option_id', 'owner' => '',        'key' => 'option_name', 'val' => 'option_value'),
        );
    }

    /**
     * One batch of ACF reference rows joined to their value rows.
     */
    private static function fetch_acf_reference_batch($cfg, $last_pk)
    {
        global $wpdb;

        $join_owner = $cfg['owner'] ? "t.{$cfg['owner']} = r.{$cfg['owner']} AND " : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.{$cfg['pk']} AS pk, r.{$cfg['val']} AS field_key, t.{$cfg['val']} AS v
                 FROM {$cfg['table']} AS r
                 INNER JOIN {$cfg['table']} AS t
                    ON {$join_owner}t.{$cfg['key']} = SUBSTRING(r.{$cfg['key']}, 2)
                 WHERE r.{$cfg['pk']} > %d
                 AND r.{$cfg['key']} LIKE %s
                 AND r.{$cfg['val']} LIKE %s
                 ORDER BY r.{$cfg['pk']} ASC
                 LIMIT %d",
                $last_pk,
                $wpdb->esc_like('_') . '%',
                $wpdb->esc_like('field_') . '%',
                self::BATCH_SIZE
            )
        );

        return is_array($rows) ? $rows : array();
    }

    /**
     * Places that may hold full upload URLs.
     *
     * @return array[]
     */
    private static function get_url_sources()
    {
        global $wpdb;

        return array(
            array(
                'table'      => $wpdb->postmeta,
                'pk'         => 'meta_id',
                'val'        => 'meta_value',
                'owner_post' => 'post_id',
                // Skip WordPress' own attachment bookkeeping (it holds relative paths of the file itself).
                'extra_sql'  => 'meta_key NOT LIKE %s',
                'extra_args' => array($wpdb->esc_like('_wp_attach') . '%'),
            ),
            array(
                'table'      => $wpdb->termmeta,
                'pk'         => 'meta_id',
                'val'        => 'meta_value',
                'owner_post' => '',
                'extra_sql'  => '1=1',
                'extra_args' => array(),
            ),
            array(
                'table'      => $wpdb->usermeta,
                'pk'         => 'umeta_id',
                'val'        => 'meta_value',
                'owner_post' => '',
                'extra_sql'  => '1=1',
                'extra_args' => array(),
            ),
            array(
                'table'      => $wpdb->options,
                'pk'         => 'option_id',
                'val'        => 'option_value',
                'owner_post' => '',
                // Transients are caches, not references.
                'extra_sql'  => 'option_name NOT LIKE %s AND option_name NOT LIKE %s',
                'extra_args' => array(
                    $wpdb->esc_like('_transient_') . '%',
                    $wpdb->esc_like('_site_transient_') . '%',
                ),
            ),
        );
    }

    /**
     * One batch of rows from a URL source whose value mentions the uploads folder.
     */
    private static function fetch_url_source_batch($source, $last_pk)
    {
        global $wpdb;

        $markers = self::get_upload_markers();
        $likes   = array();
        $args    = array($last_pk);

        foreach ($markers as $marker) {
            $likes[] = "{$source['val']} LIKE %s";
            $args[]  = '%' . $wpdb->esc_like($marker) . '%';
        }

        $args   = array_merge($args, $source['extra_args']);
        $args[] = self::BATCH_SIZE;

        $query = "SELECT {$source['pk']} AS pk, {$source['val']} AS v FROM {$source['table']}
                  WHERE {$source['pk']} > %d
                  AND (" . implode(' OR ', $likes) . ")
                  AND {$source['extra_sql']}
                  ORDER BY {$source['pk']} ASC
                  LIMIT %d";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(self::prepare_query($query, $args));

        return is_array($rows) ? $rows : array();
    }

    /**
     * Short strings that any stored upload URL must contain (used as a cheap SQL pre-filter).
     *
     * @return string[]
     */
    private static function get_upload_markers()
    {
        $markers = array('uploads');

        $upload    = wp_upload_dir(null, false);
        $base_path = trim((string) wp_parse_url($upload['baseurl'], PHP_URL_PATH), '/');
        $base_path = preg_replace('~/sites/\d+$~', '', $base_path);
        $last      = $base_path ? basename($base_path) : '';

        if ('' !== $last && 'uploads' !== $last) {
            $markers[] = $last;
        }

        return $markers;
    }

    /**
     * Regex capturing the upload-relative path of any upload URL inside a string.
     *
     * Matches both "/uploads/..." and the site's actual uploads base path, so
     * the host, scheme and CDN/S3 domain are irrelevant.
     *
     * @return string
     */
    private static function get_upload_path_regex()
    {
        static $cache = array();

        $blog_id = get_current_blog_id();

        if (! isset($cache[$blog_id])) {
            $upload    = wp_upload_dir(null, false);
            $base_path = trailingslashit((string) wp_parse_url($upload['baseurl'], PHP_URL_PATH));

            $alternatives = array(preg_quote('/uploads/', '#'));
            if ('/' !== $base_path && '/uploads/' !== $base_path) {
                $alternatives[] = preg_quote($base_path, '#');
            }

            $cache[$blog_id] = '#(?:' . implode('|', $alternatives) . ')([^\s"\'()\[\]<>\\\\,;{}|]+)#i';
        }

        return $cache[$blog_id];
    }

    /**
     * Every upload-relative path key mentioned in a block of text.
     *
     * @param string $text
     * @return array Hash set key => true.
     */
    private static function extract_upload_path_keys($text)
    {
        $keys = array();

        if (! is_string($text) || '' === $text) {
            return $keys;
        }

        // Undo JSON escaping so "\/" inside block JSON matches a plain "/".
        $text = str_replace('\\/', '/', $text);

        if (! preg_match_all(self::get_upload_path_regex(), $text, $matches)) {
            return $keys;
        }

        foreach ($matches[1] as $path) {
            $path = self::normalize_path_key($path);
            if ('' === $path) {
                continue;
            }

            $unsized = self::strip_size_suffix($path);

            $keys[$path]                                   = true;
            $keys[$unsized]                                = true;
            $keys['noext:' . self::strip_extension($unsized)] = true;
        }

        return $keys;
    }

    private static function text_matches_keys($text, array $match_keys)
    {
        foreach (array_keys(self::extract_upload_path_keys($text)) as $key) {
            if (isset($match_keys[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Upload-relative paths of an attachment's files (main file + original image).
     *
     * @param int $attachment_id
     * @return string[]
     */
    private static function get_attachment_rel_paths($attachment_id)
    {
        $paths = array();

        $file = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (is_string($file) && '' !== $file) {
            $file = str_replace('\\', '/', $file);

            // Some offload plugins store a full URL here.
            if (preg_match('#^https?://#i', $file) || 0 === strpos($file, '//')) {
                if (preg_match(self::get_upload_path_regex(), $file, $m)) {
                    $file = $m[1];
                } else {
                    $file = '';
                }
            }

            if ('' !== $file) {
                $paths[] = ltrim($file, '/');

                $meta = wp_get_attachment_metadata($attachment_id);
                if (is_array($meta) && ! empty($meta['original_image']) && is_string($meta['original_image'])) {
                    $dir     = dirname($file);
                    $paths[] = ('.' === $dir || '' === $dir ? '' : $dir . '/') . $meta['original_image'];
                }
            }
        }

        return array_values(array_unique($paths));
    }

    private static function normalize_path_key($path)
    {
        $path = preg_replace('~[?#].*$~', '', (string) $path);
        $path = preg_replace('~^sites/\d+/~i', '', ltrim($path, '/'));

        return strtolower(ltrim($path, '/'));
    }

    private static function strip_size_suffix($path)
    {
        return preg_replace('/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', (string) $path);
    }

    private static function strip_extension($path)
    {
        return preg_replace('/\.[a-zA-Z0-9]+$/', '', (string) $path);
    }

    /**
     * Build "(col LIKE ? OR col LIKE ? ...)" matching any stem in plain or JSON-escaped form.
     *
     * @param string   $column Internal column name.
     * @param string[] $stems
     * @return array [sql, args]
     */
    private static function build_stem_like($column, array $stems)
    {
        global $wpdb;

        $parts = array();
        $args  = array();

        foreach ($stems as $stem) {
            $parts[] = "{$column} LIKE %s";
            $args[]  = '%' . $wpdb->esc_like($stem) . '%';

            $parts[] = "{$column} LIKE %s";
            $args[]  = '%' . $wpdb->esc_like(str_replace('/', '\\/', $stem)) . '%';
        }

        return array('(' . implode(' OR ', $parts) . ')', $args);
    }

    /**
     * Recursively collect {url, id} media controls (any depth).
     */
    private static function walk_media_controls($node, &$ids)
    {
        if (! is_array($node)) {
            return;
        }

        if (isset($node['url'], $node['id']) && is_numeric($node['id']) && (int) $node['id'] > 0) {
            $ids[] = (int) $node['id'];
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                self::walk_media_controls($child, $ids);
            }
        }
    }

    /**
     * Resolve an ACF field key to its type (cached).
     */
    private static function get_acf_field_type($field_key)
    {
        if (! function_exists('acf_get_field')) {
            return false;
        }

        if (! isset(self::$acf_type_cache[$field_key])) {
            $field = acf_get_field($field_key);
            self::$acf_type_cache[$field_key] = (is_array($field) && isset($field['type'])) ? $field['type'] : false;
        }

        return self::$acf_type_cache[$field_key];
    }

    /**
     * Attachment IDs held in a raw ACF value, but only if the field definition
     * confirms it is an image / file / gallery field.
     *
     * @param string $field_key ACF field key (field_xxx).
     * @param mixed  $value     Raw stored value.
     * @return int[]
     */
    private static function get_acf_media_ids($field_key, $value)
    {
        $type = self::get_acf_field_type($field_key);

        if (! in_array($type, array('image', 'file', 'gallery'), true)) {
            return array();
        }

        return self::extract_acf_ids_from_value($type, $value);
    }

    private static function extract_acf_ids_from_value($type, $value)
    {
        $ids = array();

        if (empty($value)) {
            return $ids;
        }

        if (is_string($value) && ! is_numeric($value)) {
            $value = maybe_unserialize($value);
        }

        if (in_array($type, array('image', 'file'), true)) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            } elseif (is_array($value) && isset($value['ID'])) {
                $ids[] = (int) $value['ID'];
            }
            return $ids;
        }

        if ('gallery' === $type && is_array($value)) {
            foreach ($value as $item) {
                if (is_numeric($item)) {
                    $ids[] = (int) $item;
                } elseif (is_array($item) && isset($item['ID'])) {
                    $ids[] = (int) $item['ID'];
                }
            }
        }

        return $ids;
    }

    /**
     * Build a prepared NOT IN clause for the supplied IDs.
     *
     * @param string $column  Column name (internal, never user input).
     * @param int[]  $post_ids
     * @return string
     */
    private static function get_exclusion_clause($column, $post_ids)
    {
        if (empty($post_ids)) {
            return '';
        }

        return ' AND ' . $column . ' NOT IN ('
            . implode(', ', array_fill(0, count($post_ids), '%d'))
            . ')';
    }

    /**
     * Prepare a query with a dynamic argument list (WP 5.0 compatible).
     *
     * @param string $query
     * @param array  $args
     * @return string
     */
    private static function prepare_query($query, $args)
    {
        global $wpdb;

        array_unshift($args, $query);
        return call_user_func_array(array($wpdb, 'prepare'), $args);
    }

    /**
     * Split a comma-separated ID list into clean ints.
     *
     * @param string $value
     * @return int[]
     */
    private static function split_id_list($value)
    {
        $ids = array();
        foreach (explode(',', (string) $value) as $part) {
            $part = trim($part);
            if ('' !== $part && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }
        return $ids;
    }
}
