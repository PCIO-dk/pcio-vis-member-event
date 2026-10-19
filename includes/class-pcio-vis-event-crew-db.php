<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom admin table; caching would hide real-time changes.

class PCIO_VIS_Event_Crew_DB {

    const TABLE = 'me_event_crew';

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
            event_id int NOT NULL,
            member_id int NOT NULL DEFAULT 0,
            role_id int NOT NULL,
            start_datetime datetime NOT NULL,
            end_datetime datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event_id (event_id),
            KEY member_id (member_id),
            KEY role_id (role_id)
        ) {$charset};";
        dbDelta( $sql );
    }

    /**
     * Get all crew assignments for a specific event.
     *
     * @param int $event_id Event ID.
     * @return array[]
     */
    public static function get_for_event( int $event_id ): array {
        global $wpdb;
        $crew_table   = self::table();
        $members_table = PCIO_VIS_DB::table();
        $roles_table   = PCIO_VIS_Event_Roles_DB::table();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
            "SELECT c.*,
                    m.name AS member_name,
                    m.email AS member_email,
                    r.name AS role_name
             FROM %i c
             LEFT JOIN %i m ON c.member_id = m.id
             LEFT JOIN %i r ON c.role_id = r.id
             WHERE c.event_id = %d
             ORDER BY r.name ASC, m.name ASC, c.start_datetime ASC",
            $crew_table,
            $members_table,
            $roles_table,
            $event_id
            ),
            ARRAY_A
        ) ?: [];
        foreach ( $rows as &$row ) {
            $row['id']        = (int) $row['id'];
            $row['event_id']  = (int) $row['event_id'];
            $row['member_id'] = (int) $row['member_id'];
            $row['role_id']   = (int) $row['role_id'];
            $row['member_name']  = $row['member_name'] ?? '';
            $row['member_email'] = $row['member_email'] ?? '';
            $row['role_name']    = $row['role_name'] ?? '';
        }
        unset( $row );

        return $rows;
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $crew_table   = self::table();
        $members_table = PCIO_VIS_DB::table();
        $roles_table   = PCIO_VIS_Event_Roles_DB::table();

        $row = $wpdb->get_row(
            $wpdb->prepare(
            "SELECT c.*,
                    m.name AS member_name,
                    m.email AS member_email,
                    r.name AS role_name
             FROM %i c
             LEFT JOIN %i m ON c.member_id = m.id
             LEFT JOIN %i r ON c.role_id = r.id
             WHERE c.id = %d",
            $crew_table,
            $members_table,
            $roles_table,
            $id
            ),
            ARRAY_A
        );
        if ( ! $row ) {
            return null;
        }

        $row['id']        = (int) $row['id'];
        $row['event_id']  = (int) $row['event_id'];
        $row['member_id'] = (int) $row['member_id'];
        $row['role_id']   = (int) $row['role_id'];
        $row['member_name']  = $row['member_name'] ?? '';
        $row['member_email'] = $row['member_email'] ?? '';
        $row['role_name']    = $row['role_name'] ?? '';

        return $row;
    }

    /**
     * Assigned crew shifts starting in a datetime window, with member, role and event details.
     *
     * @param string $from Inclusive lower bound, 'Y-m-d H:i:s' in site time.
     * @param string $to   Exclusive upper bound, 'Y-m-d H:i:s' in site time.
     * @return array[]
     */
    public static function get_assigned_starting_between( string $from, string $to ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT c.id, c.event_id, c.member_id, c.role_id, c.start_datetime, c.end_datetime,
                        m.name AS member_name,
                        m.email AS member_email,
                        r.name AS role_name,
                        e.title AS event_title
                 FROM %i c
                 INNER JOIN %i m ON c.member_id = m.id
                 INNER JOIN %i e ON c.event_id = e.id
                 LEFT JOIN %i r ON c.role_id = r.id
                 WHERE c.member_id > 0
                   AND c.start_datetime >= %s
                   AND c.start_datetime < %s
                 ORDER BY c.start_datetime ASC, c.id ASC',
                self::table(),
                PCIO_VIS_DB::table(),
                PCIO_VIS_Events_DB::table(),
                PCIO_VIS_Event_Roles_DB::table(),
                $from,
                $to
            ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    /**
     * Create a new crew shift.
     *
     * @param array $data Input data.
     * @return int|false New ID on success, false on failure.
     */
    public static function create( array $data ) {
        global $wpdb;
        $sanitized = self::sanitize( $data );
        if ( empty( $sanitized['event_id'] ) || empty( $sanitized['role_id'] ) ) {
            return false;
        }

        // Default start and end times to event times if not explicitly provided.
        if ( empty( $sanitized['start_datetime'] ) || empty( $sanitized['end_datetime'] ) ) {
            $event = PCIO_VIS_Events_DB::get( $sanitized['event_id'] );
            if ( ! $event ) {
                return false;
            }
            if ( empty( $sanitized['start_datetime'] ) ) {
                $sanitized['start_datetime'] = $event['start_datetime'];
            }
            if ( empty( $sanitized['end_datetime'] ) ) {
                $sanitized['end_datetime'] = $event['end_datetime'] ?: $event['start_datetime'];
            }
        }

        $result = PCIO_VIS_History::insert( 'event_crew', $sanitized );
        if ( ! $result ) {
            return false;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Update an existing crew shift.
     *
     * @param int   $id   Crew shift ID.
     * @param array $data Data to update.
     * @return bool
     */
    public static function update( int $id, array $data ): bool {
        $sanitized = self::sanitize_partial( $data );
        if ( empty( $sanitized ) ) {
            return true;
        }
        $existing = self::get( $id );
        if ( ! $existing ) {
            return false;
        }
        return false !== PCIO_VIS_History::update( 'event_crew', $sanitized, [ 'id' => $id ] );
    }

    /**
     * Delete a crew shift.
     *
     * @param int $id Crew shift ID.
     * @return bool
     */
    public static function delete( int $id ): bool {
        $existing = self::get( $id );
        if ( ! $existing ) {
            return false;
        }
        return (bool) PCIO_VIS_History::delete( 'event_crew', [ 'id' => $id ], [ '%d' ] );
    }

    /**
     * Duplicate a crew shift (creates a clone with same role and times, but unassigned member).
     *
     * @param int $id Crew shift ID to clone.
     * @return int|false New crew shift ID or false on failure.
     */
    public static function duplicate( int $id ) {
        $existing = self::get( $id );
        if ( ! $existing ) {
            return false;
        }

        $clone = [
            'event_id'       => $existing['event_id'],
            'member_id'      => 0, // Unassigned
            'role_id'        => $existing['role_id'],
            'start_datetime' => $existing['start_datetime'],
            'end_datetime'   => $existing['end_datetime'],
        ];

        return self::create( $clone );
    }

    /**
     * Reset all shift times for an event to the event's start and end times.
     *
     * @param int $event_id Event ID.
     * @return bool
     */
    public static function reset_times_for_event( int $event_id ): bool {
        $event = PCIO_VIS_Events_DB::get( $event_id );
        if ( ! $event || empty( $event['start_datetime'] ) ) {
            return false;
        }

        $start_time = $event['start_datetime'];
        $end_time   = $event['end_datetime'] ?: $event['start_datetime'];

        $shifts = self::get_for_event( $event_id );
        if ( empty( $shifts ) ) {
            return true;
        }

        PCIO_VIS_History::begin(
            __( 'Reset crew shift times to event times', 'pcio-vis-member-event' ),
            [ 'event_id' => $event_id ]
        );

        $ok = true;
        foreach ( $shifts as $shift ) {
            $updated = PCIO_VIS_History::update(
                'event_crew',
                [
                    'start_datetime' => $start_time,
                    'end_datetime'   => $end_time,
                ],
                [ 'id' => (int) $shift['id'] ]
            );
            if ( false === $updated ) {
                $ok = false;
            }
        }

        PCIO_VIS_History::commit();

        return $ok;
    }

    /**
     * Delete all crew shifts for a given event.
     *
     * @param int $event_id Event ID.
     * @return bool
     */
    public static function delete_for_event( int $event_id ): bool {
        global $wpdb;
        $shifts = self::get_for_event( $event_id );
        if ( empty( $shifts ) ) {
            return true;
        }

        $ok = true;
        foreach ( $shifts as $shift ) {
            if ( ! PCIO_VIS_History::delete( 'event_crew', [ 'id' => (int) $shift['id'] ], [ '%d' ] ) ) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * Delete all crew shifts associated with a role.
     *
     * @param int $role_id Role ID.
     * @return bool
     */
    public static function delete_by_role( int $role_id ): bool {
        global $wpdb;
        $ids = $wpdb->get_col(
            $wpdb->prepare( 'SELECT id FROM %i WHERE role_id = %d', self::table(), $role_id )
        );
        if ( empty( $ids ) ) {
            return true;
        }

        $ok = true;
        foreach ( $ids as $id ) {
            if ( ! PCIO_VIS_History::delete( 'event_crew', [ 'id' => (int) $id ], [ '%d' ] ) ) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * Unassign a deleted or removed member from any crew shifts (sets member_id = 0).
     *
     * @param int $member_id Member ID.
     * @return bool
     */
    public static function unassign_member( int $member_id ): bool {
        global $wpdb;
        $ids = $wpdb->get_col(
            $wpdb->prepare( 'SELECT id FROM %i WHERE member_id = %d', self::table(), $member_id )
        );
        if ( empty( $ids ) ) {
            return true;
        }

        $ok = true;
        foreach ( $ids as $id ) {
            $updated = PCIO_VIS_History::update(
                'event_crew',
                [ 'member_id' => 0 ],
                [ 'id' => (int) $id ]
            );
            if ( false === $updated ) {
                $ok = false;
            }
        }
        return $ok;
    }

    // ── Sanitization ──────────────────────────────────────────────

    private static function sanitize( array $data ): array {
        return [
            'event_id'       => absint( $data['event_id'] ?? 0 ),
            'member_id'      => absint( $data['member_id'] ?? 0 ),
            'role_id'        => absint( $data['role_id'] ?? 0 ),
            'start_datetime' => self::sanitize_datetime( $data['start_datetime'] ?? '' ),
            'end_datetime'   => self::sanitize_datetime( $data['end_datetime'] ?? '' ),
        ];
    }

    private static function sanitize_partial( array $data ): array {
        $result = [];
        if ( array_key_exists( 'event_id', $data ) ) {
            $result['event_id'] = absint( $data['event_id'] );
        }
        if ( array_key_exists( 'member_id', $data ) ) {
            $result['member_id'] = absint( $data['member_id'] );
        }
        if ( array_key_exists( 'role_id', $data ) ) {
            $result['role_id'] = absint( $data['role_id'] );
        }
        if ( array_key_exists( 'start_datetime', $data ) ) {
            $result['start_datetime'] = self::sanitize_datetime( $data['start_datetime'] );
        }
        if ( array_key_exists( 'end_datetime', $data ) ) {
            $result['end_datetime'] = self::sanitize_datetime( $data['end_datetime'] );
        }
        return $result;
    }

    private static function sanitize_datetime( string $value ): string {
        $value = trim( sanitize_text_field( $value ) );
        if ( '' === $value ) {
            return '';
        }
        // Replace ISO T with space if needed (e.g. 2026-04-10T14:30 -> 2026-04-10 14:30:00)
        $value = str_replace( 'T', ' ', $value );
        // If seconds are omitted (from HTML datetime-local input), append :00
        if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
            $value .= ':00';
        }
        return $value;
    }

    /**
     * Members that have been assigned to at least one crew shift, for pickers.
     *
     * @return array[] List of [ 'id' => int, 'name' => string ] ordered by name.
     */
    public static function get_assigned_members(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT DISTINCT m.id, m.name
                 FROM %i c
                 INNER JOIN %i m ON m.id = c.member_id
                 ORDER BY m.name ASC',
                self::table(),
                PCIO_VIS_DB::table()
            ),
            ARRAY_A
        ) ?: [];
        return array_map( static fn( $r ) => [ 'id' => (int) $r['id'], 'name' => (string) $r['name'] ], $rows );
    }
}
