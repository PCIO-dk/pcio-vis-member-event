<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCIO_VIS_Event_Crew_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/events/(?P<event_id>\d+)/crew', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_crew' ],
                'permission_callback' => [ $this, 'can_read' ],
                'args'                => [ 'event_id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_crew' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [
                    'event_id'       => [ 'sanitize_callback' => 'absint' ],
                    'role_id'        => [ 'required' => true, 'sanitize_callback' => 'absint' ],
                    'member_id'      => [ 'sanitize_callback' => 'absint' ],
                    'start_datetime' => [ 'sanitize_callback' => 'sanitize_text_field' ],
                    'end_datetime'   => [ 'sanitize_callback' => 'sanitize_text_field' ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/events/(?P<event_id>\d+)/crew/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_crew' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [
                    'event_id'       => [ 'sanitize_callback' => 'absint' ],
                    'id'             => [ 'sanitize_callback' => 'absint' ],
                    'role_id'        => [ 'sanitize_callback' => 'absint' ],
                    'member_id'      => [ 'sanitize_callback' => 'absint' ],
                    'start_datetime' => [ 'sanitize_callback' => 'sanitize_text_field' ],
                    'end_datetime'   => [ 'sanitize_callback' => 'sanitize_text_field' ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_crew' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [
                    'event_id' => [ 'sanitize_callback' => 'absint' ],
                    'id'       => [ 'sanitize_callback' => 'absint' ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/events/(?P<event_id>\d+)/crew/(?P<id>\d+)/duplicate', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'duplicate_crew' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [
                    'event_id' => [ 'sanitize_callback' => 'absint' ],
                    'id'       => [ 'sanitize_callback' => 'absint' ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/events/(?P<event_id>\d+)/crew/reset-times', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'reset_times' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [
                    'event_id' => [ 'sanitize_callback' => 'absint' ],
                ],
            ],
        ] );
    }

    public function can_read(): bool {
        return current_user_can( 'vis_member' );
    }

    public function can_write(): bool {
        return current_user_can( 'vis_edit_all_events' ) || current_user_can( 'vis_create_own_events' );
    }

    public function list_crew( WP_REST_Request $req ): WP_REST_Response {
        $event_id = (int) $req->get_param( 'event_id' );
        if ( ! PCIO_VIS_Events_DB::get( $event_id ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Event not found.', 'pcio-vis-member-event' ) ], 404 );
        }

        $crew = PCIO_VIS_Event_Crew_DB::get_for_event( $event_id );
        return new WP_REST_Response( $crew, 200 );
    }

    public function create_crew( WP_REST_Request $req ): WP_REST_Response {
        $event_id = (int) $req->get_param( 'event_id' );
        $event    = PCIO_VIS_Events_DB::get( $event_id );
        if ( ! $event ) {
            return new WP_REST_Response( [ 'message' => __( 'Event not found.', 'pcio-vis-member-event' ) ], 404 );
        }

        $params = (array) $req->get_json_params();
        $params['event_id'] = $event_id;

        // If no role_id provided or 0, pick the first available role if any exists.
        $role_id = absint( $params['role_id'] ?? 0 );
        if ( $role_id <= 0 ) {
            $roles = PCIO_VIS_Event_Roles_DB::get_all();
            if ( ! empty( $roles ) ) {
                $role_id = (int) $roles[0]['id'];
            }
        }
        $params['role_id'] = $role_id;

        $id = PCIO_VIS_Event_Crew_DB::create( $params );
        if ( ! $id ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not create crew shift.', 'pcio-vis-member-event' ) ], 500 );
        }

        return new WP_REST_Response( PCIO_VIS_Event_Crew_DB::get( (int) $id ), 201 );
    }

    public function update_crew( WP_REST_Request $req ): WP_REST_Response {
        $id       = (int) $req->get_param( 'id' );
        $event_id = (int) $req->get_param( 'event_id' );

        $existing = PCIO_VIS_Event_Crew_DB::get( $id );
        if ( ! $existing || (int) $existing['event_id'] !== $event_id ) {
            return new WP_REST_Response( [ 'message' => __( 'Crew shift not found.', 'pcio-vis-member-event' ) ], 404 );
        }

        $params = (array) $req->get_json_params();
        $ok     = PCIO_VIS_Event_Crew_DB::update( $id, $params );
        if ( ! $ok ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not update crew shift.', 'pcio-vis-member-event' ) ], 500 );
        }

        return new WP_REST_Response( PCIO_VIS_Event_Crew_DB::get( $id ), 200 );
    }

    public function delete_crew( WP_REST_Request $req ): WP_REST_Response {
        $id       = (int) $req->get_param( 'id' );
        $event_id = (int) $req->get_param( 'event_id' );

        $existing = PCIO_VIS_Event_Crew_DB::get( $id );
        if ( ! $existing || (int) $existing['event_id'] !== $event_id ) {
            return new WP_REST_Response( [ 'message' => __( 'Crew shift not found.', 'pcio-vis-member-event' ) ], 404 );
        }

        $ok = PCIO_VIS_Event_Crew_DB::delete( $id );
        if ( ! $ok ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not delete crew shift.', 'pcio-vis-member-event' ) ], 500 );
        }

        return new WP_REST_Response( [ 'deleted' => true ], 200 );
    }

    public function duplicate_crew( WP_REST_Request $req ): WP_REST_Response {
        $id       = (int) $req->get_param( 'id' );
        $event_id = (int) $req->get_param( 'event_id' );

        $existing = PCIO_VIS_Event_Crew_DB::get( $id );
        if ( ! $existing || (int) $existing['event_id'] !== $event_id ) {
            return new WP_REST_Response( [ 'message' => __( 'Crew shift not found.', 'pcio-vis-member-event' ) ], 404 );
        }

        $new_id = PCIO_VIS_Event_Crew_DB::duplicate( $id );
        if ( ! $new_id ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not duplicate crew shift.', 'pcio-vis-member-event' ) ], 500 );
        }

        return new WP_REST_Response( PCIO_VIS_Event_Crew_DB::get( (int) $new_id ), 201 );
    }

    public function reset_times( WP_REST_Request $req ): WP_REST_Response {
        $event_id = (int) $req->get_param( 'event_id' );
        if ( ! PCIO_VIS_Events_DB::get( $event_id ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Event not found.', 'pcio-vis-member-event' ) ], 404 );
        }

        $ok = PCIO_VIS_Event_Crew_DB::reset_times_for_event( $event_id );
        if ( ! $ok ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not reset crew shift times.', 'pcio-vis-member-event' ) ], 500 );
        }

        $crew = PCIO_VIS_Event_Crew_DB::get_for_event( $event_id );
        return new WP_REST_Response( $crew, 200 );
    }
}
