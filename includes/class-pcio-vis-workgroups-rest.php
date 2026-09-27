<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCIO_VIS_Workgroups_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/workgroups', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_workgroups' ],
                'permission_callback' => [ $this, 'can_read' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_workgroup' ],
                'permission_callback' => [ $this, 'can_write' ],
            ],
        ] );

        register_rest_route( $ns, '/workgroups/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_workgroup' ],
                'permission_callback' => [ $this, 'can_read' ],
                'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_workgroup' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_workgroup' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
        ] );

        register_rest_route( $ns, '/workgroups/(?P<id>\d+)/members', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'add_member' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
        ] );

        register_rest_route( $ns, '/workgroups/(?P<id>\d+)/members/(?P<member_id>\d+)', [
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'remove_member' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [
                    'id'        => [ 'sanitize_callback' => 'absint' ],
                    'member_id' => [ 'sanitize_callback' => 'absint' ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'set_chairman' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [
                    'id'        => [ 'sanitize_callback' => 'absint' ],
                    'member_id' => [ 'sanitize_callback' => 'absint' ],
                ],
            ],
        ] );
    }

    public function can_read(): bool {
        return current_user_can( 'vis_manage_users' );
    }

    public function can_write(): bool {
        return current_user_can( 'vis_manage_users' );
    }

    public function list_workgroups( WP_REST_Request $req ): WP_REST_Response {
        return new WP_REST_Response( PCIO_VIS_Workgroups_DB::get_all(), 200 );
    }

    public function get_workgroup( WP_REST_Request $req ): WP_REST_Response {
        $wg = PCIO_VIS_Workgroups_DB::get( (int) $req->get_param( 'id' ) );
        if ( ! $wg ) {
            return new WP_REST_Response( [ 'message' => __( 'Workgroup not found.', 'pcio-vis-member-event' ) ], 404 );
        }
        return new WP_REST_Response( $wg, 200 );
    }

    public function create_workgroup( WP_REST_Request $req ): WP_REST_Response {
        $id = PCIO_VIS_Workgroups_DB::create( (array) $req->get_json_params() );
        if ( ! $id ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not create workgroup.', 'pcio-vis-member-event' ) ], 500 );
        }
        return new WP_REST_Response( PCIO_VIS_Workgroups_DB::get( (int) $id ), 201 );
    }

    public function update_workgroup( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        $ok = PCIO_VIS_Workgroups_DB::update( $id, (array) $req->get_json_params() );
        if ( ! $ok ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not update workgroup.', 'pcio-vis-member-event' ) ], 500 );
        }
        return new WP_REST_Response( PCIO_VIS_Workgroups_DB::get( $id ), 200 );
    }

    public function delete_workgroup( WP_REST_Request $req ): WP_REST_Response {
        if ( ! PCIO_VIS_Workgroups_DB::delete( (int) $req->get_param( 'id' ) ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not delete workgroup.', 'pcio-vis-member-event' ) ], 500 );
        }
        return new WP_REST_Response( null, 204 );
    }

    public function add_member( WP_REST_Request $req ): WP_REST_Response {
        $workgroup_id = (int) $req->get_param( 'id' );
        $params       = (array) $req->get_json_params();
        $member_id    = absint( $params['member_id']   ?? 0 );
        $is_chairman  = ! empty( $params['is_chairman'] );

        if ( ! $member_id ) {
            return new WP_REST_Response( [ 'message' => __( 'member_id is required.', 'pcio-vis-member-event' ) ], 400 );
        }

        // Only volunteers may join a workgroup.
        $member = PCIO_VIS_DB::get( $member_id );
        if ( ! $member ) {
            return new WP_REST_Response( [ 'message' => __( 'Member not found.', 'pcio-vis-member-event' ) ], 404 );
        }
        if ( empty( $member['is_volunteer'] ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Only volunteers can be added to a workgroup.', 'pcio-vis-member-event' ) ], 422 );
        }

        PCIO_VIS_Workgroups_DB::add_member( $workgroup_id, $member_id, $is_chairman );
        return new WP_REST_Response( PCIO_VIS_Workgroups_DB::get( $workgroup_id ), 200 );
    }

    public function remove_member( WP_REST_Request $req ): WP_REST_Response {
        PCIO_VIS_Workgroups_DB::remove_member(
            (int) $req->get_param( 'id' ),
            (int) $req->get_param( 'member_id' )
        );
        return new WP_REST_Response( PCIO_VIS_Workgroups_DB::get( (int) $req->get_param( 'id' ) ), 200 );
    }

    public function set_chairman( WP_REST_Request $req ): WP_REST_Response {
        $params      = (array) ( $req->get_json_params() ?? [] );
        $is_chairman = ! empty( $params['is_chairman'] );
        PCIO_VIS_Workgroups_DB::set_chairman(
            (int) $req->get_param( 'id' ),
            (int) $req->get_param( 'member_id' ),
            $is_chairman
        );
        return new WP_REST_Response( PCIO_VIS_Workgroups_DB::get( (int) $req->get_param( 'id' ) ), 200 );
    }
}
