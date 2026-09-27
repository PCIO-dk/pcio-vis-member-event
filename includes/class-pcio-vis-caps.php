<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Capability system for PCIO VIS Member Event.
 *
 * Custom capabilities are stored directly on WP roles (wp_user_roles option)
 * so they work with current_user_can() like any native WP capability.
 *
 * Vis roles are cumulative — each level includes all caps of the level below:
 *   Member        (vis_member marker only)
 *   Volunteer     + vis_create_own_events, vis_edit_own_events
 *   Event Manager + vis_edit_all_events
 *   Publisher     + vis_publish_events
 *   System Admin  + vis_manage_users, vis_manage_settings
 *
 * These are logical roles, not separate WP roles. They are assigned by
 * granting caps to the built-in WP roles on the Vis → Roles admin page.
 */
class PCIO_VIS_Caps {

    /**
     * Bump this whenever ROLE_DEFAULTS changes so that the new caps are granted
     * to existing installs without requiring a manual deactivate/reactivate.
     */
    const CAPS_VERSION = 4;

    const VERSION_OPTION = 'pcio_me_caps_version';

    /**
     * Per-user marker capability that identifies a WordPress account as a Vis
     * member. It is granted/revoked on individual users (never on roles) and is
     * the switch that turns Vis member access on or off. Removing it "deletes"
     * the membership without deleting the WordPress account.
     */
    const MEMBER_CAP    = 'vis_member';
    const VOLUNTEER_CAP = 'vis_volunteer';

    // ── Capability definitions ────────────────────────────────────

    /** All capabilities owned by this plugin. */
    const ALL_CAPS = [
        'vis_volunteer',
        'vis_create_own_events',
        'vis_edit_own_events',
        'vis_edit_all_events',
        'vis_publish_events',
        'vis_manage_users',
        'vis_manage_settings',
        'vis_manage_finance',
    ];

    /** Human-readable labels used in the admin UI. */
    const CAP_LABELS = [
        'vis_volunteer'           => 'Volunteer',
        'vis_create_own_events'   => 'Create own events',
        'vis_edit_own_events'     => 'Edit own events',
        'vis_edit_all_events'     => 'Edit all events',
        'vis_publish_events'      => 'Publish / manage documents',
        'vis_manage_users'        => 'Manage members',
        'vis_manage_settings'     => 'Manage settings & mails',
        'vis_manage_finance'      => 'Manage finance',
    ];

    /**
     * Logical role groupings — cumulative (each level includes all caps from levels below).
     * Used for the admin legend and to seed the me_vis_roles DB table on first install.
     */
    const LOGICAL_ROLES = [
        'Member'        => [],
        'Volunteer'     => [
            'vis_create_own_events',
            'vis_edit_own_events',
        ],
        'Event Manager' => [
            'vis_create_own_events',
            'vis_edit_own_events',
            'vis_edit_all_events',
        ],
        'Publisher'     => [
            'vis_create_own_events',
            'vis_edit_own_events',
            'vis_edit_all_events',
            'vis_publish_events',
        ],
        'System Admin'  => [
            'vis_create_own_events',
            'vis_edit_own_events',
            'vis_edit_all_events',
            'vis_publish_events',
            'vis_manage_users',
            'vis_manage_settings',
            'vis_manage_finance',
        ],
    ];

    /** Default capability grants per WP role (applied on activation). */
    const ROLE_DEFAULTS = [
        'subscriber'    => [],
        'contributor'   => [],
        'author'        => [
            'vis_create_own_events',
            'vis_edit_own_events',
        ],
        'editor'        => [
            'vis_create_own_events',
            'vis_edit_own_events',
            'vis_edit_all_events',
            'vis_publish_events',
        ],
        'administrator' => [
            'vis_create_own_events',
            'vis_edit_own_events',
            'vis_edit_all_events',
            'vis_publish_events',
            'vis_manage_users',
            'vis_manage_settings',
            'vis_manage_finance',
        ],
    ];

    // ── Lifecycle ─────────────────────────────────────────────────

    /**
     * Called on plugin activation.
     * Adds default caps to each WP role (only adds — never removes).
     */
    public static function activate(): void {
        foreach ( self::ROLE_DEFAULTS as $role_slug => $caps ) {
            $role = get_role( $role_slug );
            if ( ! $role ) {
                continue;
            }
            foreach ( $caps as $cap ) {
                $role->add_cap( $cap );
            }
        }
        update_option( self::VERSION_OPTION, self::CAPS_VERSION );
    }

