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
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id int NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL DEFAULT '',
            slug varchar(50) NOT NULL DEFAULT '',
            access varchar(20) NOT NULL DEFAULT 'members',
            color varchar(20) NOT NULL DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) {$charset};";
        dbDelta( $sql );
    }

    public static function get_all(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC', self::table() ),
            ARRAY_A
        ) ?: [];
        foreach ( $rows as &$row ) {
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
            $row['default_resource_ids'] = PCIO_VIS_Resources_DB::get_default_ids_for_type( $id );
        }
        return $row;
    }

    /** @return int|false */
    public static function create( array $data ) {
        global $wpdb;
        $result = $wpdb->insert( self::table(), self::sanitize( $data ) );
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
        if ( ! empty( $sanitized ) ) {
            if ( false === $wpdb->update( self::table(), $sanitized, [ 'id' => $id ] ) ) {
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
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    // ── Sanitization ──────────────────────────────────────────────

    private static function sanitize( array $data ): array {
        $name = sanitize_text_field( $data['name'] ?? '' );
        return [
            'name'   => $name,
            'slug'   => sanitize_title( $data['slug'] ?? $name ),
            'access' => self::sanitize_access( $data['access'] ?? '' ),
            'color'  => self::sanitize_color( $data['color'] ?? '' ),
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
