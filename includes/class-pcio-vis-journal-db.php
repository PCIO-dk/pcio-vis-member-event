<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom admin table; caching would hide real-time changes.

/**
 * Finance journal ("Kasseklade") data layer.
 *
 * A single flat journal of cash transactions. Amounts are stored as integer øre
 * (amount_cents); a negative amount means money paid out. The running balance
 * (saldo) is NOT stored — it is computed in chronological order and attached to
 * each row by list_all().
 *
 * Extensions (e.g. the Tickets plugin) write rows via the static record() helper,
 * tagging them with a source + source_ref so duplicates can be detected.
 */
class PCIO_VIS_Journal_DB {

    const TABLE = 'me_finance_journal';

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
            entry_date datetime NOT NULL,
            amount_cents int NOT NULL DEFAULT 0,
            title varchar(200) NOT NULL DEFAULT '',
            member_name varchar(200) NOT NULL DEFAULT '',
            member_number varchar(50) NOT NULL DEFAULT '',
            media_id bigint unsigned NULL DEFAULT NULL,
            verified tinyint(1) NOT NULL DEFAULT 0,
            source varchar(50) NOT NULL DEFAULT 'manual',
            source_ref varchar(100) NOT NULL DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY entry_date (entry_date),
            KEY source_ref (source, source_ref)
        ) {$charset};";
        dbDelta( $sql );
    }

    // ── Queries ───────────────────────────────────────────────────

    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE `id` = %d', self::table(), $id ),
            ARRAY_A
        );
        return $row ? self::shape( $row ) : null;
    }

    /**
     * Return all journal rows, newest first, with a running balance (saldo)
     * attached to each row. The balance is computed in chronological order
     * (oldest → newest) so it is stable regardless of how the client sorts.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function list_all(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i ORDER BY `entry_date` ASC, `id` ASC', self::table() ),
            ARRAY_A
        ) ?: [];

        $balance = 0;
        $out     = [];
        foreach ( $rows as $row ) {
            $shaped                  = self::shape( $row );
            $balance                += (int) $shaped['amount_cents'];
            $shaped['balance_cents'] = $balance;
            $out[]                   = $shaped;
        }
        // Newest first for display.
        return array_reverse( $out );
    }

    /**
     * Whether a row already exists for a given source + reference. Lets
     * extensions avoid writing duplicate rows on webhook retries.
     */
    public static function exists_ref( string $source, string $source_ref ): bool {
        global $wpdb;
        if ( '' === $source_ref ) {
            return false;
        }
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE `source` = %s AND `source_ref` = %s',
                self::table(),
                $source,
                $source_ref
            )
        );
        return $count > 0;
    }

    // ── CRUD ──────────────────────────────────────────────────────

    /** @return int|false */
    public static function create( array $data ) {
        global $wpdb;
        $insert = self::sanitize_all( $data );
        $result = $wpdb->insert( self::table(), $insert );
        if ( ! $result ) {
            return false;
        }
        $id = (int) $wpdb->insert_id;
        /**
         * Action: pcio_me_journal_saved
         *
         * @param int   $id     Journal entry ID.
         * @param array $data   Raw submitted data.
         * @param bool  $is_new True when just created.
         */
        do_action( 'pcio_me_journal_saved', $id, $data, true );
        return $id;
    }

    /** Partial update — only fields present in $data are changed. */
    public static function update( int $id, array $data ): bool {
        global $wpdb;
        $sanitized = self::sanitize_partial( $data );
        if ( empty( $sanitized ) ) {
            return true;
        }
        $result = $wpdb->update( self::table(), $sanitized, [ 'id' => $id ] );
        if ( $result === false ) {
            return false;
        }
        do_action( 'pcio_me_journal_saved', $id, $data, false );
        return true;
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        $ok = (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
        if ( $ok ) {
            /**
             * Action: pcio_me_journal_deleted
             *
             * @param int $id Deleted journal entry ID.
             */
            do_action( 'pcio_me_journal_deleted', $id );
        }
        return $ok;
    }

    /**
     * Record a journal row from an extension (e.g. the Tickets plugin).
     *
     * Deduplicates on source + source_ref: if a row with the same reference
     * already exists, nothing is written and the existing behaviour is a no-op.
     *
     * @param array{
     *   entry_date?:string, amount_cents:int, title?:string, member_name?:string,
     *   member_number?:string, media_id?:int, source?:string, source_ref?:string
     * } $data
     * @return int|false New row ID, or false on failure / duplicate.
     */
    public static function record( array $data ) {
        $source     = isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : 'manual';
        $source_ref = isset( $data['source_ref'] ) ? sanitize_text_field( (string) $data['source_ref'] ) : '';
        if ( '' !== $source_ref && self::exists_ref( $source, $source_ref ) ) {
            return false;
        }
        return self::create( $data );
    }

    // ── Shaping & sanitization ────────────────────────────────────

    /**
     * Normalise a raw DB row: cast types and resolve the media URL.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function shape( array $row ): array {
        $media_id  = isset( $row['media_id'] ) && $row['media_id'] !== null ? (int) $row['media_id'] : 0;
        $media_url = $media_id ? (string) wp_get_attachment_url( $media_id ) : '';
        return [
            'id'            => (int) $row['id'],
            'entry_date'    => (string) $row['entry_date'],
            'amount_cents'  => (int) $row['amount_cents'],
            'title'         => (string) $row['title'],
            'member_name'   => (string) $row['member_name'],
            'member_number' => (string) $row['member_number'],
            'media_id'      => $media_id,
            'media_url'     => $media_url,
            'verified'      => ! empty( $row['verified'] ) ? 1 : 0,
            'source'        => (string) $row['source'],
            'source_ref'    => (string) $row['source_ref'],
            'created_at'    => (string) ( $row['created_at'] ?? '' ),
            'updated_at'    => (string) ( $row['updated_at'] ?? '' ),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function sanitize_all( array $data ): array {
        return [
            'entry_date'    => self::sanitize_datetime( $data['entry_date'] ?? '' ),
            'amount_cents'  => self::sanitize_amount( $data['amount_cents'] ?? 0 ),
            'title'         => sanitize_text_field( $data['title']         ?? '' ),
            'member_name'   => sanitize_text_field( $data['member_name']   ?? '' ),
            'member_number' => sanitize_text_field( $data['member_number'] ?? '' ),
            'media_id'      => ! empty( $data['media_id'] ) ? absint( $data['media_id'] ) : null,
            'verified'      => ! empty( $data['verified'] ) ? 1 : 0,
            'source'        => isset( $data['source'] ) ? sanitize_key( (string) $data['source'] ) : 'manual',
            'source_ref'    => sanitize_text_field( $data['source_ref'] ?? '' ),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function sanitize_partial( array $data ): array {
        $result = [];
        $map = [
            'entry_date'    => fn( $v ) => self::sanitize_datetime( $v ),
            'amount_cents'  => fn( $v ) => self::sanitize_amount( $v ),
            'title'         => fn( $v ) => sanitize_text_field( $v ),
            'member_name'   => fn( $v ) => sanitize_text_field( $v ),
            'member_number' => fn( $v ) => sanitize_text_field( $v ),
            'media_id'      => fn( $v ) => empty( $v ) ? null : absint( $v ),
            'verified'      => fn( $v ) => ! empty( $v ) ? 1 : 0,
            'source'        => fn( $v ) => sanitize_key( (string) $v ),
            'source_ref'    => fn( $v ) => sanitize_text_field( $v ),
        ];
        foreach ( $map as $field => $sanitizer ) {
            if ( array_key_exists( $field, $data ) ) {
                $result[ $field ] = $sanitizer( $data[ $field ] );
            }
        }
        return $result;
    }

    private static function sanitize_amount( $value ): int {
        return (int) round( (float) $value );
    }

    /**
     * Accept a 'Y-m-d H:i:s' or 'Y-m-d' or HTML datetime-local 'Y-m-d\TH:i'
     * string; fall back to the current site time when empty/invalid.
     */
    private static function sanitize_datetime( $value ): string {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return current_time( 'mysql' );
        }
        $value = str_replace( 'T', ' ', $value );
        $ts    = strtotime( $value );
        if ( false === $ts ) {
            return current_time( 'mysql' );
        }
        return gmdate( 'Y-m-d H:i:s', $ts );
    }
}
