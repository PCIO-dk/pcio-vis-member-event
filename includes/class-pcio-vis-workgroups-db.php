<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom admin table; caching would hide real-time changes.

class PCIO_VIS_Workgroups_DB {

    const TABLE      = 'me_workgroups';
    const TABLE_LINK = 'me_workgroup_members';

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function table_link(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_LINK;
    }

    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql_wg = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}me_workgroups (
            id int NOT NULL AUTO_INCREMENT,
            name varchar(120) NOT NULL DEFAULT '',
            description text,
            color varchar(20) NOT NULL DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) {$charset};";
        dbDelta( $sql_wg );

        $sql_link = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}me_workgroup_members (
            workgroup_id int NOT NULL,
            member_id int NOT NULL,
            is_chairman tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (workgroup_id, member_id),
            KEY member_id (member_id)
        ) {$charset};";
        dbDelta( $sql_link );
    }

    // ── Workgroup CRUD ────────────────────────────────────────────

    /** List all workgroups with volunteer count. */
    public static function get_all(): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT wg.*, COUNT(wm.member_id) AS member_count
                 FROM %i wg
                 LEFT JOIN %i wm ON wm.workgroup_id = wg.id
                 GROUP BY wg.id
                 ORDER BY wg.name ASC',
                self::table(),
                self::table_link()
            ),
            ARRAY_A
        ) ?: [];
    }

    /** Single workgroup with its volunteer member rows. */
    public static function get( int $id ): ?array {
        global $wpdb;
        $wg = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
            ARRAY_A
        );
        if ( ! $wg ) {
            return null;
        }
        $wg['members'] = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT m.id, m.name, m.email, wm.is_chairman
                 FROM %i wm
                 INNER JOIN %i m ON m.id = wm.member_id
                 WHERE wm.workgroup_id = %d
                 ORDER BY m.name ASC',
                self::table_link(),
                $wpdb->prefix . 'me_members',
                $id
            ),
            ARRAY_A
        ) ?: [];
        return $wg;
    }

    /** @return int|false */
    public static function create( array $data ) {
        global $wpdb;
        $result = $wpdb->insert( self::table(), self::sanitize( $data ) );
        return $result ? $wpdb->insert_id : false;
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return false !== $wpdb->update( self::table(), self::sanitize_partial( $data ), [ 'id' => $id ] );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        $wpdb->delete( self::table_link(), [ 'workgroup_id' => $id ], [ '%d' ] );
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    // ── Membership management ─────────────────────────────────────

    public static function add_member( int $workgroup_id, int $member_id, bool $is_chairman = false ): bool {
        global $wpdb;
        $result = $wpdb->replace(
            self::table_link(),
            [
                'workgroup_id' => $workgroup_id,
                'member_id'    => $member_id,
                'is_chairman'  => $is_chairman ? 1 : 0,
            ],
            [ '%d', '%d', '%d' ]
        );
        return false !== $result;
    }

    public static function remove_member( int $workgroup_id, int $member_id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete(
            self::table_link(),
            [ 'workgroup_id' => $workgroup_id, 'member_id' => $member_id ],
            [ '%d', '%d' ]
        );
    }

    public static function set_chairman( int $workgroup_id, int $member_id, bool $is_chairman ): bool {
        global $wpdb;
        return false !== $wpdb->update(
            self::table_link(),
            [ 'is_chairman' => $is_chairman ? 1 : 0 ],
            [ 'workgroup_id' => $workgroup_id, 'member_id' => $member_id ],
            [ '%d' ],
            [ '%d', '%d' ]
        );
    }

    // ── Sanitization ──────────────────────────────────────────────

    private static function sanitize( array $data ): array {
        return [
            'name'        => sanitize_text_field( $data['name'] ?? '' ),
            'description' => sanitize_textarea_field( $data['description'] ?? '' ),
            'color'       => self::sanitize_color( $data['color'] ?? '' ),
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
        if ( array_key_exists( 'color', $data ) ) {
            $result['color'] = self::sanitize_color( $data['color'] );
        }
        return $result;
    }

    private static function sanitize_color( string $color ): string {
        $color = trim( $color );
        return preg_match( '/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $color ) ? $color : '';
    }
}
