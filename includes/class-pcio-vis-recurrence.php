<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Recurrence engine — computes the projected series of start dates from a rule,
 * diffs it against the events that physically exist, and materialises the series
 * by cloning the anchor event (the earliest event in the group).
 *
 * The rule vocabulary and date math are ported from the reference implementation.
 */
class PCIO_VIS_Recurrence {

    /** Never project more than this many occurrences, whatever the rule says. */
    const MAX_OCCURRENCES = 500;

    /** Hard safety horizon: never project further than this many years past the anchor. */
    const MAX_HORIZON_YEARS = 20;

    public static function init(): void {
        // Best-effort cleanup: when the last event of a series is removed, drop the rule row.
        add_action( 'pcio_me_event_deleted', [ __CLASS__, 'cleanup_orphans' ] );
    }

    /**
     * Remove rule rows whose series no longer has any events.
     * Runs after any event delete; cheap and self-healing.
     */
    public static function cleanup_orphans(): void {
        global $wpdb;
        $rec_table   = PCIO_VIS_Recurrence_DB::table();
        $event_table = PCIO_VIS_Events_DB::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $orphans = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT r.group_id FROM %i r
                 LEFT JOIN %i e ON e.recurrence_group_id = r.group_id
                 WHERE e.id IS NULL',
                $rec_table,
                $event_table
            )
        );
        foreach ( $orphans as $group_id ) {
            PCIO_VIS_Recurrence_DB::delete( (int) $group_id );
        }
    }

    // ── Series context ────────────────────────────────────────────

    /**
     * Build the full recurrence context for an event's tab.
     *
     * @param int $event_id Event being viewed.
     * @return array Context payload (see keys below).
     */
    public static function context( int $event_id ): array {
        $event = PCIO_VIS_Events_DB::get( $event_id );
        if ( ! $event ) {
            return [ 'isRecurring' => false ];
        }
        $group_id = (int) ( $event['recurrence_group_id'] ?? 0 );

        $ctx = [
            'isRecurring' => $group_id > 0,
            'groupId'     => $group_id ?: null,
            'eventId'     => $event_id,
            'position'    => 0,
            'total'       => 0,
            'isAnchor'    => true,
            'prevId'      => null,
            'nextId'      => null,
            'firstId'     => null,
            'lastId'      => null,
            'rule'        => null,
            'projection'  => [],
            'counts'      => [ 'existing' => 0, 'missing' => 0, 'extra' => 0 ],
        ];

        if ( $group_id < 1 ) {
            return $ctx;
        }

        $series = PCIO_VIS_Events_DB::get_series( $group_id );
        $ids    = array_map( static fn( $r ) => (int) $r['id'], $series );
        $total  = count( $ids );
        $pos    = array_search( $event_id, $ids, true );
        $pos    = $pos === false ? 0 : $pos + 1; // 1-based

        $ctx['total']    = $total;
        $ctx['position'] = $pos;
        $ctx['isAnchor'] = $pos === 1;
        $ctx['firstId']  = $ids[0] ?? null;
        $ctx['lastId']   = $ids[ $total - 1 ] ?? null;
        $ctx['prevId']   = ( $pos > 1 ) ? $ids[ $pos - 2 ] : null;
        $ctx['nextId']   = ( $pos > 0 && $pos < $total ) ? $ids[ $pos ] : null;

        $rule = PCIO_VIS_Recurrence_DB::get( $group_id );
        if ( $rule ) {
            $ctx['rule'] = self::rule_for_client( $rule );
        }

        $projection = self::project( $group_id, $series, $rule );
        $ctx['projection'] = $projection;
        foreach ( $projection as $row ) {
            if ( $row['state'] === 'exists' ) {
                $ctx['counts']['existing']++;
            } elseif ( $row['state'] === 'missing' ) {
                $ctx['counts']['missing']++;
            } elseif ( $row['state'] === 'extra' ) {
                $ctx['counts']['extra']++;
            }
        }
        return $ctx;
    }

    /** Shape a stored rule row for the client. */
    private static function rule_for_client( array $rule ): array {
        return [
            'occurrences' => isset( $rule['occurrences'] ) ? (int) $rule['occurrences'] : null,
            'untilDate'   => $rule['until_date'] ?? null,
            'freqType'    => (int) $rule['freq_type'],
            'intervalN'   => (int) $rule['interval_n'],
            'weekdays'    => PCIO_VIS_Recurrence_DB::mask_to_weekdays( (int) $rule['weekdays_mask'] ),
            'weekOrdinal' => isset( $rule['week_ordinal'] ) ? (int) $rule['week_ordinal'] : null,
            'weekday'     => isset( $rule['weekday'] ) ? (int) $rule['weekday'] : null,
            'monthDay'    => isset( $rule['month_day'] ) ? (int) $rule['month_day'] : null,
            'month'       => isset( $rule['month'] ) ? (int) $rule['month'] : null,
        ];
    }

    // ── Projection (diff of computed vs existing) ─────────────────

    /**
     * Diff the computed date series against existing events.
     *
     * @param int        $group_id Series group ID.
     * @param array|null $series   Pre-fetched series rows (optional).
     * @param array|null $rule     Pre-fetched rule row (optional).
     * @return array[] Rows: [ 'eventId' => int, 'start' => 'Y-m-d H:i:s', 'state' => exists|missing|extra ].
     */
    public static function project( int $group_id, ?array $series = null, ?array $rule = null ): array {
        $series = $series ?? PCIO_VIS_Events_DB::get_series( $group_id );
        if ( empty( $series ) ) {
            return [];
        }
        $rule = $rule ?? PCIO_VIS_Recurrence_DB::get( $group_id );

        // Index existing events by their exact start datetime.
        $existing = [];
        foreach ( $series as $row ) {
            $existing[ (string) $row['start_datetime'] ] = (int) $row['id'];
        }

        $anchor_start = (string) $series[0]['start_datetime'];
        $computed     = self::compute_dates( $anchor_start, $rule );

        $result = [];
        $used   = [];
        foreach ( $computed as $date ) {
            if ( isset( $existing[ $date ] ) ) {
                $result[]      = [ 'eventId' => $existing[ $date ], 'start' => $date, 'state' => 'exists' ];
                $used[ $date ] = true;
            } else {
                $result[] = [ 'eventId' => 0, 'start' => $date, 'state' => 'missing' ];
            }
        }
        // Existing events not covered by the rule → extra (candidates for deletion).
        foreach ( $series as $row ) {
            $start = (string) $row['start_datetime'];
            if ( empty( $used[ $start ] ) && ! self::in_computed( $start, $computed ) ) {
                $result[] = [ 'eventId' => (int) $row['id'], 'start' => $start, 'state' => 'extra' ];
            }
        }
        return $result;
    }

    private static function in_computed( string $date, array $computed ): bool {
        return in_array( $date, $computed, true );
    }

    /**
     * Compute the ordered list of start datetimes for a rule, beginning at the anchor.
     *
     * @return string[] Datetimes 'Y-m-d H:i:s' (always includes the anchor first).
     */
    public static function compute_dates( string $anchor_start, ?array $rule ): array {
        $tz  = wp_timezone();
        $idx = date_create_immutable_from_format( 'Y-m-d H:i:s', $anchor_start, $tz );
        if ( ! $idx ) {
            return [ $anchor_start ];
        }
        $dates = [ $idx->format( 'Y-m-d H:i:s' ) ];

        // A rule must terminate: either a count or an until-date. "Forever" is not allowed.
        if ( ! $rule ) {
            return $dates;
        }
        $occurrences = isset( $rule['occurrences'] ) ? (int) $rule['occurrences'] : 0;
        $until       = ! empty( $rule['until_date'] ) ? (string) $rule['until_date'] : '';
        if ( $occurrences < 1 && $until === '' ) {
            return $dates;
        }

        $limit    = $occurrences > 0 ? min( $occurrences, self::MAX_OCCURRENCES ) : self::MAX_OCCURRENCES;
        $horizon  = $idx->modify( '+' . self::MAX_HORIZON_YEARS . ' years' );
        $count    = 1;

        while ( $count < $limit ) {
            $next = self::find_next( $idx, $rule );
            if ( ! $next || $next > $horizon ) {
                break;
            }
            if ( $until !== '' && $next->format( 'Y-m-d' ) > $until ) {
                break;
            }
            $dates[] = $next->format( 'Y-m-d H:i:s' );
            $idx     = $next;
            $count++;
        }
        return $dates;
    }

    // ── Date math ─────────────────────────────────────────────────

    /**
     * Given the current occurrence, return the next one (strictly after), or null.
     */
    private static function find_next( DateTimeImmutable $dt, array $rule ): ?DateTimeImmutable {
        $interval = max( 1, (int) $rule['interval_n'] );
        switch ( (int) $rule['freq_type'] ) {
            case PCIO_VIS_Recurrence_DB::FREQ_DAY:
                return $dt->modify( "+{$interval} days" );

            case PCIO_VIS_Recurrence_DB::FREQ_WEEK:
                return self::next_week_hit( $dt, $interval, (int) $rule['weekdays_mask'] );

            case PCIO_VIS_Recurrence_DB::FREQ_MONTH:
                return self::nth_weekday_in_month(
                    self::first_of_month( $dt )->modify( "+{$interval} months" ),
                    (int) $rule['week_ordinal'],
                    (int) $rule['weekday'],
                    $dt
                );

            case PCIO_VIS_Recurrence_DB::FREQ_MONTHDAY:
                $base = self::first_of_month( $dt )->modify( "+{$interval} months" );
                return self::set_month_day( $base, (int) $rule['month_day'], $dt );

            case PCIO_VIS_Recurrence_DB::FREQ_YEARDAY:
                $year  = (int) $dt->format( 'Y' ) + $interval;
                $month = (int) $rule['month'];
                $base  = $dt->setDate( $year, $month, 1 );
                return self::set_month_day( $base, (int) $rule['month_day'], $dt );

            case PCIO_VIS_Recurrence_DB::FREQ_YEAR:
                $year  = (int) $dt->format( 'Y' ) + $interval;
                $month = (int) $rule['month'];
                $base  = $dt->setDate( $year, $month, 1 );
                return self::nth_weekday_in_month( $base, (int) $rule['week_ordinal'], (int) $rule['weekday'], $dt );
        }
        return null;
    }

    /** First day of $dt's month, time preserved. */
    private static function first_of_month( DateTimeImmutable $dt ): DateTimeImmutable {
        return $dt->setDate( (int) $dt->format( 'Y' ), (int) $dt->format( 'n' ), 1 );
    }

    /**
     * Set the day-of-month on $base (clamped to the month length), keeping $time's time-of-day.
     */
    private static function set_month_day( DateTimeImmutable $base, int $day, DateTimeImmutable $time ): DateTimeImmutable {
        $year   = (int) $base->format( 'Y' );
        $month  = (int) $base->format( 'n' );
        $length = (int) $base->format( 't' );
        $day    = max( 1, min( $day, $length ) );
        return $base->setDate( $year, $month, $day )
            ->setTime( (int) $time->format( 'H' ), (int) $time->format( 'i' ), (int) $time->format( 's' ) );
    }

    /**
     * The Nth (ordinal 1..4, 5=last) $weekday (1=Mon..7=Sun) in $base's month.
     * Time-of-day comes from $time.
     */
    private static function nth_weekday_in_month( DateTimeImmutable $base, int $ordinal, int $weekday, DateTimeImmutable $time ): ?DateTimeImmutable {
        if ( $weekday < 1 || $weekday > 7 || $ordinal < 1 || $ordinal > 5 ) {
            return null;
        }
        $year   = (int) $base->format( 'Y' );
        $month  = (int) $base->format( 'n' );
        $length = (int) $base->format( 't' );

        if ( $ordinal <= 4 ) {
            $first    = $base->setDate( $year, $month, 1 );
            $first_dw = (int) $first->format( 'N' );
            $offset   = ( $weekday - $first_dw + 7 ) % 7;
            $day      = 1 + $offset + ( $ordinal - 1 ) * 7;
            if ( $day > $length ) {
                return null; // e.g. no 5th requested — 4th max, but guard anyway
            }
        } else {
            $last    = $base->setDate( $year, $month, $length );
            $last_dw = (int) $last->format( 'N' );
            $offset  = ( $last_dw - $weekday + 7 ) % 7;
            $day     = $length - $offset;
        }
        return $base->setDate( $year, $month, $day )
            ->setTime( (int) $time->format( 'H' ), (int) $time->format( 'i' ), (int) $time->format( 's' ) );
    }

    /**
     * Next selected weekday, honouring the every-N-weeks interval.
     * Mask bit 0 = Monday … bit 6 = Sunday.
     */
    private static function next_week_hit( DateTimeImmutable $dt, int $interval, int $mask ): ?DateTimeImmutable {
        if ( $mask === 0 ) {
            return null;
        }
        $days = [];
        for ( $i = 0; $i < 7; $i++ ) {
            $days[ $i + 1 ] = (bool) ( $mask & ( 1 << $i ) ); // 1=Mon..7=Sun
        }
        $current = (int) $dt->format( 'N' );

        // Another selected day later this same week?
        for ( $d = $current + 1; $d <= 7; $d++ ) {
            if ( $days[ $d ] ) {
                return $dt->modify( '+' . ( $d - $current ) . ' days' );
            }
        }
        // Otherwise jump to the first selected day of a week $interval weeks ahead.
        $first = 0;
        for ( $d = 1; $d <= 7; $d++ ) {
            if ( $days[ $d ] ) {
                $first = $d;
                break;
            }
        }
        $offset = $current - $first;
        $add    = $interval * 7 - $offset;
        return $dt->modify( "+{$add} days" );
    }

    // ── Materialisation ───────────────────────────────────────────

    /**
     * Create the events missing from the series and delete the extras so the
     * physical events match the rule. Returns counts of what changed.
     *
     * @param int $group_id Series group ID.
     * @return array{created:int,deleted:int}
     */
    public static function reconcile( int $group_id ): array {
        $series = PCIO_VIS_Events_DB::get_series( $group_id );
        if ( empty( $series ) ) {
            return [ 'created' => 0, 'deleted' => 0 ];
        }
        $anchor     = $series[0];
        $projection = self::project( $group_id, $series );

        $created = 0;
        $deleted = 0;
        foreach ( $projection as $row ) {
            if ( $row['state'] === 'missing' ) {
                if ( self::clone_event( $anchor, $row['start'], $group_id ) ) {
                    $created++;
                }
            } elseif ( $row['state'] === 'extra' ) {
                if ( (int) $row['eventId'] !== (int) $anchor['id']
                    && PCIO_VIS_Events_DB::delete( (int) $row['eventId'] ) ) {
                    $deleted++;
                }
            }
        }
        return [ 'created' => $created, 'deleted' => $deleted ];
    }

    /**
     * Clone the anchor event onto a new start datetime and attach it to the series.
     * Copies core fields and resource assignments; never copies images, signups or tickets.
     *
     * @return int New event ID (0 on failure).
     */
    private static function clone_event( array $anchor, string $start, int $group_id ): int {
        $start_ts = strtotime( (string) $anchor['start_datetime'] );
        $end_ts   = ! empty( $anchor['end_datetime'] ) ? strtotime( (string) $anchor['end_datetime'] ) : 0;
        $duration = ( $end_ts && $start_ts && $end_ts > $start_ts ) ? ( $end_ts - $start_ts ) : 0;

        $new_start_ts = strtotime( $start );
        $end          = $duration > 0 ? gmdate( 'Y-m-d H:i:s', $new_start_ts + $duration ) : null;

        $new_id = PCIO_VIS_Events_DB::create( [
            'title'          => $anchor['title'] ?? '',
            'description'    => $anchor['description'] ?? '',
            'start_datetime' => $start,
            'end_datetime'   => $end,
            'all_day'        => $anchor['all_day'] ?? 0,
            'color'          => $anchor['color'] ?? '',
            'location'       => $anchor['location'] ?? '',
            'event_type_id'  => $anchor['event_type_id'] ?? null,
            'signup_mode'    => $anchor['signup_mode'] ?? 'simple',
        ] );
        if ( ! $new_id ) {
            return 0;
        }
        PCIO_VIS_Events_DB::set_recurrence_group( (int) $new_id, $group_id );

        // Copy resource assignments from the anchor.
        $res = PCIO_VIS_Resources_DB::get_for_event( (int) $anchor['id'] );
        if ( ! empty( $res ) ) {
            PCIO_VIS_Resources_DB::set_for_event(
                (int) $new_id,
                array_map( static fn( $r ) => (int) $r['id'], $res )
            );
        }
        return (int) $new_id;
    }

    /**
     * Detach every event in the series and drop the rule (make all non-recurring).
     */
    public static function resolve( int $group_id ): void {
        foreach ( PCIO_VIS_Events_DB::get_series( $group_id ) as $row ) {
            PCIO_VIS_Events_DB::set_recurrence_group( (int) $row['id'], null );
        }
        PCIO_VIS_Recurrence_DB::delete( $group_id );
    }

    /**
     * Delete every event in the series and drop the rule.
     */
    public static function delete_all( int $group_id ): int {
        $deleted = 0;
        foreach ( PCIO_VIS_Events_DB::get_series( $group_id ) as $row ) {
            if ( PCIO_VIS_Events_DB::delete( (int) $row['id'] ) ) {
                $deleted++;
            }
        }
        PCIO_VIS_Recurrence_DB::delete( $group_id );
        return $deleted;
    }

    /**
     * Delete every event in the series except $keep_id, and make $keep_id non-recurring.
     */
    public static function delete_others( int $group_id, int $keep_id ): int {
        $deleted = 0;
        foreach ( PCIO_VIS_Events_DB::get_series( $group_id ) as $row ) {
            if ( (int) $row['id'] === $keep_id ) {
                continue;
            }
            if ( PCIO_VIS_Events_DB::delete( (int) $row['id'] ) ) {
                $deleted++;
            }
        }
        PCIO_VIS_Events_DB::set_recurrence_group( $keep_id, null );
        PCIO_VIS_Recurrence_DB::delete( $group_id );
        return $deleted;
    }
}