    /**
     * Self-heal: re-grant default caps when CAPS_VERSION has advanced past the
     * value stored for this install. Runs cheaply on every admin request but
     * only touches the DB when a version bump is detected (e.g. after the
     * vis_manage_finance cap was added). Avoids needing a manual reactivation.
     */
    public static function maybe_sync(): void {
        if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::CAPS_VERSION ) {
            return;
        }
        // Remove the retired vis_access cap from all roles on first run after the upgrade.
        foreach ( wp_roles()->role_objects as $role ) {
            $role->remove_cap( 'vis_access' );
        }
        self::activate();
    }

    /**
     * Registers runtime hooks (call from plugin constructor, not on activation).
     * Dynamically grants upload_files to any user with vis_publish_events or
     * vis_edit_all_events so they can use the WP media library picker on the
     * Mails, Documents, and Event (Photos tab) pages.
     */
    public static function init(): void {
        // Self-heal default role caps after a CAPS_VERSION bump (no manual
        // deactivate/reactivate needed). Cheap no-op once synced.
        add_action( 'admin_init', [ self::class, 'maybe_sync' ] );

        add_filter( 'user_has_cap', static function ( array $allcaps, array $caps, array $args, $user ): array {
            $uid = ( is_object( $user ) && isset( $user->ID ) ) ? (int) $user->ID : 0;

            // Grant the vis_* caps from the user's assigned vis_role (per-member
            // role assignment) — but only to accounts that are Vis members, i.e.
            // that carry the vis_member marker capability. Removing vis_member
            // therefore revokes all member access without deleting the account.
            if ( $uid > 0 && ! empty( $allcaps[ self::MEMBER_CAP ] ) ) {
                static $cache = [];
                if ( ! array_key_exists( $uid, $cache ) ) {
                    $cache[ $uid ] = PCIO_VIS_Roles_DB::get_caps_for_wp_user( $uid );
                }
                foreach ( $cache[ $uid ] as $cap ) {
                    $allcaps[ $cap ] = true;
                }
            }

            // Anyone who can publish/manage events also gets the media library.
            if ( ! empty( $allcaps['vis_publish_events'] ) || ! empty( $allcaps['vis_edit_all_events'] ) ) {
                $allcaps['upload_files'] = true;
            }
            return $allcaps;
        }, 10, 4 );
    }

    /**
     * Called on plugin deactivation.
     * Strips all vis_* caps from every WP role.
     */
    public static function deactivate(): void {
        foreach ( wp_roles()->role_objects as $role ) {
            foreach ( self::ALL_CAPS as $cap ) {
                $role->remove_cap( $cap );
            }
        }
    }

    // ── Membership marker (vis_member) ─────────────────────────────

    public static function grant_membership( int $wp_user_id ): void {
        $user = $wp_user_id > 0 ? get_userdata( $wp_user_id ) : false;
        if ( $user ) {
            $user->add_cap( self::MEMBER_CAP );
        }
    }

    public static function revoke_membership( int $wp_user_id ): void {
        $user = $wp_user_id > 0 ? get_userdata( $wp_user_id ) : false;
        if ( $user ) {
            $user->remove_cap( self::MEMBER_CAP );
        }
    }

    public static function is_member( int $wp_user_id ): bool {
        return $wp_user_id > 0 && user_can( $wp_user_id, self::MEMBER_CAP );
    }

    // ── Volunteer marker (vis_volunteer) ──────────────────────────

    public static function grant_volunteer( int $wp_user_id ): void {
        $user = $wp_user_id > 0 ? get_userdata( $wp_user_id ) : false;
        if ( $user ) {
            $user->add_cap( self::VOLUNTEER_CAP );
        }
    }

    public static function revoke_volunteer( int $wp_user_id ): void {
        $user = $wp_user_id > 0 ? get_userdata( $wp_user_id ) : false;
        if ( $user ) {
            $user->remove_cap( self::VOLUNTEER_CAP );
        }
    }

    public static function is_volunteer( int $wp_user_id ): bool {
        return $wp_user_id > 0 && user_can( $wp_user_id, self::VOLUNTEER_CAP );
    }

    /**
     * Create or link a WP subscriber account for a Vis member.
     *
     * Returns an array with 'wp_user_id' (0 on failure) and 'is_new' (true
     * when a fresh account was just created). Callers decide on notifications.
     *
     * @return array{wp_user_id: int, is_new: bool}
     */
    public static function provision_member_wp_account(
        int $member_id,
        string $email,
        string $name,
        string $vis_role = 'member'
    ): array {
        $email = sanitize_email( $email );
        if ( ! is_email( $email ) ) {
            return [ 'wp_user_id' => 0, 'is_new' => false ];
        }

        $existing = email_exists( $email );
        if ( $existing ) {
            $wp_user_id = (int) $existing;
            PCIO_VIS_Roles_DB::set_member_role( $member_id, $vis_role, $wp_user_id );
            self::grant_membership( $wp_user_id );
            return [ 'wp_user_id' => $wp_user_id, 'is_new' => false ];
        }

        $base  = sanitize_user( (string) current( explode( '@', $email ) ), true );
        $base  = $base !== '' ? $base : 'member';
        $login = $base;
        $i     = 1;
        while ( username_exists( $login ) ) {
            $login = $base . $i++;
        }
        $parts = explode( ' ', trim( $name ), 2 );
        $uid   = wp_insert_user( [
            'user_login'   => $login,
            'user_email'   => $email,
            'display_name' => trim( $name ),
            'first_name'   => $parts[0] ?? '',
            'last_name'    => $parts[1] ?? '',
            'role'         => 'subscriber',
            'user_pass'    => wp_generate_password( 24, true, true ),
        ] );

        if ( is_wp_error( $uid ) ) {
            return [ 'wp_user_id' => 0, 'is_new' => false ];
        }

        $wp_user_id = (int) $uid;
        PCIO_VIS_Roles_DB::set_member_role( $member_id, $vis_role, $wp_user_id );
        self::grant_membership( $wp_user_id );
        return [ 'wp_user_id' => $wp_user_id, 'is_new' => true ];
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * All vis_* capabilities, including any registered by extensions.
     *
     * @return string[]
     */
    public static function all_caps(): array {
        /**
         * Filter: pcio_me_all_caps
         * Extensions append their own vis_* capabilities so they appear in the
         * Vis Roles admin matrix.
         *
         * @param string[] $caps
         */
        return array_values( array_unique( apply_filters( 'pcio_me_all_caps', self::ALL_CAPS ) ) );
    }

    /**
     * Human-readable labels for all capabilities (extensions may add their own).
     *
     * @return array<string,string>
     */
    public static function cap_labels(): array {
        /**
         * Filter: pcio_me_cap_labels
         *
         * @param array<string,string> $labels
         */
        return apply_filters( 'pcio_me_cap_labels', self::CAP_LABELS );
    }

    public static function can( string $cap ): bool {
        return current_user_can( $cap );
    }

    /**
     * Returns the vis_* caps the current user has (for JS injection).
     *
     * @return string[]
     */
    public static function current_user_caps(): array {
        $user = wp_get_current_user();
        if ( ! $user->exists() ) {
            return [];
        }
        return array_values( array_filter(
            self::ALL_CAPS,
            fn( $cap ) => $user->has_cap( $cap )
        ) );
    }

    /**
     * Returns all WP roles as [ slug => display_name ], excluding super-admin.
     *
     * @return array<string,string>
     */
    public static function get_wp_roles(): array {
        $out = [];
        foreach ( wp_roles()->role_objects as $slug => $role ) {
            $out[ $slug ] = translate_user_role( wp_roles()->roles[ $slug ]['name'] ?? $slug );
        }
        return $out;
    }

    /**
     * Returns the cap matrix: [ role_slug => [ cap => bool ] ]
     *
     * @return array<string, array<string, bool>>
     */
    public static function get_matrix(): array {
        $matrix = [];
        foreach ( wp_roles()->role_objects as $slug => $role ) {
            $matrix[ $slug ] = [];
            foreach ( self::all_caps() as $cap ) {
                $matrix[ $slug ][ $cap ] = ! empty( $role->capabilities[ $cap ] );
            }
        }
        return $matrix;
    }
}
