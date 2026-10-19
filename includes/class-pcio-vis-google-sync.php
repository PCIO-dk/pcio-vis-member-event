<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Google Calendar sync job (port of the legacy /vis/sync_calendar.php).
 *
 * Runs as the "Sync Google Calendar" job under Vis → Sync Jobs. For every
 * calendar configured under Vis → Google:
 *
 *  1. Read the Vis events that belong in the calendar (start within ±1 year;
 *     'public' calendars only take homepage-approved events whose type has public access).
 *  2. List the Google events in the same window (all pages).
 *  3. Drop links whose Google event has disappeared, so the event is recreated.
 *  4. Delete Google events that no eligible Vis event links to.
 *  5. Create Google events for eligible Vis events without a link.
 *  6. Update Google events whose Vis data changed since the last sync.
 *
 * Change detection replaces the legacy shared dirty flag: each link stores a
 * hash of the payload last written to Google, so a failed update is retried
 * on the next run and every calendar is tracked independently.
 */
class PCIO_VIS_Google_Sync {

    const LOCK_OPTION  = 'pcio_me_google_sync_lock';
    const LOCK_TTL     = 15 * MINUTE_IN_SECONDS;
    const CLIP_TIME    = '22:00:00';
    // Google accepts up to 8192 characters; stay safely below it.
    const DESC_LENGTH  = 8000;
    const MAX_MESSAGES = 10;

    /** @var string[] Error messages collected during the run. */
    private array $messages = [];

    public static function init(): void {
        add_filter( 'pcio_me_sync_callables', [ __CLASS__, 'register_callable' ] );
        add_action( 'pcio_me_event_deleted', [ 'PCIO_VIS_Google_DB', 'delete_links_for_event' ] );
    }

    /**
     * Allow the Sync Jobs runner to execute this job.
     *
     * @param array<string,string> $callables Callable => label.
     * @return array<string,string>
     */
    public static function register_callable( array $callables ): array {
        $callables['PCIO_VIS_Google_Sync::run'] = __( 'Sync Google Calendar', 'pcio-vis-member-event' );
        return $callables;
    }

    /**
     * Sync-job entry point.
     *
     * @param array $job The me_sync row (unused).
     * @return string Human-readable result stored on the job.
     */
    public static function run( array $job = [] ): string {
        $key = PCIO_VIS_Google_DB::get_key();
        if ( '' === $key ) {
            return __( 'Error: no Google key configured (Vis → Google).', 'pcio-vis-member-event' );
        }

        $client = PCIO_VIS_Google_Client::from_json( $key );
        if ( is_wp_error( $client ) ) {
            /* translators: %s: error message */
            return sprintf( __( 'Error: %s', 'pcio-vis-member-event' ), $client->get_error_message() );
        }

        $calendars = PCIO_VIS_Google_DB::get_calendars();
        if ( ! $calendars ) {
            return __( 'Nothing to do: no Google calendars configured (Vis → Google).', 'pcio-vis-member-event' );
        }

        if ( ! self::acquire_lock() ) {
            return __( 'Skipped: a Google Calendar sync is already running.', 'pcio-vis-member-event' );
        }

        try {
            return ( new self() )->sync_all( $client, $calendars );
        } finally {
            delete_option( self::LOCK_OPTION );
        }
    }

    /**
     * Sync every calendar and build the summary.
     *
     * @param PCIO_VIS_Google_Client $client    Authenticated client.
     * @param array[]                $calendars Calendar rows.
     */
    private function sync_all( PCIO_VIS_Google_Client $client, array $calendars ): string {
        $tz    = wp_timezone();
        $now   = new DateTimeImmutable( 'now', $tz );
        $from  = $now->modify( '-1 year' );
        $to    = $now->modify( '+1 year' );
        $lines = [];

        foreach ( $calendars as $calendar ) {
            $counts  = $this->sync_calendar( $client, $calendar, $from, $to, $tz );
            $lines[] = sprintf(
                /* translators: 1: calendar name, 2: Vis events, 3: Google events, 4: added, 5: updated, 6: deleted, 7: relinked, 8: errors */
                __( '%1$s: Vis %2$d, Google %3$d | Added %4$d | Updated %5$d | Deleted %6$d | Re-linked %7$d | Errors %8$d', 'pcio-vis-member-event' ),
                $calendar['name'],
                $counts['vis'],
                $counts['google'],
                $counts['added'],
                $counts['updated'],
                $counts['deleted'],
                $counts['relinked'],
                $counts['errors']
            );
        }

        $summary = implode( '  ||  ', $lines );
        if ( $this->messages ) {
            $summary .= '  ||  ' . implode( '; ', array_slice( $this->messages, 0, self::MAX_MESSAGES ) );
        }
        return $summary;
    }

