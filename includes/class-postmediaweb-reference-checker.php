<?php
/**
 * Reference Checker.
 *
 * Single source of truth for "is this attachment actually used
 * somewhere?". Used both by the real-time skip-shared check (when a
 * post is permanently deleted) and by the full-site orphan scanner,
 * so the two code paths can no longer disagree with each other.
 *
 * Guiding rule (per user report): a CONFIRMED reference keeps the
 * attachment. A bare numeric value that merely equals an attachment
 * ID is NOT by itself treated as a reference — we only trust meta
 * keys/structures we can positively identify as media storage
 * (WooCommerce gallery meta, Elementor's own data key, an ACF field
 * whose definition says it's an image/file/gallery field, etc).
 * Everything else stays conservative to avoid false "used" positives
 * that would let genuine orphans regrow, but also — the actual bug
 * report here — to avoid the opposite failure: false "unused"
 * verdicts that get real, in-use media deleted.
 *
 * @package PostMediaCleanup
 */

if (! defined('ABSPATH')) {
    exit;
}

class Postmediaweb_Reference_Checker
{
    /**
     * Rows fetched per internal batch when scanning postmeta/options
     * tables. Same rationale as Orphan_Scanner::BATCH_SIZE — bounded
     * memory regardless of site size.
     */
    const BATCH_SIZE = 500;

    /**
     * Real-time, single-attachment check: is $attachment_id referenced
     * anywhere other than (optionally) $exclude_post_id?
     *
     * This re-queries the database fresh every time it's called — it
     * is NOT allowed to rely on any previously-built cache/set, because
     * the whole point of calling it right before a delete is to catch
     * changes that happened after an earlier scan.
     *
     * @param int $attachment_id
     * @param int $exclude_post_id Post ID to ignore matches from (e.g. the
     *                              post currently being deleted, whose own
     *                              stored references don't count as "shared").
     * @return bool
     */
    public static function is_referenced($attachment_id, $exclude_post_id = 0)
    {
        $attachment_id = (int) $attachment_id;

        if ($attachment_id <= 0) {
            return false;
        }

        if (self::has_featured_image_reference($attachment_id, $exclude_post_id)) {
            return true;
        }

        if (self::has_woocommerce_gallery_reference($attachment_id, $exclude_post_id)) {
            return true;
        }

        if (self::has_elementor_reference($attachment_id, $exclude_post_id)) {
            return true;
        }

        if (self::has_acf_reference($attachment_id, $exclude_post_id)) {
            return true;
        }

        if (self::has_content_reference($attachment_id, $exclude_post_id)) {
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
     * Build a hash set of every attachment ID that is referenced
     * anywhere on the site, for the full-library orphan scan. This is
     * the batched/efficient equivalent of calling is_referenced() on
     * every attachment — used once per scan, not once per attachment.
     *
     * @return array Hash set: attachment ID (int) => true.
     */
    public static function build_global_reference_set()
    {
        global $wpdb;

        $referenced = array();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $thumb_ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value != ''"
        );
        foreach ($thumb_ids as $id) {
            $referenced[(int) $id] = true;
        }

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

        if (function_exists('acf_get_field')) {
            $last_meta_id = 0;
            do {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
                         WHERE meta_key LIKE %s
                         AND meta_value LIKE %s
                         AND meta_id > %d
                         ORDER BY meta_id ASC
                         LIMIT %d",
                        $wpdb->esc_like('_') . '%',
                        $wpdb->esc_like('field_') . '%',
                        $last_meta_id,
                        self::BATCH_SIZE
                    )
                );

                foreach ($rows as $row) {
                    $last_meta_id = max($last_meta_id, (int) $row->meta_id);
                    foreach (self::extract_acf_ids_from_reference_row($row) as $id) {
                        $referenced[$id] = true;
                    }
                }

                $fetched = count($rows);
            } while ($fetched === self::BATCH_SIZE);
        }

