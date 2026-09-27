<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for the Finance journal (Kasseklade).
 *
 * Routes (namespace pcio-vis/v1):
 *   GET    /finance/journal          list all entries (running balance attached)
 *   POST   /finance/journal          create an entry
 *   GET    /finance/journal/{id}     read one entry
 *   PUT    /finance/journal/{id}     update an entry
 *   DELETE /finance/journal/{id}     delete an entry
 *
 * All routes require the `vis_manage_finance` capability.
 */
class PCIO_VIS_Journal_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/finance/journal', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_entries' ],
                'permission_callback' => [ $this, 'can_manage' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_entry' ],
                'permission_callback' => [ $this, 'can_manage' ],
            ],
        ] );

        register_rest_route( $ns, '/finance/journal/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_entry' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_entry' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_entry' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => [ 'id' => self::id_arg() ],
            ],
        ] );
    }

    private static function id_arg(): array {
        return [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ];
    }

    // ── Permissions ───────────────────────────────────────────────

    public function can_manage(): bool {
        return current_user_can( 'vis_manage_finance' );
    }

    // ── Handlers ──────────────────────────────────────────────────

    public function list_entries( WP_REST_Request $req ): WP_REST_Response {
        try {
            return new WP_REST_Response( PCIO_VIS_Journal_DB::list_all(), 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function get_entry( WP_REST_Request $req ): WP_REST_Response {
        try {
            $entry = PCIO_VIS_Journal_DB::get( (int) $req->get_param( 'id' ) );
            if ( ! $entry ) {
                return new WP_REST_Response( [ 'message' => __( 'Not found.', 'pcio-vis-member-event' ) ], 404 );
            }
            return new WP_REST_Response( $entry, 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function create_entry( WP_REST_Request $req ): WP_REST_Response {
        try {
            $params = (array) $req->get_json_params();
            // Manual entries are always tagged source=manual; ignore client source.
            $params['source']     = 'manual';
            $params['source_ref'] = '';
            $id = PCIO_VIS_Journal_DB::create( $params );
            if ( ! $id ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not create entry.', 'pcio-vis-member-event' ) ], 500 );
            }
            return new WP_REST_Response( PCIO_VIS_Journal_DB::get( (int) $id ), 201 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function update_entry( WP_REST_Request $req ): WP_REST_Response {
        try {
            $id     = (int) $req->get_param( 'id' );
            $params = (array) $req->get_json_params();
            // Never let the client change the provenance of an entry.
            unset( $params['source'], $params['source_ref'] );
            if ( ! PCIO_VIS_Journal_DB::get( $id ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Not found.', 'pcio-vis-member-event' ) ], 404 );
            }
            $ok = PCIO_VIS_Journal_DB::update( $id, $params );
            if ( ! $ok ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not update entry.', 'pcio-vis-member-event' ) ], 500 );
            }
            return new WP_REST_Response( PCIO_VIS_Journal_DB::get( $id ), 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function delete_entry( WP_REST_Request $req ): WP_REST_Response {
        try {
            if ( ! PCIO_VIS_Journal_DB::delete( (int) $req->get_param( 'id' ) ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not delete entry.', 'pcio-vis-member-event' ) ], 500 );
            }
            return new WP_REST_Response( null, 204 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }
}
