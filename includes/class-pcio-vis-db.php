<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom admin table; caching would hide real-time changes.

class PCIO_VIS_DB {

    const TABLE = 'me_members';

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    // ── Schema ────────────────────────────────────────────────────

    public static function install(): void {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        // No IF NOT EXISTS: dbDelta reads the table name straight out of the
        // statement, so "CREATE TABLE IF NOT EXISTS …" makes it resolve the table
        // as "IF" and silently skip the whole table — existing tables would then
        // never be brought up to date.
        $sql = "CREATE TABLE {$table} (
            id int NOT NULL AUTO_INCREMENT,
            member_number int NOT NULL DEFAULT 0,
            name varchar(120) NOT NULL DEFAULT '',
            email varchar(120) NOT NULL DEFAULT '',
            phone varchar(40) NOT NULL DEFAULT '',
            address text,
            join_date date DEFAULT NULL,
            leave_date date DEFAULT NULL,
            is_volunteer tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY is_volunteer (is_volunteer),
            KEY member_number (member_number)
        ) {$charset};";
        dbDelta( $sql );
       
    }

    // ── Field definitions ─────────────────────────────────────────

    /**
     * Returns the canonical list of member fields used by the table, modal, and REST.
     * Core fields (meta=false) are stored in the main table columns.
     * Extension fields added via filter have meta=true and are stored in me_member_meta.
     *
     * Field shape:
     *   key      (string)  — internal key / JS name
     *   label    (string)  — display label
     *   type     (string)  — 'text'|'email'|'tel'|'date'|'textarea'
     *   sortable (bool)    — sortable column in the members table
     *   list_col (bool)    — true = shown as a column in the list; false = edit modal only
     *   meta     (bool)    — false = core column, true = stored in extension plugin's own table
     */
    public static function get_field_definitions(): array {
        $core = [
            [ 'key' => 'member_number', 'label' => __( '#',           'pcio-vis-member-event' ), 'type' => 'text',     'sortable' => true,  'list_col' => true,  'meta' => false ],
            [ 'key' => 'name',          'label' => __( 'Name',        'pcio-vis-member-event' ), 'type' => 'text',     'sortable' => true,  'list_col' => true,  'meta' => false ],
            [ 'key' => 'email',         'label' => __( 'Email',       'pcio-vis-member-event' ), 'type' => 'email',    'sortable' => true,  'list_col' => true,  'meta' => false ],
            [ 'key' => 'phone',         'label' => __( 'Phone',       'pcio-vis-member-event' ), 'type' => 'tel',      'sortable' => false, 'list_col' => true,  'meta' => false ],
            [ 'key' => 'address',       'label' => __( 'Address',     'pcio-vis-member-event' ), 'type' => 'textarea', 'sortable' => false, 'list_col' => false, 'meta' => false ],
            [ 'key' => 'join_date',     'label' => __( 'Joined',      'pcio-vis-member-event' ), 'type' => 'date',     'sortable' => true,  'list_col' => false, 'meta' => false ],
            [ 'key' => 'leave_date',    'label' => __( 'Left',        'pcio-vis-member-event' ), 'type' => 'date',     'sortable' => true,  'list_col' => false, 'meta' => false ],
            [ 'key' => 'is_volunteer',  'label' => __( 'Volunteer',   'pcio-vis-member-event' ), 'type' => 'checkbox', 'sortable' => false, 'list_col' => false, 'meta' => false ],
        ];
        /**
         * Filter: pcio_me_member_fields
         * Allows extension plugins to append extra member fields.
         * Each item must follow the field shape described above with meta=true.
         *
         * @param array $fields
         */
        return apply_filters( 'pcio_me_member_fields', $core );
    }

    // ── CRUD ──────────────────────────────────────────────────────

    public static function get_all(): array {
        global $wpdb;
        $members = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC', self::table() ),
            ARRAY_A
        ) ?: [];

        if ( empty( $members ) ) {
            return [];
        }

        // Bulk-fetch role assignments (single query, no N+1)
        $ids         = array_column( $members, 'id' );
        $role_map    = PCIO_VIS_Roles_DB::get_bulk_roles( array_map( 'intval', $ids ) );

        foreach ( $members as &$m ) {
            $r = $role_map[ (int) $m['id'] ] ?? null;
            $m['vis_role']      = $r['vis_role']      ?? '';
            $m['vis_role_name'] = $r['vis_role_name'] ?? '';
            $m['wp_user_id']    = $r ? (int) ( $r['wp_user_id'] ?? 0 ) : 0;
            $m['wp_role']       = '';
            $m['wp_user_locked'] = false;
            // The WP account link is the stored wp_user_id only (no email guessing).
            if ( ! empty( $m['wp_user_id'] ) ) {
                $user         = get_userdata( (int) $m['wp_user_id'] );
                $m['wp_role'] = $user ? ( $user->roles[0] ?? '' ) : '';
            }
            /**
             * Filter: pcio_me_member_row
             * Extension plugins use this to merge fields from their own table.
             *
             * @param array $member  Core member row.
             */
            $m = apply_filters( 'pcio_me_member_row', $m );
        }
        unset( $m );

        return $members;
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
            ARRAY_A
        );
        if ( ! $row ) {
            return null;
        }
        // Decorate with role data
        $r = PCIO_VIS_Roles_DB::get_member_role( $id );
        $row['vis_role']      = $r['vis_role']      ?? '';
        $row['vis_role_name'] = $r['vis_role_name'] ?? '';
        $row['wp_user_id']    = $r ? (int) ( $r['wp_user_id'] ?? 0 ) : 0;
        $row['wp_role']       = '';
        $row['wp_user_locked'] = false;
        // The WP account link is the stored wp_user_id only (no email guessing).
        if ( ! empty( $row['wp_user_id'] ) ) {
            $user           = get_userdata( (int) $row['wp_user_id'] );
            $row['wp_role'] = $user ? ( $user->roles[0] ?? '' ) : '';
        }
        return apply_filters( 'pcio_me_member_row', $row );
    }

    /** @return int|false */
    public static function create( array $data ) {
        global $wpdb;
        $result = $wpdb->insert( self::table(), self::sanitize_core( $data ) );
        if ( ! $result ) {
            return false;
        }
        $id = $wpdb->insert_id;
        /**
         * Action: pcio_me_member_saved
         *
         * @param int   $id    Member ID.
         * @param array $data  Raw submitted data (core + meta fields).
         */
        do_action( 'pcio_me_member_saved', $id, $data );
        return $id;
    }

    /** @return int|false  int = rows affected (0 is valid), false = DB error */
    public static function update( int $id, array $data ) {
        global $wpdb;
        $result = $wpdb->update( self::table(), self::sanitize_core( $data ), [ 'id' => $id ] );
        if ( false !== $result ) {
            do_action( 'pcio_me_member_saved', $id, $data );
        }
        return $result;
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        $ok = (bool) $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
        if ( $ok ) {
            // Clean up role assignment (replaces the FK CASCADE we can't use with dbDelta).
            PCIO_VIS_Roles_DB::clear_member_role( $id );
            /**
             * Action: pcio_me_member_deleted
             *
             * @param int $id  Deleted member ID.
             */
            do_action( 'pcio_me_member_deleted', $id );
        }
        return $ok;
    }

    /**
     * Returns an array of ['name' => ..., 'email' => ...] for all members
     * that have a non-empty email address. Used for bulk mail sending.
     */
    public static function get_emails(): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT name, email FROM %i WHERE email != %s ORDER BY name ASC',
                self::table(),
                ''
            ),
            ARRAY_A
        ) ?: [];
    }

    /** Returns name+email pairs for all members flagged as volunteers with a non-empty email. */
    public static function get_volunteer_emails(): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT name, email FROM %i WHERE is_volunteer = 1 AND email != %s ORDER BY name ASC',
                self::table(),
                ''
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Find a member id by (exact) email address. Returns 0 when none matches.
     */
    public static function find_id_by_email( string $email ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT id FROM %i WHERE email = %s', self::table(), $email )
        );
    }

    /**
     * Next member number = highest existing numeric member_number + 1.
     */
    public static function get_next_member_number(): int {
        global $wpdb;
        $max = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COALESCE(MAX(member_number), 0) FROM %i', self::table() )
        );
        return ( $max + 1 );
    }

    /**
     * Next member number that no member holds yet.
     *
     * get_next_member_number() is a read-then-write sequence, so two signups
     * running at the same time can pick the same number. This walks past the
     * numbers that were claimed in the meantime.
     */
    public static function get_next_free_member_number(): int {
        $candidate = self::get_next_member_number();
        for ( $i = 0; $i < 100 && self::member_number_is_taken( $candidate ); $i++ ) {
            $candidate++;
        }
        return $candidate;
    }

    /** Whether any member already holds this membership number. */
    public static function member_number_is_taken( int $number ): bool {
        global $wpdb;
        if ( $number <= 0 ) {
            return false;
        }
        return (bool) $wpdb->get_var(
            $wpdb->prepare( 'SELECT id FROM %i WHERE member_number = %d LIMIT 1', self::table(), $number )
        );
    }

    /**
     * Create a member and assign it the next free membership number.
     *
     * Any member_number in $data is ignored — the number is allocated here.
     *
     * @param array $data Member fields.
     * @return int|false Member ID, or false when the insert never succeeded.
     */
    public static function create_with_next_number( array $data ) {
        unset( $data['member_number'] );
        for ( $attempt = 0; $attempt < 5; $attempt++ ) {
            $number = self::get_next_free_member_number();
            $id     = self::create( array_merge( $data, [ 'member_number' => $number ] ) );
            if ( ! $id ) {
                continue;
            }
            // A concurrent signup may have claimed the same number between our
            // check and our insert; when it did, move this member to a free one.
            if ( count( self::get_members_by_member_number( $number ) ) > 1 ) {
                self::set_member_number( (int) $id, self::get_next_free_member_number() );
            }
            return $id;
        }
        return false;
    }

    /** The membership number a member currently belongs to (0 = none). */
    public static function get_member_number_by_id( int $member_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT member_number FROM %i WHERE id = %d', self::table(), $member_id )
        );
    }

    /** Assign a membership number to a member. */
    public static function set_member_number( int $member_id, int $number ): void {
        global $wpdb;
        $wpdb->update(
            self::table(),
            [ 'member_number' => max( 0, $number ) ],
            [ 'id' => $member_id ],
            [ '%d' ],
            [ '%d' ]
        );
    }

    /** Member ids sharing a membership number (the boat/household group). */
    public static function get_member_ids_by_member_number( int $member_number ): array {
        global $wpdb;
        if ( $member_number <= 0 ) {
            return [];
        }
        $ids = $wpdb->get_col(
            $wpdb->prepare( 'SELECT id FROM %i WHERE member_number = %d', self::table(), $member_number )
        );
        return array_map( 'intval', $ids ?: [] );
    }

    /** Member ids sharing a membership number (the boat/household group). */
    public static function get_members_by_member_number( int $member_number ): array {
        global $wpdb;
        if ( $member_number <= 0 ) {
            return [];
        }
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i WHERE member_number = %d', self::table(), $member_number ),
            ARRAY_A
        );
        return $rows ?: [];
    }

    // ── Internal helpers ──────────────────────────────────────────

    /**
     * Sanitize core (non-meta) fields only.
     */
    private static function sanitize_core( array $data ): array {
        $out = [
            'member_number' => sanitize_text_field(     $data['member_number'] ?? '' ),
            'name'          => sanitize_text_field(     $data['name']          ?? '' ),
            'email'         => sanitize_email(          $data['email']         ?? '' ),
            'phone'         => sanitize_text_field(     $data['phone']         ?? '' ),
            'address'       => sanitize_textarea_field( $data['address']       ?? '' ),
        ];

        // Persist optional date fields only when a non-empty value is submitted.
        foreach ( [ 'join_date', 'leave_date' ] as $date_col ) {
            if ( array_key_exists( $date_col, $data ) ) {
                $val = sanitize_text_field( $data[ $date_col ] ?? '' );
                $out[ $date_col ] = '' === $val ? null : $val;
            }
        }

        if ( array_key_exists( 'is_volunteer', $data ) ) {
            $out['is_volunteer'] = ! empty( $data['is_volunteer'] ) ? 1 : 0;
        }

        if ( array_key_exists( 'member_number', $data ) ) {
            $out['member_number'] = max( 0, (int) $data['member_number'] );
        }

        return $out;
    }

    /**
     * Return members filtered by type:
     *   'all'       - all members (default)
     *   'volunteers'  - is_volunteer = 1
     *   'non_active'  - linked WP user exists but lacks vis_member cap
     */
    public static function get_all_by_type( string $type ): array {
        global $wpdb;

        if ( 'volunteers' === $type ) {
            $members = $wpdb->get_results(
                $wpdb->prepare( 'SELECT * FROM %i WHERE is_volunteer = 1 ORDER BY name ASC', self::table() ),
                ARRAY_A
            ) ?: [];
            if ( empty( $members ) ) {
                return [];
            }
            $ids      = array_map( 'intval', array_column( $members, 'id' ) );
            $role_map = PCIO_VIS_Roles_DB::get_bulk_roles( $ids );
            foreach ( $members as &$m ) {
                $r                  = $role_map[ (int) $m['id'] ] ?? null;
                $m['vis_role']      = $r['vis_role']      ?? '';
                $m['vis_role_name'] = $r['vis_role_name'] ?? '';
                $m['wp_user_id']    = $r ? (int) ( $r['wp_user_id'] ?? 0 ) : 0;
                $m['wp_role']       = '';
                $m['wp_user_locked'] = false;
                if ( ! empty( $m['wp_user_id'] ) ) {
                    $user         = get_userdata( (int) $m['wp_user_id'] );
                    $m['wp_role'] = $user ? ( $user->roles[0] ?? '' ) : '';
                }
                $m = apply_filters( 'pcio_me_member_row', $m );
            }
            unset( $m );
            return $members;
        }

        if ( 'non_active' === $type ) {
            // All members whose linked WP user lacks vis_member cap.
            return array_values( array_filter( self::get_all(), static function ( array $m ): bool {
                $uid = (int) ( $m['wp_user_id'] ?? 0 );
                return $uid > 0 && ! user_can( $uid, PCIO_VIS_Caps::MEMBER_CAP );
            } ) );
        }

        return self::get_all();
    }

}
