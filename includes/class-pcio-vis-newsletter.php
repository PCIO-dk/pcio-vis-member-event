<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders a newsletter mail (intro + upcoming-events list + footer) to HTML and
 * generates a PDF document stored in the WP media library.
 *
 * A "newsletter" is a mail flagged with is_newsletter = 1. Its `content` is the
 * intro text, `footer` is the closing section, and `event_max_date` limits how
 * far ahead events are included (default: end of next month).
 */
class PCIO_VIS_Newsletter {

    /**
     * Inline style declarations for the newsletter PDF template.
     *
     * The output of render_html() is handed straight to Dompdf (PCIO_VIS_PDF::render)
     * to produce a PDF file — it is never served to a browser, so there is no
     * wp_enqueue_style() context. Styles are applied inline (Dompdf's most reliable
     * mechanism) instead of an embedded stylesheet block.
     *
     * @var array<string,string>
     */
    private const PDF_STYLE = [
        'body'          => 'font-family:"DejaVu Sans",sans-serif;color:#1a1a1a;font-size:12px;margin:0;',
        'head'          => 'border-bottom:3px solid #0f172a;padding:0 0 10px;margin:0 0 16px;',
        'site'          => 'color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:1px;',
        'title'         => 'font-size:24px;font-weight:bold;margin:4px 0 0;color:#0f172a;',
        'intro'         => 'margin:0 0 18px;line-height:1.5;',
        'section_title' => 'font-size:15px;font-weight:bold;color:#0f172a;border-bottom:1px solid #cbd5e1;padding:0 0 4px;margin:0 0 12px;',
        'event'         => 'width:100%;border:1px solid #e2e8f0;border-radius:6px;margin:0 0 12px;page-break-inside:avoid;',
        'cell'          => 'vertical-align:top;padding:10px 12px;',
        'date'          => 'vertical-align:top;padding:10px 12px;width:64px;text-align:center;background:#0f172a;color:#fff;border-radius:6px 0 0 6px;',
        'date_day'      => 'font-size:22px;font-weight:bold;line-height:1;',
        'date_mon'      => 'font-size:11px;text-transform:uppercase;',
        'ev_title'      => 'font-size:14px;font-weight:bold;margin:0 0 3px;color:#0f172a;',
        'ev_meta'       => 'color:#475569;font-size:11px;margin:0 0 6px;',
        'ev_desc'       => 'color:#334155;line-height:1.45;',
        'empty'         => 'color:#64748b;font-style:italic;',
        'footer'        => 'margin:22px 0 0;padding:12px 0 0;border-top:1px solid #cbd5e1;color:#475569;line-height:1.5;font-size:11px;',
    ];

    /**
     * Compute the default event cut-off: the last day of next month.
     */
    public static function default_max_date(): string {
        $dt = new DateTime( current_time( 'Y-m-d' ) );
        $dt->modify( 'first day of next month' );
        $dt->modify( 'last day of this month' );
        return $dt->format( 'Y-m-d' );
    }

    /**
     * Upcoming events from today through the given max date (inclusive).
     */
    public static function events_for( string $max_date ): array {
        $start = current_time( 'Y-m-d' ) . ' 00:00:00';
        $end   = $max_date . ' 23:59:59';
        return PCIO_VIS_Events_DB::get_range( $start, $end );
    }

    /**
     * Generate a newsletter PDF for a mail and record it.
     *
     * @return array|WP_Error The created newsletter row, or an error.
     */
    public static function generate( int $mail_id ) {
        $mail = PCIO_VIS_Mails_DB::get( $mail_id );
        if ( ! $mail ) {
            return new WP_Error( 'not_found', __( 'Mail not found.', 'pcio-vis-member-event' ), [ 'status' => 404 ] );
        }

        if ( ! class_exists( 'PCIO_VIS_PDF' ) || ! PCIO_VIS_PDF::available() ) {
            return new WP_Error( 'pdf_unavailable', __( 'PDF library is not available.', 'pcio-vis-member-event' ), [ 'status' => 500 ] );
        }

        $max_date = ! empty( $mail['event_max_date'] ) ? (string) $mail['event_max_date'] : self::default_max_date();
        $events   = self::events_for( $max_date );

        $html = self::render_html( $mail, $events, $max_date );
        $pdf  = PCIO_VIS_PDF::render( $html, 'A4', 'portrait' );
        if ( ! is_string( $pdf ) || '' === $pdf ) {
            return new WP_Error( 'pdf_failed', __( 'Could not render the newsletter PDF.', 'pcio-vis-member-event' ), [ 'status' => 500 ] );
        }

        $title    = $mail['subject'] !== '' ? $mail['subject'] : __( 'Newsletter', 'pcio-vis-member-event' );
        $filename = self::filename( $title );

        $attachment_id = self::store_pdf( $pdf, $filename, $title );
        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        $id = PCIO_VIS_Newsletters_DB::create( [
            'mail_id'        => $mail_id,
            'title'          => $title,
            'attachment_id'  => $attachment_id,
            'event_max_date' => $max_date,
            'event_count'    => count( $events ),
        ] );
        if ( ! $id ) {
            return new WP_Error( 'db_failed', __( 'Could not save the newsletter record.', 'pcio-vis-member-event' ), [ 'status' => 500 ] );
        }

        return PCIO_VIS_Newsletters_DB::get( (int) $id );
    }

    // ── PDF storage (WP media library) ────────────────────────────