    /**
     * Sync one calendar (steps 1–6).
     *
     * @return array<string,int> Counters for the calendar.
     */
    private function sync_calendar( PCIO_VIS_Google_Client $client, array $calendar, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $tz ): array {
        $counts = [ 'vis' => 0, 'google' => 0, 'added' => 0, 'updated' => 0, 'deleted' => 0, 'relinked' => 0, 'errors' => 0 ];
        $ref    = (int) $calendar['id'];
        $gcal   = (string) $calendar['calendar_id'];
        $name   = (string) $calendar['name'];

        // 1: Vis events that belong in this calendar, with the payload Google should hold.
        $events = PCIO_VIS_Google_DB::get_eligible_events(
            (string) $calendar['scope'],
            $from->format( 'Y-m-d H:i:s' ),
            $to->format( 'Y-m-d H:i:s' )
        );
        $counts['vis'] = count( $events );

        $location   = PCIO_VIS_Google_DB::get_location();
        $clip_types = PCIO_VIS_Google_DB::get_clip_types();
        $payloads   = [];
        foreach ( $events as $event ) {
            $payloads[ (int) $event['id'] ] = self::build_payload( $event, $location, $clip_types, $tz );
        }

        // 2: Google events in the same window. Without a complete list nothing
        //    can be reconciled safely, so the calendar is skipped on failure.
        $google = $client->list_events( $gcal, $from->format( 'c' ), $to->modify( '+1 day' )->format( 'c' ) );
        if ( is_wp_error( $google ) ) {
            $this->add_message( $name, $google->get_error_message() );
            ++$counts['errors'];
            return $counts;
        }
        $counts['google'] = count( $google );

        $google_ids = [];
        foreach ( $google as $item ) {
            $google_ids[ (string) $item['id'] ] = $item;
        }

        // 3: Forget links whose Google event no longer exists, so it is recreated.
        $links = array_intersect_key( PCIO_VIS_Google_DB::get_links( $ref ), $payloads );
        foreach ( $links as $event_id => $link ) {
            if ( ! isset( $google_ids[ $link['google_event_id'] ] ) ) {
                PCIO_VIS_Google_DB::delete_link( $ref, $event_id );
                unset( $links[ $event_id ] );
                ++$counts['relinked'];
            }
        }

        // 4: Delete Google events no eligible Vis event links to.
        $referenced = array_flip( array_column( $links, 'google_event_id' ) );
        foreach ( $google_ids as $google_id => $item ) {
            if ( isset( $referenced[ $google_id ] ) ) {
                continue;
            }
            $result = $client->delete_event( $gcal, (string) $google_id );
            if ( is_wp_error( $result ) && ! self::is_gone( $result ) ) {
                $this->add_message( $name, sprintf( 'delete "%s": %s', $item['summary'] ?? $google_id, $result->get_error_message() ) );
                ++$counts['errors'];
                continue;
            }
            PCIO_VIS_Google_DB::delete_links_by_google_id( $ref, (string) $google_id );
            ++$counts['deleted'];
        }

        // 5 + 6: Create missing events and update changed ones.
        foreach ( $payloads as $event_id => $payload ) {
            $hash = md5( (string) wp_json_encode( $payload ) );

            if ( ! isset( $links[ $event_id ] ) ) {
                $created = $client->insert_event( $gcal, $payload );
                if ( is_wp_error( $created ) || empty( $created['id'] ) ) {
                    $this->add_message( $name, sprintf( 'create "%s": %s', $payload['summary'], is_wp_error( $created ) ? $created->get_error_message() : 'no id' ) );
                    ++$counts['errors'];
                    continue;
                }
                PCIO_VIS_Google_DB::save_link( $ref, $event_id, (string) $created['id'], $hash );
                ++$counts['added'];
                continue;
            }

            if ( $links[ $event_id ]['synced_hash'] === $hash ) {
                continue;
            }

            $google_id = (string) $links[ $event_id ]['google_event_id'];
            $updated   = $client->update_event( $gcal, $google_id, $payload );
            if ( is_wp_error( $updated ) ) {
                if ( self::is_gone( $updated ) ) {
                    // Removed in Google meanwhile: recreate on the next run.
                    PCIO_VIS_Google_DB::delete_link( $ref, $event_id );
                }
                $this->add_message( $name, sprintf( 'update "%s": %s', $payload['summary'], $updated->get_error_message() ) );
                ++$counts['errors'];
                continue;
            }
            PCIO_VIS_Google_DB::save_link( $ref, $event_id, $google_id, $hash );
            ++$counts['updated'];
        }

        return $counts;
    }

