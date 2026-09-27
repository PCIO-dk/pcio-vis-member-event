<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom admin table; caching would hide real-time changes.

class PCIO_VIS_Events_DB {

    /**
     * sanitize_hex_color() lives in wp-includes/class-wp-customize-manager.php
     * which is NOT loaded on REST API requests.  Use our own safe version.
     */
    private static function sanitize_color( string $color ): string {
        $color = trim( $color );
        // Accept #RGB and #RRGGBB
        return preg_match( '/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $color ) ? $color : '';
    }

    /**
     * Whitelist the signup/invitation mode. Falls back to 'simple' (the default,
     * backward-compatible join/interested/not-joining UI).
     *
     * @param mixed $mode Raw value.
     */
    private static function sanitize_signup_mode( $mode ): string {
        $mode = sanitize_key( (string) $mode );
        return in_array( $mode, [ 'none', 'simple', 'tickets' ], true ) ? $mode : 'simple';
    }

    const TABLE = 'me_events';

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
            id int NOT NULL AUTO_INCREMENT,
            title varchar(200) NOT NULL DEFAULT '',
            description text,
            start_datetime datetime NOT NULL,
            end_datetime datetime NULL DEFAULT NULL,
            all_day tinyint(1) NOT NULL DEFAULT 0,
            color varchar(20) NOT NULL DEFAULT '',
            location varchar(200) NOT NULL DEFAULT '',
            image_id bigint unsigned NULL DEFAULT NULL,
            thumb_image_id bigint unsigned NULL DEFAULT NULL,
            event_type_id int NULL DEFAULT NULL,
            signup_mode varchar(20) NOT NULL DEFAULT 'simple',
            recurrence_group_id int NULL DEFAULT NULL,
            slug varchar(200) NOT NULL DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY start_datetime (start_datetime),
            KEY slug (slug),
            KEY event_type_id (event_type_id),
            KEY recurrence_group_id (recurrence_group_id)
        ) {$charset};";
        dbDelta( $sql );

