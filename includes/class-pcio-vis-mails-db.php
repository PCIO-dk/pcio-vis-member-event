<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class PCIO_VIS_Mails_DB {

    const TABLE = 'me_mails';

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
            subject varchar(255) NOT NULL DEFAULT '',
            content longtext,
            attachments text NULL DEFAULT NULL,
            is_newsletter tinyint(1) NOT NULL DEFAULT 0,
            footer longtext NULL DEFAULT NULL,
            event_max_date date NULL DEFAULT NULL,
            mailtype varchar(50) NULL DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            sent_at datetime NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY sent_at (sent_at),
            KEY mailtype (mailtype)
        ) {$charset};";
        dbDelta( $sql );
    }

    // ── Queries ───────────────────────────────────────────────────

    /**
     * List view — excludes heavy content column.
     */
    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT `id`, `subject`, `is_newsletter`, `mailtype`, `created_at`, `sent_at`
                 FROM %i
                 ORDER BY `created_at` DESC',
                self::table()
            ),
            ARRAY_A
        ) ?: [];
    }

    /** Return the first row matching a mailtype slug, or null if not seeded yet. */
    public static function get_by_mailtype( string $type ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE `mailtype` = %s LIMIT 1', self::table(), $type ),
            ARRAY_A
        );
        if ( ! $row ) {
            return null;
        }
        $row['attachments'] = isset( $row['attachments'] )
            ? (array) json_decode( $row['attachments'], true )
            : [];
        return $row;
    }

    /** True when the row has a non-empty mailtype (i.e. is a system-managed template). */
    public static function is_system_mail( int $id ): bool {
        global $wpdb;
        $val = $wpdb->get_var(
            $wpdb->prepare( 'SELECT `mailtype` FROM %i WHERE `id` = %d', self::table(), $id )
        );
        return ! empty( $val );
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE `id` = %d', self::table(), $id ),
            ARRAY_A
        );
        if ( ! $row ) {
            return null;
        }
        $row['attachments'] = isset( $row['attachments'] )
            ? (array) json_decode( $row['attachments'], true )
            : [];
        return $row;
    }

    // ── CRUD ──────────────────────────────────────────────────────

    /** @return int|false */
    public static function create( array $data ) {
        global $wpdb;
        $result = $wpdb->insert( self::table(), self::sanitize_all( $data ) );
        return $result ? $wpdb->insert_id : false;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        $sanitized = self::sanitize_partial( $data );
        if ( empty( $sanitized ) ) {
            return true;
        }
        $result = $wpdb->update( self::table(), $sanitized, [ 'id' => $id ] );
        return $result !== false;
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    // ── Sanitization ──────────────────────────────────────────────

    private static function sanitize_all( array $data ): array {
        return [
            'subject'        => sanitize_text_field( $data['subject'] ?? '' ),
            'content'        => wp_kses_post(         $data['content'] ?? '' ),
            'attachments'    => self::encode_attachments( $data['attachments'] ?? [] ),
            'is_newsletter'  => ! empty( $data['is_newsletter'] ) ? 1 : 0,
            'footer'         => wp_kses_post( $data['footer'] ?? '' ),
            'event_max_date' => self::sanitize_date( $data['event_max_date'] ?? '' ),
            'mailtype'       => ! empty( $data['mailtype'] ) ? sanitize_key( $data['mailtype'] ) : null,
        ];
    }

    private static function sanitize_partial( array $data ): array {
        $result = [];
        $map = [
            'subject'        => fn( $v ) => sanitize_text_field( $v ),
            'content'        => fn( $v ) => wp_kses_post( $v ),
            'sent_at'        => fn( $v ) => empty( $v ) ? null : sanitize_text_field( $v ),
            'attachments'    => fn( $v ) => self::encode_attachments( $v ),
            'is_newsletter'  => fn( $v ) => ! empty( $v ) ? 1 : 0,
            'footer'         => fn( $v ) => wp_kses_post( $v ),
            'event_max_date' => fn( $v ) => self::sanitize_date( $v ),
            // mailtype is intentionally absent — it is immutable after creation.
        ];
        foreach ( $map as $field => $sanitizer ) {
            if ( array_key_exists( $field, $data ) ) {
                $result[ $field ] = $sanitizer( $data[ $field ] );
            }
        }
        return $result;
    }

    /**
     * Encode an array of integer attachment IDs to JSON.
     * Returns NULL when the array is empty so the column stays clean.
     */
    private static function encode_attachments( $ids ): ?string {
        $clean = array_values( array_filter(
            array_map( 'absint', (array) $ids ),
            fn( $id ) => $id > 0
        ) );
        return $clean ? wp_json_encode( $clean ) : null;
    }

    /**
     * Validate a YYYY-MM-DD date string; return null when empty/invalid.
     */
    private static function sanitize_date( $value ): ?string {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return null;
        }
        $d = DateTime::createFromFormat( 'Y-m-d', $value );
        return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $value : null;
    }
}
