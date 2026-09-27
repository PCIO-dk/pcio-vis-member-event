<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom admin table; caching would hide real-time changes.

/**
 * Storage for rolling-text entries used by [pcio_me_rolling_text].
 *
 * Each row defines a message, a roller_id (the shortcode id attribute),
 * and a start/stop datetime window during which the message is active.
 */
class PCIO_VIS_Rolling_Text_DB {

    const TABLE = 'me_rolling_texts';

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
            roller_id varchar(100) NOT NULL DEFAULT '',
            start_at datetime NOT NULL,
            stop_at datetime NOT NULL,
            text text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY roller_id (roller_id)
        ) {$charset};";
        dbDelta( $sql );
    }

    // ── Queries ───────────────────────────────────────────────────

    /**
     * Return all entries, optionally filtered by roller_id.
     *
     * @param string $roller_id Empty string = return all rollers.
     */
    public static function get_all( string $roller_id = '' ): array {
        global $wpdb;
        if ( $roller_id !== '' ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE roller_id = %s ORDER BY start_at ASC',
                    self::table(),
                    $roller_id
                ),
                ARRAY_A
            ) ?: [];
        }
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i ORDER BY roller_id ASC, start_at ASC',
                self::table()
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Return entries that are active right now (server time) for a given roller_id.
     * Multiple active entries are concatenated by the shortcode handler.
     */
    public static function get_active( string $roller_id ): array {
        global $wpdb;
        $now = current_time( 'mysql' );
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE roller_id = %s AND start_at <= %s AND stop_at >= %s ORDER BY start_at ASC',
                self::table(),
                $roller_id,
                $now,
                $now
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
            ARRAY_A
        ) ?: null;
    }

    /** @return int|false New row ID or false on failure. */
    public static function create( array $data ) {
        global $wpdb;
        $result = $wpdb->insert( self::table(), self::sanitize( $data ) );
        return $result ? $wpdb->insert_id : false;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        $sanitized = self::sanitize_partial( $data );
        if ( empty( $sanitized ) ) {
            return true;
        }
        return false !== $wpdb->update( self::table(), $sanitized, [ 'id' => $id ] );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    // ── Sanitization ──────────────────────────────────────────────

    private static function sanitize( array $data ): array {
        return [
            'roller_id' => sanitize_key( $data['roller_id'] ?? '' ),
            'start_at'  => self::sanitize_datetime( (string) ( $data['start_at'] ?? '' ) ),
            'stop_at'   => self::sanitize_datetime( (string) ( $data['stop_at']  ?? '' ) ),
            'text'      => sanitize_text_field( wp_unslash( (string) ( $data['text'] ?? '' ) ) ),
        ];
    }

    private static function sanitize_partial( array $data ): array {
        $out = [];
        if ( array_key_exists( 'roller_id', $data ) ) {
            $out['roller_id'] = sanitize_key( (string) $data['roller_id'] );
        }
        if ( array_key_exists( 'start_at', $data ) ) {
            $out['start_at'] = self::sanitize_datetime( (string) $data['start_at'] );
        }
        if ( array_key_exists( 'stop_at', $data ) ) {
            $out['stop_at'] = self::sanitize_datetime( (string) $data['stop_at'] );
        }
        if ( array_key_exists( 'text', $data ) ) {
            $out['text'] = sanitize_text_field( wp_unslash( (string) $data['text'] ) );
        }
        return $out;
    }

    /**
     * Accept ISO-8601 or 'Y-m-d H:i:s'; always return 'Y-m-d H:i:s'.
     * Returns an empty string for unparseable input (DB will reject the row).
     */
    private static function sanitize_datetime( string $value ): string {
        if ( $value === '' ) {
            return '';
        }
        // datetime-local format: 2026-09-01T14:30 → normalise the separator.
        $normalised = str_replace( 'T', ' ', $value );
        $ts         = strtotime( $normalised );
        return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
    }
}
