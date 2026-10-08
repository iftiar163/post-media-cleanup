<?php

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

        wp_raise_memory_limit('admin');
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
        }

        $reference_set = Postmediaweb_Reference_Checker::build_global_reference_set();
        $referenced_ids = $reference_set['ids'];
        $used_urls      = $reference_set['urls'];

        $orphan_ids  = array();
        $orphan_data = array();
        $last_id     = 0;

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT attachment.ID, attachment.post_parent, attachment.post_mime_type,
                            attachment.post_date, parent.post_status AS parent_status
                     FROM {$wpdb->posts} AS attachment
                     LEFT JOIN {$wpdb->posts} AS parent
                       ON parent.ID = attachment.post_parent
                     WHERE attachment.post_type = 'attachment'
                     AND attachment.post_status != 'trash'
                     AND attachment.ID > %d
                     ORDER BY attachment.ID ASC
                     LIMIT %d",
                    $last_id,
                    self::BATCH_SIZE
                )
            );

            foreach ($rows as $attachment) {
                $id      = (int) $attachment->ID;
                $last_id = max($last_id, $id);

                // A nonzero parent may no longer exist, so only skip live parents.
                if (! empty($attachment->parent_status) && 'trash' !== $attachment->parent_status) {
                    continue;
                }

                if (isset($referenced_ids[$id])) {
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
     * Delete a list of attachments permanently.
     *
     * A scan can take a while on a large library, and the site can
     * change while it's running (someone adds the image to a new
     * product gallery, an Elementor template gets published, etc).
     * So each attachment is re-checked fresh, right here, immediately
     * before it's actually deleted — not just relying on the scan
     * result the browser is holding. Anything found to be referenced
     * now is skipped rather than deleted.
     *
     * @param int[] $ids  Array of attachment IDs to delete.
     * @return array {
     *     @type int   $deleted Number of successfully deleted attachments.
     *     @type int[] $skipped IDs skipped because they turned out to be referenced.
     * }
     */
    public static function delete(array $ids)
    {
        wp_raise_memory_limit('admin');
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
        }

        $deleted = 0;
        $skipped = array();

        foreach ($ids as $id) {
            $id = absint($id);

            if ($id <= 0) {
                continue;
            }

            // Reject arbitrary post IDs from AJAX requests.
            if ('attachment' !== get_post_type($id)) {
                continue;
            }

            if (Postmediaweb_Reference_Checker::is_referenced($id)) {
                $skipped[] = $id;
                continue;
            }

            $result = wp_delete_attachment($id, true);

            if ($result) {
                $deleted++;
            }
        }

        return array(
            'deleted' => $deleted,
            'skipped' => $skipped,
        );
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
