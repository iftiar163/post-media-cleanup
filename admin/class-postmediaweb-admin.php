<?php

/**
 * Admin UI — Settings page.
 *
 * @package PostMediaCleanup
 */

if (! defined('ABSPATH')) {
    exit;
}

class Postmediaweb_Admin
{
    private static $instance = null;

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('plugin_action_links_' . plugin_basename(POSTMEDIAWEB_PLUGIN_DIR . 'post-media-cleanup.php'), [$this, 'add_settings_link']);
    }

    public function add_menu()
    {
        add_options_page(
            __('Post Media Cleanup Settings', 'post-media-cleanup'),
            __('Post Media Cleanup', 'post-media-cleanup'),
            'manage_options',
            'post-media-cleanup',
            [$this, 'render_page']
        );
    }

    public function add_settings_link($links)
    {
        $url  = admin_url('options-general.php?page=post-media-cleanup');
        $link = '<a href="' . esc_url($url) . '">' . __('Settings', 'post-media-cleanup') . '</a>';
        array_unshift($links, $link);
        return $links;
    }

    public function register_settings()
    {
        register_setting(
            'postmediaweb_settings_group',
            POSTMEDIAWEB_OPTION_KEY,
            [$this, 'sanitize_settings']
        );

        add_settings_section(
            'postmediaweb_section_general',
            __('General', 'post-media-cleanup'),
            '__return_empty_string',
            'post-media-cleanup'
        );

        add_settings_field(
            'postmediaweb_enabled',
            __('Enable Deletion', 'post-media-cleanup'),
            [$this, 'field_enabled'],
            'post-media-cleanup',
            'postmediaweb_section_general'
        );

        add_settings_field(
            'postmediaweb_post_types',
            __('Post Types', 'post-media-cleanup'),
            [$this, 'field_post_types'],
            'post-media-cleanup',
            'postmediaweb_section_general'
        );

        add_settings_section(
            'postmediaweb_section_deletion',
            __('What to Delete', 'post-media-cleanup'),
            '__return_empty_string',
            'post-media-cleanup'
        );

        add_settings_field(
            'postmediaweb_delete_featured',
            __('Featured Image', 'post-media-cleanup'),
            [$this, 'field_delete_featured'],
            'post-media-cleanup',
            'postmediaweb_section_deletion'
        );

        add_settings_field(
            'postmediaweb_delete_content',
            __('Embeded Media', 'post-media-cleanup'),
            [$this, 'field_delete_content'],
            'post-media-cleanup',
            'postmediaweb_section_deletion'
        );

        add_settings_field(
            'postmediaweb_delete_gallery',
            __('Uploaded Attachment', 'post-media-cleanup'),
            [$this, 'field_delete_gallery'],
            'post-media-cleanup',
            'postmediaweb_section_deletion'
        );

        add_settings_field(
            'postmediaweb_skip_shared',
            __('Skip Shared Media', 'post-media-cleanup'),
            [$this, 'field_skip_shared'],
            'post-media-cleanup',
            'postmediaweb_section_deletion'
        );

        add_settings_field(
            'postmediaweb_delete_pagebuilder',
            __('Page Builder Media', 'post-media-cleanup'),
            [$this, 'field_delete_pagebuilder'],
            'post-media-cleanup',
            'postmediaweb_section_deletion'
        );

        add_settings_field(
            'postmediaweb_delete_acf',
            __('ACF Media', 'post-media-cleanup'),
            [$this, 'field_delete_acf'],
            'post-media-cleanup',
            'postmediaweb_section_deletion'
        );
    }

    public function field_delete_pagebuilder()
    {
        $val = Postmediaweb_Settings::get('delete_pagebuilder');
        echo '<input type="checkbox" name="' . esc_attr(POSTMEDIAWEB_OPTION_KEY) . '[delete_pagebuilder]" value="1" ' . checked($val, true, false) . '>';
        echo '<p class="description">' . esc_html__('Delete media used in Elementor, Divi, and WPBakery page builder content.', 'post-media-cleanup') . '</p>';
    }

    public function field_enabled()
    {
        $val = Postmediaweb_Settings::get('enabled');
        echo '<input type="checkbox" name="' . esc_attr(POSTMEDIAWEB_OPTION_KEY) . '[enabled]" value="1" ' . checked($val, true, false) . '>';
        echo '<p class="description">' . esc_html__('Uncheck to disable all deletion without deactivating the plugin.', 'post-media-cleanup') . '</p>';
    }

    public function field_post_types()
    {
        $current = (array) Postmediaweb_Settings::get('post_types');
        $types   = get_post_types(array('public' => true), 'objects');
        unset($types['attachment']);

        foreach ($types as $type) {
            $checked = in_array($type->name, $current, true);
            echo '<label style="display:block;margin-bottom:5px;">';
            echo '<input type="checkbox" name="' . esc_attr(POSTMEDIAWEB_OPTION_KEY) . '[post_types][]" value="' . esc_attr($type->name) . '" ' . checked($checked, true, false) . '> ';
            echo esc_html($type->label) . ' <code>(' . esc_html($type->name) . ')</code>';
            echo '</label>';
        }
    }

    public function field_delete_featured()
    {
        $val = Postmediaweb_Settings::get('delete_featured');
        echo '<input type="checkbox" name="' . esc_attr(POSTMEDIAWEB_OPTION_KEY) . '[delete_featured]" value="1" ' . checked($val, true, false) . '>';
    }

    public function field_delete_content()
    {
        $val = Postmediaweb_Settings::get('delete_content_media');
        echo '<input type="checkbox" name="' . esc_attr(POSTMEDIAWEB_OPTION_KEY) . '[delete_content_media]" value="1" ' . checked($val, true, false) . '>';
        echo '<p class="description">' . esc_html__('Images, PDFs, and files linked inside post content.', 'post-media-cleanup') . '</p>';
    }

    public function field_delete_gallery()
    {
        $val = Postmediaweb_Settings::get('delete_gallery');
        echo '<input type="checkbox" name="' . esc_attr(POSTMEDIAWEB_OPTION_KEY) . '[delete_gallery]" value="1" ' . checked($val, true, false) . '>';
        echo '<p class="description">' . esc_html__('All attachments uploaded directly to this post.', 'post-media-cleanup') . '</p>';
    }

    public function field_skip_shared()
    {
        $val = Postmediaweb_Settings::get('skip_shared');
        echo '<input type="checkbox" name="' . esc_attr(POSTMEDIAWEB_OPTION_KEY) . '[skip_shared]" value="1" ' . checked($val, true, false) . '>';
        echo '<p class="description" style="color:#b32d2e;">' . esc_html__('Recommended: skip media that is also used by other posts.', 'post-media-cleanup') . '</p>';
    }

    public function field_delete_acf()
    {
        $val = Postmediaweb_Settings::get('delete_acf');

        echo '<input type="checkbox" name="' . esc_attr(POSTMEDIAWEB_OPTION_KEY) . '[delete_acf]" value="1" ' . checked($val, true, false) . '>';
        echo '<p class="description">' . esc_html__('Delete media stored in ACF image, file, and gallery fields (Free and Pro).', 'post-media-cleanup') . '</p>';

        // Tell the user if ACF is not active right now.
        if (! function_exists('acf_get_field_objects')) {
            echo '<p class="description" style="color:#b32d2e;">' . esc_html__('ACF is not currently active. This setting will have no effect until ACF is installed and activated.', 'post-media-cleanup') . '</p>';
        }
    }



    public function sanitize_settings($input)
    {
        $clean = array();

        $clean['enabled']              = ! empty($input['enabled']);
        $clean['delete_featured']      = ! empty($input['delete_featured']);
        $clean['delete_content_media'] = ! empty($input['delete_content_media']);
        $clean['delete_gallery']       = ! empty($input['delete_gallery']);
        $clean['skip_shared']          = ! empty($input['skip_shared']);
        $clean['delete_pagebuilder']   = ! empty($input['delete_pagebuilder']);
        $clean['delete_acf']           = ! empty($input['delete_acf']);

        $valid_types        = array_keys(get_post_types(array('public' => true)));
        $submitted          = isset($input['post_types']) ? (array) $input['post_types'] : array();
        $clean['post_types'] = array_values(
            array_intersect(array_map('sanitize_key', $submitted), $valid_types)
        );

        if (empty($clean['post_types'])) {
            $clean['post_types'] = array('post');
        }

        return $clean;
    }

    public function render_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        // Read the active tab from URL — default to settings.
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
        $page_url   = admin_url('options-general.php?page=post-media-cleanup');
