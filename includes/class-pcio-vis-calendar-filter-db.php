<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Per-user settings table; caching would hide just-saved filters.
// phpcs:disable WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Rule keys named 'exclude' are boolean UI flags, not get_posts() exclusion args.

/**
 * Personal calendar filters: one row per WordPress user. No row means the
 * default calendar view. Filters are personal preferences and are therefore
 * not tracked by the change history.
 */
class PCIO_VIS_Calendar_Filter_DB {

    const TABLE = 'me_calendar_filters';

    /** Rule groups that can show matching events or hide them. */
    const RULES = [ 'date', 'text', 'person', 'published', 'types', 'roles' ];

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install(): void {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$table} (
            user_id bigint unsigned NOT NULL,
            settings longtext NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (user_id)
        ) {$charset};" );
    }

    /** Settings a new personal filter starts from: present and future events. */
    public static function defaults(): array {
        return [
            'date'      => [ 'on' => true, 'exclude' => false, 'from' => current_datetime()->format( 'Y-m-d' ), 'to' => '' ],
            'text'      => [ 'on' => false, 'exclude' => false, 'value' => '' ],
            'person'    => [ 'on' => false, 'exclude' => false, 'member_id' => 0 ],
            'published' => [ 'on' => false, 'exclude' => false ],
            'types'     => [ 'on' => false, 'exclude' => false, 'ids' => [] ],
            'roles'     => [ 'on' => false, 'exclude' => false, 'ids' => [] ],
        ];
    }

    /** The user's saved filter, or null when the default view applies. */
    public static function get( int $user_id ): ?array {
        global $wpdb;
        $json = $wpdb->get_var(
            $wpdb->prepare( 'SELECT settings FROM %i WHERE user_id = %d', self::table(), $user_id )
        );
        if ( null === $json ) {
            return null;
        }
        $data = json_decode( (string) $json, true );
        return self::normalize( is_array( $data ) ? $data : [] );
    }

    /** Store the user's filter; returns the normalized settings, or null on failure. */
    public static function save( int $user_id, array $settings ): ?array {
        global $wpdb;
        $settings = self::normalize( $settings );
        $ok       = $wpdb->replace(
            self::table(),
            [ 'user_id' => $user_id, 'settings' => wp_json_encode( $settings ) ],
            [ '%d', '%s' ]
        );
        return false === $ok ? null : $settings;
    }

    /** Remove the user's filter so the default view applies again. */
    public static function delete( int $user_id ): bool {
        global $wpdb;
        return false !== $wpdb->delete( self::table(), [ 'user_id' => $user_id ], [ '%d' ] );
    }

    /** Sanitize untrusted settings into the canonical shape. */
    public static function normalize( array $input ): array {
        $out = self::defaults();
        foreach ( self::RULES as $rule ) {
            $group                   = isset( $input[ $rule ] ) && is_array( $input[ $rule ] ) ? $input[ $rule ] : [];
            $out[ $rule ]['on']      = ! empty( $group['on'] );
            $out[ $rule ]['exclude'] = ! empty( $group['exclude'] );
        }

        $date              = (array) ( $input['date'] ?? [] );
        $out['date']['from'] = self::sanitize_date( $date['from'] ?? '' );
        $out['date']['to']   = self::sanitize_date( $date['to'] ?? '' );
        if ( '' !== $out['date']['from'] && '' !== $out['date']['to'] && $out['date']['to'] < $out['date']['from'] ) {
            $out['date']['to'] = $out['date']['from'];
        }

        $out['text']['value']       = mb_substr( sanitize_text_field( (string) ( $input['text']['value'] ?? '' ) ), 0, 100 );
        $out['person']['member_id'] = absint( $input['person']['member_id'] ?? 0 );
        $out['types']['ids']        = self::sanitize_ids( $input['types']['ids'] ?? [] );
        $out['roles']['ids']        = self::sanitize_ids( $input['roles']['ids'] ?? [] );
        return $out;
    }

    private static function sanitize_date( $value ): string {
        $value = sanitize_text_field( (string) $value );
        $date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
        return $date && $date->format( 'Y-m-d' ) === $value ? $value : '';
    }

    private static function sanitize_ids( $ids ): array {
        return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
    }
}