    /**
     * Build the Google event resource for a Vis event.
     *
     * @param array        $event      me_events row.
     * @param string       $location   Fallback location.
     * @param int[]        $clip_types Event type IDs whose end is cut to 22:00 on the start day.
     * @param DateTimeZone $tz         Site timezone (Vis datetimes are stored in site time).
     * @return array
     */
    public static function build_payload( array $event, string $location, array $clip_types, DateTimeZone $tz ): array {
        $start   = new DateTimeImmutable( (string) $event['start_datetime'], $tz );
        $end_raw = (string) ( $event['end_datetime'] ?? '' );
        $end     = '' !== $end_raw ? new DateTimeImmutable( $end_raw, $tz ) : null;
        $clip    = in_array( (int) ( $event['event_type_id'] ?? 0 ), $clip_types, true );

        $payload = [
            'summary'     => trim( (string) $event['title'] ),
            'description' => self::event_text( $event ),
            'location'    => '' !== trim( (string) ( $event['location'] ?? '' ) ) ? trim( (string) $event['location'] ) : $location,
        ];

        if ( (int) ( $event['all_day'] ?? 0 ) ) {
            // All-day: Google's end date is exclusive.
            $last = ( $clip || ! $end || $end < $start ) ? $start : $end;
            $payload['start'] = [ 'date' => $start->format( 'Y-m-d' ) ];
            $payload['end']   = [ 'date' => $last->modify( '+1 day' )->format( 'Y-m-d' ) ];
            return $payload;
        }

        if ( $clip ) {
            // Avoid long-running events (e.g. exhibitions) filling the whole calendar.
            $end = new DateTimeImmutable( $start->format( 'Y-m-d' ) . ' ' . self::CLIP_TIME, $tz );
        }
        if ( ! $end || $end <= $start ) {
            $end = $start->modify( '+1 hour' );
        }

        $payload['start'] = self::google_datetime( $start );
        $payload['end']   = self::google_datetime( $end );
        return $payload;
    }

    /**
     * Google dateTime object; the offset in the ISO string carries DST, and the
     * IANA zone name is added when the site uses one.
     */
    private static function google_datetime( DateTimeImmutable $dt ): array {
        $out  = [ 'dateTime' => $dt->format( 'c' ) ];
        $zone = $dt->getTimezone()->getName();
        if ( str_contains( $zone, '/' ) || 'UTC' === $zone ) {
            $out['timeZone'] = $zone;
        }
        return $out;
    }

    /**
     * The full description when it fits Google's limit; otherwise the shorter resume,
     * falling back to a shortened description when there is no resume.
     */
    private static function event_text( array $event ): string {
        $description = self::plain_text( (string) ( $event['description'] ?? '' ) );
        if ( '' !== $description && mb_strlen( $description ) <= self::DESC_LENGTH ) {
            return $description;
        }
        $resume = self::plain_text( (string) ( $event['resume'] ?? '' ) );
        return self::limit( '' !== $resume ? $resume : $description );
    }

    /** Plain text from the event's rich-text HTML, keeping paragraph breaks. */
    private static function plain_text( string $html ): string {
        $text = preg_replace( '#<br\s*/?>#i', "\n", $html );
        $text = preg_replace( '#</(p|div|li|h[1-6])\s*>#i', "\n\n", (string) $text );
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/[^\S\n]+/u', ' ', $text );
        $text = trim( (string) preg_replace( '/ *\n */u', "\n", (string) $text ) );
        return (string) preg_replace( '/\n{3,}/', "\n\n", $text );
    }

    /** Cut text to Google's description limit at a word boundary. */
    private static function limit( string $text ): string {
        if ( mb_strlen( $text ) <= self::DESC_LENGTH ) {
            return $text;
        }
        $cut   = mb_substr( $text, 0, self::DESC_LENGTH - 1 );
        $space = mb_strrpos( $cut, ' ' );
        return rtrim( false !== $space ? mb_substr( $cut, 0, $space ) : $cut ) . '…';
    }

    /** Whether Google reported the event as already gone (404/410). */
    private static function is_gone( WP_Error $error ): bool {
        $data = $error->get_error_data();
        return is_array( $data ) && in_array( (int) ( $data['status'] ?? 0 ), [ 404, 410 ], true );
    }

    private function add_message( string $calendar, string $message ): void {
        $this->messages[] = $calendar . ': ' . $message;
    }

    /**
     * Prevent overlapping runs (cron + "Run now"), which could create duplicates.
     * add_option() is atomic on the unique option_name; a stale lock is taken over.
     */
    private static function acquire_lock(): bool {
        if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
            return true;
        }
        $since = (int) get_option( self::LOCK_OPTION, 0 );
        if ( time() - $since < self::LOCK_TTL ) {
            return false;
        }
        update_option( self::LOCK_OPTION, time(), false );
        return true;
    }
}
