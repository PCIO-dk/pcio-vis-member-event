<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCIO_VIS_Signups_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        // GET  /events/{id}/signups  — aggregate counts + optional member list
        register_rest_route( $ns, '/events/(?P<id>\d+)/signups', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_signups' ],
            'permission_callback' => [ $this, 'can_read' ],
            'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
        ] );

        // PUT  /events/{id}/signup   — upsert own signup
        register_rest_route( $ns, '/events/(?P<id>\d+)/signup', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'upsert_signup' ],
                'permission_callback' => [ $this, 'can_signup' ],
                'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_signup' ],
                'permission_callback' => [ $this, 'can_signup' ],
                'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
        ] );

        // GET  /events/{id}/signups/available  — members without a signup (admin only)
        register_rest_route( $ns, '/events/(?P<id>\d+)/signups/available', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_available' ],
            'permission_callback' => [ $this, 'can_admin' ],
            'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
        ] );

        // PUT  /events/{id}/signups/{member_id}  — admin override for any member
        register_rest_route( $ns, '/events/(?P<id>\d+)/signups/(?P<member_id>\d+)', [
            'methods'             => WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'update_member_signup' ],
            'permission_callback' => [ $this, 'can_admin' ],
            'args'                => [
                'id'        => [ 'sanitize_callback' => 'absint' ],
                'member_id' => [ 'sanitize_callback' => 'absint' ],
            ],
        ] );
    }

    // ── Permissions ───────────────────────────────────────────────

    /** Any member with the base capability may read signup status/counts. */
    public function can_read(): bool {
        return current_user_can( 'vis_member' );
    }

    /** Event managers only. */
    public function can_admin(): bool {
        return current_user_can( 'vis_edit_all_events' );
    }

    /**
     * To sign up the user must:
     *   1. Have the vis_member capability.
     *   2. Be linked to a member record via me_member_roles.
     */
    public function can_signup(): bool|WP_Error {
        if ( ! current_user_can( 'vis_member' ) ) {
            return false;
        }
        if ( PCIO_VIS_Signups_DB::get_member_id_for_current_user() === null ) {
            return new WP_Error(
                'no_member_link',
                __( 'Your user account is not linked to a member record.', 'pcio-vis-member-event' ),
                [ 'status' => 403 ]
            );
        }
        return true;
    }

    // ── Handlers ──────────────────────────────────────────────────

    /**
     * GET /events/{id}/signups
     *
     * Returns:
     *   my_status  — current user's status (or null)
     *   counts     — { joining, interested, not_joining }
     *   members    — full list; only included for vis_edit_all_events users
     */
    public function get_signups( WP_REST_Request $req ): WP_REST_Response {
        try {
            $event_id  = (int) $req->get_param( 'id' );
            $member_id = PCIO_VIS_Signups_DB::get_member_id_for_current_user();

            $my_row           = $member_id ? PCIO_VIS_Signups_DB::get_for_member_event( $member_id, $event_id ) : null;
            $counts           = PCIO_VIS_Signups_DB::get_counts( $event_id );
            $counts['not_answered'] = PCIO_VIS_Signups_DB::get_not_answered_count( $event_id );

            $data = [
                'my_status' => $my_row ? $my_row['status'] : null,
                'counts'    => $counts,
            ];

            // Full member list only for event managers+
            // Exclude 'interested' rows from the list (they still appear in counts).
            if ( current_user_can( 'vis_edit_all_events' ) ) {
                $all = PCIO_VIS_Signups_DB::get_for_event( $event_id );
                $data['members'] = array_values(
                    array_filter( $all, fn( $r ) => $r['status'] !== 'interested' )
                );
            }

            return new WP_REST_Response( $data, 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * PUT /events/{id}/signup
     * Body: { "status": "joining"|"interested"|"not_joining" }
     */
    public function upsert_signup( WP_REST_Request $req ): WP_REST_Response {
        try {
            $event_id  = (int) $req->get_param( 'id' );
            $member_id = PCIO_VIS_Signups_DB::get_member_id_for_current_user();

            $params = (array) $req->get_json_params();
            $status = sanitize_key( $params['status'] ?? '' );

            if ( ! in_array( $status, PCIO_VIS_Signups_DB::VALID_STATUSES, true ) ) {
                return new WP_REST_Response(
                    [ 'message' => __( 'Invalid status value.', 'pcio-vis-member-event' ) ],
                    400
                );
            }

            // Verify the event exists.
            if ( ! PCIO_VIS_Events_DB::get( $event_id ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Event not found.', 'pcio-vis-member-event' ) ], 404 );
            }

            if ( ! PCIO_VIS_Signups_DB::upsert( $event_id, $member_id, $status ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not save signup.', 'pcio-vis-member-event' ) ], 500 );
            }

            return new WP_REST_Response(
                PCIO_VIS_Signups_DB::get_for_member_event_full( $member_id, $event_id ),
                200
            );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * DELETE /events/{id}/signup
     */
    public function delete_signup( WP_REST_Request $req ): WP_REST_Response {
        try {
            $event_id  = (int) $req->get_param( 'id' );
            $member_id = PCIO_VIS_Signups_DB::get_member_id_for_current_user();

            PCIO_VIS_Signups_DB::delete( $event_id, $member_id );

            return new WP_REST_Response( null, 204 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * GET /events/{id}/signups/available
     * Returns members who have not yet signed up for this event.
     */
    public function get_available( WP_REST_Request $req ): WP_REST_Response {
        try {
            $event_id = (int) $req->get_param( 'id' );
            return new WP_REST_Response(
                PCIO_VIS_Signups_DB::get_available_members( $event_id ),
                200
            );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * PUT /events/{id}/signups/{member_id}
     * Admin override — upsert a signup for any member.
     * Body: { "status": "joining"|"not_joining"|"arrived" }
     */
    public function update_member_signup( WP_REST_Request $req ): WP_REST_Response {
        try {
            $event_id  = (int) $req->get_param( 'id' );
            $member_id = (int) $req->get_param( 'member_id' );

            $params = (array) $req->get_json_params();
            $status = sanitize_key( $params['status'] ?? '' );

            // Admins may not set 'interested' via this endpoint — only the three explicit states.
            $admin_statuses = [ 'joining', 'not_joining', 'arrived' ];
            if ( ! in_array( $status, $admin_statuses, true ) ) {
                return new WP_REST_Response(
                    [ 'message' => __( 'Invalid status value.', 'pcio-vis-member-event' ) ],
                    400
                );
            }

            if ( ! PCIO_VIS_Events_DB::get( $event_id ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Event not found.', 'pcio-vis-member-event' ) ], 404 );
            }

            if ( ! PCIO_VIS_Signups_DB::upsert( $event_id, $member_id, $status ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not save signup.', 'pcio-vis-member-event' ) ], 500 );
            }

            return new WP_REST_Response(
                PCIO_VIS_Signups_DB::get_for_member_event( $member_id, $event_id ),
                200
            );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }
}