?>
        <div class="wrap pmc-wrap">
            <h1><?php esc_html_e('Post Media Cleanup', 'post-media-cleanup'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="<?php echo esc_url($page_url . '&tab=settings'); ?>"
                    class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Settings', 'post-media-cleanup'); ?>
                </a>
                <a href="<?php echo esc_url($page_url . '&tab=cleanup'); ?>"
                    class="nav-tab <?php echo 'cleanup' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Bulk Orphan Cleanup', 'post-media-cleanup'); ?>
                </a>
            </nav>

            <div class="pmc-tab-content">
                <?php
                if ('settings' === $active_tab) {
                    $this->render_settings_tab();
                } else {
                    $this->render_cleanup_tab();
                }
                ?>
            </div>
        </div>
    <?php
    }

    private function render_settings_tab()
    {
    ?>

        <form method="post" action="options.php">
            <?php
            settings_fields('postmediaweb_settings_group');
            do_settings_sections('post-media-cleanup');
            submit_button(__('Save Settings', 'post-media-cleanup'));
            ?>
        </form>
    <?php
    }

    private function render_cleanup_tab()
    {
    ?>
        <div class="pmc-cleanup-wrap">

            <p class="pmc-cleanup-intro">
                <?php esc_html_e('Scan your media library for orphaned files — attachments not used in any post, page, or custom post type. Review the results and delete them permanently.', 'post-media-cleanup'); ?>
            </p>

            <div class="pmc-scan-actions">
                <button id="pmc-scan-btn" class="button button-primary button-large">
                    <?php esc_html_e('Scan for Orphaned Media', 'post-media-cleanup'); ?>
                </button>
            </div>

            <div id="pmc-progress-wrap" class="pmc-progress-wrap" style="display:none;">
                <div class="pmc-progress-bar-track">
                    <div id="pmc-progress-bar" class="pmc-progress-bar-fill"></div>
                </div>
                <p id="pmc-progress-label" class="pmc-progress-label">
                    <?php esc_html_e('Scanning media library...', 'post-media-cleanup'); ?>
                </p>
            </div>

            <div id="pmc-results-wrap" class="pmc-results-wrap" style="display:none;">

                <div class="pmc-results-header">
                    <h3 id="pmc-results-title"></h3>
                </div>

                <div id="pmc-no-orphans" style="display:none;">
                    <p class="pmc-success-msg">
                        ✅ <?php esc_html_e('No orphaned media found. Your media library is clean.', 'post-media-cleanup'); ?>
                    </p>
                </div>

                <div id="pmc-orphans-found" style="display:none;">
                    <table class="wp-list-table widefat fixed striped pmc-orphans-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('File Name', 'post-media-cleanup'); ?></th>
                                <th><?php esc_html_e('File Type', 'post-media-cleanup'); ?></th>
                                <th><?php esc_html_e('File Size', 'post-media-cleanup'); ?></th>
                                <th><?php esc_html_e('Uploaded', 'post-media-cleanup'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="pmc-orphans-list"></tbody>
                    </table>

                    <div class="pmc-delete-actions">
                        <button id="pmc-delete-btn" class="button button-primary pmc-delete-btn">
                            <?php esc_html_e('Delete All Orphaned Files', 'post-media-cleanup'); ?>
                        </button>
                        <span class="pmc-delete-warning">
                            <?php esc_html_e('⚠️ This action is permanent and cannot be undone.', 'post-media-cleanup'); ?>
                        </span>
                    </div>
                </div>

            </div>

            <div id="pmc-deleted-wrap" class="pmc-deleted-wrap" style="display:none;">
                <p class="pmc-success-msg">
                    ✅ <span id="pmc-deleted-count"></span>
                </p>
            </div>

        </div>
<?php
    }

    public function enqueue_assets($hook)
    {
        if ('settings_page_post-media-cleanup' !== $hook) {
            return;
        }

        // wp_add_inline_script() only actually prints its payload if the
        // handle it's attached to is enqueued and output on the page.
        // A bare Settings API page does not load jQuery by default, so
        // without this explicit enqueue, none of the inline script below
        // ever ran — the Scan/Delete buttons had no JavaScript attached.
        wp_enqueue_script('jquery');

        // Pass data from PHP to JavaScript safely.
        // wp_localize_script() is the correct WordPress way to do this —
        // never hardcode URLs or nonces directly in JavaScript files.
        wp_add_inline_script(
            'jquery',
            'var PMC_Ajax = ' . wp_json_encode(array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('pmc_orphan_nonce'),
                'strings'  => array(
                    'scanning'       => __('Scanning media library...', 'post-media-cleanup'),
                    'deleting'       => __('Deleting orphaned files...', 'post-media-cleanup'),
                    'found'          => __('orphaned files found', 'post-media-cleanup'),
                    'deleted'        => __('files permanently deleted.', 'post-media-cleanup'),
                    'confirm_delete' => __('Are you sure? This will permanently delete all orphaned files. This cannot be undone.', 'post-media-cleanup'),
                    'error'          => __('Something went wrong. Please try again.', 'post-media-cleanup'),
                ),
            )) . ';'
        );

        // Inline the JavaScript — no separate file needed for this amount of code.
        wp_add_inline_script('jquery', $this->get_cleanup_js());

        // Inline the CSS.
        wp_add_inline_style('wp-admin', $this->get_admin_css());
    }

    private function get_cleanup_js()
    {
        return <<<'JS'
jQuery(function($) {

    var $scanBtn      = $('#pmc-scan-btn');
    var $progressWrap = $('#pmc-progress-wrap');
    var $progressBar  = $('#pmc-progress-bar');
    var $progressLbl  = $('#pmc-progress-label');
    var $resultsWrap  = $('#pmc-results-wrap');
    var $resultsTitle = $('#pmc-results-title');
    var $noOrphans    = $('#pmc-no-orphans');
    var $orphansFound = $('#pmc-orphans-found');
    var $orphansList  = $('#pmc-orphans-list');
    var $deleteBtn    = $('#pmc-delete-btn');
    var $deletedWrap  = $('#pmc-deleted-wrap');
    var $deletedCount = $('#pmc-deleted-count');

    var collectedIds  = [];
    var progressTimer = null;

    // ── Scan ─────────────────────────────────────────────────────────
    $scanBtn.on('click', function() {
        resetUI();
        startProgressBar();
        $scanBtn.prop('disabled', true);
        $progressWrap.show();

        $.ajax({
            url:    PMC_Ajax.ajax_url,
            method: 'POST',
            data: {
                action:   'pmc_scan_orphans',
                nonce:    PMC_Ajax.nonce,
            },
            success: function(response) {
                stopProgressBar(100);

                if ( ! response.success ) {
                    showError(response.data || PMC_Ajax.strings.error);
                    return;
                }

                var orphans = response.data.orphans;
                collectedIds = response.data.ids;

                showResults(orphans);
            },
            error: function() {
                stopProgressBar(0);
                showError(PMC_Ajax.strings.error);
            },
            complete: function() {
                $scanBtn.prop('disabled', false);
            }
        });
    });

    // ── Delete ───────────────────────────────────────────────────────
    $deleteBtn.on('click', function() {
        if ( ! confirm(PMC_Ajax.strings.confirm_delete) ) {
            return;
        }

        $deleteBtn.prop('disabled', true).text(PMC_Ajax.strings.deleting);

        $.ajax({
            url:    PMC_Ajax.ajax_url,
            method: 'POST',
            data: {
                action:   'pmc_delete_orphans',
                nonce:    PMC_Ajax.nonce,
                ids:      collectedIds,
            },
            success: function(response) {
                if ( ! response.success ) {
                    showError(response.data || PMC_Ajax.strings.error);
                    $deleteBtn.prop('disabled', false);
                    return;
                }

                $resultsWrap.hide();
                $deletedCount.text(response.data.count + ' ' + PMC_Ajax.strings.deleted);
                $deletedWrap.show();
                collectedIds = [];
            },
            error: function() {
                showError(PMC_Ajax.strings.error);
                $deleteBtn.prop('disabled', false);
            }
        });
    });

    // ── Progress bar ─────────────────────────────────────────────────
    // Animates from 0 to 90 on a timer while AJAX runs.
    // Jumps to 100 when response arrives.
    // This gives professional feel without lying about real progress.
    function startProgressBar() {
        var pct = 0;
        $progressBar.css('width', '0%');
        $progressLbl.text(PMC_Ajax.strings.scanning);

        progressTimer = setInterval(function() {
            // Easing: fast at start, slows down as it approaches 90.
            // This feels natural — quick response then anticipation.
            var increment = (90 - pct) * 0.04;
            pct = Math.min(pct + increment, 90);
            $progressBar.css('width', pct.toFixed(1) + '%');
        }, 80);
    }

    function stopProgressBar(finalPct) {
        clearInterval(progressTimer);
        $progressBar.css({
            'width':      finalPct + '%',
            'transition': 'width 0.4s ease'
        });

        setTimeout(function() {
            $progressWrap.hide();
            $progressBar.css('transition', '');
        }, 500);
    }

    // ── Display results ───────────────────────────────────────────────
    function showResults(orphans) {
        $resultsWrap.show();

        if ( ! orphans || orphans.length === 0 ) {
            $resultsTitle.text('');
            $noOrphans.show();
            $orphansFound.hide();
            return;
        }

        $resultsTitle.text(orphans.length + ' ' + PMC_Ajax.strings.found);
        $noOrphans.hide();
        $orphansFound.show();

        var rows = '';
        $.each(orphans, function(i, file) {
            rows += '<tr>' +
                '<td><code>' + escHtml(file.name) + '</code></td>' +
                '<td>' + escHtml(file.type) + '</td>' +
                '<td>' + escHtml(file.size) + '</td>' +
                '<td>' + escHtml(file.date) + '</td>' +
                '</tr>';
        });

        $orphansList.html(rows);
        $deleteBtn.prop('disabled', false).text(
            PMC_Ajax.strings.found.replace('orphaned', 'Delete all orphaned')
        );
    }

    function resetUI() {
        $resultsWrap.hide();
        $deletedWrap.hide();
        $noOrphans.hide();
        $orphansFound.hide();
        $orphansList.empty();
        collectedIds = [];
    }

    function showError(message) {
        $progressWrap.hide();
        $resultsWrap.show();
        $resultsTitle.text('Error: ' + message);
        $noOrphans.hide();
        $orphansFound.hide();
    }

    // Prevent XSS when rendering server data into the DOM.
    // Never use innerHTML with unescaped server data.
    function escHtml(str) {
        return $('<div>').text(str).html();
    }
});
JS;
    }

    private function get_admin_css()
    {
        return '
    .pmc-wrap { max-width: 900px; }
    .pmc-tab-content { background: #fff; border: 1px solid #c3c4c7; border-top: none; padding: 24px; }
    .pmc-cleanup-intro { font-size: 14px; color: #50575e; margin: 0 0 20px; }
    .pmc-scan-actions { margin-bottom: 24px; }
    .pmc-progress-wrap { margin: 20px 0; }
    .pmc-progress-bar-track { background: #e0e0e0; border-radius: 4px; height: 10px; overflow: hidden; }
    .pmc-progress-bar-fill { height: 100%; width: 0; background: #2271b1; border-radius: 4px; transition: width 0.08s linear; }
    .pmc-progress-label { font-size: 13px; color: #50575e; margin-top: 8px; }
    .pmc-results-header h3 { font-size: 16px; margin: 20px 0 12px; }
    .pmc-orphans-table { margin-bottom: 20px; }
    .pmc-orphans-table code { font-size: 12px; }
    .pmc-delete-actions { display: flex; align-items: center; gap: 16px; margin-top: 8px; }
    .pmc-delete-btn { background: #b32d2e !important; border-color: #8a2222 !important; color: #fff !important; }
    .pmc-delete-btn:hover { background: #8a2222 !important; }
    .pmc-delete-warning { font-size: 12px; color: #b32d2e; }
    .pmc-success-msg { font-size: 14px; color: #1d7e1d; background: #edfaed; border: 1px solid #8dbe8d; padding: 12px 16px; border-radius: 4px; }
    .pmc-deleted-wrap { margin-top: 20px; }
    ';
    }
}
