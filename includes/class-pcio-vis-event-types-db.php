<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom admin table; caching would hide real-time changes.

class PCIO_VIS_Event_Types_DB {

    const TABLE = 'me_event_types';

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install(): void {
        global $wpdb;
        $table = self::table();
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table creation and upgrades run only from the install routine.
        if ( false === $wpdb->query( $wpdb->prepare( "CREATE TABLE IF NOT EXISTS %i (
            id int NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL DEFAULT '',
            slug varchar(50) NOT NULL DEFAULT '',
            access varchar(20) NOT NULL DEFAULT 'members',
            color varchar(20) NOT NULL DEFAULT '',
            in_cafe_time tinyint(1) NOT NULL DEFAULT 0,
            system_event_type tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci", $table ) ) ) {
            wp_die( esc_html__( 'Could not upgrade event types.', 'pcio-vis-member-event' ) );
        }
        foreach ( [ 'in_cafe_time', 'system_event_type' ] as $column ) {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );
            if ( null === $exists && false === $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN %i tinyint(1) NOT NULL DEFAULT 0', $table, $column ) ) ) {
                wp_die( esc_html__( 'Could not upgrade event types.', 'pcio-vis-member-event' ) );
            }
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
        self::seed_system_types();
    }

    /** Seed stable system identities without replacing existing names or settings. */
    private static function seed_system_types(): void {
        global $wpdb;
        $types = [
            'cafe'    => [ 'name' => __( 'Cafe', 'pcio-vis-member-event' ), 'in_cafe_time' => 1 ],
            'art'     => [ 'name' => __( 'Art', 'pcio-vis-member-event' ), 'in_cafe_time' => 1 ],
            'holiday' => [ 'name' => __( 'Holiday', 'pcio-vis-member-event' ), 'in_cafe_time' => 0 ],
        ];
        foreach ( $types as $slug => $data ) {
            $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s', self::table(), $slug ), ARRAY_A );
            if ( $existing ) {
                if ( (int) $existing['system_event_type'] ) {
                    continue;
                }
                $ok = $wpdb->update( self::table(), [ 'system_event_type' => 1 ], [ 'id' => (int) $existing['id'] ], [ '%d' ], [ '%d' ] );
            } else {
                $ok = $wpdb->insert( self::table(), $data + [
                    'slug' => $slug, 'access' => 'members', 'color' => '', 'system_event_type' => 1,
                ] );
            }
            if ( false === $ok ) {
                wp_die( esc_html__( 'Could not seed system event types.', 'pcio-vis-member-event' ) );
            }
        }
    }

    public static function get_all(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC', self::table() ),
            ARRAY_A
        ) ?: [];
        foreach ( $rows as &$row ) {
            $row['in_cafe_time'] = (bool) $row['in_cafe_time'];
            $row['system_event_type'] = (bool) $row['system_event_type'];
            $row['default_resource_ids'] = PCIO_VIS_Resources_DB::get_default_ids_for_type( (int) $row['id'] );
        }
        return $rows;
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
            ARRAY_A
        ) ?: null;
        if ( $row ) {
            $row['in_cafe_time'] = (bool) $row['in_cafe_time'];
            $row['system_event_type'] = (bool) $row['system_event_type'];
            $row['default_resource_ids'] = PCIO_VIS_Resources_DB::get_default_ids_for_type( $id );
        }
        return $row;
    }

    /** @return int|false */
    public static function create( array $data ) {
        global $wpdb;
        $result = PCIO_VIS_History::insert( 'event_types', self::sanitize( $data ) );
        if ( ! $result ) {
            return false;
        }
        $id = $wpdb->insert_id;
        if ( isset( $data['default_resource_ids'] ) && is_array( $data['default_resource_ids'] ) ) {
            PCIO_VIS_Resources_DB::set_defaults_for_type( $id, $data['default_resource_ids'] );
        }
        return $id;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        $sanitized = self::sanitize_partial( $data );
        $existing = self::get( $id );
        if ( ! $existing ) {
            return false;
        }
        if ( $existing['system_event_type'] ) {
            unset( $sanitized['slug'] );
        }
        if ( ! empty( $sanitized ) ) {
            if ( false === PCIO_VIS_History::update( 'event_types', $sanitized, [ 'id' => $id ] ) ) {
                return false;
            }
        }
        if ( isset( $data['default_resource_ids'] ) && is_array( $data['default_resource_ids'] ) ) {
            PCIO_VIS_Resources_DB::set_defaults_for_type( $id, $data['default_resource_ids'] );
        }
        return true;
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        $existing = self::get( $id );
        if ( ! $existing || $existing['system_event_type'] || self::count_events( $id ) > 0 ) {
            return false;
        }
        return (bool) PCIO_VIS_History::delete( 'event_types', [ 'id' => $id ], [ '%d' ] );
    }

    /**
     * Number of events using an event type. A type in use cannot be deleted,
     * because every event must have an event type.
     *
     * @param int $id Event type ID.
     */
    public static function count_events( int $id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event_type_id = %d', PCIO_VIS_Events_DB::table(), $id )
        );
    }

    /**
     * True when the ID refers to an existing event type.
     *
     * @param mixed $id Candidate event type ID.
     */
    public static function exists( $id ): bool {
        global $wpdb;
        $id = absint( $id );
        if ( $id <= 0 ) {
            return false;
        }
        return (bool) $wpdb->get_var(
            $wpdb->prepare( 'SELECT 1 FROM %i WHERE id = %d', self::table(), $id )
        );
    }

    // ── Sanitization ──────────────────────────────────────────────

    private static function sanitize( array $data ): array {
        $name = sanitize_text_field( $data['name'] ?? '' );
        return [
            'name'   => $name,
            'slug'   => sanitize_title( $data['slug'] ?? $name ),
            'access' => self::sanitize_access( $data['access'] ?? '' ),
            'color'  => self::sanitize_color( $data['color'] ?? '' ),
            'in_cafe_time' => rest_sanitize_boolean( $data['in_cafe_time'] ?? false ) ? 1 : 0,
        ];
    }

    private static function sanitize_partial( array $data ): array {
        $result = [];
        if ( array_key_exists( 'name', $data ) ) {
            $result['name'] = sanitize_text_field( $data['name'] );
        }
        if ( array_key_exists( 'slug', $data ) ) {
            $result['slug'] = sanitize_title( $data['slug'] );
        }
        if ( array_key_exists( 'access', $data ) ) {
            $result['access'] = self::sanitize_access( $data['access'] );
        }
        if ( array_key_exists( 'color', $data ) ) {
            $result['color'] = self::sanitize_color( $data['color'] );
        }
        if ( array_key_exists( 'in_cafe_time', $data ) ) {
            $result['in_cafe_time'] = rest_sanitize_boolean( $data['in_cafe_time'] ) ? 1 : 0;
        }
        return $result;
    }

    private static function sanitize_access( string $access ): string {
        return in_array( $access, [ 'public', 'members', 'volunteers' ], true ) ? $access : 'members';
    }

    private static function sanitize_color( string $color ): string {
        $color = trim( $color );
        return preg_match( '/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $color ) ? $color : '';
    }
}
