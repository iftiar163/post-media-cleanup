<?php
/**
 * Admin UI — Settings page.
 *
 * @package PostMediaCleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PMC_Admin{
    private static $instance = null;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_filter('plugin_action_links_post-media-cleanup/post-media-cleanup.php', [$this, 'add_settings_link']);
    }

    public function add_menu() {
        add_options_page(
            __('Post Media Cleanup Settings', 'post-media-cleanup'),
            __('Post Media Cleanup', 'post-media-cleanup'),
            'manage_options',
            'post-media-cleanup',
            [$this, 'render_page']
        );
    }

    public function add_settings_link( $links ) {
        $url  = admin_url( 'options-general.php?page=post-media-cleanup' );
        $link = '<a href="' . esc_url( $url ) . '">' . __( 'Settings', 'post-media-cleanup' ) . '</a>';
        array_unshift( $links, $link );
        return $links;
    }
    
    public function register_settings() {
        register_setting(
            'pmc_settings_group',
            PMC_OPTION_KEY,
            [$this, 'sanitize_settings']
        );

        add_settings_section(
            'pmc_section_general',
            __('General', 'post-media-cleanup'),
            '__return_empty_string',
            'post-media-cleanup'
        );

        add_settings_field(
            'pmc_enabled',
            __('Enable Deletion', 'post-media-cleanup'),
            [$this, 'field_enabled'],
            'post-media-cleanup',
            'pmc_section_general'
        );

        add_settings_field(
            'pmc_post_types',
            __('Post Types', 'post-media-cleanup'),
            [$this, 'field_post_types'],
            'post-media-cleanup',
            'pmc_section_general'
        );

        add_settings_section(
            'pmc_section_deletion',
            __('What to Delete', 'post-media-cleanup'),
            '__return_empty_string',
            'post-media-cleanup'
        );

        add_settings_field(
            'pmc_delete_featured',
            __('Featured Image', 'post-media-cleanup'),
            [$this, 'field_delete_featured'],
            'post-media-cleanup',
            'pmc_section_deletion'
        );

        add_settings_field(
            'pmc_delete_content',
            __('Embeded Media', 'post-media-cleanup'),
            [$this, 'field_delete_content'],
            'post-media-cleanup',
            'pmc_section_deletion'
        );

        add_settings_field(
            'pmc_delete_gallery',
            __('Uploaded Attachment', 'post-media-cleanup'),
            [$this, 'field_delete_gallery'],
            'post-media-cleanup',
            'pmc_section_deletion'
        );

        add_settings_field(
            'pmc_skip_shared',
            __('Skip Shared Media', 'post-media-cleanup'),
            [$this, 'field_skip_shared'],
            'post-media-cleanup',
            'pmc_section_deletion'
        );

    }

    public function field_enabled() {
        $val = PMC_Settings::get( 'enabled' );
        echo '<input type="checkbox" name="' . esc_attr( PMC_OPTION_KEY ) . '[enabled]" value="1" ' . checked( $val, true, false ) . '>';
        echo '<p class="description">' . esc_html__( 'Uncheck to disable all deletion without deactivating the plugin.', 'post-media-cleanup' ) . '</p>';
    }

    public function field_post_types() {
        $current = (array) PMC_Settings::get( 'post_types' );
        $types   = get_post_types( array( 'public' => true ), 'objects' );
        unset( $types['attachment'] );

        foreach ( $types as $type ) {
            $checked = in_array( $type->name, $current, true );
            echo '<label style="display:block;margin-bottom:5px;">';
            echo '<input type="checkbox" name="' . esc_attr( PMC_OPTION_KEY ) . '[post_types][]" value="' . esc_attr( $type->name ) . '" ' . checked( $checked, true, false ) . '> ';
            echo esc_html( $type->label ) . ' <code>(' . esc_html( $type->name ) . ')</code>';
            echo '</label>';
        }
    }

    public function field_delete_featured() {
        $val = PMC_Settings::get( 'delete_featured' );
        echo '<input type="checkbox" name="' . esc_attr( PMC_OPTION_KEY ) . '[delete_featured]" value="1" ' . checked( $val, true, false ) . '>';
    }

    public function field_delete_content() {
        $val = PMC_Settings::get( 'delete_content_media' );
        echo '<input type="checkbox" name="' . esc_attr( PMC_OPTION_KEY ) . '[delete_content_media]" value="1" ' . checked( $val, true, false ) . '>';
        echo '<p class="description">' . esc_html__( 'Images, PDFs, and files linked inside post content.', 'post-media-cleanup' ) . '</p>';
    }

    public function field_delete_gallery() {
        $val = PMC_Settings::get( 'delete_gallery' );
        echo '<input type="checkbox" name="' . esc_attr( PMC_OPTION_KEY ) . '[delete_gallery]" value="1" ' . checked( $val, true, false ) . '>';
        echo '<p class="description">' . esc_html__( 'All attachments uploaded directly to this post.', 'post-media-cleanup' ) . '</p>';
    }

    public function field_skip_shared() {
        $val = PMC_Settings::get( 'skip_shared' );
        echo '<input type="checkbox" name="' . esc_attr( PMC_OPTION_KEY ) . '[skip_shared]" value="1" ' . checked( $val, true, false ) . '>';
        echo '<p class="description" style="color:#b32d2e;">' . esc_html__( 'Recommended: skip media that is also used by other posts.', 'post-media-cleanup' ) . '</p>';
    }

    public function sanitize( $input ) {
        $clean = array();

        $clean['enabled']              = ! empty( $input['enabled'] );
        $clean['delete_featured']      = ! empty( $input['delete_featured'] );
        $clean['delete_content_media'] = ! empty( $input['delete_content_media'] );
        $clean['delete_gallery']       = ! empty( $input['delete_gallery'] );
        $clean['skip_shared']          = ! empty( $input['skip_shared'] );

        $valid_types        = array_keys( get_post_types( array( 'public' => true ) ) );
        $submitted          = isset( $input['post_types'] ) ? (array) $input['post_types'] : array();
        $clean['post_types'] = array_values(
            array_intersect( array_map( 'sanitize_key', $submitted ), $valid_types )
        );

        if ( empty( $clean['post_types'] ) ) {
            $clean['post_types'] = array( 'post' );
        }

        return $clean;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Post Media Cleanup', 'post-media-cleanup' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'pmc_settings_group' );
                do_settings_sections( 'post-media-cleanup' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }


}