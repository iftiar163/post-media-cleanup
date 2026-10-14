<?php
/**
 * Deletion Logger.
 *
 * Creates and manages the deletion log database table.
 * Every permanent file deletion the plugin makes is recorded here.
 *
 * Table: {prefix}pmc_deletion_log
 *
 * @package PostMediaCleanup
 */

if( ! defined( 'ABSPATH' ) ){
    exit;
}

class Postmediaweb_Logger {

    const TABLE = 'pmc_deletion_log';
    const SCHEMA_VERSION = 1;
    const SCHEMA_OPTION = 'postmediaweb_db_version';

     /**
     * Get the full prefixed table name.
     *
     * Why a method instead of a constant?
     * $wpdb->prefix is not available at class definition time —
     * it only exists after WordPress has loaded. A method reads it
     * at call time, which is always safe.
     *
     * @return string
     */

     public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
     }

    /**
     * Create or upgrade the table using dbDelta().
     *
     * Safe to call on every activation and upgrade.
     * dbDelta() compares the current structure against what exists
     * and only makes changes that are needed.
     *
     * Called from register_activation_hook() in the main plugin file.
     */
    public static function create_table() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::get_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id            BIGINT(20)   UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id       BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
            post_title    VARCHAR(255) NOT NULL DEFAULT '',
            post_type     VARCHAR(100) NOT NULL DEFAULT '',
            attachment_id BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
            file_name     VARCHAR(255) NOT NULL DEFAULT '',
            file_size     BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
            trigger_type  VARCHAR(20)  NOT NULL DEFAULT '',
            user_id       BIGINT(20)   UNSIGNED NOT NULL DEFAULT 0,
            deleted_at    DATETIME     NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY trigger_type (trigger_type),
            KEY deleted_at (deleted_at)
        ) {$charset};";

        dbDelta( $sql );
        update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
    }

    /**
     * Drop the table completely.
     *
     * Only called from uninstall.php — never on deactivation.
     * Log data belongs to the user; we only delete it when
     * they explicitly uninstall the plugin.
     */
    public static function drop_table() {
        global $wpdb;

        $wpdb->query( "DROP TABLE IF EXISTS " . self::get_table_name() );

        delete_option( self::SCHEMA_OPTION );
    }

    /**
     * Log a single file deletion.
     *
     * Called immediately after wp_delete_attachment() succeeds.
     *
     * @param array $args {
     *     @type int    $post_id       Post the media belonged to (0 for orphans).
     *     @type string $post_title    Title of that post.
     *     @type string $post_type     Post type slug.
     *     @type int    $attachment_id Attachment that was deleted.
     *     @type string $file_name     Filename (basename only).
     *     @type int    $file_size     File size in bytes.
     *     @type string $trigger_type  'post_delete'|'bulk_cleanup'|'scheduled'|'wpcli'
     *     @type int    $user_id       ID of the user who triggered this (0 for cron/CLI).
     * }
     * @return int|false Inserted row ID or false on failure.
     */
    public static function log( array $args ) {
        global $wpdb;

        $defaults = array(
            'post_id'       => 0,
            'post_title'    => '',
            'post_type'     => '',
            'attachment_id' => 0,
            'file_name'     => '',
            'file_size'     => 0,
            'trigger_type'  => 'post_delete',
            'user_id'       => get_current_user_id(),
        );

        $data = wp_parse_args( $args, $defaults );

        // Sanitize every value before it touches the database.
        $insert = array(
            'post_id'       => absint( $data['post_id'] ),
            'post_title'    => sanitize_text_field( $data['post_title'] ),
            'post_type'     => sanitize_key( $data['post_type'] ),
            'attachment_id' => absint( $data['attachment_id'] ),
            'file_name'     => sanitize_file_name( $data['file_name'] ),
            'file_size'     => absint( $data['file_size'] ),
            'trigger_type'  => sanitize_key( $data['trigger_type'] ),
            'user_id'       => absint( $data['user_id'] ),
            'deleted_at'    => current_time( 'mysql' ),
        );

        $result = $wpdb->insert(
            self::get_table_name(),
            $insert,
            array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s' )
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Log multiple deletions at once.
     *
     * More efficient than calling log() in a loop because
     * it uses a single INSERT with multiple rows.
     *
     * @param array[] $entries Array of $args arrays (same shape as log()).
     * @return int Number of rows inserted.
     */
    public static function log_bulk( array $entries ) {
        if( empty( $entries ) ){
            return 0;
        }

        global $wpdb;

        $table = self::get_table_name();
        $now = current_time( 'mysql' );
        $user_id = get_current_user_id();

        $placeholders = array();
        $values = array();

        foreach ( $entries as $entry ) {
            $placeholders[] = '(%d, %s, %s, %d, %s, %d, %s, %d, %s)';
            $values[]       = absint( $entry['post_id'] ?? 0 );
            $values[]       = sanitize_text_field( $entry['post_title'] ?? '' );
            $values[]       = sanitize_key( $entry['post_type'] ?? '' );
            $values[]       = absint( $entry['attachment_id'] ?? 0 );
            $values[]       = sanitize_file_name( $entry['file_name'] ?? '' );
            $values[]       = absint( $entry['file_size'] ?? 0 );
            $values[]       = sanitize_key( $entry['trigger_type'] ?? 'post_delete' );
            $values[]       = absint( $entry['user_id'] ?? $user_id );
            $values[]       = $entry['deleted_at'] ?? $now;
        }

        $sql = "INSERT INTO {$table}
            (post_id, post_title, post_type, attachment_id, file_name, file_size, trigger_type, user_id, deleted_at)
            VALUES " . implode( ', ', $placeholders );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

        return $result ? $result : 0;
    }

    /**
     * Get paginated log entries with optional filters.
     *
     * @param array $args {
     *     @type int    $per_page     Rows per page. Default 20.
     *     @type int    $page         Current page (1-based). Default 1.
     *     @type string $trigger_type Filter by trigger type. Default '' (all).
     *     @type string $order        ASC or DESC. Default DESC.
     * }
     * @return array {
     *     @type object[] $items  Log rows.
     *     @type int      $total  Total matching rows (for pagination).
     * }
     */
    public static function get_entries( array $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page'     => 20,
            'page'         => 1,
            'trigger_type' => '',
            'order'        => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );
        $table = self::get_table_name();
        $where = array( '1=1' );
        $params = array();
        $order = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
        $per_page = max( 1, absint( $args['per_page'] ) );
        $offset = ( max( 1, absint( $args['page'] ) ) - 1 ) * $per_page;

        if( ! empty( $args['trigger_type'] ) ){
            $where[] = 'trigger_type = %s';
            $params[] = sanitize_key( $args['trigger_type'] );
        }

        $where_sql = implode( ' AND ', $where );

        $total = (int) $wpdb->get_var(
            empty( $params )
                ? "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}"
                : $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params )
        );

        // Paginated results.
        $query_params   = array_merge( $params, array( $per_page, $offset ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY deleted_at {$order} LIMIT %d OFFSET %d",
                $query_params
            )
        );

        return array(
            'items' => $items ? $items : array(),
            'total' => $total,
        );
    }

    /**
     * Get summary statistics for the log overview.
     *
     * @return array {
     *     @type int $total_deleted   Total files ever deleted.
     *     @type int $total_size      Total bytes freed (sum of file_size).
     *     @type int $post_delete     Count by trigger type.
     *     @type int $bulk_cleanup    Count by trigger type.
     *     @type int $scheduled       Count by trigger type.
     *     @type int $wpcli           Count by trigger type.
     * }
     */

    public static function get_stats() {
        global $wpdb;

        $table = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            "SELECT
                COUNT(*)                                    AS total_deleted,
                COALESCE(SUM(file_size), 0)                AS total_size,
                SUM(trigger_type = 'post_delete')          AS post_delete,
                SUM(trigger_type = 'bulk_cleanup')         AS bulk_cleanup,
                SUM(trigger_type = 'scheduled')            AS scheduled,
                SUM(trigger_type = 'wpcli')                AS wpcli
             FROM {$table}"
        );

        if ( ! $row ) {
            return array(
                'total_deleted' => 0,
                'total_size'    => 0,
                'post_delete'   => 0,
                'bulk_cleanup'  => 0,
                'scheduled'     => 0,
                'wpcli'         => 0,
            );
        }

        return array(
            'total_deleted' => (int) $row->total_deleted,
            'total_size'    => (int) $row->total_size,
            'post_delete'   => (int) $row->post_delete,
            'bulk_cleanup'  => (int) $row->bulk_cleanup,
            'scheduled'     => (int) $row->scheduled,
            'wpcli'         => (int) $row->wpcli,
        );
    }

    /**
     * Delete all log entries.
     *
     * Called from the admin UI when the user clicks "Clear Log".
     *
     * @return int Number of rows deleted.
     */
    public static function clear() {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->query(
            'TRUNCATE TABLE ' . self::get_table_name()
        );
    }

    
}
