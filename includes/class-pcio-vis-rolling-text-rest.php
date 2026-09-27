<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST endpoints for rolling-text entries.
 *
 * Routes (namespace pcio-vis/v1):
 *   GET    /rolling-texts             – list all (optional ?roller_id= filter)
 *   POST   /rolling-texts             – create
 *   PUT    /rolling-texts/{id}        – update
 *   DELETE /rolling-texts/{id}        – delete
 *
 * Read:  vis_create_own_events (calendar editors can view)
 * Write: vis_edit_all_events   (calendar managers can create / edit / delete)
 */
class PCIO_VIS_Rolling_Text_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/rolling-texts', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_items' ],
                'permission_callback' => [ $this, 'can_read' ],
                'args'                => [
                    'roller_id' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                        'default'           => '',
                    ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_item' ],
                'permission_callback' => [ $this, 'can_write' ],
            ],
        ] );

        register_rest_route( $ns, '/rolling-texts/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_item' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_item' ],
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

    public function list_items( WP_REST_Request $req ): WP_REST_Response {
        $roller_id = (string) $req->get_param( 'roller_id' );
        return new WP_REST_Response( PCIO_VIS_Rolling_Text_DB::get_all( $roller_id ), 200 );
    }

    public function create_item( WP_REST_Request $req ): WP_REST_Response {
        $id = PCIO_VIS_Rolling_Text_DB::create( (array) $req->get_json_params() );
        if ( ! $id ) {
            return new WP_REST_Response(
                [ 'message' => __( 'Could not create rolling text entry.', 'pcio-vis-member-event' ) ],
                500
            );
        }
        return new WP_REST_Response( PCIO_VIS_Rolling_Text_DB::get( (int) $id ), 201 );
    }

    public function update_item( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        $ok = PCIO_VIS_Rolling_Text_DB::update( $id, (array) $req->get_json_params() );
        if ( ! $ok ) {
            return new WP_REST_Response(
                [ 'message' => __( 'Could not update rolling text entry.', 'pcio-vis-member-event' ) ],
                500
            );
        }
        return new WP_REST_Response( PCIO_VIS_Rolling_Text_DB::get( $id ), 200 );
    }

    public function delete_item( WP_REST_Request $req ): WP_REST_Response {
        if ( ! PCIO_VIS_Rolling_Text_DB::delete( (int) $req->get_param( 'id' ) ) ) {
            return new WP_REST_Response(
                [ 'message' => __( 'Could not delete rolling text entry.', 'pcio-vis-member-event' ) ],
                500
            );
        }
        return new WP_REST_Response( null, 204 );
    }
}
