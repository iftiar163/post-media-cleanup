<?php
/**
 * WP-CLI Commands.
 *
 * Registers the 'wp pmc' command group.
 *
 * Usage:
 *   wp pmc status              Show plugin stats and log summary
 *   wp pmc scan                Scan for orphaned media
 *   wp pmc cleanup             Scan and delete orphaned media
 *   wp pmc cleanup --dry-run   Show what would be deleted without deleting
 *   wp pmc logs                Show recent deletion log entries
 *   wp pmc logs --limit=50     Show last 50 entries
 *   wp pmc logs --trigger=bulk_cleanup  Filter by trigger type
 *
 * @package PostMediaCleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Postmediaweb_CLI {

    /**
     * Show plugin status and deletion statistics.
     *
     * ## EXAMPLES
     *
     *   wp pmc status
     *
     * @when after_wp_load
     */
    public function status( $args, $assoc_args ) {

        WP_CLI::line( '' );
        WP_CLI::line( '=== Post Media Cleanup ===' );
        WP_CLI::line( 'Version: ' . POSTMEDIAWEB_VERSION );
        WP_CLI::line( '' );

        // Plugin settings summary.
        $enabled    = Postmediaweb_Settings::is_enabled();
        $post_types = (array) Postmediaweb_Settings::get( 'post_types' );

        WP_CLI::line( '-- Settings --' );
        WP_CLI::line( 'Status:       ' . ( $enabled ? 'Enabled' : 'Disabled' ) );
        WP_CLI::line( 'Post types:   ' . ( ! empty( $post_types ) ? implode( ', ', $post_types ) : 'None' ) );
        WP_CLI::line( 'Skip shared:  ' . ( Postmediaweb_Settings::get( 'skip_shared' ) ? 'Yes' : 'No' ) );
        WP_CLI::line( 'Logging:      ' . ( Postmediaweb_Settings::get( 'log_enabled' ) ? 'Enabled' : 'Disabled' ) );
        WP_CLI::line( '' );

        // Log statistics.
        $stats = Postmediaweb_Logger::get_stats();

        WP_CLI::line( '-- Deletion Log --' );

        $rows = array(
            array( 'Metric' => 'Total files deleted',   'Value' => number_format_i18n( $stats['total_deleted'] ) ),
            array( 'Metric' => 'Total space freed',     'Value' => size_format( $stats['total_size'] ) ),
            array( 'Metric' => 'From post deletions',   'Value' => number_format_i18n( $stats['post_delete'] ) ),
            array( 'Metric' => 'From bulk cleanup',     'Value' => number_format_i18n( $stats['bulk_cleanup'] ) ),
            array( 'Metric' => 'From scheduled runs',   'Value' => number_format_i18n( $stats['scheduled'] ) ),
            array( 'Metric' => 'From WP-CLI',           'Value' => number_format_i18n( $stats['wpcli'] ) ),
        );

        // format_items() renders a nice ASCII table in the terminal.
        WP_CLI\Utils\format_items( 'table', $rows, array( 'Metric', 'Value' ) );
    }

    /**
     * Scan the media library for orphaned files.
     *
     * Lists all attachments that are not referenced by any post,
     * page, or custom post type. Does not delete anything.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Options: table, csv, json, count. Default: table.
     *
     * ## EXAMPLES
     *
     *   wp pmc scan
     *   wp pmc scan --format=json
     *   wp pmc scan --format=count
     *
     * @when after_wp_load
     */
    public function scan( $args, $assoc_args ) {

        $format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

        if ( ! in_array( $format, array( 'table', 'csv', 'json', 'count' ), true ) ) {
            WP_CLI::error( 'Invalid format. Choose table, csv, json, or count.' );
        }

        if ( 'table' === $format ) {
            WP_CLI::line( 'Scanning media library for orphaned files...' );
        }

        $result  = Postmediaweb_Orphan_Scanner::scan();
        $orphans = $result['orphans'];

        if ( 'count' === $format ) {
            WP_CLI::line( (string) count( $orphans ) );
            return;
        }

        if ( 'json' === $format ) {
            $json = wp_json_encode( $orphans );
            if ( false === $json ) {
                WP_CLI::error( 'Could not encode scan results as JSON.' );
            }
            WP_CLI::line( $json );
            return;
        }

        if ( 'csv' === $format ) {
            // Output CSV header then rows.
            WP_CLI::line( 'id,name,type,size,date' );
            foreach ( $orphans as $orphan ) {
                WP_CLI::line( implode( ',', array(
                    $orphan['id'],
                    '"' . str_replace( '"', '""', $orphan['name'] ) . '"',
                    $orphan['type'],
                    $orphan['size'],
                    $orphan['date'],
                ) ) );
            }
            return;
        }

        if ( empty( $orphans ) ) {
            WP_CLI::success( 'No orphaned media found. Your media library is clean.' );
            return;
        }

        // Default: table format.
        WP_CLI\Utils\format_items(
            'table',
            $orphans,
            array( 'id', 'name', 'type', 'size', 'date' )
        );

        WP_CLI::line( '' );
        WP_CLI::warning(
            count( $orphans ) . ' orphaned file(s) found. '
            . 'Run `wp pmc cleanup` to delete them.'
        );
    }

    /**
     * Delete orphaned media files.
     *
     * Scans for orphaned files and deletes them permanently.
     * Use --dry-run to preview what would be deleted without
     * actually deleting anything.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : List what would be deleted without deleting anything.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *   wp pmc cleanup
     *   wp pmc cleanup --dry-run
     *   wp pmc cleanup --yes
     *
     * @when after_wp_load
     */
    public function cleanup( $args, $assoc_args ) {

        $dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

        WP_CLI::line( 'Scanning media library...' );

        $result  = Postmediaweb_Orphan_Scanner::scan();
        $orphans = $result['orphans'];
        $ids     = $result['ids'];

        if ( empty( $ids ) ) {
            WP_CLI::success( 'No orphaned media found. Nothing to delete.' );
            return;
        }

        // Show what was found.
        WP_CLI\Utils\format_items(
            'table',
            $orphans,
            array( 'id', 'name', 'type', 'size', 'date' )
        );

        WP_CLI::line( '' );
        WP_CLI::line( count( $ids ) . ' orphaned file(s) found.' );

        if ( $dry_run ) {
            WP_CLI::warning( 'Dry run — nothing was deleted.' );
            return;
        }

        // Ask for confirmation unless --yes was passed.
        // WP_CLI::confirm() exits with an error if the user types 'n'.
        // This is the standard WP-CLI pattern for destructive operations.
        WP_CLI::confirm(
            'Delete all ' . count( $ids ) . ' orphaned file(s) permanently?',
            $assoc_args
        );

        // Show a progress bar in the terminal.
        $progress = \WP_CLI\Utils\make_progress_bar(
            'Deleting orphaned files',
            count( $ids )
        );

        $deleted = 0;
        $skipped = 0;

        // We delete in batches of 25 to keep memory bounded.
        // Each batch is one loop iteration.
        foreach ( array_chunk( $ids, 25 ) as $batch ) {
            $batch_result = Postmediaweb_Orphan_Scanner::delete( $batch, 'wpcli' );
            $deleted     += $batch_result['deleted'];
            $skipped     += count( $batch_result['skipped'] );

            foreach ( $batch as $_ ) {
                $progress->tick();
            }
        }

        $progress->finish();
        WP_CLI::line( '' );
        WP_CLI::success( $deleted . ' file(s) deleted.' );

        if ( $skipped > 0 ) {
            WP_CLI::warning( $skipped . ' file(s) skipped (now referenced elsewhere).' );
        }
    }

    /**
     * Show recent deletion log entries.
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Number of entries to show. Default: 20.
     *
     * [--trigger=<type>]
     * : Filter by trigger type: post_delete, bulk_cleanup, scheduled, wpcli.
     *
     * [--format=<format>]
     * : Output format: table, csv, json. Default: table.
     *
     * ## EXAMPLES
     *
     *   wp pmc logs
     *   wp pmc logs --limit=50
     *   wp pmc logs --trigger=bulk_cleanup
     *   wp pmc logs --format=csv
     *
     * @when after_wp_load
     */
    public function logs( $args, $assoc_args ) {

        $limit   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 20 );
        $trigger = \WP_CLI\Utils\get_flag_value( $assoc_args, 'trigger', '' );
        $format  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

        if ( ! is_scalar( $limit ) || false === filter_var( $limit, FILTER_VALIDATE_INT ) || (int) $limit < 1 ) {
            WP_CLI::error( 'The --limit value must be a positive integer.' );
        }
        $limit = (int) $limit;

        if ( ! in_array( $trigger, array( '', 'post_delete', 'bulk_cleanup', 'scheduled', 'wpcli' ), true ) ) {
            WP_CLI::error( 'Invalid trigger. Choose post_delete, bulk_cleanup, scheduled, or wpcli.' );
        }

        if ( ! in_array( $format, array( 'table', 'csv', 'json' ), true ) ) {
            WP_CLI::error( 'Invalid format. Choose table, csv, or json.' );
        }

        $result = Postmediaweb_Logger::get_entries( array(
            'per_page'     => $limit,
            'page'         => 1,
            'trigger_type' => $trigger,
        ) );

        if ( empty( $result['items'] ) ) {
            if ( 'json' === $format ) {
                WP_CLI::line( '[]' );
                return;
            }

            if ( 'csv' === $format ) {
                WP_CLI::line( 'id,file_name,size,post,trigger_type,deleted_at' );
                return;
            }

            WP_CLI::line( 'No log entries found.' );
            return;
        }

        // Flatten the DB objects into arrays for format_items().
        $rows = array();
        foreach ( $result['items'] as $entry ) {
            $rows[] = array(
                'id'           => $entry->id,
                'file_name'    => $entry->file_name,
                'size'         => $entry->file_size > 0 ? size_format( $entry->file_size ) : '—',
                'post'         => $entry->post_title ?: ( $entry->post_id > 0 ? '#' . $entry->post_id : 'orphan' ),
                'trigger_type' => $entry->trigger_type,
                'deleted_at'   => mysql2date( 'd M Y H:i', $entry->deleted_at ),
            );
        }

        WP_CLI\Utils\format_items(
            $format,
            $rows,
            array( 'id', 'file_name', 'size', 'post', 'trigger_type', 'deleted_at' )
        );

        WP_CLI::line( '' );
        WP_CLI::line( 'Showing ' . count( $rows ) . ' of ' . $result['total'] . ' total entries.' );
    }
}