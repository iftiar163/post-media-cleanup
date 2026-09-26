<?php

if (! defined('ABSPATH')) {
    exit;
}

class Postmediaweb_Ajax
{

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

        $raw_ids = isset($_POST['ids']) ? (array) $_POST['ids'] : array();
        $ids     = array_filter(array_map('absint', $raw_ids));

        if (empty($ids)) {
            wp_send_json_error(__('No files selected.', 'post-media-cleanup'));
        }

        $result = Postmediaweb_Orphan_Scanner::delete($ids);

        wp_send_json_success(array(
            'count'   => $result['deleted'],
            'skipped' => $result['skipped'],
        ));
    }
}