        $used_urls = array();
        $upload_dir = wp_upload_dir();
        $base_url   = self::normalize_url($upload_dir['baseurl']);
        $url_pattern = '#' . preg_quote($base_url, '#') . '[^\s"\'\)\]<>\\\\]+#i';

        $last_id = 0;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_content FROM {$wpdb->posts}
                     WHERE post_status NOT IN ('trash', 'auto-draft')
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

                if (empty($row->post_content)) {
                    continue;
                }

                // Undo JSON/URL-escaping (\/ => /) so escaped URLs inside
                // Gutenberg block JSON still match the plain upload URL.
                $unescaped = str_replace('\\/', '/', $row->post_content);
                $normalized = self::normalize_url($unescaped);

                if (preg_match_all($url_pattern, $normalized, $matches)) {
                    foreach ($matches[0] as $match) {
                        $match = strtok($match, '?');
                        $used_urls[$match] = true;
                        $used_urls[self::strip_size_suffix($match)] = true;
                    }
                }

                foreach (self::extract_content_ids($row->post_content) as $id) {
                    $referenced[$id] = true;
                }
            }

            $fetched = count($rows);
        } while ($fetched === self::BATCH_SIZE);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $term_thumb_ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->termmeta}
             WHERE meta_key = 'thumbnail_id' AND meta_value != ''"
        );
        foreach ($term_thumb_ids as $id) {
            $referenced[(int) $id] = true;
        }

        foreach (self::get_site_option_ids() as $id) {
            $referenced[$id] = true;
        }

        return array(
            'ids'  => $referenced,
            'urls' => $used_urls,
        );
    }

    private static function has_featured_image_reference($attachment_id, $exclude_post_id)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id'
             AND meta_value = %d
             AND post_id != %d",
            $attachment_id,
            $exclude_post_id
        ));

        return $count > 0;
    }

    private static function has_woocommerce_gallery_reference($attachment_id, $exclude_post_id)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery'
             AND post_id != %d
             AND meta_value LIKE %s",
            $exclude_post_id,
            '%' . $wpdb->esc_like((string) $attachment_id) . '%'
        ));

        foreach ($rows as $row) {
            if (in_array($attachment_id, self::split_id_list($row->meta_value), true)) {
                return true;
            }
        }

        return false;
    }

    private static function has_elementor_reference($attachment_id, $exclude_post_id)
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_elementor_data'
             AND post_id != %d
             AND meta_value LIKE %s",
            $exclude_post_id,
            '%' . $wpdb->esc_like((string) $attachment_id) . '%'
        ));

        foreach ($rows as $row) {
            if (in_array($attachment_id, self::extract_elementor_ids($row->meta_value), true)) {
                return true;
            }
        }

        return false;
    }

    private static function has_acf_reference($attachment_id, $exclude_post_id)
    {
        global $wpdb;

        if (! function_exists('acf_get_field')) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key LIKE %s
             AND meta_value LIKE %s
             AND post_id != %d",
            $wpdb->esc_like('_') . '%',
            $wpdb->esc_like('field_') . '%',
            $exclude_post_id
        ));

        foreach ($rows as $row) {
            if (in_array($attachment_id, self::extract_acf_ids_from_reference_row($row), true)) {
                return true;
            }
        }

        return false;
    }

    private static function has_content_reference($attachment_id, $exclude_post_id)
    {
        global $wpdb;

        $url = wp_get_attachment_url($attachment_id);

        if (! $url) {
            return false;
        }

        $url_base = self::strip_size_suffix(self::normalize_url($url));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $content_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_status NOT IN ('trash', 'auto-draft')
             AND ID != %d
             AND (post_content LIKE %s OR post_content LIKE %s)",
            $exclude_post_id,
            '%' . $wpdb->esc_like($url_base) . '%',
            '%' . $wpdb->esc_like(str_replace('/', '\\/', $url_base)) . '%'
        ));

        if ($content_count > 0) {
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts}
             WHERE post_status NOT IN ('trash', 'auto-draft')
             AND ID != %d
             AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s)",
            $exclude_post_id,
            '%' . $wpdb->esc_like('ids="') . '%' . $wpdb->esc_like((string) $attachment_id) . '%',
            '%' . $wpdb->esc_like('image="' . $attachment_id . '"') . '%',
            '%' . $wpdb->esc_like((string) $attachment_id) . '%'
        ));

        foreach ($rows as $row) {
            if (in_array($attachment_id, self::extract_content_ids($row->post_content), true)) {
                return true;
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
     * Attachment IDs stored in well-known, confirmed-media site options:
     * custom logo, site icon, and any theme_mod known to hold an
     * attachment ID (header image / background image use a URL, which
     * the content/URL pass already covers via wp_upload_dir base URL
     * matching against option values would require scanning wp_options
     * — kept intentionally narrow here to known ID-based options).
     *
     * @return int[]
     */
    private static function get_site_option_ids()
    {
        $ids = array();

        $logo_id = get_theme_mod('custom_logo');
        if ($logo_id) {
            $ids[] = (int) $logo_id;
        }

        $site_icon_id = get_option('site_icon');
        if ($site_icon_id) {
            $ids[] = (int) $site_icon_id;
        }

        return $ids;
    }

    /**
     * Split a comma-separated ID list (WooCommerce gallery meta,
     * [gallery ids="1,2,3"] shortcode attribute, etc.) into clean ints.
     *
     * @param string $value
     * @return int[]
     */
    private static function split_id_list($value)
    {
        $ids = array();
        foreach (explode(',', (string) $value) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }
        return $ids;
    }

    /**
     * Extract every attachment ID referenced inside an Elementor
     * _elementor_data JSON blob: image/file controls (which store
     * {"id":N,"url":"..."}), background_image controls, and gallery
     * controls (arrays of {"id":N,...}). Handles both plain JSON and
     * the escaped-JSON form Elementor sometimes stores.
     *
     * @param string $raw
     * @return int[]
     */
    private static function extract_elementor_ids($raw)
    {
        if (empty($raw)) {
            return array();
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            $decoded = json_decode(wp_unslash($raw), true);
        }

        $ids = array();

        if (is_array($decoded)) {
            self::walk_elementor_tree($decoded, $ids);
            return array_values(array_unique($ids));
        }

        // Match only media-shaped id/url pairs to avoid treating arbitrary IDs as references.
        if (preg_match_all('/"id"\s*:\s*(\d+)\s*,\s*"url"/', $raw, $matches)) {
            foreach ($matches[1] as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function walk_elementor_tree($elements, &$ids)
    {
        if (! is_array($elements)) {
            return;
        }

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            if (! empty($element['elements']) && is_array($element['elements'])) {
                self::walk_elementor_tree($element['elements'], $ids);
            }

            if (empty($element['settings']) || ! is_array($element['settings'])) {
                continue;
            }

            foreach ($element['settings'] as $value) {
                if (is_array($value) && isset($value['url'], $value['id']) && is_numeric($value['id']) && $value['id'] > 0) {
                    $ids[] = (int) $value['id'];
                }

                if (is_array($value) && isset($value['background_image']['id'])) {
                    $bg_id = (int) $value['background_image']['id'];
                    if ($bg_id > 0) {
                        $ids[] = $bg_id;
                    }
                }

                if (is_array($value) && isset($value[0]) && is_array($value[0]) && isset($value[0]['id'])) {
                    foreach ($value as $gallery_item) {
                        if (is_array($gallery_item) && isset($gallery_item['id']) && (int) $gallery_item['id'] > 0) {
                            $ids[] = (int) $gallery_item['id'];
                        }
                    }
                }
            }
        }
    }

    /**
     * Given an ACF "reference" postmeta row (meta_key = "_{field}",
     * meta_value = "field_xxx"), look up the field definition and, only
     * if it is actually an image/file/gallery field, return the
     * attachment ID(s) stored in the sibling value row. This is the
     * "confirmed reference, not a coincidental number" check.
     *
     * @param object $row {meta_id, post_id, meta_key, meta_value}
     * @return int[]
     */
    private static function extract_acf_ids_from_reference_row($row)
    {
        static $field_type_cache = array();

        $field_key = $row->meta_value;

        if (! isset($field_type_cache[$field_key])) {
            $field = acf_get_field($field_key);
            $field_type_cache[$field_key] = $field ? $field['type'] : false;
        }

        $type = $field_type_cache[$field_key];

        if (! in_array($type, array('image', 'file', 'gallery'), true)) {
            return array();
        }

        // meta_key here is "_{field_name}" (leading underscore); the
        // actual stored value lives under the same post_id with the
        // underscore stripped.
        $value_key = ltrim($row->meta_key, '_');
        $value     = get_post_meta($row->post_id, $value_key, true);

        return self::extract_acf_ids_from_value($type, $value);
    }

    private static function extract_acf_ids_from_value($type, $value)
    {
        $ids = array();

        if (empty($value)) {
            return $ids;
        }

        if (in_array($type, array('image', 'file'), true)) {
            if (is_numeric($value)) {
                $ids[] = (int) $value;
            } elseif (is_array($value) && isset($value['ID'])) {
                $ids[] = (int) $value['ID'];
            } elseif (is_string($value)) {
                $maybe_array = maybe_unserialize($value);
                if (is_array($maybe_array) && isset($maybe_array['ID'])) {
                    $ids[] = (int) $maybe_array['ID'];
                } elseif (is_numeric($maybe_array)) {
                    $ids[] = (int) $maybe_array;
                }
            }
            return $ids;
        }

        if ('gallery' === $type) {
            $items = is_string($value) ? maybe_unserialize($value) : $value;
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (is_numeric($item)) {
                        $ids[] = (int) $item;
                    } elseif (is_array($item) && isset($item['ID'])) {
                        $ids[] = (int) $item['ID'];
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * Extract attachment IDs referenced directly by ID (not just URL)
     * inside post_content: Gutenberg image/gallery/media-text/cover
     * block JSON comments, and the [gallery ids="1,2,3"] shortcode.
     * Divi/WPBakery shortcodes reference media by URL or numeric image=
     * attribute — the numeric form is covered here too.
     *
     * @param string $content
     * @return int[]
     */
    private static function extract_content_ids($content)
    {
        $ids = array();

        if (empty($content)) {
            return $ids;
        }

        if (preg_match_all('/<!--\s*wp:(?:image|cover|media-text|gallery)\s+(\{.*?\})\s*(?:\/)?-->/s', $content, $matches)) {
            foreach ($matches[1] as $json) {
                $data = json_decode($json, true);
                if (is_array($data)) {
                    if (isset($data['id']) && is_numeric($data['id'])) {
                        $ids[] = (int) $data['id'];
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
        }

        if (preg_match_all('/\[gallery[^\]]*\bids=["\']([0-9,\s]+)["\']/i', $content, $matches)) {
            foreach ($matches[1] as $id_list) {
                $ids = array_merge($ids, self::split_id_list($id_list));
            }
        }

        if (preg_match_all('/\[vc_[^\]]+\s(?:image|images)=["\']([0-9,\s]+)["\']/i', $content, $matches)) {
            foreach ($matches[1] as $id_list) {
                $ids = array_merge($ids, self::split_id_list($id_list));
            }
        }

        return array_values(array_unique($ids));
    }

    private static function normalize_url($url)
    {
        return preg_replace('#https?://#i', '//', (string) $url);
    }

    private static function strip_size_suffix($url)
    {
        return preg_replace('/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $url);
    }
}
