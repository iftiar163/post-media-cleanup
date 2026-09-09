<?php

/**
 * AJAX Handlers.
 *
 * Registers and processes all AJAX requests for the plugin.
 *
 * @package PostMediaCleanup
 */

if (! defined('ABSPATH')) {
    exit;
}

class Postmediaweb_Ajax
{

    /** @var PMC_Ajax|null */
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

    /**
     * Register AJAX hooks.
     *
     * wp_ajax_{action} fires for logged-in users only.
     * wp_ajax_nopriv_{action} fires for logged-out users.
     *
     * We only register wp_ajax_ (not nopriv) because orphan
     * scanning and deletion must require admin login.
     * A logged-out request simply gets a 400 response from WordPress.
     */
    private function register_hooks()
    {
        add_action('wp_ajax_pmc_scan_orphans',   array($this, 'handle_scan'));
        add_action('wp_ajax_pmc_delete_orphans', array($this, 'handle_delete'));
    }

    /**
     * Handle the scan AJAX request.
     *
     * Security checks:
     *  1. Verify the nonce — confirms the request came from our page.
     *  2. Check user capability — confirms the user is an admin.
     *
     * Without both checks, any logged-in user or forged request
     * could trigger a full media library scan.
     */
    public function handle_scan()
    {
        // Step 1: Verify nonce.
        // check_ajax_referer() verifies the nonce AND dies with -1
        // if it fails. The second parameter must match wp_create_nonce()
        // in enqueue_assets(). This prevents CSRF attacks.
        check_ajax_referer('pmc_orphan_nonce', 'nonce');

        // Step 2: Verify capability.
        if (! current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'post-media-cleanup'), 403);
        }

        // Run the scan.
        $result = Postmediaweb_Orphan_Scanner::scan();

        // wp_send_json_success() sets Content-Type: application/json,
        // encodes the data, and calls wp_die() — all in one call.
        // This is the correct way to end an AJAX handler in WordPress.
        wp_send_json_success($result);
    }

    /**
     * Handle the delete AJAX request.
     */
    public function handle_delete()
    {
        check_ajax_referer('pmc_orphan_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'post-media-cleanup'), 403);
        }

        // Sanitize the incoming IDs.
        // Never trust raw POST data. array_map + absint ensures
        // every value is a positive integer before it touches the DB.
        $raw_ids = isset($_POST['ids']) ? (array) $_POST['ids'] : array();
        $ids     = array_filter(array_map('absint', $raw_ids));

        if (empty($ids)) {
            wp_send_json_error(__('No files selected.', 'post-media-cleanup'));
        }

        $deleted = Postmediaweb_Orphan_Scanner::delete($ids);

        wp_send_json_success(array(
            'count' => $deleted,
        ));
    }
}
