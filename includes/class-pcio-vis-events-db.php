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
        return in_array( $mode, [ 'none', 'simple', 'tickets', 'external' ], true ) ? $mode : 'simple';
    }

    /**
     * External ticket-sale URL: only absolute http(s) URLs are kept.
     *
     * @param mixed $url Raw value.
     */
    public static function sanitize_external_url( $url ): string {
        $url = trim( (string) $url );
        if ( '' === $url || ! preg_match( '#^https?://[^/\s]+#i', $url ) ) {
            return '';
        }
        return substr( esc_url_raw( $url, [ 'http', 'https' ] ), 0, 500 );
    }

    const TABLE = 'me_events';
    const PUBLISH_FIELDS = [ 'publish_homepage', 'publish_poster', 'publish_facebook' ];
    const OPTION_DEFAULT_LOCATION = 'pcio_me_default_event_location';

    /** Default event address (location) applied to every newly created event. */
    public static function get_default_location(): string {
        return (string) get_option( self::OPTION_DEFAULT_LOCATION, '' );
    }

    public static function set_default_location( string $location ): void {
        update_option( self::OPTION_DEFAULT_LOCATION, sanitize_text_field( $location ), false );
    }
    /** Optional times before the start: artist get-in and door opening for the audience. */
    const PRE_START_FIELDS = [ 'get_in_datetime', 'door_opening_datetime' ];

    /** Whether the event has been approved for public viewing and signup. */
    public static function is_published( array $event ): bool {
        return 1 === (int) ( $event['publish_homepage'] ?? 0 );
    }

    /** Whether the current user may inspect unpublished events internally. */
    public static function can_view_unpublished(): bool {
        return current_user_can( 'vis_edit_all_events' )
            || current_user_can( 'vis_create_own_events' )
            || current_user_can( 'vis_publish_events' );
    }

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    // ── Schema ────────────────────────────────────────────────────

    public static function install(): void {
        global $wpdb;
        $table   = self::table();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table creation and upgrades run only from the install routine.
        if ( false === $wpdb->query( $wpdb->prepare( "CREATE TABLE IF NOT EXISTS %i (
            id int NOT NULL AUTO_INCREMENT,
            title varchar(200) NOT NULL DEFAULT '',
            description text,
            resume text,
            start_datetime datetime NOT NULL,
            end_datetime datetime NULL DEFAULT NULL,
            get_in_datetime datetime NULL DEFAULT NULL,
            door_opening_datetime datetime NULL DEFAULT NULL,
            all_day tinyint(1) NOT NULL DEFAULT 0,
            color varchar(20) NOT NULL DEFAULT '',
            location varchar(200) NOT NULL DEFAULT '',
            image_id bigint unsigned NULL DEFAULT NULL,
            thumb_image_id bigint unsigned NULL DEFAULT NULL,
            event_type_id int NULL DEFAULT NULL,
            signup_mode varchar(20) NOT NULL DEFAULT 'simple',
            external_url varchar(500) NOT NULL DEFAULT '',
            publish_homepage tinyint(1) NOT NULL DEFAULT 0,
            publish_poster tinyint(1) NOT NULL DEFAULT 0,
            publish_facebook tinyint(1) NOT NULL DEFAULT 0,
            recurrence_group_id int NULL DEFAULT NULL,
            slug varchar(200) NOT NULL DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY start_datetime (start_datetime),
            KEY slug (slug),
            KEY event_type_id (event_type_id),
            KEY recurrence_group_id (recurrence_group_id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", $table ) ) ) {
            wp_die( esc_html__( 'Could not upgrade event publication settings.', 'pcio-vis-member-event' ) );
        }

        foreach ( self::PUBLISH_FIELDS as $field ) {
            if ( self::column_missing( $field ) ) {
                // Preserve existing public events only when first introducing approval.
                $default = 'publish_homepage' === $field ? 1 : 0;
                if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i tinyint(1) NOT NULL DEFAULT %d', $table, $field, $default ) ) ) {
                    wp_die( esc_html__( 'Could not upgrade event publication settings.', 'pcio-vis-member-event' ) );
                }
            }
            if ( false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ALTER COLUMN %i SET DEFAULT %d', $table, $field, 0 ) ) ) {
                wp_die( esc_html__( 'Could not upgrade event publication settings.', 'pcio-vis-member-event' ) );
            }
        }

        // dbDelta silently skips ADD COLUMN on some pre-existing tables, leaving
        // older installs missing columns added after the initial release. Add each
        // missing column/index directly with literal DDL (table via the %i placeholder).
        if ( self::column_missing( 'resume' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `resume` text AFTER `description`", $table ) );
        }
        if ( self::column_missing( 'end_datetime' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `end_datetime` datetime NULL DEFAULT NULL", $table ) );
        }
        if ( self::column_missing( 'get_in_datetime' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `get_in_datetime` datetime NULL DEFAULT NULL AFTER `end_datetime`", $table ) );
        }
        if ( self::column_missing( 'door_opening_datetime' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `door_opening_datetime` datetime NULL DEFAULT NULL AFTER `get_in_datetime`", $table ) );
        }
        if ( self::column_missing( 'all_day' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `all_day` tinyint(1) NOT NULL DEFAULT 0", $table ) );
        }
        if ( self::column_missing( 'color' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `color` varchar(20) NOT NULL DEFAULT ''", $table ) );
        }
        if ( self::column_missing( 'location' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `location` varchar(200) NOT NULL DEFAULT ''", $table ) );
        }
        if ( self::column_missing( 'image_id' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `image_id` bigint unsigned NULL DEFAULT NULL", $table ) );
        }
        if ( self::column_missing( 'thumb_image_id' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `thumb_image_id` bigint unsigned NULL DEFAULT NULL", $table ) );
        }
        if ( self::column_missing( 'event_type_id' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `event_type_id` int NULL DEFAULT NULL", $table ) );
        }
        if ( self::column_missing( 'signup_mode' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `signup_mode` varchar(20) NOT NULL DEFAULT 'simple'", $table ) );
        }
        if ( self::column_missing( 'external_url' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `external_url` varchar(500) NOT NULL DEFAULT '' AFTER `signup_mode`", $table ) );
        }
        if ( self::column_missing( 'recurrence_group_id' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `recurrence_group_id` int NULL DEFAULT NULL", $table ) );
        }
        if ( self::column_missing( 'slug' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN `slug` varchar(200) NOT NULL DEFAULT ''", $table ) );
        }
        if ( self::index_missing( 'slug' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD KEY `slug` (`slug`)", $table ) );
        }
        if ( self::index_missing( 'event_type_id' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD KEY `event_type_id` (`event_type_id`)", $table ) );
        }
        if ( self::index_missing( 'recurrence_group_id' ) ) {
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD KEY `recurrence_group_id` (`recurrence_group_id`)", $table ) );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
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
                 WHERE  DATE(start_datetime) >= %s AND publish_homepage = 1
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
                 WHERE  DATE(start_datetime) >= %s AND publish_homepage = 1
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
    public static function get_range( string $start, string $end, bool $published_only = false ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i
                 WHERE  start_datetime < %s
                   AND  ( %d = 0 OR publish_homepage = 1 )
                   AND  ( end_datetime > %s
                          OR ( end_datetime IS NULL AND start_datetime >= %s ) )
                 ORDER  BY start_datetime ASC',
                self::table(), $end, (int) $published_only, $start, $start
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Events strictly after (or before) a position in (start_datetime, id) order.
     *
     * @param string $start          Start datetime of the reference event.
     * @param int    $id             ID of the reference event (tie-breaker).
     * @param bool   $forward        True for later events, false for earlier ones (nearest first).
     * @param bool   $published_only Only events published on the homepage.
     * @param int    $limit          Maximum number of rows.
     * @return array[]
     */
    public static function get_adjacent( string $start, int $id, bool $forward, bool $published_only, int $limit ): array {
        global $wpdb;
        $limit = max( 1, $limit );
        if ( $forward ) {
            $query = $wpdb->prepare(
                'SELECT * FROM %i
                 WHERE ( start_datetime > %s OR ( start_datetime = %s AND id > %d ) )
                   AND ( %d = 0 OR publish_homepage = 1 )
                 ORDER BY start_datetime ASC, id ASC
                 LIMIT %d',
                self::table(), $start, $start, $id, (int) $published_only, $limit
            );
        } else {
            $query = $wpdb->prepare(
                'SELECT * FROM %i
                 WHERE ( start_datetime < %s OR ( start_datetime = %s AND id < %d ) )
                   AND ( %d = 0 OR publish_homepage = 1 )
                 ORDER BY start_datetime DESC, id DESC
                 LIMIT %d',
                self::table(), $start, $start, $id, (int) $published_only, $limit
            );
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is prepared above.
        return $wpdb->get_results( $query, ARRAY_A ) ?: [];
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
        // Every event must belong to an existing event type.
        if ( ! PCIO_VIS_Event_Types_DB::exists( $insert['event_type_id'] ) ) {
            return false;
        }
        $insert['slug'] = self::generate_unique_slug( $insert['title'] );
        $result = PCIO_VIS_History::insert( 'events', $insert );
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
        if ( array_key_exists( 'event_type_id', $sanitized ) && ! PCIO_VIS_Event_Types_DB::exists( $sanitized['event_type_id'] ) ) {
            return false;
        }
        // Keep the slug in sync when the title changes.
        if ( array_key_exists( 'title', $sanitized ) ) {
            $sanitized['slug'] = self::generate_unique_slug( $sanitized['title'], $id );
        }
        $result = PCIO_VIS_History::update( 'events', $sanitized, [ 'id' => $id ] );
        // $result === 0 means no rows changed (data identical) — still a success
        if ( $result === false ) {
            return false;
        }
        do_action( 'pcio_me_event_saved', $id, $data, false );
        return true;
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        $ok = (bool) PCIO_VIS_History::delete( 'events', [ 'id' => $id ], [ '%d' ] );
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
        return false !== PCIO_VIS_History::update(
            'events',
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
            'resume'         => wp_kses_post(            $data['resume']         ?? '' ),
            'start_datetime' => sanitize_text_field(     $data['start_datetime'] ?? '' ),
            'end_datetime'   => ! empty( $data['end_datetime'] )
                                    ? sanitize_text_field( $data['end_datetime'] )
                                    : null,
            'get_in_datetime'       => self::sanitize_optional_datetime( $data['get_in_datetime'] ?? null ),
            'door_opening_datetime' => self::sanitize_optional_datetime( $data['door_opening_datetime'] ?? null ),
            'all_day'        => isset( $data['all_day'] ) ? (int) (bool) $data['all_day'] : 0,
            'color'          => self::sanitize_color( $data['color'] ?? '' ),
            'location'       => sanitize_text_field( $data['location'] ?? '' ),
            'image_id'       => ! empty( $data['image_id'] ) ? absint( $data['image_id'] ) : null,
            'thumb_image_id' => ! empty( $data['thumb_image_id'] ) ? absint( $data['thumb_image_id'] ) : null,
            'event_type_id'  => ! empty( $data['event_type_id'] ) ? absint( $data['event_type_id'] ) : null,
            'signup_mode'    => self::sanitize_signup_mode( $data['signup_mode'] ?? '' ),
            'external_url'   => self::sanitize_external_url( $data['external_url'] ?? '' ),
            'publish_homepage' => ! empty( $data['publish_homepage'] ) ? 1 : 0,
            'publish_poster'   => ! empty( $data['publish_poster'] ) ? 1 : 0,
            'publish_facebook' => ! empty( $data['publish_facebook'] ) ? 1 : 0,
        ];
    }

    private static function sanitize_partial( array $data ): array {
        $result = [];
        $map = [
            'title'          => fn( $v ) => sanitize_text_field( $v ),
            'description'    => fn( $v ) => wp_kses_post( $v ),
            'resume'         => fn( $v ) => wp_kses_post( (string) $v ),
            'start_datetime' => fn( $v ) => sanitize_text_field( $v ),
            'end_datetime'   => fn( $v ) => empty( $v ) ? null : sanitize_text_field( $v ),
            'get_in_datetime'       => fn( $v ) => self::sanitize_optional_datetime( $v ),
            'door_opening_datetime' => fn( $v ) => self::sanitize_optional_datetime( $v ),
            'all_day'        => fn( $v ) => (int) (bool) $v,
            'color'          => fn( $v ) => self::sanitize_color( $v ),
            'location'       => fn( $v ) => sanitize_text_field( $v ),
            'image_id'       => fn( $v ) => empty( $v ) ? null : absint( $v ),
            'thumb_image_id' => fn( $v ) => empty( $v ) ? null : absint( $v ),
            'event_type_id'  => fn( $v ) => empty( $v ) ? null : absint( $v ),
            'signup_mode'    => fn( $v ) => self::sanitize_signup_mode( $v ),
            'external_url'   => fn( $v ) => self::sanitize_external_url( $v ),
            'publish_homepage' => fn( $v ) => ! empty( $v ) ? 1 : 0,
            'publish_poster'   => fn( $v ) => ! empty( $v ) ? 1 : 0,
            'publish_facebook' => fn( $v ) => ! empty( $v ) ? 1 : 0,
        ];
        foreach ( $map as $field => $sanitizer ) {
            if ( array_key_exists( $field, $data ) ) {
                $result[ $field ] = $sanitizer( $data[ $field ] );
            }
        }
        return $result;
    }

    /**
     * Normalise an optional "Y-m-d H:i[:s]" value; anything else becomes null.
     *
     * @param mixed $value Raw value.
     */
    private static function sanitize_optional_datetime( $value ): ?string {
        if ( ! is_string( $value ) ) {
            return null;
        }
        $value = trim( sanitize_text_field( $value ) );
        if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?$/', $value, $m ) ) {
            return null;
        }
        return $m[1] . ' ' . $m[2] . ( ! empty( $m[3] ) ? $m[3] : ':00' );
    }
}
