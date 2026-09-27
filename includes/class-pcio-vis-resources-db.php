<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom admin table; caching would hide real-time changes.

class PCIO_VIS_Resources_DB {

    const TABLE           = 'me_resources';
    const TABLE_LINK      = 'me_event_resources';
    const TABLE_TYPE_LINK = 'me_event_type_resources';

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function table_link(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_LINK;
    }

    public static function table_type_link(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_TYPE_LINK;
    }

    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql_r = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}me_resources (
            id int NOT NULL AUTO_INCREMENT,
            name varchar(150) NOT NULL DEFAULT '',
            description text,
            PRIMARY KEY  (id)
        ) {$charset};";
        dbDelta( $sql_r );

        $sql_l = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}me_event_resources (
            event_id int NOT NULL,
            resource_id int NOT NULL,
            PRIMARY KEY  (event_id, resource_id),
            KEY resource_id (resource_id)
        ) {$charset};";
        dbDelta( $sql_l );

        $sql_tl = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}me_event_type_resources (
            event_type_id int NOT NULL,
            resource_id int NOT NULL,
            PRIMARY KEY  (event_type_id, resource_id),
            KEY resource_id (resource_id)
        ) {$charset};";
        dbDelta( $sql_tl );
    }

    // ── Resources CRUD ────────────────────────────────────────────

    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC', self::table() ),
            ARRAY_A
        ) ?: [];
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
            ARRAY_A
        ) ?: null;
    }

    /** @return int|false */
    public static function create( array $data ) {
        global $wpdb;
        $result = $wpdb->insert( self::table(), [
            'name'        => sanitize_text_field( $data['name'] ?? '' ),
            'description' => sanitize_textarea_field( $data['description'] ?? '' ),
        ] );
        return $result ? $wpdb->insert_id : false;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        $fields = [];
        if ( array_key_exists( 'name', $data ) ) {
            $fields['name'] = sanitize_text_field( $data['name'] );
        }
        if ( array_key_exists( 'description', $data ) ) {
            $fields['description'] = sanitize_textarea_field( $data['description'] );
        }
        if ( empty( $fields ) ) {
            return true;
        }
        return false !== $wpdb->update( self::table(), $fields, [ 'id' => $id ] );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        $wpdb->delete( self::table_link(),      [ 'resource_id' => $id ], [ '%d' ] );
        $wpdb->delete( self::table_type_link(), [ 'resource_id' => $id ], [ '%d' ] );
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    // ── Event Type ↔ Resource defaults ───────────────────────────

    /** Return the IDs of resources set as defaults for an event type. */
    public static function get_default_ids_for_type( int $type_id ): array {
        global $wpdb;
        return array_map( 'intval', $wpdb->get_col(
            $wpdb->prepare( 'SELECT resource_id FROM %i WHERE event_type_id = %d', self::table_type_link(), $type_id )
        ) );
    }

    /** Replace default resources for an event type atomically. */
    public static function set_defaults_for_type( int $type_id, array $resource_ids ): void {
        global $wpdb;
        $wpdb->delete( self::table_type_link(), [ 'event_type_id' => $type_id ], [ '%d' ] );
        foreach ( array_unique( array_map( 'absint', $resource_ids ) ) as $rid ) {
            if ( $rid > 0 ) {
                $wpdb->insert( self::table_type_link(), [ 'event_type_id' => $type_id, 'resource_id' => $rid ], [ '%d', '%d' ] );
            }
        }
    }

    // ── Event ↔ Resource junction ─────────────────────────────────

    /** Fetch resource rows assigned to an event. */
    public static function get_for_event( int $event_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT r.* FROM %i r INNER JOIN %i er ON er.resource_id = r.id WHERE er.event_id = %d ORDER BY r.name ASC',
                self::table(),
                self::table_link(),
                $event_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /** Replace all resources for an event atomically (DELETE + INSERT). */
    public static function set_for_event( int $event_id, array $resource_ids ): void {
        global $wpdb;
        $wpdb->delete( self::table_link(), [ 'event_id' => $event_id ], [ '%d' ] );
        foreach ( array_unique( array_map( 'absint', $resource_ids ) ) as $rid ) {
            if ( $rid > 0 ) {
                $wpdb->insert( self::table_link(), [ 'event_id' => $event_id, 'resource_id' => $rid ], [ '%d', '%d' ] );
            }
        }
    }

    /**
     * Find events (other than $event_id) sharing any of $resource_ids that
     * overlap the given time window [start, end).
     *
     * @return array[] Each row: resource_name, event_id, event_title, start_datetime, end_datetime.
     */
    public static function find_conflicts( int $event_id, array $resource_ids, string $start, string $end ): array {
        global $wpdb;
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $resource_ids ) ) ) );
        if ( empty( $ids ) ) {
            return [];
        }
        $set = implode( ',', $ids ); // safe: all values are absint()-validated integers
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT r.name AS resource_name, e.id AS event_id, e.title AS event_title,
                        e.start_datetime, e.end_datetime
                 FROM `{$wpdb->prefix}me_event_resources` er
                 INNER JOIN `{$wpdb->prefix}me_events` e    ON e.id  = er.event_id
                 INNER JOIN `{$wpdb->prefix}me_resources` r ON r.id  = er.resource_id
                 WHERE FIND_IN_SET( er.resource_id, %s )
                   AND er.event_id   != %d
                   AND e.start_datetime  < %s
                   AND ( e.end_datetime IS NULL OR e.end_datetime > %s )",
                $set,
                $event_id,
                $end,
                $start
            ),
            ARRAY_A
        ) ?: [];
    }
}
