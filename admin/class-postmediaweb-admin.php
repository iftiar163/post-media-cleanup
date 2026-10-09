<?php

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
            __('Embedded Media', 'post-media-cleanup'),
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

        // Only register the WooCommerce section if WooCommerce is active.
        // No point showing WooCommerce options to users who don't have it.
        if ( function_exists( 'wc_get_product' ) ) {
            add_settings_section(
                'pmc_section_woocommerce',
                __( 'WooCommerce', 'post-media-cleanup' ),
                array( $this, 'render_section_woocommerce' ),
                'post-media-cleanup'
            );

            add_settings_field(
                'pmc_delete_woocommerce',
                __( 'Product Media', 'post-media-cleanup' ),
                array( $this, 'field_delete_woocommerce' ),
                'post-media-cleanup',
                'pmc_section_woocommerce'
            );
        }
    }

    public function render_section_woocommerce() {
        echo '<p>' . esc_html__(
            'Control how media is handled when WooCommerce products are permanently deleted.',
            'post-media-cleanup'
        ) . '</p>';
    }

    public function field_delete_woocommerce() {
        $val = Postmediaweb_Settings::get( 'delete_woocommerce' );

        echo '<input type="checkbox"
            name="' . esc_attr( POSTMEDIAWEB_OPTION_KEY ) . '[delete_woocommerce]"
            value="1" ' . checked( $val, true, false ) . '>';

        echo '<p class="description">' . esc_html__(
            'When enabled, permanently deleting a product also deletes its product gallery images, short description media, and all variation images.',
            'post-media-cleanup'
        ) . '</p>';

        // Show exactly what will be deleted so the user has no surprises.
        echo '<ul class="pmc-woo-scope">
            <li>✅ ' . esc_html__( 'Featured image', 'post-media-cleanup' ) . '</li>
            <li>✅ ' . esc_html__( 'Product gallery images (_product_image_gallery)', 'post-media-cleanup' ) . '</li>
            <li>✅ ' . esc_html__( 'Images in product description', 'post-media-cleanup' ) . '</li>
            <li>✅ ' . esc_html__( 'Images in short description', 'post-media-cleanup' ) . '</li>
            <li>✅ ' . esc_html__( 'All product variation images', 'post-media-cleanup' ) . '</li>
        </ul>';
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

    public function field_post_types() {
        $current = (array) Postmediaweb_Settings::get( 'post_types' );
        $types   = get_post_types( array( 'public' => true ), 'objects' );

        unset( $types['attachment'] );

        $builtin = array();
        $custom  = array();

        foreach ( $types as $type ) {
            if ( $type->_builtin ) {
                $builtin[ $type->name ] = $type;
            } else {
                $custom[ $type->name ] = $type;
            }
        }

        echo '<div class="pmc-post-types-wrap">';

        if ( ! empty( $builtin ) ) {
            echo '<p class="pmc-post-type-group-label">';
            esc_html_e( 'WordPress Built-in', 'post-media-cleanup' );
            echo '</p>';

            foreach ( $builtin as $type ) {
                $this->render_post_type_checkbox( $type, $current );
            }
        }

        if ( ! empty( $custom ) ) {
            echo '<p class="pmc-post-type-group-label pmc-post-type-group-label--custom">';
            esc_html_e( 'Custom Post Types', 'post-media-cleanup' );
            echo '</p>';

            foreach ( $custom as $type ) {
                $this->render_post_type_checkbox( $type, $current );
            }
        }

        if ( empty( $custom ) ) {
            echo '<p class="pmc-post-type-none">';
            esc_html_e( 'No custom post types detected on this site.', 'post-media-cleanup' );
            echo '</p>';
        }

        echo '</div>';

        echo '<p class="description">';
        esc_html_e( 'Media will only be deleted when posts of the checked types are permanently deleted.', 'post-media-cleanup' );
        echo '</p>';

        $nothing_checked = empty( $current );
        echo '<p class="pmc-post-type-warning" style="' . ( $nothing_checked ? '' : 'display:none;' ) . '">';
        esc_html_e( '⚠️ No post types selected. The plugin will not delete any media.', 'post-media-cleanup' );
        echo '</p>';
    }

    private function render_post_type_checkbox( $type, $current ) {
        $checked     = in_array( $type->name, $current, true );
        $description = $this->get_post_type_description( $type->name );

        echo '<label class="pmc-post-type-label">';

        echo '<input
            type="checkbox"
            class="pmc-post-type-checkbox"
            name="' . esc_attr( POSTMEDIAWEB_OPTION_KEY ) . '[post_types][]"
            value="' . esc_attr( $type->name ) . '"
            ' . checked( $checked, true, false ) . '
        >';

        echo '<span class="pmc-post-type-name">' . esc_html( $type->label ) . '</span>';

        echo '<span class="pmc-post-type-slug">' . esc_html( $type->name ) . '</span>';

        if ( $description ) {
            echo '<span class="pmc-post-type-desc">' . esc_html( $description ) . '</span>';
        }

        echo '</label>';
    }

    private function get_post_type_description( $post_type ) {
        $descriptions = array(
            'post' => __( 'Standard blog posts', 'post-media-cleanup' ),
            'page' => __( 'Static pages like About, Contact', 'post-media-cleanup' ),
        );

        $third_party = array(
            'product'          => __( 'WooCommerce products', 'post-media-cleanup' ),
            'shop_order'       => __( 'WooCommerce orders', 'post-media-cleanup' ),
            'tribe_events'     => __( 'The Events Calendar events', 'post-media-cleanup' ),
            'portfolio'        => __( 'Portfolio items', 'post-media-cleanup' ),
            'jetpack-portfolio' => __( 'Jetpack portfolio items', 'post-media-cleanup' ),
            'download'         => __( 'Easy Digital Downloads products', 'post-media-cleanup' ),
        );

        $all = array_merge( $descriptions, $third_party );

        return isset( $all[ $post_type ] ) ? $all[ $post_type ] : '';
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

        if (! function_exists('acf_get_field_objects')) {
            echo '<p class="description" style="color:#b32d2e;">' . esc_html__('ACF is not currently active. This setting will have no effect until ACF is installed and activated.', 'post-media-cleanup') . '</p>';
        }
    }



    public function sanitize_settings($input)
    {
        if (! is_array($input)) {
            $input = array();
        }

        $previous = get_option(POSTMEDIAWEB_OPTION_KEY, array());
        if (! is_array($previous)) {
            $previous = array();
        }

        $clean = array();

        foreach (array('enabled', 'delete_featured', 'delete_content_media', 'delete_gallery', 'skip_shared', 'delete_pagebuilder', 'delete_acf') as $key) {
            $clean[$key] = ! empty($input[$key]);
        }

        // The WooCommerce checkbox is only rendered while WooCommerce is active. When it is not,
        // keep the stored value instead of silently resetting it to "off" on every save.
        if (function_exists('wc_get_product')) {
            $clean['delete_woocommerce'] = ! empty($input['delete_woocommerce']);
        } else {
            $clean['delete_woocommerce'] = isset($previous['delete_woocommerce']) ? (bool) $previous['delete_woocommerce'] : true;
        }

        $valid_types = array_diff(
            array_keys(get_post_types(array('public' => true))),
            array('attachment')
        );
        $submitted = isset($input['post_types']) ? array_filter((array) $input['post_types'], 'is_scalar') : array();

        // Selecting nothing must really mean "delete nothing". The settings screen promises this;
        // the previous code silently re-enabled "post", so media of ordinary posts was still deleted.
        $clean['post_types'] = array_values(
            array_intersect(array_map('sanitize_key', $submitted), $valid_types)
        );

        if (empty($clean['post_types'])) {
            add_settings_error(
                'postmediaweb_settings_group',
                'pmc_no_post_types',
                __('No post types are selected, so Post Media Cleanup will not delete any media.', 'post-media-cleanup'),
                'warning'
            );
        }

        return $clean;
    }

    private function get_active_tab()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';

        return in_array($tab, array('settings', 'cleanup'), true) ? $tab : 'settings';
    }

    public function render_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        $active_tab = $this->get_active_tab();
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

        // Dedicated handles. Inline-only assets need a registered handle, and the
        // previous code hung its CSS on "wp-admin", which is not guaranteed to be enqueued.
        wp_register_style('pmc-admin', false, array(), POSTMEDIAWEB_VERSION);
        wp_enqueue_style('pmc-admin');
        wp_add_inline_style('pmc-admin', $this->get_admin_css());

        wp_register_script('pmc-admin', false, array('jquery'), POSTMEDIAWEB_VERSION, true);
        wp_enqueue_script('pmc-admin');

        if ('cleanup' === $this->get_active_tab()) {
            wp_add_inline_script(
                'pmc-admin',
                'var PMC_Ajax = ' . wp_json_encode(array(
                    'ajax_url'   => admin_url('admin-ajax.php'),
                    'nonce'      => wp_create_nonce('pmc_orphan_nonce'),
                    'batch_size' => 25,
                    'strings'    => array(
                        'scanning'       => __('Scanning media library...', 'post-media-cleanup'),
                        'deleting'       => __('Deleting orphaned files...', 'post-media-cleanup'),
                        'found'          => __('orphaned files found', 'post-media-cleanup'),
                        'delete_all'     => __('Delete All Orphaned Files', 'post-media-cleanup'),
                        'deleted'        => __('files permanently deleted.', 'post-media-cleanup'),
                        'skipped'        => __('skipped — now referenced elsewhere or no longer orphaned', 'post-media-cleanup'),
                        'confirm_delete' => __('Are you sure? This will permanently delete all orphaned files. This cannot be undone.', 'post-media-cleanup'),
                        'error'          => __('Something went wrong. Please try again.', 'post-media-cleanup'),
                        'error_prefix'   => __('Error:', 'post-media-cleanup'),
                    ),
                )) . ';',
                'before'
            );

            wp_add_inline_script('pmc-admin', $this->get_cleanup_js());
        } else {
            wp_add_inline_script('pmc-admin', $this->get_settings_js());
        }
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

    $scanBtn.on('click', function() {
        resetUI();
        startProgressBar();
        $scanBtn.prop('disabled', true);
        $progressWrap.show();

        $.ajax({
            url:    PMC_Ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'pmc_scan_orphans',
                nonce:  PMC_Ajax.nonce
            },
            success: function(response) {
                stopProgressBar(100);

                if ( ! response.success ) {
                    showError(response.data || PMC_Ajax.strings.error);
                    return;
                }

                collectedIds = response.data.ids || [];
                showResults(response.data.orphans);
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

    // Deletion runs in small sequential batches. One giant request would be cut off by PHP's
    // max_input_vars (default 1000) and by execution-time limits on large libraries.
    $deleteBtn.on('click', function() {
        if ( ! confirm(PMC_Ajax.strings.confirm_delete) ) {
            return;
        }

        var queue   = collectedIds.slice();
        var total   = queue.length;
        var deleted = 0;
        var skipped = 0;

        $deleteBtn.prop('disabled', true).text(PMC_Ajax.strings.deleting);
        $progressBar.css('width', '0%');
        $progressLbl.text(PMC_Ajax.strings.deleting);
        $progressWrap.show();

        function finish() {
            $progressWrap.hide();
            $resultsWrap.hide();

            var message = deleted + ' ' + PMC_Ajax.strings.deleted;

            if ( skipped > 0 ) {
                message += ' (' + skipped + ' ' + PMC_Ajax.strings.skipped + ')';
            }

            $deletedCount.text(message);
            $deletedWrap.show();
            collectedIds = [];
        }

        function fail(message, batch) {
            // Keep what has not been processed so the user can retry.
            collectedIds = queue.concat(batch || []);
            $deleteBtn.prop('disabled', false).text(PMC_Ajax.strings.delete_all);
            showError(message || PMC_Ajax.strings.error);
        }

        function next() {
            if ( ! queue.length ) {
                finish();
                return;
            }

            var batch = queue.splice(0, PMC_Ajax.batch_size);

            $.ajax({
                url:    PMC_Ajax.ajax_url,
                method: 'POST',
                data: {
                    action: 'pmc_delete_orphans',
                    nonce:  PMC_Ajax.nonce,
                    ids:    batch
                },
                success: function(response) {
                    if ( ! response.success ) {
                        fail(response.data, batch);
                        return;
                    }

                    deleted += parseInt(response.data.count, 10) || 0;
                    skipped += ( response.data.skipped && response.data.skipped.length ) ? response.data.skipped.length : 0;

                    var done = total - queue.length;
                    $progressBar.css('width', Math.min(100, (done / total) * 100).toFixed(1) + '%');

                    next();
                },
                error: function() {
                    fail(PMC_Ajax.strings.error, batch);
                }
            });
        }

        next();
    });

    // Progress is simulated during the scan because that single request has no incremental updates.
    function startProgressBar() {
        var pct = 0;
        $progressBar.css('width', '0%');
        $progressLbl.text(PMC_Ajax.strings.scanning);

        clearInterval(progressTimer);
        progressTimer = setInterval(function() {
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
        $deleteBtn.prop('disabled', false).text(PMC_Ajax.strings.delete_all);
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
        $resultsTitle.text(PMC_Ajax.strings.error_prefix + ' ' + message);
        $noOrphans.hide();
        // Keep the table visible after a failed delete so the user can retry.
        if ( ! collectedIds.length ) {
            $orphansFound.hide();
        }
    }

    // Prevent XSS when rendering server data into the DOM.
    // Never use innerHTML with unescaped server data.
    function escHtml(str) {
        return $('<div>').text(str).html();
    }
});
JS;
    }

    private function get_settings_js()
    {
        return <<<'JS'
jQuery(function($) {

    function updatePostTypeWarning() {
        var anyChecked = $('.pmc-post-type-checkbox:checked').length > 0;
        $('.pmc-post-type-warning').toggle( ! anyChecked );
    }

    $(document).on('change', '.pmc-post-type-checkbox', updatePostTypeWarning);

    updatePostTypeWarning();
});
JS;
    }

    private function get_admin_css() {
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

        .pmc-post-types-wrap {
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            padding: 4px 0;
            max-width: 480px;
            margin-bottom: 8px;
        }
        .pmc-post-type-group-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #8c8f94;
            margin: 12px 14px 6px;
            padding-bottom: 4px;
            border-bottom: 1px solid #f0f0f1;
        }
        .pmc-post-type-group-label--custom {
            margin-top: 4px;
        }
        .pmc-post-type-label {
            display: flex;
            align-items: baseline;
            gap: 8px;
            padding: 7px 14px;
            cursor: pointer;
            transition: background 0.1s;
            border-radius: 3px;
        }
        .pmc-post-type-label:hover {
            background: #f6f7f7;
        }
        .pmc-post-type-label input[type="checkbox"] {
            margin: 0;
            flex-shrink: 0;
        }
        .pmc-post-type-name {
            font-size: 13px;
            color: #1d2327;
            font-weight: 500;
            flex-shrink: 0;
        }
        .pmc-post-type-slug {
            font-size: 11px;
            color: #8c8f94;
            background: #f0f0f1;
            padding: 1px 5px;
            border-radius: 3px;
            font-family: monospace;
            flex-shrink: 0;
        }
        .pmc-post-type-desc {
            font-size: 12px;
            color: #8c8f94;
            font-style: italic;
        }
        .pmc-post-type-none {
            font-size: 13px;
            color: #8c8f94;
            padding: 6px 14px;
            font-style: italic;
        }
        .pmc-post-type-warning {
            color: #b32d2e;
            font-size: 12px;
            margin-top: 6px;
        }
        .pmc-woo-scope { margin: 8px 0 0 4px; padding: 0; list-style: none; }
        .pmc-woo-scope li { font-size: 12px; color: #50575e; margin-bottom: 3px; }
        ';
    }
}