    /**
     * Write PDF bytes into the media library and return the attachment ID.
     *
     * @return int|WP_Error
     */
    private static function store_pdf( string $pdf, string $filename, string $title ) {
        $upload = wp_upload_bits( $filename, null, $pdf );
        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'upload_failed', $upload['error'], [ 'status' => 500 ] );
        }

        $attachment = [
            'post_mime_type' => 'application/pdf',
            'post_title'     => sanitize_text_field( $title ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
        if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
            return new WP_Error( 'attachment_failed', __( 'Could not create the media attachment.', 'pcio-vis-member-event' ), [ 'status' => 500 ] );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        if ( $meta ) {
            wp_update_attachment_metadata( $attachment_id, $meta );
        }

        return (int) $attachment_id;
    }

    private static function filename( string $title ): string {
        $slug = sanitize_title( $title );
        if ( '' === $slug ) {
            $slug = 'newsletter';
        }
        return $slug . '-' . current_time( 'Y-m-d' ) . '.pdf';
    }

    // ── HTML template ─────────────────────────────────────────────

    /**
     * Build the full newsletter HTML document (Dompdf-safe, inline CSS).
     */
    public static function render_html( array $mail, array $events, string $max_date ): string {
        $site    = get_bloginfo( 'name' );
        $title   = $mail['subject'] !== '' ? $mail['subject'] : $site;
        $intro   = (string) ( $mail['content'] ?? '' );
        $footer  = (string) ( $mail['footer'] ?? '' );

        ob_start();
        ?>
        <html>
        <head>
            <meta charset="utf-8">
        </head>
        <body style="<?php echo esc_attr( self::PDF_STYLE['body'] ); ?>">
            <div style="<?php echo esc_attr( self::PDF_STYLE['head'] ); ?>">
                <div style="<?php echo esc_attr( self::PDF_STYLE['site'] ); ?>"><?php echo esc_html( $site ); ?></div>
                <div style="<?php echo esc_attr( self::PDF_STYLE['title'] ); ?>"><?php echo esc_html( $title ); ?></div>
            </div>

            <?php if ( '' !== trim( wp_strip_all_tags( $intro ) ) ) : ?>
                <div style="<?php echo esc_attr( self::PDF_STYLE['intro'] ); ?>"><?php echo wp_kses_post( $intro ); ?></div>
            <?php endif; ?>

            <div style="<?php echo esc_attr( self::PDF_STYLE['section_title'] ); ?>"><?php esc_html_e( 'Upcoming events', 'pcio-vis-member-event' ); ?></div>

            <?php if ( empty( $events ) ) : ?>
                <p style="<?php echo esc_attr( self::PDF_STYLE['empty'] ); ?>"><?php esc_html_e( 'No upcoming events in this period.', 'pcio-vis-member-event' ); ?></p>
            <?php else : ?>
                <?php foreach ( $events as $event ) : ?>
                    <?php self::render_event( $event ); ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ( '' !== trim( wp_strip_all_tags( $footer ) ) ) : ?>
                <div style="<?php echo esc_attr( self::PDF_STYLE['footer'] ); ?>"><?php echo wp_kses_post( $footer ); ?></div>
            <?php endif; ?>
        </body>
        </html>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Render a single event card (echoes directly into the surrounding buffer).
     */
    private static function render_event( array $event ): void {
        $title = (string) ( $event['title'] ?? '' );
        $desc  = (string) ( $event['description'] ?? '' );
        $loc   = (string) ( $event['location'] ?? '' );
        $start = (string) ( $event['start_datetime'] ?? '' );
        $allday = ! empty( $event['all_day'] );

        $day = $mon = $when = '';
        $ts  = $start ? strtotime( $start ) : false;
        if ( $ts ) {
            $day = wp_date( 'j', $ts );
            $mon = wp_date( 'M', $ts );
            $when = $allday
                ? wp_date( get_option( 'date_format' ), $ts )
                : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
        }

        $meta_parts = [];
        if ( $when ) {
            $meta_parts[] = $when;
        }
        if ( '' !== $loc ) {
            $meta_parts[] = $loc;
        }

        ?>
        <table style="<?php echo esc_attr( self::PDF_STYLE['event'] ); ?>">
            <tr>
                <td style="<?php echo esc_attr( self::PDF_STYLE['date'] ); ?>">
                    <div style="<?php echo esc_attr( self::PDF_STYLE['date_day'] ); ?>"><?php echo esc_html( $day ); ?></div>
                    <div style="<?php echo esc_attr( self::PDF_STYLE['date_mon'] ); ?>"><?php echo esc_html( $mon ); ?></div>
                </td>
                <td style="<?php echo esc_attr( self::PDF_STYLE['cell'] ); ?>">
                    <div style="<?php echo esc_attr( self::PDF_STYLE['ev_title'] ); ?>"><?php echo esc_html( $title ); ?></div>
                    <?php if ( $meta_parts ) : ?>
                        <div style="<?php echo esc_attr( self::PDF_STYLE['ev_meta'] ); ?>"><?php echo esc_html( implode( ' · ', $meta_parts ) ); ?></div>
                    <?php endif; ?>
                    <?php if ( '' !== trim( wp_strip_all_tags( $desc ) ) ) : ?>
                        <div style="<?php echo esc_attr( self::PDF_STYLE['ev_desc'] ); ?>"><?php echo wp_kses_post( wpautop( $desc ) ); ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }
}
