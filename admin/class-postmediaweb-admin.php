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

        add_settings_field(
            'postmediaweb_log_enabled',
            __( 'Deletion Log', 'post-media-cleanup' ),
            array( $this, 'field_log_enabled' ),
            'post-media-cleanup',
            'postmediaweb_section_general'
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


    public function field_log_enabled() {
        $val = Postmediaweb_Settings::get( 'log_enabled' );
        echo '<input type="checkbox" name="' . esc_attr( POSTMEDIAWEB_OPTION_KEY ) . '[log_enabled]" value="1" ' . checked( $val, true, false ) . '>';
        echo '<p class="description">' . esc_html__( 'Keep a record of every file deleted by this plugin.', 'post-media-cleanup' ) . '</p>';
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

        $clean['log_enabled'] = ! empty( $input['log_enabled'] );

        return $clean;
    }

    private function get_active_tab()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';

        return in_array($tab, array('settings', 'cleanup', 'log'), true) ? $tab : 'settings';
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
                <a href="<?php echo esc_url( $page_url . '&tab=log' ); ?>"
                    class="nav-tab <?php echo 'log' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Deletion Log', 'post-media-cleanup' ); ?>
                </a>
            </nav>

            <div class="pmc-tab-content">
                <?php
                if ( 'settings' === $active_tab ) {
                    $this->render_settings_tab();
                } elseif ( 'cleanup' === $active_tab ) {
                    $this->render_cleanup_tab();
                } else {
                    $this->render_log_tab();
                }
                ?>
            </div>
        </div>
    <?php
    }

    private function render_log_tab() {
        $clear_log_notice = false;
        if (
            isset( $_POST['pmc_action'], $_POST['pmc_clear_log_nonce'] )
            && is_string( $_POST['pmc_action'] )
            && is_string( $_POST['pmc_clear_log_nonce'] )
            && 'clear_log' === sanitize_key( wp_unslash( $_POST['pmc_action'] ) )
            && current_user_can( 'manage_options' )
            && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pmc_clear_log_nonce'] ) ), 'pmc_clear_log' )
        ) {
            Postmediaweb_Logger::clear();
            $clear_log_notice = true;
        }

        $stats        = Postmediaweb_Logger::get_stats();
        $per_page     = 20;
        $page         = isset( $_GET['logpage'] ) && is_scalar( $_GET['logpage'] ) ? max( 1, absint( $_GET['logpage'] ) ) : 1;
        $filter_type  = isset( $_GET['trigger'] ) && is_string( $_GET['trigger'] ) ? sanitize_key( wp_unslash( $_GET['trigger'] ) ) : '';
        $result       = Postmediaweb_Logger::get_entries( array(
            'per_page'     => $per_page,
            'page'         => $page,
            'trigger_type' => $filter_type,
        ) );
        $total_pages  = $result['total'] > 0 ? (int) ceil( $result['total'] / $per_page ) : 1;
        $base_url     = admin_url( 'options-general.php?page=post-media-cleanup&tab=log' );
        $logging_on   = (bool) Postmediaweb_Settings::get( 'log_enabled' );
        $filter_options = array(
            ''             => __( 'All activity', 'post-media-cleanup' ),
            'post_delete'  => __( 'Post deletion', 'post-media-cleanup' ),
            'bulk_cleanup' => __( 'Bulk cleanup', 'post-media-cleanup' ),
            'scheduled'    => __( 'Scheduled', 'post-media-cleanup' ),
            'wpcli'        => __( 'WP-CLI', 'post-media-cleanup' ),
        );
        ?>

        <div class="pmc-log-wrap">
            <div class="pmc-log-header">
                <div>
                    <h2><?php esc_html_e( 'Deletion activity', 'post-media-cleanup' ); ?></h2>
                    <p><?php esc_html_e( 'Review media files permanently removed by Post Media Cleanup.', 'post-media-cleanup' ); ?></p>
                </div>
                <span class="pmc-log-status <?php echo $logging_on ? 'pmc-log-status-on' : 'pmc-log-status-off'; ?>">
                    <span aria-hidden="true"></span>
                    <?php echo $logging_on ? esc_html__( 'Log enabled', 'post-media-cleanup' ) : esc_html__( 'Log disabled', 'post-media-cleanup' ); ?>
                </span>
            </div>

            <?php if ( $clear_log_notice ) : ?>
                <div class="notice notice-success inline"><p><?php esc_html_e( 'Deletion log cleared.', 'post-media-cleanup' ); ?></p></div>
            <?php endif; ?>

            <div class="pmc-log-stats">
                <div class="pmc-stat-box pmc-stat-box-primary">
                    <span class="pmc-stat-label"><?php esc_html_e( 'Files deleted', 'post-media-cleanup' ); ?></span>
                    <span class="pmc-stat-number"><?php echo esc_html( number_format_i18n( $stats['total_deleted'] ) ); ?></span>
                </div>
                <div class="pmc-stat-box">
                    <span class="pmc-stat-label"><?php esc_html_e( 'Space freed', 'post-media-cleanup' ); ?></span>
                    <span class="pmc-stat-number"><?php echo esc_html( size_format( $stats['total_size'] ) ); ?></span>
                </div>
                <div class="pmc-stat-box">
                    <span class="pmc-stat-label"><?php esc_html_e( 'Post-deletion files', 'post-media-cleanup' ); ?></span>
                    <span class="pmc-stat-number"><?php echo esc_html( number_format_i18n( $stats['post_delete'] ) ); ?></span>
                </div>
                <div class="pmc-stat-box">
                    <span class="pmc-stat-label"><?php esc_html_e( 'Bulk-cleanup files', 'post-media-cleanup' ); ?></span>
                    <span class="pmc-stat-number"><?php echo esc_html( number_format_i18n( $stats['bulk_cleanup'] ) ); ?></span>
                </div>
            </div>

            <div class="pmc-log-toolbar">
                <nav class="pmc-log-filters" aria-label="<?php esc_attr_e( 'Filter deletion activity', 'post-media-cleanup' ); ?>">
                    <?php foreach ( $filter_options as $value => $label ) : ?>
                        <?php
                        $url = $value ? add_query_arg( 'trigger', $value, $base_url ) : $base_url;
                        $active = $filter_type === $value;
                        ?>
                        <a href="<?php echo esc_url( $url ); ?>" class="pmc-filter-btn<?php echo $active ? ' pmc-filter-active' : ''; ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
                            <?php echo esc_html( $label ); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <?php if ( $stats['total_deleted'] > 0 ) : ?>
                    <form method="post" action="">
                        <?php wp_nonce_field( 'pmc_clear_log', 'pmc_clear_log_nonce' ); ?>
                        <input type="hidden" name="pmc_action" value="clear_log">
                        <button type="submit" class="button pmc-clear-log-btn"
                            onclick="return confirm('<?php esc_attr_e( 'Are you sure? This will permanently clear the entire deletion log.', 'post-media-cleanup' ); ?>')">
                            <?php esc_html_e( 'Clear log', 'post-media-cleanup' ); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if ( empty( $result['items'] ) ) : ?>
                <div class="pmc-log-empty-state">
                    <span class="dashicons dashicons-archive" aria-hidden="true"></span>
                    <?php if ( $filter_type || $result['total'] > 0 ) : ?>
                        <h3><?php esc_html_e( 'No matching activity', 'post-media-cleanup' ); ?></h3>
                        <p><?php esc_html_e( 'Try a different activity filter to find the records you are looking for.', 'post-media-cleanup' ); ?></p>
                    <?php else : ?>
                        <h3><?php esc_html_e( 'No file deletions recorded yet', 'post-media-cleanup' ); ?></h3>
                        <?php if ( $logging_on ) : ?>
                            <p><?php esc_html_e( 'This log records media files removed by this plugin from now on. It cannot show earlier deletions, and deleting a post will not create a record if no media files were removed by the plugin.', 'post-media-cleanup' ); ?></p>
                        <?php else : ?>
                            <p><?php esc_html_e( 'Turn on Deletion Log in the Settings tab to record future media file deletions.', 'post-media-cleanup' ); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <p class="pmc-log-result-count">
                    <?php
                    $first_result = ( ( $page - 1 ) * $per_page ) + 1;
                    $last_result  = min( $page * $per_page, $result['total'] );
                    echo esc_html( sprintf(
                        __( 'Showing %1$s–%2$s of %3$s files', 'post-media-cleanup' ),
                        number_format_i18n( $first_result ),
                        number_format_i18n( $last_result ),
                        number_format_i18n( $result['total'] )
                    ) );
                    ?>
                </p>
                <div class="pmc-log-table-wrap">
                    <table class="wp-list-table widefat fixed striped pmc-log-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'File', 'post-media-cleanup' ); ?></th>
                                <th><?php esc_html_e( 'Size', 'post-media-cleanup' ); ?></th>
                                <th><?php esc_html_e( 'Related post', 'post-media-cleanup' ); ?></th>
                                <th><?php esc_html_e( 'Post type', 'post-media-cleanup' ); ?></th>
                                <th><?php esc_html_e( 'Source', 'post-media-cleanup' ); ?></th>
                                <th><?php esc_html_e( 'Deleted', 'post-media-cleanup' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $result['items'] as $entry ) : ?>
                                <?php
                                $trigger_label = isset( $filter_options[ $entry->trigger_type ] )
                                    ? $filter_options[ $entry->trigger_type ]
                                    : ucwords( str_replace( '_', ' ', $entry->trigger_type ) );
                                ?>
                                <tr>
                                    <td><code class="pmc-log-file-name"><?php echo esc_html( $entry->file_name ? $entry->file_name : __( 'File details unavailable', 'post-media-cleanup' ) ); ?></code></td>
                                    <td><?php echo esc_html( $entry->file_size > 0 ? size_format( $entry->file_size ) : '—' ); ?></td>
                                    <td>
                                        <?php if ( $entry->post_id > 0 ) : ?>
                                            <span class="pmc-log-post-title" title="<?php echo esc_attr( sprintf( __( 'Post ID: %d', 'post-media-cleanup' ), $entry->post_id ) ); ?>">
                                                <?php echo esc_html( $entry->post_title ? $entry->post_title : __( '(Deleted post)', 'post-media-cleanup' ) ); ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="pmc-log-orphan"><?php esc_html_e( 'Orphan media', 'post-media-cleanup' ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="pmc-log-type"><?php echo esc_html( $entry->post_type ? $entry->post_type : '—' ); ?></span></td>
                                    <td>
                                        <span class="pmc-trigger-badge pmc-trigger-<?php echo esc_attr( sanitize_html_class( $entry->trigger_type ) ); ?>">
                                            <?php echo esc_html( $trigger_label ); ?>
                                        </span>
                                    </td>
                                    <td class="pmc-log-date"><?php echo esc_html( mysql2date( 'M j, Y · H:i', $entry->deleted_at ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ( $total_pages > 1 ) : ?>
                    <div class="pmc-log-pagination">
                        <?php if ( $page > 1 ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( array_filter( array( 'logpage' => $page - 1, 'trigger' => $filter_type ) ), $base_url ) ); ?>" class="button"><?php esc_html_e( '← Previous', 'post-media-cleanup' ); ?></a>
                        <?php endif; ?>
                        <span class="pmc-log-page-info">
                            <?php echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'post-media-cleanup' ), $page, $total_pages ) ); ?>
                        </span>
                        <?php if ( $page < $total_pages ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( array_filter( array( 'logpage' => $page + 1, 'trigger' => $filter_type ) ), $base_url ) ); ?>" class="button"><?php esc_html_e( 'Next →', 'post-media-cleanup' ); ?></a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
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
        .pmc-wrap { max-width: 1120px; }
        .pmc-wrap .nav-tab-wrapper { border-bottom: 0; padding-left: 2px; }
        .pmc-wrap .nav-tab { margin-right: 6px; padding: 8px 14px; border-color: #dcdcde; border-radius: 7px 7px 0 0; background: #f6f7f7; color: #50575e; }
        .pmc-wrap .nav-tab-active, .pmc-wrap .nav-tab-active:hover { border-color: #dcdcde; border-bottom-color: #fff; background: #fff; color: #1d2327; }
        .pmc-tab-content { background: #fff; border: 1px solid #dcdcde; border-top: none; padding: 28px; border-radius: 0 0 10px 10px; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
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
        .pmc-log-wrap { color: #1d2327; }
        .pmc-log-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; margin: 0 0 24px; }
        .pmc-log-header h2 { margin: 0 0 6px; font-size: 22px; line-height: 1.3; color: #1d2327; }
        .pmc-log-header p { margin: 0; color: #646970; font-size: 13px; }
        .pmc-log-status { display: inline-flex; align-items: center; gap: 8px; white-space: nowrap; padding: 7px 11px; border: 1px solid #dcdcde; border-radius: 20px; background: #fff; color: #50575e; font-size: 12px; font-weight: 600; }
        .pmc-log-status > span { width: 8px; height: 8px; border-radius: 50%; background: #8c8f94; }
        .pmc-log-status-on > span { background: #00a32a; box-shadow: 0 0 0 3px #edfaef; }
        .pmc-log-status-off > span { background: #d63638; box-shadow: 0 0 0 3px #fcf0f1; }
        .pmc-log-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 26px; }
        .pmc-stat-box { display: flex; flex-direction: column-reverse; justify-content: center; gap: 7px; min-height: 88px; padding: 16px 18px; border: 1px solid #e2e4e7; border-radius: 9px; background: linear-gradient(145deg, #fff 0%, #f8f9fa 100%); box-shadow: 0 2px 5px rgba(0,0,0,.025); }
        .pmc-stat-box-primary { border-color: #c6dff3; background: linear-gradient(145deg, #fff 0%, #f2f8fc 100%); }
        .pmc-stat-number { display: block; font-size: 27px; font-weight: 700; letter-spacing: -.4px; color: #1d2327; line-height: 1.1; }
        .pmc-stat-label { font-size: 12px; color: #646970; font-weight: 500; }
        .pmc-log-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap; padding-bottom: 16px; border-bottom: 1px solid #f0f0f1; }
        .pmc-log-filters { display: flex; gap: 6px; flex-wrap: wrap; }
        .pmc-filter-btn { display: inline-flex; align-items: center; min-height: 32px; padding: 0 12px; border: 1px solid #dcdcde; border-radius: 6px; font-size: 12px; color: #50575e; text-decoration: none; background: #fff; transition: border-color .15s ease, color .15s ease, background .15s ease; }
        .pmc-filter-btn:hover, .pmc-filter-btn:focus { border-color: #2271b1; color: #135e96; outline: none; box-shadow: 0 0 0 1px #2271b1; }
        .pmc-filter-active, .pmc-filter-active:hover, .pmc-filter-active:focus { background: #2271b1; border-color: #2271b1; color: #fff; box-shadow: none; }
        .pmc-clear-log-btn { color: #b32d2e !important; border-color: #d63638 !important; border-radius: 6px !important; }
        .pmc-clear-log-btn:hover { background: #fcf0f1 !important; }
        .pmc-log-result-count { margin: 16px 0 10px; color: #646970; font-size: 12px; }
        .pmc-log-table-wrap { overflow-x: auto; border: 1px solid #e2e4e7; border-radius: 8px; }
        .pmc-log-table { margin: 0; border: 0; border-spacing: 0; table-layout: auto; min-width: 820px; }
        .pmc-log-table thead th { padding: 12px 14px; background: #f6f7f7; color: #50575e; font-size: 11px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
        .pmc-log-table td { padding: 13px 14px; color: #3c434a; vertical-align: middle; }
        .pmc-log-table tbody tr:last-child td { border-bottom: 0; }
        .pmc-log-table code { padding: 3px 6px; border: 1px solid #e2e4e7; border-radius: 4px; background: #f6f7f7; font-size: 11px; white-space: nowrap; }
        .pmc-log-file-name { color: #1d2327; }
        .pmc-log-post-title { font-weight: 500; }
        .pmc-log-type { color: #646970; font-size: 12px; }
        .pmc-log-date { white-space: nowrap; color: #646970 !important; font-size: 12px; }
        .pmc-log-orphan { color: #646970; font-style: italic; }
        .pmc-log-empty-state { padding: 48px 24px; text-align: center; }
        .pmc-log-empty-state > .dashicons { display: inline-block; width: 42px; height: 42px; margin-bottom: 10px; color: #8c8f94; font-size: 42px; }
        .pmc-log-empty-state h3 { margin: 0 0 8px; color: #1d2327; font-size: 16px; }
        .pmc-log-empty-state p { max-width: 580px; margin: 0 auto; color: #646970; font-size: 13px; line-height: 1.6; }
        .pmc-trigger-badge { display: inline-flex; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; white-space: nowrap; }
        .pmc-trigger-post_delete { background: #e8f3fb; color: #135e96; }
        .pmc-trigger-bulk_cleanup { background: #fcf0d8; color: #8a5d00; }
        .pmc-trigger-scheduled { background: #edfaef; color: #116329; }
        .pmc-trigger-wpcli { background: #f3eafa; color: #684399; }
        .pmc-log-pagination { display: flex; align-items: center; justify-content: center; gap: 12px; margin-top: 20px; }
        .pmc-log-pagination .button { border-radius: 5px; }
        .pmc-log-page-info { font-size: 12px; color: #646970; }
        @media screen and (max-width: 782px) {
            .pmc-tab-content { padding: 18px; }
            .pmc-log-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .pmc-stat-box { min-height: 76px; padding: 13px; }
            .pmc-stat-number { font-size: 23px; }
        }
        @media screen and (max-width: 480px) {
            .pmc-log-header { flex-direction: column; }
            .pmc-log-stats { grid-template-columns: 1fr 1fr; }
            .pmc-log-toolbar { align-items: flex-start; }
        }
        ';
    }
}
