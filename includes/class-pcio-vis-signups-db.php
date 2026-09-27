<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom admin table; caching would hide real-time changes.

/**
 * Join table between me_members and me_events.
 * Stores a member's attendance intention for a given event.
 *
 * Valid status values: 'joining', 'interested', 'not_joining'
 */
class PCIO_VIS_Signups_DB {

    const TABLE  = 'me_event_signups';
    const VALID_STATUSES = [ 'joining', 'interested', 'not_joining', 'arrived' ];

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
            event_id int NOT NULL,
            member_id int NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'joining',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY event_member (event_id, member_id),
            KEY event_id (event_id),
            KEY member_id (member_id)
        ) {$charset};";
        dbDelta( $sql );
    }

    // ── Lookup helpers ────────────────────────────────────────────

    /**
     * Resolve the current logged-in WP user to a member record.
     * Returns the member_id from me_member_roles, or null if not found.
     */
    public static function get_member_id_for_current_user(): ?int {
        global $wpdb;
        $wp_user_id = get_current_user_id();
        if ( ! $wp_user_id ) {
            return null;
        }
        $roles_table = $wpdb->prefix . 'me_member_roles';
        $member_id   = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT `member_id` FROM %i WHERE `wp_user_id` = %d LIMIT 1',
                $roles_table,
                $wp_user_id
            )
        );
        return $member_id ? (int) $member_id : null;
    }

    // ── Queries ───────────────────────────────────────────────────

    /**
     * Get all signups for an event.
     * Joins me_members to include member name.
     *
     * @return array<int, array{id:int, event_id:int, member_id:int, name:string, status:string, created_at:string}>
     */
    public static function get_for_event( int $event_id ): array {
        global $wpdb;
        $table   = self::table();
        $members = $wpdb->prefix . PCIO_VIS_DB::TABLE;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.id, s.event_id, s.member_id, m.name, m.email, s.status, s.created_at
                 FROM %i s
                 LEFT JOIN %i m ON m.id = s.member_id
                 WHERE s.event_id = %d
                 ORDER BY m.name ASC",
                $table,
                $members,
                $event_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get the signup row for a specific member + event combination.
     */
    public static function get_for_member_event( int $member_id, int $event_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE `member_id` = %d AND `event_id` = %d LIMIT 1',
                self::table(),
                $member_id,
                $event_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Same as get_for_member_event but also JOINs me_members to include name + email.
     * Used when the result must populate a guest-list row.
     */
    public static function get_for_member_event_full( int $member_id, int $event_id ): ?array {
        global $wpdb;
        $table   = self::table();
        $members = $wpdb->prefix . PCIO_VIS_DB::TABLE;
        $row     = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT s.id, s.event_id, s.member_id, m.name, m.email, s.status, s.created_at
                 FROM %i s
                 LEFT JOIN %i m ON m.id = s.member_id
                 WHERE s.member_id = %d AND s.event_id = %d LIMIT 1",
                $table,
                $members,
                $member_id,
                $event_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    // ── CRUD ──────────────────────────────────────────────────────

    /**
     * Insert or update a signup row (upsert via ON DUPLICATE KEY UPDATE).
     */
    public static function upsert( int $event_id, int $member_id, string $status ): bool {
        global $wpdb;
        if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
            return false;
        }
        $table  = self::table();
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO %i (`event_id`, `member_id`, `status`)
                 VALUES (%d, %d, %s)
                 ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `updated_at` = CURRENT_TIMESTAMP",
                $table,
                $event_id,
                $member_id,
                $status
            )
        );
        return $result !== false;
    }

    /**
     * Count members with no signup record for the event.
     */
    public static function get_not_answered_count( int $event_id ): int {
        global $wpdb;
        $members_table = $wpdb->prefix . PCIO_VIS_DB::TABLE;
        $signups_table = self::table();
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i m
                 WHERE NOT EXISTS (
                     SELECT 1 FROM %i s
                     WHERE s.member_id = m.id AND s.event_id = %d
                 )",
                $members_table,
                $signups_table,
                $event_id
            )
        );
        return (int) $count;
    }

    /**
     * Members who have NOT signed up for this event (for the Add Member picker).
     *
     * @return array<int, array{id:int, name:string, email:string}>
     */
    public static function get_available_members( int $event_id ): array {
        global $wpdb;
        $members_table = $wpdb->prefix . PCIO_VIS_DB::TABLE;
        $signups_table = self::table();
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.id, m.member_number, m.name, m.email FROM %i m
                 WHERE NOT EXISTS (
                     SELECT 1 FROM %i s
                     WHERE s.member_id = m.id AND s.event_id = %d
                 )
                 ORDER BY m.name ASC",
                $members_table,
                $signups_table,
                $event_id
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Delete a member's signup for an event.
     */
    public static function delete( int $event_id, int $member_id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete(
            self::table(),
            [ 'event_id' => $event_id, 'member_id' => $member_id ],
            [ '%d', '%d' ]
        );
    }

    /**
     * Aggregate counts by status for an event.
     *
     * @return array{joining:int, interested:int, not_joining:int}
     */
    public static function get_counts( int $event_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT `status`, COUNT(*) AS `cnt` FROM %i
                 WHERE `event_id` = %d GROUP BY `status`',
                self::table(),
                $event_id
            ),
            ARRAY_A
        ) ?: [];

        $counts = [ 'joining' => 0, 'interested' => 0, 'not_joining' => 0, 'arrived' => 0 ];
        foreach ( $rows as $row ) {
            if ( isset( $counts[ $row['status'] ] ) ) {
                $counts[ $row['status'] ] = (int) $row['cnt'];
            }
        }
        return $counts;
    }
}
