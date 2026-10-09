<?php

if (! defined('ABSPATH')) {
    exit;
}

class Postmediaweb_Orphan_Scanner
{
    /**
     * Rows fetched per internal batch. Keeps peak memory bounded regardless of library size.
     */
    const BATCH_SIZE = 500;

    /**
     * Scan the entire media library and return orphaned attachments.
     *
     * An attachment is reported only when ALL of these hold:
     *   - it has no parent post (never attached, or its parent was permanently deleted).
     *     Attachments whose parent still exists - including trashed parents - are never
     *     reported: moving a post to the trash must always stay reversible;
     *   - no confirmed reference to it by ID exists anywhere (see Postmediaweb_Reference_Checker);
     *   - none of its files is mentioned by upload path anywhere (content, meta, options ...).
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

        $reference_set  = Postmediaweb_Reference_Checker::build_global_reference_set();
        $referenced_ids = $reference_set['ids'];
        $used_paths     = $reference_set['paths'];

        $orphan_ids  = array();
        $orphan_data = array();
        $last_id     = 0;

        do {
            // Only attachments with no existing parent are candidates (parent.ID IS NULL covers
            // both post_parent = 0 and a parent that no longer exists).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT attachment.ID, attachment.post_mime_type, attachment.post_date
                     FROM {$wpdb->posts} AS attachment
                     LEFT JOIN {$wpdb->posts} AS parent
                       ON parent.ID = attachment.post_parent
                     WHERE attachment.post_type = 'attachment'
                     AND attachment.post_status != 'trash'
                     AND parent.ID IS NULL
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

                if (isset($referenced_ids[$id])) {
                    continue;
                }

                if (Postmediaweb_Reference_Checker::is_path_used($id, $used_paths)) {
                    continue; // Mentioned somewhere by URL - not an orphan.
                }

                $orphan_ids[] = $id;

                $file_path = get_attached_file($id);
                $file_size = ($file_path && file_exists($file_path) && false !== @filesize($file_path))
                    ? size_format(filesize($file_path))
                    : __('Unknown', 'post-media-cleanup');

                $orphan_data[] = array(
                    'id'   => $id,
                    'name' => wp_basename((string) get_post_meta($id, '_wp_attached_file', true)),
                    'type' => (string) $attachment->post_mime_type,
                    'size' => (string) $file_size,
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
     * The browser only sends IDs; nothing it says is trusted. Every attachment is
     * re-validated here, immediately before deletion, because the site may have changed
     * since the scan and because the request itself could have been tampered with:
     *   - it must really be an attachment;
     *   - the current user must be allowed to delete it;
     *   - it must still be an orphan (no existing parent post);
     *   - it must still be unreferenced.
     * Anything failing a check is skipped, never deleted.
     *
     * @param int[] $ids Array of attachment IDs to delete.
     * @return array {
     *     @type int   $deleted Number of successfully deleted attachments.
     *     @type int[] $skipped IDs skipped because they failed a re-check.
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

        foreach (array_unique($ids) as $id) {
            $id = absint($id);

            if ($id <= 0) {
                continue;
            }

            $post = get_post($id);

            // Reject arbitrary post IDs from AJAX requests.
            if (! $post || 'attachment' !== $post->post_type) {
                continue;
            }

            if (! current_user_can('delete_post', $id)) {
                $skipped[] = $id;
                continue;
            }

            // Only genuine orphans: an attachment whose parent still exists is in use by that post.
            if ((int) $post->post_parent > 0 && get_post((int) $post->post_parent)) {
                $skipped[] = $id;
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
}
