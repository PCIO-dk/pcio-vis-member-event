<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Two tables:
 *
 * me_vis_roles      — editable role definitions (slug, name, caps JSON)
 * me_member_roles   — per-member role assignment (vis_role slug, optional wp_user_id)
 */
class PCIO_VIS_Roles_DB {

    // ── Schema ────────────────────────────────────────────────────

    public static function install(): void {
        global $wpdb;
        $vr      = $wpdb->prefix . 'me_vis_roles';
        $mr      = $wpdb->prefix . 'me_member_roles';
        $charset = $wpdb->get_charset_collate();

        $sql_vr = "CREATE TABLE IF NOT EXISTS {$vr} (
            id int unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(50) NOT NULL,
            name varchar(100) NOT NULL,
            caps text NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_vis_role_slug (slug)
        ) {$charset};";
        dbDelta( $sql_vr );

        // FOREIGN KEY is intentionally omitted; assignments are cleaned up in PHP.
        $sql_mr = "CREATE TABLE IF NOT EXISTS {$mr} (
            member_id bigint unsigned NOT NULL,
            vis_role varchar(50) NOT NULL DEFAULT '',
            wp_user_id bigint unsigned NULL,
            PRIMARY KEY  (member_id)
        ) {$charset};";
        dbDelta( $sql_mr );

        // Seed defaults on first install
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $vr ) ) ) {
            self::seed_defaults();
        }
    }

    /**
     * Seed the default vis roles from PCIO_VIS_Caps::LOGICAL_ROLES.
     */
    public static function seed_defaults(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'me_vis_roles';
        foreach ( PCIO_VIS_Caps::LOGICAL_ROLES as $name => $caps ) {
            $wpdb->replace( $table, [
                'slug' => sanitize_title( $name ),
                'name' => $name,
                'caps' => wp_json_encode( array_values( $caps ) ),
            ] );
        }
    }

    /**
     * Default capability map (vis-role slug => cap slugs), built from the
     * logical role definitions and extendable by add-on plugins.
     *
     * @return array<string,string[]>
     */
    public static function default_role_caps(): array {
        $defaults = [];
        foreach ( PCIO_VIS_Caps::LOGICAL_ROLES as $name => $caps ) {
            $defaults[ sanitize_title( $name ) ] = array_values( $caps );
        }
        /**
         * Filter: pcio_me_default_role_caps
         * Map of vis-role slug => array of default cap slugs. Extensions append
         * their caps to the relevant role(s) so the "Sync capabilities" action
         * grants newly introduced caps to existing installs.
         *
         * @param array<string,string[]> $defaults
         */
        return apply_filters( 'pcio_me_default_role_caps', $defaults );
    }

    /**
     * Additively merge the latest default capabilities into the matching
     * seeded Vis roles (by slug). Never removes caps an admin has chosen, so
     * newly introduced caps (e.g. vis_manage_finance) flow into existing
     * installs without wiping customisations.
     *
     * @return int Number of Vis roles updated.
     */
    public static function sync_default_caps(): int {
        $updated = 0;
        foreach ( self::default_role_caps() as $slug => $caps ) {
            $role = self::get_role_by_slug( (string) $slug );
            if ( ! $role ) {
                continue;
            }
            $merged = array_values( array_unique(
                array_merge( $role['caps'], array_values( (array) $caps ) )
            ) );
            if ( count( $merged ) !== count( $role['caps'] ) ) {
                self::update_role( (int) $role['id'], [ 'caps' => $merged ] );
                $updated++;
            }
        }
        return $updated;
    }

    // ── Vis role definitions ──────────────────────────────────────

    public static function get_all_roles(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', $wpdb->prefix . 'me_vis_roles' ),
            ARRAY_A
        ) ?: [];
        return array_map( [ __CLASS__, 'decode_caps' ], $rows );
    }

    public static function get_role( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'me_vis_roles', $id ),
            ARRAY_A
        );
        return $row ? self::decode_caps( $row ) : null;
    }

    public static function get_role_by_slug( string $slug ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s', $wpdb->prefix . 'me_vis_roles', $slug ),
            ARRAY_A
        );
        return $row ? self::decode_caps( $row ) : null;
    }

    public static function create_role( array $data ): int|false {
        global $wpdb;
        $ok = $wpdb->insert( $wpdb->prefix . 'me_vis_roles', [
            'slug' => sanitize_key( $data['slug'] ?? '' ),
            'name' => sanitize_text_field( $data['name'] ?? '' ),
            'caps' => wp_json_encode( array_values( (array) ( $data['caps'] ?? [] ) ) ),
        ] );
        return $ok ? (int) $wpdb->insert_id : false;
    }

    public static function update_role( int $id, array $data ): bool {
        global $wpdb;
        $fields = [];
        if ( isset( $data['slug'] ) ) $fields['slug'] = sanitize_key( $data['slug'] );
        if ( isset( $data['name'] ) ) $fields['name'] = sanitize_text_field( $data['name'] );
        if ( isset( $data['caps'] ) ) $fields['caps'] = wp_json_encode( array_values( (array) $data['caps'] ) );
        if ( empty( $fields ) ) return false;
        return (bool) $wpdb->update( $wpdb->prefix . 'me_vis_roles', $fields, [ 'id' => $id ] );
    }

    public static function delete_role( int $id ): bool {
        global $wpdb;
        // Clear member assignments pointing to this role slug first
        $role = self::get_role( $id );
        if ( $role ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE %i SET vis_role='' WHERE vis_role=%s",
                $wpdb->prefix . 'me_member_roles',
                $role['slug']
            ) );
        }
        return (bool) $wpdb->delete( $wpdb->prefix . 'me_vis_roles', [ 'id' => $id ] );
    }

    private static function decode_caps( array $row ): array {
        $row['caps'] = json_decode( $row['caps'] ?? '[]', true ) ?: [];
        return $row;
    }

    // ── Member role assignments ───────────────────────────────────

    /**
     * Returns assignment row for one member, or null.
     */
    public static function get_member_role( int $member_id ): ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT mr.*, vr.name AS vis_role_name
                 FROM   %i mr
                 LEFT JOIN %i vr ON vr.slug = mr.vis_role
                 WHERE  mr.member_id = %d",
                $wpdb->prefix . 'me_member_roles',
                $wpdb->prefix . 'me_vis_roles',
                $member_id
            ),
            ARRAY_A
        ) ?: null;
    }

    /**
     * Bulk-fetch assignments for many members at once (avoids N+1 in get_all).
     * Returns [ member_id => row ]
     *
     * @param int[] $member_ids
     * @return array<int,array>
     */
    public static function get_bulk_roles( array $member_ids ): array {
        if ( empty( $member_ids ) ) return [];
        $indexed = [];
        foreach ( $member_ids as $member_id ) {
            $row = self::get_member_role( (int) $member_id );
            if ( $row ) {
                $indexed[ (int) $member_id ] = $row;
            }
        }
        return $indexed;
    }

    /**
     * Find the member id linked to a given WordPress user (0 when none).
     */
    public static function get_member_id_by_wp_user( int $wp_user_id ): int {
        if ( $wp_user_id <= 0 ) {
            return 0;
        }
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT member_id FROM %i WHERE wp_user_id = %d LIMIT 1",
                $wpdb->prefix . 'me_member_roles',
                $wp_user_id
            )
        );
    }

    /**
     * Returns the vis_* capabilities granted to a WP user through the vis_role
     * assigned to their linked member record. Returns [] when the user has no
     * linked member or assigned role.
     *
     * Whether these caps actually apply is gated separately by the vis_member
     * marker capability (see PCIO_VIS_Caps): a linked account only receives its
     * Vis capabilities while it carries vis_member.
     *
     * @return string[] List of cap slugs.
     */
    public static function get_caps_for_wp_user( int $wp_user_id ): array {
        if ( $wp_user_id <= 0 ) {
            return [];
        }
        global $wpdb;
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT vr.caps
                 FROM   %i mr
                 JOIN   %i   vr ON vr.slug = mr.vis_role
                 WHERE  mr.wp_user_id = %d",
                $wpdb->prefix . 'me_member_roles',
                $wpdb->prefix . 'me_vis_roles',
                $wp_user_id
            )
        ) ?: [];
        $caps = [];
        foreach ( $rows as $json ) {
            foreach ( (array) ( json_decode( (string) $json, true ) ?: [] ) as $cap ) {
                if ( is_string( $cap ) && $cap !== '' ) {
                    $caps[] = $cap;
                }
            }
        }
        return array_values( array_unique( $caps ) );
    }

    /**
     * Set (upsert) the role assignment for a member.
     * Pass $wp_user_id = 0 to clear the WP user link.
     */
    public static function set_member_role( int $member_id, string $vis_role, int $wp_user_id = 0 ): bool {
        global $wpdb;
        return (bool) $wpdb->replace( $wpdb->prefix . 'me_member_roles', [
            'member_id'  => $member_id,
            'vis_role'   => sanitize_key( $vis_role ),
            'wp_user_id' => $wp_user_id ?: null,
        ] );
    }

    /**
     * Remove a member's role assignment entirely.
     */
    public static function clear_member_role( int $member_id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete(
            $wpdb->prefix . 'me_member_roles',
            [ 'member_id' => $member_id ]
        );
    }

    /**
     * One-time migration: link members to an existing WP user by matching email.
     *
     * Runs when upgrading from the era when the member↔WP-user link was resolved
     * at runtime via email_exists(). It seeds the now-authoritative stored link
     * (me_member_roles.wp_user_id) for members that do not yet have one, so the
     * removal of the runtime auto-detection does not drop existing links.
     *
     * @return int Number of members newly linked.
     */
    public static function backfill_links_from_email(): int {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.id AS member_id, m.email AS email, mr.vis_role AS vis_role
                 FROM   %i m
                 LEFT JOIN %i mr ON mr.member_id = m.id
                 WHERE  m.email <> %s
                   AND  ( mr.wp_user_id IS NULL OR mr.wp_user_id = 0 )",
                $wpdb->prefix . 'me_members',
                $wpdb->prefix . 'me_member_roles',
                ''
            ),
            ARRAY_A
        ) ?: [];

        $linked = 0;
        foreach ( $rows as $row ) {
            $uid = email_exists( (string) $row['email'] );
            if ( ! $uid ) {
                continue;
            }
            self::set_member_role( (int) $row['member_id'], (string) ( $row['vis_role'] ?? '' ), (int) $uid );
            ++$linked;
        }
        return $linked;
    }

    /**
     * One-time migration: grant the vis_member marker capability to every WP user
     * currently linked to a member, so existing members keep access when the
     * plugin switches to capability-based membership.
     *
     * @return int Number of accounts marked.
     */
    public static function backfill_member_caps(): int {
        global $wpdb;
        $uids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT wp_user_id FROM %i WHERE wp_user_id IS NOT NULL AND wp_user_id > 0",
                $wpdb->prefix . 'me_member_roles'
            )
        ) ?: [];

        $count = 0;
        foreach ( $uids as $uid ) {
            PCIO_VIS_Caps::grant_membership( (int) $uid );
            ++$count;
        }
        return $count;
    }

    /**
     * WordPress users that are NOT linked to any member.
     *
     * Used by the admin cleanup report. Deletion is intentionally left to the
     * native WordPress Users screen (with its own delete_users capability and
     * security-plugin checks) — this plugin never deletes accounts.
     *
     * @return array<int,array> Rows: ID, user_login, user_email, user_registered.
     */
    public static function get_orphan_wp_users(): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.ID, u.user_login, u.user_email, u.user_registered
                 FROM {$wpdb->users} u
                 WHERE u.ID NOT IN (
                     SELECT mr.wp_user_id
                     FROM   %i mr
                     WHERE  mr.wp_user_id IS NOT NULL
                       AND  mr.wp_user_id > 0
                 )
                 ORDER BY u.user_registered ASC",
                $wpdb->prefix . 'me_member_roles'
            ),
            ARRAY_A
        ) ?: [];
    }
}
