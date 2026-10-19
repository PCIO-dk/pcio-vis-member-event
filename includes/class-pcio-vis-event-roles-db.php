<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom admin table; caching would hide real-time changes.

class PCIO_VIS_Event_Roles_DB {

    const TABLE = 'me_event_roles';

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function install(): void {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id int NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL DEFAULT '',
            description text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY name (name)
        ) {$charset};";
        dbDelta( $sql );

        self::seed_default_roles();
    }

    /** Seed default event roles if the table is empty. */
    private static function seed_default_roles(): void {
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) );
        if ( $count > 0 ) {
            return;
        }

        $defaults = [
            [
                'name'        => __( 'Organizer', 'pcio-vis-member-event' ),
                'description' => __( 'Lead organizer responsible for the event.', 'pcio-vis-member-event' ),
            ],
            [
                'name'        => __( 'Helper', 'pcio-vis-member-event' ),
                'description' => __( 'General volunteer assisting with event tasks.', 'pcio-vis-member-event' ),
            ],
            [
                'name'        => __( 'Bartender', 'pcio-vis-member-event' ),
                'description' => __( 'Responsible for bar and beverage sales.', 'pcio-vis-member-event' ),
            ],
            [
                'name'        => __( 'Kitchen', 'pcio-vis-member-event' ),
                'description' => __( 'Assisting in the kitchen with food preparation and cleanup.', 'pcio-vis-member-event' ),
            ],
        ];

        foreach ( $defaults as $role ) {
            $wpdb->insert( self::table(), [
                'name'        => $role['name'],
                'description' => $role['description'],
            ] );
        }
    }

    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC', self::table() ),
            ARRAY_A
        ) ?: [];
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /** @return int|false */
    public static function create( array $data ) {
        global $wpdb;
        $sanitized = self::sanitize( $data );
        if ( empty( $sanitized['name'] ) ) {
            return false;
        }
        $result = PCIO_VIS_History::insert( 'event_roles', $sanitized );
        if ( ! $result ) {
            return false;
        }
        return (int) $wpdb->insert_id;
    }

    public static function update( int $id, array $data ): bool {
        $sanitized = self::sanitize_partial( $data );
        if ( empty( $sanitized ) ) {
            return true;
        }
        $existing = self::get( $id );
        if ( ! $existing ) {
            return false;
        }
        return false !== PCIO_VIS_History::update( 'event_roles', $sanitized, [ 'id' => $id ] );
    }

    public static function delete( int $id ): bool {
        $existing = self::get( $id );
        if ( ! $existing ) {
            return false;
        }

        // Clean up any crew assignments using this role before deleting it.
        if ( class_exists( 'PCIO_VIS_Event_Crew_DB' ) ) {
            PCIO_VIS_Event_Crew_DB::delete_by_role( $id );
        }

        return (bool) PCIO_VIS_History::delete( 'event_roles', [ 'id' => $id ], [ '%d' ] );
    }

    /** Returns how many crew assignments are currently using this role. */
    public static function count_usage( int $role_id ): int {
        global $wpdb;
        if ( ! class_exists( 'PCIO_VIS_Event_Crew_DB' ) ) {
            return 0;
        }
        return (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE role_id = %d', PCIO_VIS_Event_Crew_DB::table(), $role_id )
        );
    }

    // ── Sanitization ──────────────────────────────────────────────

    private static function sanitize( array $data ): array {
        return [
            'name'        => sanitize_text_field( $data['name'] ?? '' ),
            'description' => sanitize_textarea_field( $data['description'] ?? '' ),
        ];
    }

    private static function sanitize_partial( array $data ): array {
        $result = [];
        if ( array_key_exists( 'name', $data ) ) {
            $result['name'] = sanitize_text_field( $data['name'] );
        }
        if ( array_key_exists( 'description', $data ) ) {
            $result['description'] = sanitize_textarea_field( $data['description'] );
        }
        return $result;
    }
}
