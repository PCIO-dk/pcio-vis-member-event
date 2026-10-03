<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCIO_VIS_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/members', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_members' ],
                'permission_callback' => [ $this, 'can_read' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_member' ],
                'permission_callback' => [ $this, 'can_write' ],
            ],
        ] );

        register_rest_route( $ns, '/members/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_member' ],
                'permission_callback' => [ $this, 'can_edit_member' ],
                'args'                => [
                    'id' => [
                        'validate_callback' => fn( $v ) => is_numeric( $v ),
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_member' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [
                    'id' => [
                        'validate_callback' => fn( $v ) => is_numeric( $v ),
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ] );

        // ── Role assignment ───────────────────────────────────────
        register_rest_route( $ns, '/members/(?P<id>\d+)/role', [
            [
                'methods'             => WP_REST_Server::EDITABLE,  // PUT
                'callback'            => [ $this, 'set_member_role' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [
                    'id' => [
                        'validate_callback' => fn( $v ) => is_numeric( $v ),
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ] );

        // ── Vis role definitions (read-only here — managed in admin) ──
        register_rest_route( $ns, '/vis-roles', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_vis_roles' ],
                'permission_callback' => [ $this, 'can_read' ],
            ],
        ] );

        // ── WP roles (read-only) ──────────────────────────────────
        register_rest_route( $ns, '/wp-roles', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_wp_roles' ],
                'permission_callback' => [ $this, 'can_read' ],
            ],
        ] );
    }

    // ── Permissions ───────────────────────────────────────────────

    /**
     * Read access: Vis members may view the member directory; managers always can.
     */
    public function can_read(): bool {
        return current_user_can( 'vis_member' ) || current_user_can( 'vis_manage_users' );
    }

    /**
     * Full write access (create, delete, roles, account provisioning).
     */
    public function can_write(): bool {
        return current_user_can( 'vis_manage_users' );
    }

    /**
     * Edit a single member: managers may edit anyone; a member may edit only the
     * record linked to their own WordPress account.
     */
    public function can_edit_member( WP_REST_Request $req ): bool {
        if ( current_user_can( 'vis_manage_users' ) ) {
            return true;
        }
        return $this->is_own_member( (int) $req->get_param( 'id' ) );
    }

    /**
     * Whether the given member record is linked to the current WordPress user.
     */
    private function is_own_member( int $member_id ): bool {
        $uid = get_current_user_id();
        if ( $uid <= 0 || $member_id <= 0 ) {
            return false;
        }
        $member = PCIO_VIS_DB::get( $member_id );
        return $member && (int) ( $member['wp_user_id'] ?? 0 ) === $uid;
    }

    // ── Handlers ──────────────────────────────────────────────────

    public function list_members( WP_REST_Request $req ): WP_REST_Response {
        $type = sanitize_key( $req->get_param( 'type' ) ?? 'all' );
        return new WP_REST_Response( PCIO_VIS_DB::get_all_by_type( $type ), 200 );
    }

    public function create_member( WP_REST_Request $req ): WP_REST_Response {
        $id = PCIO_VIS_DB::create( (array) $req->get_json_params() );
        if ( ! $id ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not create member.', 'pcio-vis-member-event' ) ], 500 );
        }
        return new WP_REST_Response( PCIO_VIS_DB::get( (int) $id ), 201 );
    }

    public function update_member( WP_REST_Request $req ): WP_REST_Response {
        $id   = (int) $req->get_param( 'id' );
        $data = (array) $req->get_json_params();

        // Detect an email change so we can keep a linked WP account in sync.
        $old           = PCIO_VIS_DB::get( $id );
        $old_email     = strtolower( trim( $old['email'] ?? '' ) );
        $new_email     = strtolower( trim( $data['email'] ?? '' ) );
        $linked_uid    = (int) ( $old['wp_user_id'] ?? 0 );
        $email_changed = ( '' !== $old_email && '' !== $new_email && $new_email !== $old_email );

        // Before saving: if the member has a linked WP account and the email is
        // changing, make sure the new address is not already used by a *different*
        // WP user. We never delete accounts — the admin must resolve the conflict.
        if ( $email_changed && $linked_uid > 0 ) {
            $conflict = email_exists( $new_email );
            if ( $conflict && (int) $conflict !== $linked_uid ) {
                return new WP_REST_Response(
                    [ 'message' => __( 'This email belongs to another WordPress user. Unlink that account or use a different email.', 'pcio-vis-member-event' ) ],
                    409
                );
            }
        }

        /**
         * Filter: pcio_me_member_update_validate
         * Lets extensions reject a member save before anything is written.
         * Extensions add their own fields to the payload, so they validate them here.
         *
         * @param array  $result   ['valid' => true, 'message' => '']
         * @param array  $data     Raw submitted data (core + extension fields).
         * @param int    $id       Member ID being updated.
         */
        $ext = apply_filters( 'pcio_me_member_update_validate', [ 'valid' => true, 'message' => '' ], $data, $id );
        if ( ! ( $ext['valid'] ?? true ) ) {
            return new WP_REST_Response(
                [ 'message' => (string) ( $ext['message'] ?? __( 'Validation failed.', 'pcio-vis-member-event' ) ) ],
                422
            );
        }

        $result = PCIO_VIS_DB::update( $id, $data );
        // $result is false on DB error, or an int (0 = no fields changed, ≥1 = changed). Both are valid saves.
        if ( false === $result ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not update member.', 'pcio-vis-member-event' ) ], 500 );
        }

        // Sync vis_volunteer cap when is_volunteer changes.
        if ( array_key_exists( 'is_volunteer', $data ) && $linked_uid > 0 ) {
            if ( ! empty( $data['is_volunteer'] ) ) {
                PCIO_VIS_Caps::grant_volunteer( $linked_uid );
            } else {
                PCIO_VIS_Caps::revoke_volunteer( $linked_uid );
            }
        }

        // Keep the linked WP account's email in sync. The account is never deleted.
        if ( $email_changed && $linked_uid > 0 ) {
            wp_update_user( [
                'ID'         => $linked_uid,
                'user_email' => $new_email,
            ] );
        }

        return new WP_REST_Response( PCIO_VIS_DB::get( $id ), 200 );
    }

    public function delete_member( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );

        // Revoke Vis membership from the linked WP account (the account is kept),
        // then delete the member record. We never delete WordPress accounts.
        $member = PCIO_VIS_DB::get( $id );
        if ( $member && ! empty( $member['wp_user_id'] ) ) {
            PCIO_VIS_Caps::revoke_membership( (int) $member['wp_user_id'] );
        }

        if ( ! PCIO_VIS_DB::delete( $id ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not delete member.', 'pcio-vis-member-event' ) ], 500 );
        }
        return new WP_REST_Response( null, 204 );
    }

    // ── Role assignment handler ───────────────────────────────────

    public function set_member_role( WP_REST_Request $req ): WP_REST_Response {
        $member_id  = (int) $req->get_param( 'id' );
        $params     = (array) $req->get_json_params();
        $vis_role   = sanitize_key( $params['vis_role']   ?? '' );
        $wp_user_id = absint(       $params['wp_user_id'] ?? 0  );

        $member = PCIO_VIS_DB::get( $member_id );
        if ( ! $member ) {
            return new WP_REST_Response( [ 'message' => __( 'Member not found.', 'pcio-vis-member-event' ) ], 404 );
        }

        // Resolve the linked WP user: an explicit ID from the request, otherwise the
        // member's already-linked account. Account creation is a separate, explicit
        // admin action (POST /members/{id}/wp-user); we never create a user here.
        if ( ! $wp_user_id && ! empty( $member['wp_user_id'] ) ) {
            $wp_user_id = (int) $member['wp_user_id'];
        }

        // Persist the Vis role assignment (with the resolved wp_user_id link).
        if ( $vis_role || $wp_user_id ) {
            PCIO_VIS_Roles_DB::set_member_role( $member_id, $vis_role, $wp_user_id );
        } else {
            PCIO_VIS_Roles_DB::clear_member_role( $member_id );
        }

        // A linked WordPress account is, by definition, a Vis member.
        if ( $wp_user_id ) {
            PCIO_VIS_Caps::grant_membership( $wp_user_id );
        }

        return new WP_REST_Response( PCIO_VIS_DB::get( $member_id ), 200 );
    }

    // ── Vis + WP role lists ───────────────────────────────────────

    public function list_vis_roles( WP_REST_Request $req ): WP_REST_Response {
        return new WP_REST_Response( PCIO_VIS_Roles_DB::get_all_roles(), 200 );
    }

    public function list_wp_roles( WP_REST_Request $req ): WP_REST_Response {
        $out = [];
        foreach ( PCIO_VIS_Caps::get_wp_roles() as $slug => $name ) {
            $out[] = [ 'slug' => $slug, 'name' => $name ];
        }
        return new WP_REST_Response( $out, 200 );
    }
}
