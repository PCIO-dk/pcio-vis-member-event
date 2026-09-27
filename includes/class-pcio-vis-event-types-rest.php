<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCIO_VIS_Event_Types_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/event-types', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_types' ],
                'permission_callback' => [ $this, 'can_read' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_type' ],
                'permission_callback' => [ $this, 'can_write' ],
            ],
        ] );

        register_rest_route( $ns, '/event-types/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_type' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_type' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
        ] );
    }

    public function can_read(): bool {
        return current_user_can( 'vis_create_own_events' );
    }

    public function can_write(): bool {
        return current_user_can( 'vis_edit_all_events' );
    }

    public function list_types( WP_REST_Request $req ): WP_REST_Response {
        return new WP_REST_Response( PCIO_VIS_Event_Types_DB::get_all(), 200 );
    }

    public function create_type( WP_REST_Request $req ): WP_REST_Response {
        $id = PCIO_VIS_Event_Types_DB::create( (array) $req->get_json_params() );
        if ( ! $id ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not create event type.', 'pcio-vis-member-event' ) ], 500 );
        }
        return new WP_REST_Response( PCIO_VIS_Event_Types_DB::get( (int) $id ), 201 );
    }

    public function update_type( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        $ok = PCIO_VIS_Event_Types_DB::update( $id, (array) $req->get_json_params() );
        if ( ! $ok ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not update event type.', 'pcio-vis-member-event' ) ], 500 );
        }
        return new WP_REST_Response( PCIO_VIS_Event_Types_DB::get( $id ), 200 );
    }

    public function delete_type( WP_REST_Request $req ): WP_REST_Response {
        if ( ! PCIO_VIS_Event_Types_DB::delete( (int) $req->get_param( 'id' ) ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not delete event type.', 'pcio-vis-member-event' ) ], 500 );
        }
        return new WP_REST_Response( null, 204 );
    }
}
