<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Applies a personal calendar filter (see PCIO_VIS_Calendar_Filter_DB) to a
 * list of event rows. Every enabled rule must pass; a rule marked "exclude"
 * hides the matching events instead of showing them.
 */
class PCIO_VIS_Calendar_Filter {

    /**
     * Filter events for a user. Without a saved filter the events are returned untouched.
     *
     * @param array[] $events  Event rows (ARRAY_A).
     * @param int     $user_id WordPress user ID.
     * @return array[]
     */
    public static function apply_for_user( array $events, int $user_id ): array {
        $filter = PCIO_VIS_Calendar_Filter_DB::get( $user_id );
        if ( null === $filter || ! $events ) {
            return $events;
        }
        $crew = self::needs_crew( $filter ) ? self::load_crew( array_column( $events, 'id' ) ) : [];
        return self::apply( $events, $filter, $crew );
    }

    /**
     * @param array[] $events Event rows.
     * @param array   $filter Normalized filter settings.
     * @param array   $crew   event_id => list of [ 'member_id' => int, 'role_id' => int ].
     * @return array[]
     */
    public static function apply( array $events, array $filter, array $crew ): array {
        $kept = array_filter(
            $events,
            static function ( array $event ) use ( $filter, $crew ): bool {
                $event_crew = $crew[ (int) $event['id'] ] ?? [];
                foreach ( self::matchers() as $rule => $matcher ) {
                    $settings = $filter[ $rule ] ?? [];
                    if ( empty( $settings['on'] ) ) {
                        continue;
                    }
                    if ( $matcher( $event, $settings, $event_crew ) === ! empty( $settings['exclude'] ) ) {
                        return false;
                    }
                }
                return true;
            }
        );
        return array_values( $kept );
    }

    /** Whether any enabled rule depends on crew assignments. */
    private static function needs_crew( array $filter ): bool {
        return ! empty( $filter['person']['on'] ) || ! empty( $filter['roles']['on'] );
    }

    /** @return array<string, callable(array, array, array): bool> */
    private static function matchers(): array {
        return [
            'date'      => [ self::class, 'match_date' ],
            'text'      => [ self::class, 'match_text' ],
            'person'    => static fn( $e, $s, $c ) => in_array( (int) $s['member_id'], array_column( $c, 'member_id' ), true ),
            'published' => static fn( $e ) => 1 === (int) ( $e['publish_homepage'] ?? 0 ),
            'types'     => static fn( $e, $s ) => in_array( (int) ( $e['event_type_id'] ?? 0 ), $s['ids'], true ),
            'roles'     => static fn( $e, $s, $c ) => (bool) array_intersect( $s['ids'], array_column( $c, 'role_id' ) ),
        ];
    }

    /** True when the event overlaps the [from, to] day range; empty bounds are open. */
    public static function match_date( array $event, array $settings ): bool {
        $start = (string) $event['start_datetime'];
        $end   = (string) ( $event['end_datetime'] ?: $start );
        if ( '' !== $settings['from'] && $end < $settings['from'] . ' 00:00:00' ) {
            return false;
        }
        return '' === $settings['to'] || $start <= $settings['to'] . ' 23:59:59';
    }

    /** Case-insensitive search in title, resume and description. */
    public static function match_text( array $event, array $settings ): bool {
        $needle = trim( (string) $settings['value'] );
        if ( '' === $needle ) {
            return true;
        }
        $haystack = implode( ' ', [ $event['title'] ?? '', $event['resume'] ?? '', wp_strip_all_tags( (string) ( $event['description'] ?? '' ) ) ] );
        return false !== mb_stripos( $haystack, $needle );
    }

    /** Rows fetched per step when looking for the neighbouring event. */
    private const STEP = 50;

    /** Maximum number of steps (STEP × MAX_STEPS events) searched in each direction. */
    private const MAX_STEPS = 40;

    /**
     * Previous and next event around $event in time order, honouring the user's filter.
     *
     * @param array $event          Current event row.
     * @param int   $user_id        WordPress user ID.
     * @param bool  $published_only Only consider events published on the homepage.
     * @return array{prev: ?array, next: ?array}
     */
    public static function neighbours( array $event, int $user_id, bool $published_only ): array {
        $filter = PCIO_VIS_Calendar_Filter_DB::get( $user_id );
        $fetch  = static fn( string $start, int $id, bool $forward ) =>
            PCIO_VIS_Events_DB::get_adjacent( $start, $id, $forward, $published_only, self::STEP );
        return [
            'prev' => self::find_adjacent( $event, false, $filter, $fetch, [ self::class, 'load_crew' ] ),
            'next' => self::find_adjacent( $event, true, $filter, $fetch, [ self::class, 'load_crew' ] ),
        ];
    }

    /**
     * Nearest event before/after $event that passes $filter.
     *
     * @param array      $event     Current event row.
     * @param bool       $forward   Search later (true) or earlier (false) events.
     * @param array|null $filter    Normalized filter, or null for the default view.
     * @param callable   $fetch     fn( string $start, int $id, bool $forward ): array[] — nearest rows first.
     * @param callable   $load_crew fn( int[] $event_ids ): array — see load_crew().
     */
    public static function find_adjacent( array $event, bool $forward, ?array $filter, callable $fetch, callable $load_crew ): ?array {
        $start = (string) $event['start_datetime'];
        $id    = (int) $event['id'];
        for ( $step = 0; $step < self::MAX_STEPS; $step++ ) {
            $rows = $fetch( $start, $id, $forward );
            if ( ! $rows ) {
                return null;
            }
            $kept = $rows;
            if ( null !== $filter ) {
                $crew = self::needs_crew( $filter ) ? $load_crew( array_column( $rows, 'id' ) ) : [];
                $kept = self::apply( $rows, $filter, $crew );
            }
            if ( $kept ) {
                return $kept[0];
            }
            $last  = end( $rows );
            $start = (string) $last['start_datetime'];
            $id    = (int) $last['id'];
        }
        return null;
    }

    /**
     * Crew assignments for many events in one query.
     *
     * @param array $event_ids Event IDs.
     * @return array event_id => list of [ 'member_id' => int, 'role_id' => int ].
     */
    private static function load_crew( array $event_ids ): array {
        global $wpdb;
        $ids = array_values( array_unique( array_map( 'absint', $event_ids ) ) );
        if ( ! $ids ) {
            return [];
        }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a list of %d placeholders; live crew data must not be cached.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_id, member_id, role_id FROM %i WHERE event_id IN ({$placeholders})",
                PCIO_VIS_Event_Crew_DB::table(),
                ...$ids
            ),
            ARRAY_A
        ) ?: [];
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        $crew = [];
        foreach ( $rows as $row ) {
            $crew[ (int) $row['event_id'] ][] = [ 'member_id' => (int) $row['member_id'], 'role_id' => (int) $row['role_id'] ];
        }
        return $crew;
    }
}
