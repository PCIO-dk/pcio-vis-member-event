<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCIO_VIS_Resources_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/resources', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_resources' ],
                'permission_callback' => [ $this, 'can_read' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_resource' ],
                'permission_callback' => [ $this, 'can_write' ],
            ],
        ] );

        register_rest_route( $ns, '/resources/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_resource' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_resource' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
        ] );

        register_rest_route( $ns, '/events/(?P<id>\d+)/resources', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_event_resources' ],
                'permission_callback' => [ $this, 'can_read' ],
                'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'set_event_resources' ],
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

    public function list_resources( WP_REST_Request $req ): WP_REST_Response {
        return new WP_REST_Response( PCIO_VIS_Resources_DB::get_all(), 200 );
    }

    public function create_resource( WP_REST_Request $req ): WP_REST_Response {
        $id = PCIO_VIS_Resources_DB::create( (array) $req->get_json_params() );
        if ( ! $id ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not create resource.', 'pcio-vis-member-event' ) ], 500 );
        }
        return new WP_REST_Response( PCIO_VIS_Resources_DB::get( (int) $id ), 201 );
    }

    public function update_resource( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        $ok = PCIO_VIS_Resources_DB::update( $id, (array) $req->get_json_params() );
        if ( ! $ok ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not update resource.', 'pcio-vis-member-event' ) ], 500 );
        }
        return new WP_REST_Response( PCIO_VIS_Resources_DB::get( $id ), 200 );
    }

    public function delete_resource( WP_REST_Request $req ): WP_REST_Response {
        if ( ! PCIO_VIS_Resources_DB::delete( (int) $req->get_param( 'id' ) ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not delete resource.', 'pcio-vis-member-event' ) ], 500 );
        }
        return new WP_REST_Response( null, 204 );
    }

    public function get_event_resources( WP_REST_Request $req ): WP_REST_Response {
        return new WP_REST_Response( PCIO_VIS_Resources_DB::get_for_event( (int) $req->get_param( 'id' ) ), 200 );
    }

    public function set_event_resources( WP_REST_Request $req ): WP_REST_Response {
        $event_id     = (int) $req->get_param( 'id' );
        $params       = (array) ( $req->get_json_params() ?? [] );
        $resource_ids = array_map( 'absint', (array) ( $params['resource_ids'] ?? [] ) );
        $start        = sanitize_text_field( $params['start_datetime'] ?? '' );
        $end          = sanitize_text_field( $params['end_datetime']   ?? '' );

        PCIO_VIS_Resources_DB::set_for_event( $event_id, $resource_ids );

        $response = [ 'resources' => PCIO_VIS_Resources_DB::get_for_event( $event_id ) ];

        // Include non-blocking conflict warnings when time bounds are provided.
        if ( $start && $end ) {
            $response['conflicts'] = PCIO_VIS_Resources_DB::find_conflicts( $event_id, $resource_ids, $start, $end );
        }

        return new WP_REST_Response( $response, 200 );
    }
}
