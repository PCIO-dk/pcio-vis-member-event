<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Storage for generated newsletter PDFs.
 *
 * Each row records one generated newsletter document: the mail it was produced
 * from, the WP media attachment holding the PDF, and a snapshot of the event
 * window / count at generation time.
 */
class PCIO_VIS_Newsletters_DB {

    const TABLE = 'me_newsletters';

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
            mail_id int NULL DEFAULT NULL,
            title varchar(255) NOT NULL DEFAULT '',
            attachment_id bigint unsigned NULL DEFAULT NULL,
            event_max_date date NULL DEFAULT NULL,
            event_count int NOT NULL DEFAULT 0,
            generated_at datetime DEFAULT CURRENT_TIMESTAMP,
            generated_by bigint unsigned NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY mail_id (mail_id),
            KEY generated_at (generated_at)
        ) {$charset};";
        dbDelta( $sql );
    }

    // ── Queries ───────────────────────────────────────────────────

    /**
     * Newsletters for the admin list, decorated with the PDF URL + mail subject.
     */
    public static function get_all(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i ORDER BY `generated_at` DESC', self::table() ),
            ARRAY_A
        ) ?: [];
        return array_map( [ __CLASS__, 'decorate' ], $rows );
    }

    /**
     * Most recent newsletters that still have a valid PDF attachment (for the shortcode).
     */
    public static function get_recent( int $limit = 12 ): array {
        global $wpdb;
        $limit = max( 1, $limit );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i
                 WHERE `attachment_id` IS NOT NULL
                 ORDER BY `generated_at` DESC
                 LIMIT %d',
                self::table(),
                $limit
            ),
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ( $rows as $row ) {
            $url = wp_get_attachment_url( (int) $row['attachment_id'] );
            if ( ! $url ) {
                continue;
            }
            $out[] = self::decorate( $row );
        }
        return $out;
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE `id` = %d', self::table(), $id ),
            ARRAY_A
        );
        return $row ? self::decorate( $row ) : null;
    }

    // ── CRUD ──────────────────────────────────────────────────────

    /** @return int|false */
    public static function create( array $data ) {
        global $wpdb;
        $result = $wpdb->insert(
            self::table(),
            [
                'mail_id'        => isset( $data['mail_id'] ) ? absint( $data['mail_id'] ) : null,
                'title'          => sanitize_text_field( $data['title'] ?? '' ),
                'attachment_id'  => isset( $data['attachment_id'] ) ? absint( $data['attachment_id'] ) : null,
                'event_max_date' => $data['event_max_date'] ?? null,
                'event_count'    => absint( $data['event_count'] ?? 0 ),
                'generated_by'   => get_current_user_id() ?: null,
            ]
        );
        return $result ? $wpdb->insert_id : false;
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    // ── Helpers ───────────────────────────────────────────────────

    private static function decorate( array $row ): array {
        $row['id']            = (int) $row['id'];
        $row['mail_id']       = isset( $row['mail_id'] ) ? (int) $row['mail_id'] : null;
        $row['attachment_id'] = isset( $row['attachment_id'] ) ? (int) $row['attachment_id'] : null;
        $row['event_count']   = (int) $row['event_count'];
        $row['url']           = $row['attachment_id'] ? ( wp_get_attachment_url( $row['attachment_id'] ) ?: '' ) : '';
        return $row;
    }
}
