<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom admin table; caching would hide real-time changes.

/**
 * Stores arbitrary key/value metadata for members.
 * Extension plugins add fields via the pcio_me_member_fields filter;
 * values are saved/loaded automatically by PCIO_VIS_DB.
 */
class PCIO_VIS_Meta_DB {

    const TABLE = 'me_member_meta';

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
            member_id int NOT NULL,
            meta_key varchar(100) NOT NULL DEFAULT '',
            meta_value longtext,
            PRIMARY KEY  (id),
            UNIQUE KEY member_key (member_id, meta_key),
            KEY meta_key (meta_key)
        ) {$charset};";
        dbDelta( $sql );
    }

    // ── Read ──────────────────────────────────────────────────────

    /**
     * Returns all meta for a single member as ['key' => 'value', ...].
     */
    public static function get_member_meta( int $member_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT `meta_key`, `meta_value` FROM %i WHERE `member_id` = %d',
                self::table(),
                $member_id
            ),
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ( $rows as $row ) {
            $out[ $row['meta_key'] ] = $row['meta_value'];
        }
        return $out;
    }

    /**
     * Returns meta for multiple members as [member_id => ['key' => 'value', ...], ...].
     *
     * @param int[] $member_ids
     */
    public static function get_all_meta( array $member_ids ): array {
        if ( empty( $member_ids ) ) {
            return [];
        }
        $out = [];
        foreach ( $member_ids as $member_id ) {
            $out[ (int) $member_id ] = self::get_member_meta( (int) $member_id );
        }
        return $out;
    }

    // ── Write ─────────────────────────────────────────────────────

    /**
     * Upsert a single meta value.
     */
    public static function set( int $member_id, string $key, string $value ): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (`member_id`, `meta_key`, `meta_value`)
                 VALUES (%d, %s, %s)
                 ON DUPLICATE KEY UPDATE `meta_value` = VALUES(`meta_value`)',
                self::table(), $member_id, $key, $value
            )
        );
    }

    /**
     * Delete all meta rows for a member (called when the member is deleted).
     */
    public static function delete_member( int $member_id ): void {
        global $wpdb;
        $wpdb->delete( self::table(), [ 'member_id' => $member_id ], [ '%d' ] );
    }
}
