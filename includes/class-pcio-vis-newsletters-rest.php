<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST endpoints for generated newsletters and newsletter generation.
 *
 *   POST /mails/{id}/newsletter  → render + store a PDF for a newsletter mail
 *   GET  /newsletters            → list generated newsletters (admin list)
 *   GET  /newsletters/{id}       → single newsletter
 *   DELETE /newsletters/{id}     → delete a newsletter record (and its PDF)
 */
class PCIO_VIS_Newsletters_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/mails/(?P<id>\d+)/newsletter', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'generate' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ],
            ],
        ] );

        register_rest_route( $ns, '/newsletters', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_newsletters' ],
                'permission_callback' => [ $this, 'can_read' ],
            ],
        ] );

        register_rest_route( $ns, '/newsletters/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_newsletter' ],
                'permission_callback' => [ $this, 'can_read' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_newsletter' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ],
            ],
        ] );
    }

    // ── Permissions ───────────────────────────────────────────────

    public function can_read(): bool {
        return current_user_can( 'vis_manage_settings' );
    }

    public function can_write(): bool {
        return current_user_can( 'vis_manage_settings' );
    }

    // ── Handlers ──────────────────────────────────────────────────

    public function generate( WP_REST_Request $req ): WP_REST_Response {
        try {
            $result = PCIO_VIS_Newsletter::generate( (int) $req->get_param( 'id' ) );
            if ( is_wp_error( $result ) ) {
                $status = (int) ( $result->get_error_data()['status'] ?? 500 );
                return new WP_REST_Response( [ 'message' => $result->get_error_message() ], $status );
            }
            return new WP_REST_Response( $result, 201 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function list_newsletters( WP_REST_Request $req ): WP_REST_Response {
        try {
            return new WP_REST_Response( PCIO_VIS_Newsletters_DB::get_all(), 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function get_newsletter( WP_REST_Request $req ): WP_REST_Response {
        try {
            $row = PCIO_VIS_Newsletters_DB::get( (int) $req->get_param( 'id' ) );
            if ( ! $row ) {
                return new WP_REST_Response( [ 'message' => __( 'Not found.', 'pcio-vis-member-event' ) ], 404 );
            }
            return new WP_REST_Response( $row, 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function delete_newsletter( WP_REST_Request $req ): WP_REST_Response {
        try {
            $id  = (int) $req->get_param( 'id' );
            $row = PCIO_VIS_Newsletters_DB::get( $id );
            if ( ! $row ) {
                return new WP_REST_Response( [ 'message' => __( 'Not found.', 'pcio-vis-member-event' ) ], 404 );
            }
            // Remove the underlying PDF attachment too.
            if ( ! empty( $row['attachment_id'] ) ) {
                wp_delete_attachment( (int) $row['attachment_id'], true );
            }
            if ( ! PCIO_VIS_Newsletters_DB::delete( $id ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not delete newsletter.', 'pcio-vis-member-event' ) ], 500 );
            }
            return new WP_REST_Response( null, 204 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }
}
