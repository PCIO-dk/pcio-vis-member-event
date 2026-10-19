<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom sync tables; caching would hide real-time changes.

/**
 * Persistence for the Google Calendar sync.
 *
 * Tables
 * ──────
 *  {prefix}me_google_calendars  – Google calendars to keep in sync.
 *    id, name, calendar_id (Google ID), scope ('all' | 'public'), created_at
 *
 *  {prefix}me_google_event_map  – Vis event ↔ Google event link, per calendar.
 *    id, calendar_ref (me_google_calendars.id), event_id (me_events.id),
 *    google_event_id, synced_hash (hash of the payload last written to Google),
 *    synced_at
 *
 * Settings (wp_options, never autoloaded)
 * ───────────────────────────────────────
 *  pcio_me_google_key         – service-account credentials JSON (GOOGLE_KEY).
 *  pcio_me_google_location    – fallback location for events without one.
 *  pcio_me_google_clip_types  – event type IDs whose end is cut to 22:00 on the start day.
 *  pcio_me_ai_resume_prompt   – instruction prefixed to the description when an AI resume is generated.
 */
class PCIO_VIS_Google_DB {

    const CALENDARS_TABLE = 'me_google_calendars';
    const MAP_TABLE       = 'me_google_event_map';

    const OPTION_KEY        = 'pcio_me_google_key';
    const OPTION_LOCATION   = 'pcio_me_google_location';
    const OPTION_CLIP_TYPES = 'pcio_me_google_clip_types';
    const OPTION_AI_PROMPT  = 'pcio_me_ai_resume_prompt';

    const SCOPE_ALL    = 'all';
    const SCOPE_PUBLIC = 'public';

    public static function calendars_table(): string {
        global $wpdb;
        return $wpdb->prefix . self::CALENDARS_TABLE;
    }

    public static function map_table(): string {
        global $wpdb;
        return $wpdb->prefix . self::MAP_TABLE;
    }

    // ── Schema ────────────────────────────────────────────────────

