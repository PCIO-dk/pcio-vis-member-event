<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Daily "you have a crew shift tomorrow" notification.
 *
 * The editable crew_shift_reminder system mail lives in Vis → Mails. The
 * built-in sync job (cron_name pcio_me_crew_shift_reminder) calls run(), which
 * mails every member assigned to a crew shift that starts tomorrow (site time).
 * Sent shifts are remembered, so a job that runs more than once a day, or a
 * manual "Run now", never repeats a reminder. A shift that is moved or handed
 * to another member is reminded again.
 */
class PCIO_VIS_Crew_Reminder {

    const MAIL_TYPE   = 'crew_shift_reminder';
    const CRON_NAME   = 'pcio_me_crew_shift_reminder';
    const SENT_OPTION = 'pcio_vis_crew_reminder_sent';
    const SEED_OPTION = 'pcio_vis_crew_reminder_mail_seeded';

    public function register(): void {
        add_filter( 'pcio_me_mail_tags', [ $this, 'register_mail_tags' ], 10, 2 );
    }

    /**
     * Seed the crew_shift_reminder system mail if it does not exist yet.
     * Called on admin_init via class-pcio-vis-plugin.php and before each run.
     */
    public static function seed_mail(): void {
        if ( get_option( self::SEED_OPTION ) ) {
            return;
        }
        if ( ! PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE ) ) {
            PCIO_VIS_Mails_DB::create( [
                'subject'  => __( 'Reminder: your shift at {event_title} tomorrow', 'pcio-vis-member-event' ),
                'content'  =>
                    '<p>' . __( 'Hi {name},', 'pcio-vis-member-event' ) . '</p>'
                    . '<p>' . __( 'This is a reminder that you are on the crew for <strong>{event_title}</strong> tomorrow as {role_name}.', 'pcio-vis-member-event' ) . '</p>'
                    . '<p>' . __( 'Your shift starts <strong>{shift_start}</strong> and ends <strong>{shift_end}</strong>.', 'pcio-vis-member-event' ) . '</p>'
                    . '<p>' . __( 'Thank you for helping out!', 'pcio-vis-member-event' ) . '</p>'
                    . '<p>{site_name}</p>',
                'mailtype' => self::MAIL_TYPE,
            ] );
        }
        if ( PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE ) ) {
            update_option( self::SEED_OPTION, '1' );
        }
    }

    /**
     * Register tag labels for the crew_shift_reminder mailtype in the mail editor.
     * Filter: pcio_me_mail_tags
     *
     * @param array  $tags     Existing tag descriptors.
     * @param string $mailtype Mailtype of the mail being edited.
     * @return array
     */
    public function register_mail_tags( array $tags, string $mailtype ): array {
        if ( self::MAIL_TYPE !== $mailtype ) {
            return $tags;
        }
        return array_merge( $tags, [
            [ 'tag' => '{name}',        'label' => __( 'Volunteer name', 'pcio-vis-member-event' ) ],
            [ 'tag' => '{event_title}', 'label' => __( 'Event title', 'pcio-vis-member-event' ) ],
            [ 'tag' => '{shift_start}', 'label' => __( 'Shift start (date and time)', 'pcio-vis-member-event' ) ],
            [ 'tag' => '{shift_end}',   'label' => __( 'Shift end', 'pcio-vis-member-event' ) ],
            [ 'tag' => '{role_name}',   'label' => __( 'Crew role', 'pcio-vis-member-event' ) ],
            [ 'tag' => '{site_name}',   'label' => __( 'Site name', 'pcio-vis-member-event' ) ],
        ] );
    }

    /**
     * Sync job entry point: mail everyone with a crew shift starting tomorrow.
     *
     * @param array $job Sync table row (unused).
     * @return string Human-readable result summary.
     */
    public static function run( array $job = [] ): string {
        self::seed_mail();
        $template = PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE );
        if ( ! $template ) {
            return __( 'Crew reminders: the system mail is missing.', 'pcio-vis-member-event' );
        }

        $tomorrow = current_datetime()->setTime( 0, 0 )->modify( '+1 day' );
        $shifts   = PCIO_VIS_Event_Crew_DB::get_assigned_starting_between(
            $tomorrow->format( 'Y-m-d H:i:s' ),
            $tomorrow->modify( '+1 day' )->format( 'Y-m-d H:i:s' )
        );

        $sent_log = self::load_sent_log();
        $sent     = 0;
        $skipped  = 0;
        $failed   = 0;
        foreach ( $shifts as $shift ) {
            $key = self::shift_key( $shift );
            if ( ( $sent_log[ (int) $shift['id'] ]['key'] ?? '' ) === $key ) {
                ++$skipped;
                continue;
            }
            $to = sanitize_email( (string) $shift['member_email'] );
            if ( ! is_email( $to ) ) {
                ++$skipped;
                continue;
            }
            if ( ! self::send( $template, $shift, $to ) ) {
                ++$failed;
                continue;
            }
            $sent_log[ (int) $shift['id'] ] = [ 'key' => $key, 'until' => (string) $shift['end_datetime'] ];
            ++$sent;
        }
        update_option( self::SENT_OPTION, $sent_log, false );

        return sprintf(
            /* translators: 1: date of the shifts, 2: reminders sent, 3: shifts skipped (already reminded or no valid email), 4: failed sends */
            __( 'Shifts on %1$s  |  Sent: %2$d  |  Skipped: %3$d  |  Failed: %4$d', 'pcio-vis-member-event' ),
            wp_date( get_option( 'date_format' ), $tomorrow->getTimestamp() ),
            $sent,
            $skipped,
            $failed
        );
    }

    /**
     * Render the reminder for one shift.
     *
     * @param array $template crew_shift_reminder mail row.
     * @param array $shift    Row from PCIO_VIS_Event_Crew_DB::get_assigned_starting_between().
     * @return array{subject:string,content:string}
     */
    public static function render( array $template, array $shift ): array {
        $plain = [
            '{name}'        => (string) $shift['member_name'],
            '{event_title}' => (string) $shift['event_title'],
            '{shift_start}' => self::format_datetime( (string) $shift['start_datetime'] ),
            '{shift_end}'   => self::format_end( (string) $shift['start_datetime'], (string) $shift['end_datetime'] ),
            '{role_name}'   => (string) ( $shift['role_name'] ?? '' ),
            '{site_name}'   => (string) get_bloginfo( 'name' ),
        ];
        $html = array_map( 'esc_html', $plain );

        return [
            'subject' => wp_strip_all_tags( strtr( (string) $template['subject'], $plain ) ),
            'content' => strtr( (string) $template['content'], $html ),
        ];
    }

    /** Send one rendered reminder through the configured mailer, falling back to wp_mail. */
    private static function send( array $template, array $shift, string $to ): bool {
        $mail   = self::render( $template, $shift );
        $result = apply_filters(
            'pcio_me_mailer_send',
            null,
            [ 'subject' => $mail['subject'], 'content' => $mail['content'], 'attachments' => [] ],
            [ [ 'email' => $to, 'name' => (string) $shift['member_name'] ] ],
            []
        );
        if ( null !== $result && ! is_wp_error( $result ) ) {
            return true;
        }
        return wp_mail( $to, $mail['subject'], $mail['content'], [ 'Content-Type: text/html; charset=UTF-8' ] );
    }

    /** Identity of a reminder: a new member or new times means a new reminder. */
    private static function shift_key( array $shift ): string {
        return md5( $shift['member_id'] . '|' . $shift['start_datetime'] . '|' . $shift['end_datetime'] );
    }

    /** Previously sent reminders, without entries for shifts that have already ended. */
    private static function load_sent_log(): array {
        $log = get_option( self::SENT_OPTION, [] );
        if ( ! is_array( $log ) ) {
            return [];
        }
        $now = current_time( 'mysql' );
        return array_filter(
            $log,
            static fn( $entry ) => is_array( $entry ) && (string) ( $entry['until'] ?? '' ) >= $now
        );
    }

    /** Format a site-time 'Y-m-d H:i:s' value with the site date and time formats. */
    private static function format_datetime( string $value ): string {
        return self::format( $value, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
    }

    /** Shift end: time only when it ends on the start day, otherwise date and time. */
    private static function format_end( string $start, string $end ): string {
        if ( substr( $start, 0, 10 ) === substr( $end, 0, 10 ) ) {
            return self::format( $end, (string) get_option( 'time_format' ) );
        }
        return self::format_datetime( $end );
    }

    private static function format( string $value, string $format ): string {
        $date = date_create_immutable( $value, wp_timezone() );
        return $date ? (string) wp_date( $format, $date->getTimestamp() ) : '';
    }
}
