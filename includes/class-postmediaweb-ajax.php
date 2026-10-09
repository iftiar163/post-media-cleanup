<?php

if (! defined('ABSPATH')) {
    exit;
}

class Postmediaweb_Ajax
{
    /**
     * Largest number of IDs accepted in a single delete request. The admin UI sends smaller
     * batches; the cap keeps one request within PHP's max_input_vars and execution limits.
     */
    const MAX_IDS_PER_REQUEST = 200;

    /** @var Postmediaweb_Ajax|null */
    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->register_hooks();
    }

    private function register_hooks()
    {
        add_action('wp_ajax_pmc_scan_orphans',   array($this, 'handle_scan'));
        add_action('wp_ajax_pmc_delete_orphans', array($this, 'handle_delete'));
    }

    public function handle_scan()
    {
        check_ajax_referer('pmc_orphan_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'post-media-cleanup'), 403);
        }

        $result = Postmediaweb_Orphan_Scanner::scan();

        wp_send_json_success($result);
    }

    public function handle_delete()
    {
        check_ajax_referer('pmc_orphan_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'post-media-cleanup'), 403);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
        $raw_ids = isset($_POST['ids']) ? wp_unslash($_POST['ids']) : array();

        if (! is_array($raw_ids)) {
            $raw_ids = explode(',', (string) $raw_ids);
        }

        // Keep scalar values only: absint() of a nested array silently becomes 1.
        $raw_ids = array_filter($raw_ids, 'is_scalar');
        $ids     = array_values(array_unique(array_filter(array_map('absint', $raw_ids))));

        if (empty($ids)) {
            wp_send_json_error(__('No files selected.', 'post-media-cleanup'));
        }

        if (count($ids) > self::MAX_IDS_PER_REQUEST) {
            wp_send_json_error(__('Too many files in one request. Please try again.', 'post-media-cleanup'));
        }

        $result = Postmediaweb_Orphan_Scanner::delete($ids);

        wp_send_json_success(array(
            'count'   => $result['deleted'],
            'skipped' => $result['skipped'],
        ));
    }
}