    /**
     * Create the sync tables. Called from PCIO_VIS_Installer::install().
     */
    public static function install(): void {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table creation and upgrades run only from the install routine.
        $wpdb->query(
            $wpdb->prepare(
                "CREATE TABLE IF NOT EXISTS %i (
                    id int unsigned NOT NULL AUTO_INCREMENT,
                    name varchar(100) NOT NULL DEFAULT '',
                    calendar_id varchar(255) NOT NULL DEFAULT '',
                    scope varchar(20) NOT NULL DEFAULT 'all',
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
                self::calendars_table()
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "CREATE TABLE IF NOT EXISTS %i (
                    id int unsigned NOT NULL AUTO_INCREMENT,
                    calendar_ref int unsigned NOT NULL,
                    event_id int NOT NULL,
                    google_event_id varchar(255) NOT NULL DEFAULT '',
                    synced_hash char(32) NOT NULL DEFAULT '',
                    synced_at datetime DEFAULT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_calendar_event (calendar_ref, event_id),
                    KEY google_event_id (google_event_id(191))
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
                self::map_table()
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
    }

    // ── Settings ──────────────────────────────────────────────────

    /** Raw credentials JSON, or '' when none is stored. */
    public static function get_key(): string {
        return (string) get_option( self::OPTION_KEY, '' );
    }

    /**
     * Store the credentials JSON (autoload disabled so it is not loaded on every request).
     *
     * @param string $json Validated service-account JSON; '' removes the key.
     */
    public static function set_key( string $json ): void {
        if ( '' === $json ) {
            delete_option( self::OPTION_KEY );
            return;
        }
        update_option( self::OPTION_KEY, $json, false );
    }

    public static function get_location(): string {
        return (string) get_option( self::OPTION_LOCATION, '' );
    }

    public static function set_location( string $location ): void {
        update_option( self::OPTION_LOCATION, sanitize_text_field( $location ), false );
    }

    /** @return int[] Event type IDs whose exported end is clipped to 22:00 on the start day. */
    public static function get_clip_types(): array {
        $ids = get_option( self::OPTION_CLIP_TYPES, [] );
        return is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : [];
    }

    /** @param int[] $ids Event type IDs. */
    public static function set_clip_types( array $ids ): void {
        update_option( self::OPTION_CLIP_TYPES, array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ), false );
    }

    /** Default instruction sent before the event description when generating a resume. */
    public static function default_ai_prompt(): string {
        return __( 'Shorten the following Danish event text to at most 250 characters. Write a natural and inviting short text for use in an event calendar. Keep important details such as names, dates, times and place, if present. Do not invent information that is not in the original text. Return only the short text.', 'pcio-vis-member-event' );
    }

    /** Instruction (prompt prefix) for AI-generated resumes; falls back to the translated default. */
    public static function get_ai_prompt(): string {
        $prompt = trim( (string) get_option( self::OPTION_AI_PROMPT, '' ) );
        return '' !== $prompt ? $prompt : self::default_ai_prompt();
    }

    /**
     * Store the AI prompt. Empty or default text removes the option so the
     * translated default follows the site language.
     *
     * @param string $prompt Sanitized prompt text.
     */
    public static function set_ai_prompt( string $prompt ): void {
        $prompt = trim( $prompt );
        if ( '' === $prompt || self::default_ai_prompt() === $prompt ) {
            delete_option( self::OPTION_AI_PROMPT );
            return;
        }
        update_option( self::OPTION_AI_PROMPT, $prompt, false );
    }

    // ── Calendars ─────────────────────────────────────────────────

    public static function get_calendars(): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', self::calendars_table() ),
            ARRAY_A
        ) ?: [];
    }

    public static function get_calendar( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::calendars_table(), $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /** @return int|false New row ID. */
    public static function create_calendar( array $data ) {
        global $wpdb;
        $ok = $wpdb->insert( self::calendars_table(), self::sanitize_calendar( $data ), [ '%s', '%s', '%s' ] );
        return $ok ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update a calendar. Changing the Google calendar ID drops its event links,
     * since the stored Google event IDs belong to the previous calendar.
     */
    public static function update_calendar( int $id, array $data ): bool {
        global $wpdb;
        $old   = self::get_calendar( $id );
        $clean = self::sanitize_calendar( $data );
        $ok    = false !== $wpdb->update( self::calendars_table(), $clean, [ 'id' => $id ], [ '%s', '%s', '%s' ], [ '%d' ] );
        if ( $ok && $old && $old['calendar_id'] !== $clean['calendar_id'] ) {
            self::delete_links_for_calendar( $id );
        }
        return $ok;
    }

    public static function delete_calendar( int $id ): bool {
        global $wpdb;
        self::delete_links_for_calendar( $id );
        return (bool) $wpdb->delete( self::calendars_table(), [ 'id' => $id ], [ '%d' ] );
    }

    private static function sanitize_calendar( array $data ): array {
        $scope = sanitize_key( (string) ( $data['scope'] ?? '' ) );
        return [
            'name'        => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
            'calendar_id' => sanitize_text_field( (string) ( $data['calendar_id'] ?? '' ) ),
            'scope'       => self::SCOPE_PUBLIC === $scope ? self::SCOPE_PUBLIC : self::SCOPE_ALL,
        ];
    }

    // ── Event links ───────────────────────────────────────────────

    /**
     * All links for a calendar, keyed by Vis event ID.
     *
     * @return array<int,array> event_id => map row.
     */
    public static function get_links( int $calendar_ref ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i WHERE calendar_ref = %d', self::map_table(), $calendar_ref ),
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ( $rows as $row ) {
            $out[ (int) $row['event_id'] ] = $row;
        }
        return $out;
    }

    /**
     * Create or replace the link between a Vis event and its Google event.
     */
    public static function save_link( int $calendar_ref, int $event_id, string $google_event_id, string $hash ): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (calendar_ref, event_id, google_event_id, synced_hash, synced_at)
                 VALUES (%d, %d, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE google_event_id = VALUES(google_event_id),
                                         synced_hash = VALUES(synced_hash),
                                         synced_at = VALUES(synced_at)',
                self::map_table(),
                $calendar_ref,
                $event_id,
                $google_event_id,
                $hash,
                current_time( 'mysql' )
            )
        );
    }

    /** Remove the link for one Vis event so it is created again on the next sync. */
    public static function delete_link( int $calendar_ref, int $event_id ): void {
        global $wpdb;
        $wpdb->delete( self::map_table(), [ 'calendar_ref' => $calendar_ref, 'event_id' => $event_id ], [ '%d', '%d' ] );
    }

    /** Remove every link that points at a given Google event. */
    public static function delete_links_by_google_id( int $calendar_ref, string $google_event_id ): void {
        global $wpdb;
        $wpdb->delete(
            self::map_table(),
            [ 'calendar_ref' => $calendar_ref, 'google_event_id' => $google_event_id ],
            [ '%d', '%s' ]
        );
    }

    public static function delete_links_for_calendar( int $calendar_ref ): void {
        global $wpdb;
        $wpdb->delete( self::map_table(), [ 'calendar_ref' => $calendar_ref ], [ '%d' ] );
    }

    /** Drop every link to a deleted Vis event (hooked on pcio_me_event_deleted). */
    public static function delete_links_for_event( int $event_id ): void {
        global $wpdb;
        $wpdb->delete( self::map_table(), [ 'event_id' => $event_id ], [ '%d' ] );
    }

    // ── Vis events eligible for a calendar ────────────────────────

    /**
     * Vis events starting inside [$from, $to] that belong in a calendar.
     *
     * Scope 'public' keeps homepage-approved events whose type has public access;
     * scope 'all' also includes unpublished events.
     *
     * @param string $scope Calendar scope.
     * @param string $from  'Y-m-d H:i:s' (site time).
     * @param string $to    'Y-m-d H:i:s' (site time).
     * @return array[] Event rows.
     */
    public static function get_eligible_events( string $scope, string $from, string $to ): array {
        global $wpdb;

        if ( self::SCOPE_PUBLIC === $scope ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT e.* FROM %i e
                     INNER JOIN %i t ON t.id = e.event_type_id AND t.access = 'public'
                     WHERE e.start_datetime >= %s AND e.start_datetime <= %s AND e.publish_homepage = 1
                     ORDER BY e.start_datetime ASC",
                    PCIO_VIS_Events_DB::table(),
                    PCIO_VIS_Event_Types_DB::table(),
                    $from,
                    $to
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE start_datetime >= %s AND start_datetime <= %s ORDER BY start_datetime ASC',
                    PCIO_VIS_Events_DB::table(),
                    $from,
                    $to
                ),
                ARRAY_A
            );
        }

        return $rows ?: [];
    }
}
