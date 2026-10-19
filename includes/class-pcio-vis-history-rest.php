<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST endpoints for the change history.
 *
 * Routes (namespace pcio-vis/v1):
 *   GET  /history              – paginated list (?event_id, ?member_id, ?page, ?per_page)
 *   POST /history/{id}/undo    – undo one logged operation
 *
 * Read:  event history → vis_edit_all_events, member history → vis_manage_users,
 *        full log → vis_manage_settings.
 * Undo:  vis_undo_changes (re-checked inside PCIO_VIS_History::undo()).
 */
class PCIO_VIS_History_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/history', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_items' ],
            'permission_callback' => [ $this, 'can_read' ],
            'args'                => [
                'event_id'  => [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ],
                'member_id' => [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ],
                'page'      => [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 1, 'minimum' => 1 ],
                'per_page'  => [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ],
            ],
        ] );

        register_rest_route( $ns, '/history/(?P<id>\d+)/undo', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'undo_item' ],
            'permission_callback' => [ $this, 'can_undo' ],
            'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
        ] );
    }

    /**
     * Scope-dependent read permission.
     */
    public function can_read( WP_REST_Request $req ): bool {
        if ( current_user_can( 'vis_manage_settings' ) ) {
            return true;
        }
        if ( (int) $req->get_param( 'event_id' ) > 0 ) {
            return current_user_can( 'vis_edit_all_events' );
        }
        if ( (int) $req->get_param( 'member_id' ) > 0 ) {
            return current_user_can( 'vis_manage_users' );
        }
        return false;
    }

    public function can_undo(): bool {
        return PCIO_VIS_History::current_user_can_undo();
    }

    public function list_items( WP_REST_Request $req ): WP_REST_Response {
        $result = PCIO_VIS_History_DB::query( [
            'event_id'  => (int) $req->get_param( 'event_id' ),
            'member_id' => (int) $req->get_param( 'member_id' ),
            'page'      => (int) $req->get_param( 'page' ),
            'per_page'  => (int) $req->get_param( 'per_page' ),
        ] );
        $response = new WP_REST_Response(
            [
                'items'   => array_map( [ 'PCIO_VIS_History', 'format_item' ], $result['items'] ),
                'total'   => $result['total'],
                'canUndo' => PCIO_VIS_History::current_user_can_undo(),
            ],
            200
        );
        $response->header( 'X-WP-Total', (string) $result['total'] );
        return $response;
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function undo_item( WP_REST_Request $req ) {
        $id     = (int) $req->get_param( 'id' );
        $result = PCIO_VIS_History::undo( $id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $row = PCIO_VIS_History_DB::get( $result );
        return new WP_REST_Response(
            [
                'undoneId' => $id,
                'entry'    => $row ? PCIO_VIS_History::format_item( $row ) : null,
                'message'  => __( 'Change undone.', 'pcio-vis-member-event' ),
            ],
            200
        );
    }
}
