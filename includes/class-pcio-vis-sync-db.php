<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom admin table; caching would hide real-time changes.

/**
 * Database layer for the cron-job registry used by PCIO VIS Member Event and its extensions.
 *
 * Table: {prefix}me_sync
 *
 * Columns:
 *   id           INT UNSIGNED  – auto-increment primary key
 *   name         VARCHAR(100)  – human-readable job name
 *   description  TEXT          – longer description shown in the admin UI
 *   cron_name    VARCHAR(100)  – unique slug used as the WP cron hook suffix
 *   php_function VARCHAR(200)  – callable in "ClassName::method" or "function_name" format
 *   sync_url     VARCHAR(500)  – optional external URL the job may call
 *   frequence    SMALLINT      – how often (in hours) the job should run
 *   enabled      TINYINT(1)    – 1 = runs on schedule, 0 = disabled (manual run only)
 *   last_run     DATETIME      – UTC timestamp of the last successful execution
 *   result       TEXT          – human-readable result string from the last run
 */
class PCIO_VIS_Sync_DB {

    const TABLE = 'me_sync';

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    // ── Schema ────────────────────────────────────────────────────

    public static function install(): void {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id int unsigned NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL DEFAULT '',
            description text,
            cron_name varchar(100) NOT NULL DEFAULT '',
            php_function varchar(200) NOT NULL DEFAULT '',
            sync_url varchar(500) NOT NULL DEFAULT '',
            frequence smallint unsigned NOT NULL DEFAULT 24,
            enabled tinyint(1) NOT NULL DEFAULT 0,
            last_run datetime DEFAULT NULL,
            result text,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_me_sync_cron_name (cron_name)
        ) {$charset};";
        dbDelta( $sql );

        // Seed each built-in job individually so new jobs are added to existing installations.
        self::seed_defaults();
    }

    private static function seed_defaults(): void {
        self::seed_job( [
            'name'         => 'Sync WP Users',
            'description'  => 'Ensures every member in the member list has a linked WordPress subscriber account '
                . 'with the vis_member capability. Creates accounts for members without one, grants vis_member '
                . 'to existing WP users that lack it, and revokes vis_member from accounts whose e-mail is no '
                . 'longer in the member list (administrators are never touched, WP accounts are never deleted).',
            'cron_name'    => 'pcio_me_sync_wp_users',
            'php_function' => 'PCIO_VIS_Sync::run_sync_wp_users',
            'sync_url'     => '',
            'frequence'    => 24,
            'enabled'      => 0,
        ] );
        self::seed_job( [
            'name'         => 'Import WP Users',
            'description'  => 'Creates a member record for every WordPress user that does not already '
                . 'have a matching e-mail in the member list. Existing members are never modified.',
            'cron_name'    => 'pcio_me_import_wp_users',
            'php_function' => 'PCIO_VIS_Sync::run_import_wp_users',
            'sync_url'     => '',
            'frequence'    => 24,
            'enabled'      => 0,
        ] );
    }

    /**
     * Insert a built-in job row only if its cron_name does not already exist.
     * This lets new built-in jobs appear on existing installations without wiping user edits.
     */
    private static function seed_job( array $data ): void {
        global $wpdb;
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE `cron_name` = %s',
                self::table(),
                $data['cron_name']
            )
        );
        if ( ! (int) $exists ) {
            $wpdb->insert(
                self::table(),
                $data,
                [ '%s', '%s', '%s', '%s', '%s', '%d', '%d' ]
            );
        }
    }

    // ── Queries ───────────────────────────────────────────────────

    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i ORDER BY `id` ASC', self::table() ),
            ARRAY_A
        ) ?: [];
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE `id` = %d', self::table(), $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_by_cron_name( string $cron_name ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE `cron_name` = %s', self::table(), $cron_name ),
            ARRAY_A
        );
        return $row ?: null;
    }

    // ── Mutations ─────────────────────────────────────────────────

    public static function create( array $data ): int|false {
        global $wpdb;
        $ok = $wpdb->insert( self::table(), self::prepare_data( $data ) );
        return $ok ? (int) $wpdb->insert_id : false;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            self::table(),
            self::prepare_data( $data ),
            [ 'id' => $id ],
            null,
            [ '%d' ]
        );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    /**
     * Record the outcome of a completed run (sets last_run to now).
     */
    public static function update_run( int $id, string $result ): void {
        global $wpdb;
        $wpdb->update(
            self::table(),
            [
                'last_run' => current_time( 'mysql' ),
                'result'   => $result,
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    // ── Helpers ───────────────────────────────────────────────────

    private static function prepare_data( array $data ): array {
        $out = [];

        if ( array_key_exists( 'name', $data ) ) {
            $out['name'] = sanitize_text_field( $data['name'] );
        }
        if ( array_key_exists( 'description', $data ) ) {
            $out['description'] = sanitize_textarea_field( $data['description'] );
        }
        if ( array_key_exists( 'cron_name', $data ) ) {
            $out['cron_name'] = sanitize_key( $data['cron_name'] );
        }
        if ( array_key_exists( 'php_function', $data ) ) {
            // Allow only valid PHP identifier characters, backslash, and ::
            $fn              = sanitize_text_field( wp_unslash( (string) $data['php_function'] ) );
            $out['php_function'] = preg_replace( '/[^a-zA-Z0-9_\\\\:]/', '', $fn );
        }
        if ( array_key_exists( 'sync_url', $data ) ) {
            $out['sync_url'] = esc_url_raw( $data['sync_url'] );
        }
        if ( array_key_exists( 'frequence', $data ) ) {
            $out['frequence'] = max( 1, (int) $data['frequence'] );
        }
        if ( array_key_exists( 'enabled', $data ) ) {
            $out['enabled'] = (int) (bool) $data['enabled'];
        }

        return $out;
    }
}
