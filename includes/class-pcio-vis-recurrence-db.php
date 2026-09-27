<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom admin table; caching would hide real-time changes.

/**
 * Recurrence rule storage.
 *
 * One row per event series, keyed by its own auto-increment group id. Events in
 * the series carry that id in me_events.recurrence_group_id. The "first" event is
 * always the earliest by start_datetime — it is not stored here, so deleting the
 * first event needs no rule migration.
 */
class PCIO_VIS_Recurrence_DB {

    const TABLE = 'me_event_recurrence';

    // Frequency types (stored in freq_type).
    const FREQ_DAY      = 1; // every N days
    const FREQ_WEEK     = 2; // every N weeks on selected weekdays
    const FREQ_MONTH    = 3; // every N months on the Nth weekday
    const FREQ_MONTHDAY = 4; // every N months on a day-of-month
    const FREQ_YEARDAY  = 5; // every N years on month + day-of-month
    const FREQ_YEAR     = 6; // every N years in a month on the Nth weekday

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install(): void {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            group_id int NOT NULL AUTO_INCREMENT,
            occurrences int NULL DEFAULT NULL,
            until_date date NULL DEFAULT NULL,
            freq_type tinyint NOT NULL DEFAULT 1,
            interval_n int NOT NULL DEFAULT 1,
            weekdays_mask int NOT NULL DEFAULT 0,
            week_ordinal tinyint NULL DEFAULT NULL,
            weekday tinyint NULL DEFAULT NULL,
            month_day tinyint NULL DEFAULT NULL,
            month tinyint NULL DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (group_id)
        ) {$charset};";
        dbDelta( $sql );
    }

    /**
     * Fetch a rule row.
     *
     * @param int $group_id Series group ID.
     * @return array|null Raw rule row, or null when missing.
     */
    public static function get( int $group_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE group_id = %d', self::table(), $group_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Create a new empty series and return its group id.
     *
     * @return int New group ID (0 on failure).
     */
    public static function create( array $rule ): int {
        global $wpdb;
        $ok = $wpdb->insert( self::table(), self::sanitize( $rule ), self::formats() );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Update an existing rule.
     *
     * @param int   $group_id Series group ID.
     * @param array $rule     Rule fields to store.
     */
    public static function update( int $group_id, array $rule ): bool {
        global $wpdb;
        return false !== $wpdb->update(
            self::table(),
            self::sanitize( $rule ),
            [ 'group_id' => $group_id ],
            self::formats(),
            [ '%d' ]
        );
    }

    public static function delete( int $group_id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [ 'group_id' => $group_id ], [ '%d' ] );
    }

    /**
     * Coerce raw input into safe, typed rule columns.
     */
    public static function sanitize( array $r ): array {
        $freq = self::clamp_int( $r['freq_type'] ?? self::FREQ_DAY, 1, 6 );

        $occ = ( isset( $r['occurrences'] ) && $r['occurrences'] !== '' && (int) $r['occurrences'] > 0 )
            ? self::clamp_int( $r['occurrences'], 1, 1000 )
            : null;

        $until = '';
        if ( ! empty( $r['until_date'] ) ) {
            $until = sanitize_text_field( (string) $r['until_date'] );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) {
                $until = '';
            }
        }

        $week_ordinal = ( isset( $r['week_ordinal'] ) && $r['week_ordinal'] !== '' )
            ? self::clamp_int( $r['week_ordinal'], 1, 5 )
            : null;
        $weekday = ( isset( $r['weekday'] ) && $r['weekday'] !== '' )
            ? self::clamp_int( $r['weekday'], 1, 7 )
            : null;
        $month_day = ( isset( $r['month_day'] ) && $r['month_day'] !== '' )
            ? self::clamp_int( $r['month_day'], 1, 31 )
            : null;
        $month = ( isset( $r['month'] ) && $r['month'] !== '' )
            ? self::clamp_int( $r['month'], 1, 12 )
            : null;

        return [
            'occurrences'  => $occ,
            'until_date'   => $until !== '' ? $until : null,
            'freq_type'    => $freq,
            'interval_n'   => max( 1, self::clamp_int( $r['interval_n'] ?? 1, 1, 999 ) ),
            'weekdays_mask'=> self::weekdays_to_mask( $r['weekdays'] ?? 0 ),
            'week_ordinal' => $week_ordinal,
            'weekday'      => $weekday,
            'month_day'    => $month_day,
            'month'        => $month,
        ];
    }

    /** Column formats aligned with sanitize(); nullables use %d and are passed as null. */
    private static function formats(): array {
        return [ '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d' ];
    }

    private static function clamp_int( $v, int $min, int $max ): int {
        $v = (int) $v;
        if ( $v < $min ) {
            return $min;
        }
        return $v > $max ? $max : $v;
    }

    /**
     * Convert a weekday selection into a bitmask (bit 0 = Monday … bit 6 = Sunday).
     *
     * Accepts either an int mask, or an array of 7 booleans/ints indexed 0..6 (Mon..Sun).
     */
    public static function weekdays_to_mask( $weekdays ): int {
        if ( is_array( $weekdays ) ) {
            $mask = 0;
            for ( $i = 0; $i < 7; $i++ ) {
                if ( ! empty( $weekdays[ $i ] ) ) {
                    $mask |= ( 1 << $i );
                }
            }
            return $mask;
        }
        return max( 0, (int) $weekdays ) & 0x7F;
    }

    /**
     * Expand a mask into 7 booleans indexed 0..6 (Mon..Sun) for the client.
     */
    public static function mask_to_weekdays( int $mask ): array {
        $out = [];
        for ( $i = 0; $i < 7; $i++ ) {
            $out[] = (bool) ( $mask & ( 1 << $i ) );
        }
        return $out;
    }
}
