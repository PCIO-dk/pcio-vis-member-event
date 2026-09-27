<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCIO_VIS_Docs_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        // ── Document types ────────────────────────────────────────
        register_rest_route( $ns, '/document-types', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_types' ],
                'permission_callback' => [ $this, 'can_read' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_type' ],
                'permission_callback' => [ $this, 'can_admin' ],
            ],
        ] );

        register_rest_route( $ns, '/document-types/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_type' ],
                'permission_callback' => [ $this, 'can_admin' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_type' ],
                'permission_callback' => [ $this, 'can_admin' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ],
            ],
        ] );

        // ── Documents ─────────────────────────────────────────────
        register_rest_route( $ns, '/documents', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_docs' ],
                'permission_callback' => [ $this, 'can_read' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_doc' ],
                'permission_callback' => [ $this, 'can_write' ],
            ],
        ] );

        register_rest_route( $ns, '/documents/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_doc' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_doc' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ],
            ],
        ] );
    }

    // ── Permissions ───────────────────────────────────────────────

    /**
     * Logs the outcome of a capability check for document endpoints.
     * Only writes when WP_DEBUG is enabled.
     */
    private function log_cap( string $context, string $cap, bool $granted ): bool {
        return $granted;
    }

    /** Any member may browse documents. */
    public function can_read(): bool {
        return $this->log_cap( 'can_read', 'vis_member', current_user_can( 'vis_member' ) );
    }

    /** Publisher+ may create/edit/delete documents. */
    public function can_write(): bool {
        return $this->log_cap( 'can_write', 'vis_publish_events', current_user_can( 'vis_publish_events' ) );
    }

    /** System Admin may manage document types. */
    public function can_admin(): bool {
        return $this->log_cap( 'can_admin', 'vis_manage_settings', current_user_can( 'vis_manage_settings' ) );
    }

    // ── Document type handlers ────────────────────────────────────

    public function list_types( WP_REST_Request $req ): WP_REST_Response {
        try {
            return new WP_REST_Response( PCIO_VIS_Docs_DB::get_all_types(), 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function create_type( WP_REST_Request $req ): WP_REST_Response {
        try {
            $id = PCIO_VIS_Docs_DB::create_type( (array) $req->get_json_params() );
            if ( ! $id ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not create document type.', 'pcio-vis-member-event' ) ], 500 );
            }
            return new WP_REST_Response( PCIO_VIS_Docs_DB::get_type( (int) $id ), 201 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function update_type( WP_REST_Request $req ): WP_REST_Response {
        try {
            $id = (int) $req->get_param( 'id' );
            if ( ! PCIO_VIS_Docs_DB::update_type( $id, (array) $req->get_json_params() ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not update document type.', 'pcio-vis-member-event' ) ], 500 );
            }
            return new WP_REST_Response( PCIO_VIS_Docs_DB::get_type( $id ), 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function delete_type( WP_REST_Request $req ): WP_REST_Response {
        try {
            $id     = (int) $req->get_param( 'id' );
            $result = PCIO_VIS_Docs_DB::delete_type( $id );
            if ( $result === false ) {
                return new WP_REST_Response(
                    [ 'message' => __( 'Cannot delete: documents of this type still exist.', 'pcio-vis-member-event' ) ],
                    409
                );
            }
            return new WP_REST_Response( null, 204 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    // ── Document handlers ─────────────────────────────────────────

    public function list_docs( WP_REST_Request $req ): WP_REST_Response {
        try {
            return new WP_REST_Response( PCIO_VIS_Docs_DB::get_all(), 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function create_doc( WP_REST_Request $req ): WP_REST_Response {
        try {
            $id = PCIO_VIS_Docs_DB::create( (array) $req->get_json_params() );
            if ( ! $id ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not create document.', 'pcio-vis-member-event' ) ], 500 );
            }
            return new WP_REST_Response( PCIO_VIS_Docs_DB::get( (int) $id ), 201 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function update_doc( WP_REST_Request $req ): WP_REST_Response {
        try {
            $id = (int) $req->get_param( 'id' );
            if ( ! PCIO_VIS_Docs_DB::update( $id, (array) $req->get_json_params() ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not update document.', 'pcio-vis-member-event' ) ], 500 );
            }
            return new WP_REST_Response( PCIO_VIS_Docs_DB::get( $id ), 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function delete_doc( WP_REST_Request $req ): WP_REST_Response {
        try {
            if ( ! PCIO_VIS_Docs_DB::delete( (int) $req->get_param( 'id' ) ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not delete document.', 'pcio-vis-member-event' ) ], 500 );
            }
            return new WP_REST_Response( null, 204 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }
}