        // dbDelta silently skips ADD COLUMN on some pre-existing tables, leaving
        // older installs missing columns added after the initial release. Add each
        // missing column/index directly with literal DDL (table via the %i placeholder).
        if ( self::column_missing( 'end_datetime' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `end_datetime` datetime NULL DEFAULT NULL", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema repair.
        }
        if ( self::column_missing( 'all_day' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `all_day` tinyint(1) NOT NULL DEFAULT 0", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema repair.
        }
        if ( self::column_missing( 'color' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `color` varchar(20) NOT NULL DEFAULT ''", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema repair.
        }
        if ( self::column_missing( 'location' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `location` varchar(200) NOT NULL DEFAULT ''", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema repair.
        }
        if ( self::column_missing( 'image_id' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `image_id` bigint unsigned NULL DEFAULT NULL", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema repair.
        }
        if ( self::column_missing( 'thumb_image_id' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `thumb_image_id` bigint unsigned NULL DEFAULT NULL", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema repair.
        }
        if ( self::column_missing( 'event_type_id' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `event_type_id` int NULL DEFAULT NULL", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema repair.
        }
        if ( self::column_missing( 'signup_mode' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `signup_mode` varchar(20) NOT NULL DEFAULT 'simple'", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema repair.
        }
        if ( self::column_missing( 'recurrence_group_id' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `recurrence_group_id` int NULL DEFAULT NULL", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema repair.
        }
        if ( self::column_missing( 'slug' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `slug` varchar(200) NOT NULL DEFAULT ''", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema repair.
        }
        if ( self::index_missing( 'slug' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD KEY `slug` (`slug`)", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema repair.
        }
        if ( self::index_missing( 'event_type_id' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD KEY `event_type_id` (`event_type_id`)", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema repair.
        }
        if ( self::index_missing( 'recurrence_group_id' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD KEY `recurrence_group_id` (`recurrence_group_id`)", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time schema repair.
        }
    }

    /** Whether the given column is absent from the events table. */
    private static function column_missing( string $column ): bool {
        global $wpdb;
        return ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', self::table(), $column ) );
    }

    /** Whether the given index is absent from the events table. */
    private static function index_missing( string $index ): bool {
        global $wpdb;
        return ! $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', self::table(), $index ) );
    }

    /**
     * Build a URL-safe, unique slug from an event title.
     *
     * @param string $title      Event title.
     * @param int    $exclude_id Event ID to ignore when checking uniqueness (for updates).
     * @return string Unique slug.
     */
    public static function generate_unique_slug( string $title, int $exclude_id = 0 ): string {
        global $wpdb;
        $base = sanitize_title( $title );
        if ( $base === '' ) {
            $base = 'event';
        }
        $table = self::table();
        $slug  = $base;
        $i     = 2;
        while ( true ) {
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE slug = %s AND id <> %d",
                    $table,
                    $slug,
                    $exclude_id
                )
            );
            if ( $exists === 0 ) {
                return $slug;
            }
            $slug = $base . '-' . $i;
            $i++;
        }
    }

    public static function get_by_slug( string $slug ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE `slug` = %s', self::table(), $slug ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Get the next upcoming events for the [pcio_me_event_roller] front-page slider.
     * Compares on the DATE part of start_datetime only, so events happening
     * today are still included. Uses the site timezone for "today".
     *
     * @param int $limit Maximum events to return.
     */
    public static function get_upcoming( int $limit = 3 ): array {
        global $wpdb;
        if ( $limit < 1 ) {
            $limit = 3;
        }
        $today = current_time( 'Y-m-d' );
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i
                 WHERE  DATE(start_datetime) >= %s
                 ORDER  BY start_datetime ASC
                 LIMIT  %d',
                self::table(),
                $today,
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Upcoming events from the start of today up to (but excluding) $end_exclusive,
     * ordered chronologically. Used by the [pcio_me_upcoming_events] agenda list.
     *
     * @param string $end_exclusive Datetime 'Y-m-d H:i:s' in the site timezone.
     */
    public static function get_upcoming_until( string $end_exclusive ): array {
        global $wpdb;
        $today = current_time( 'Y-m-d' );
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i
                 WHERE  DATE(start_datetime) >= %s
                   AND  start_datetime < %s
                 ORDER  BY start_datetime ASC',
                self::table(),
                $today,
                $end_exclusive
            ),
            ARRAY_A
        ) ?: [];
    }

    // ── Queries ───────────────────────────────────────────────────

    /**
     * Get all events whose window overlaps with [start, end).
     * FullCalendar sends ISO 8601 date strings as query params.
     */
    public static function get_range( string $start, string $end ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i
                 WHERE  start_datetime < %s
                   AND  ( end_datetime > %s
                          OR ( end_datetime IS NULL AND start_datetime >= %s ) )
                 ORDER  BY start_datetime ASC',
                self::table(), $end, $start, $start
            ),
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

    // ── CRUD ──────────────────────────────────────────────────────

    /** @return int|false */
    public static function create( array $data ) {
        global $wpdb;
        $insert = self::sanitize_all( $data );
        $insert['slug'] = self::generate_unique_slug( $insert['title'] );
        $result = $wpdb->insert( self::table(), $insert );
        if ( ! $result ) {
            return false;
        }
        $id = (int) $wpdb->insert_id;
        /**
         * Action: pcio_me_event_saved
         *
         * Fires after an event is created or updated. Extensions (e.g. galleries)
         * use this to react to event changes.
         *
         * @param int   $id    Event ID.
         * @param array $data  Raw submitted data.
         * @param bool  $is_new True when the event was just created.
         */
        do_action( 'pcio_me_event_saved', $id, $data, true );
        return $id;
    }

    /**
     * Partial update — only fields present in $data are changed.
     * This allows drag-and-drop saves (date-only) without overwriting other fields.
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;
        $sanitized = self::sanitize_partial( $data );
        if ( empty( $sanitized ) ) {
            return true; // nothing to change — treat as success
        }
        // Keep the slug in sync when the title changes.
        if ( array_key_exists( 'title', $sanitized ) ) {
            $sanitized['slug'] = self::generate_unique_slug( $sanitized['title'], $id );
        }
        $result = $wpdb->update( self::table(), $sanitized, [ 'id' => $id ] );
        // $result === 0 means no rows changed (data identical) — still a success
        if ( $result === false ) {
            return false;
        }
        do_action( 'pcio_me_event_saved', $id, $data, false );
        return true;
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        $ok = (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
        if ( $ok ) {
            /**
             * Action: pcio_me_event_deleted
             *
             * @param int $id  Deleted event ID.
             */
            do_action( 'pcio_me_event_deleted', $id );
        }
        return $ok;
    }

    // ── Recurrence series ─────────────────────────────────────────

    /**
     * Link or unlink an event to a recurrence series.
     *
     * @param int      $id       Event ID.
     * @param int|null $group_id Series group ID, or null to detach.
     */
    public static function set_recurrence_group( int $id, ?int $group_id ): bool {
        global $wpdb;
        // A null value is written as NULL by wpdb regardless of the declared format.
        return false !== $wpdb->update(
            self::table(),
            [ 'recurrence_group_id' => $group_id ],
            [ 'id' => $id ],
            [ '%d' ],
            [ '%d' ]
        );
    }

    /**
     * All events in a recurrence series, ordered chronologically.
     *
     * @param int $group_id Series group ID.
     * @return array[] Full event rows.
     */
    public static function get_series( int $group_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE recurrence_group_id = %d ORDER BY start_datetime ASC, id ASC',
                self::table(),
                $group_id
            ),
            ARRAY_A
        ) ?: [];
    }

    // ── Sanitization ──────────────────────────────────────────────

    private static function sanitize_all( array $data ): array {
        return [
            'title'          => sanitize_text_field(     $data['title']          ?? '' ),
            'description'    => wp_kses_post(            $data['description']    ?? '' ),
            'start_datetime' => sanitize_text_field(     $data['start_datetime'] ?? '' ),
            'end_datetime'   => ! empty( $data['end_datetime'] )
                                    ? sanitize_text_field( $data['end_datetime'] )
                                    : null,
            'all_day'        => isset( $data['all_day'] ) ? (int) (bool) $data['all_day'] : 0,
            'color'          => self::sanitize_color( $data['color'] ?? '' ),
            'location'       => sanitize_text_field( $data['location'] ?? '' ),
            'image_id'       => ! empty( $data['image_id'] ) ? absint( $data['image_id'] ) : null,
            'thumb_image_id' => ! empty( $data['thumb_image_id'] ) ? absint( $data['thumb_image_id'] ) : null,
            'event_type_id'  => ! empty( $data['event_type_id'] ) ? absint( $data['event_type_id'] ) : null,
            'signup_mode'    => self::sanitize_signup_mode( $data['signup_mode'] ?? '' ),
        ];
    }

    private static function sanitize_partial( array $data ): array {
        $result = [];
        $map = [
            'title'          => fn( $v ) => sanitize_text_field( $v ),
            'description'    => fn( $v ) => wp_kses_post( $v ),
            'start_datetime' => fn( $v ) => sanitize_text_field( $v ),
            'end_datetime'   => fn( $v ) => empty( $v ) ? null : sanitize_text_field( $v ),
            'all_day'        => fn( $v ) => (int) (bool) $v,
            'color'          => fn( $v ) => self::sanitize_color( $v ),
            'location'       => fn( $v ) => sanitize_text_field( $v ),
            'image_id'       => fn( $v ) => empty( $v ) ? null : absint( $v ),
            'thumb_image_id' => fn( $v ) => empty( $v ) ? null : absint( $v ),
            'event_type_id'  => fn( $v ) => empty( $v ) ? null : absint( $v ),
            'signup_mode'    => fn( $v ) => self::sanitize_signup_mode( $v ),
        ];
        foreach ( $map as $field => $sanitizer ) {
            if ( array_key_exists( $field, $data ) ) {
                $result[ $field ] = $sanitizer( $data[ $field ] );
            }
        }
        return $result;
    }
}
