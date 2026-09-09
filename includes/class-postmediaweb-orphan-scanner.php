<?php

/**
 * Orphan Scanner.
 *
 * Finds all media attachments not referenced anywhere across the
 * entire site.
 *
 * @package PostMediaCleanup
 */

if (! defined('ABSPATH')) {
    exit;
}

class Postmediaweb_Orphan_Scanner
{
    /**
     * Rows fetched per internal batch, for both the content-scanning
     * pass and the attachment-checking pass. Kept modest so peak memory
     * per batch stays bounded regardless of overall site size — the
     * previous implementation loaded every attachment object AND every
     * post's content into memory simultaneously in one shot, which
     * could exhaust memory_limit on large libraries.
     */
    const BATCH_SIZE = 500;

    /**
     * Scan the entire media library and return orphaned attachments.
     *
     * Returns an array with two keys:
     *   'ids'     => flat array of orphaned attachment IDs
     *   'orphans' => array of display data for each orphan
     *
     * @return array
     */
    public static function scan()
    {
        global $wpdb;

        // Large libraries can take a while and touch a lot of rows;
        // reduce the chance of a hard timeout/OOM mid-scan. Best-effort —
        // some hosts disable these, so both are silenced.
        wp_raise_memory_limit('admin');
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
        }

        // ── Featured images — one query, small result set ────────────
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $featured_ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id'
             AND meta_value != ''"
        );
        $featured_ids = array_flip(array_map('absint', $featured_ids));

        // ── Build a set of every upload URL referenced in content ────
        // Instead of concatenating every post's content into one giant
        // string and running strpos() against it once PER ATTACHMENT
        // (which is O(attachments × total content size) and can also
        // exhaust memory holding the whole blob at once), we make a
        // single pass over content in bounded batches, extract every
        // upload-URL-looking substring with one regex pass per batch,
        // and store the stripped versions in a hash set. Checking
        // whether a given attachment is "used" is then an O(1)
        // isset() lookup instead of a fresh full-text scan.
        $used_urls = self::build_used_url_set();

        // ── Walk attachments in batches, fields-only where possible ──
        $orphan_ids  = array();
        $orphan_data = array();
        $last_id     = 0;

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_parent, post_mime_type, post_date
                     FROM {$wpdb->posts}
                     WHERE post_type = 'attachment'
                     AND post_status != 'trash'
                     AND ID > %d
                     ORDER BY ID ASC
                     LIMIT %d",
                    $last_id,
                    self::BATCH_SIZE
                )
            );

            foreach ($rows as $attachment) {
                $id      = (int) $attachment->ID;
                $last_id = max($last_id, $id);

                // Skip if it belongs to a post that still genuinely
                // exists. Previously this only checked "post_parent > 0",
                // which permanently hides attachments whose parent post
                // was removed by some other means (direct DB edits,
                // an import tool, another plugin) — those are real
                // orphans but would never surface. get_post_status()
                // returns false for a nonexistent post.
                if ($attachment->post_parent > 0) {
                    $parent_status = get_post_status($attachment->post_parent);
                    if ($parent_status && 'trash' !== $parent_status) {
                        continue;
                    }
                }

                if (isset($featured_ids[$id])) {
                    continue;
                }

                $url = wp_get_attachment_url($id);
                if (! $url) {
                    continue;
                }

                $url_stripped = self::normalize_url($url);
                $url_base     = self::strip_size_suffix($url_stripped);

                if (isset($used_urls[$url_stripped]) || isset($used_urls[$url_base])) {
                    continue; // Used in content — not an orphan.
                }

                // Genuinely orphaned — only now do we pay for the
                // extra per-row lookups needed to display it, instead
                // of doing this for every attachment up front.
                $orphan_ids[] = $id;

                $file_path = get_attached_file($id);
                $file_size = $file_path && file_exists($file_path)
                    ? size_format(filesize($file_path))
                    : __('Unknown', 'post-media-cleanup');

                $orphan_data[] = array(
                    'id'   => $id,
                    'name' => basename($url),
                    'type' => $attachment->post_mime_type,
                    'size' => $file_size,
                    'date' => mysql2date('d M Y', $attachment->post_date),
                );
            }

            $fetched = count($rows);
        } while ($fetched === self::BATCH_SIZE);

        return array(
            'ids'     => $orphan_ids,
            'orphans' => $orphan_data,
        );
    }

    /**
     * Scans post_content across the whole site in bounded batches and
     * returns a hash set (as array keys) of every normalized upload URL
     * found — both with and without a resize suffix (e.g. -150x150).
     *
     * @return array Hash set: normalized URL => true.
     */
    private static function build_used_url_set()
    {
        global $wpdb;

        $upload_dir  = wp_upload_dir();
        $base_url    = self::normalize_url($upload_dir['baseurl']);
        $pattern     = '#' . preg_quote($base_url, '#') . '[^\s"\'\)\]<>]+#i';

        $used_urls = array();
        $last_id   = 0;

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

                $normalized = self::normalize_url($row->post_content);

                if (! preg_match_all($pattern, $normalized, $matches)) {
                    continue;
                }

                foreach ($matches[0] as $match) {
                    $match = strtok($match, '?'); // drop query strings
                    $used_urls[$match] = true;
                    $used_urls[self::strip_size_suffix($match)] = true;
                }
            }

            // Batch content is discarded here as $rows goes out of
            // scope on the next iteration — peak memory is bounded by
            // one batch's worth of post_content, not the whole site's.
            $fetched = count($rows);
        } while ($fetched === self::BATCH_SIZE);

        return $used_urls;
    }

    /**
     * Delete a list of attachments permanently.
     *
     * @param int[] $ids  Array of attachment IDs to delete.
     * @return int  Number of successfully deleted attachments.
     */
    public static function delete(array $ids)
    {
        wp_raise_memory_limit('admin');
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
        }

        $deleted = 0;

        foreach ($ids as $id) {
            $id = absint($id);

            if ($id <= 0) {
                continue;
            }

            // Verify it is actually an attachment before deleting.
            // This prevents someone sending arbitrary post IDs via AJAX.
            if ('attachment' !== get_post_type($id)) {
                continue;
            }

            $result = wp_delete_attachment($id, true);

            if ($result) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Normalizes a URL for comparison: strips the http(s) scheme so
     * http/https-mismatched references still match.
     *
     * @param string $url
     * @return string
     */
    private static function normalize_url($url)
    {
        return preg_replace('#https?://#i', '//', (string) $url);
    }

    private static function strip_size_suffix($url)
    {
        return preg_replace('/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $url);
    }
}
