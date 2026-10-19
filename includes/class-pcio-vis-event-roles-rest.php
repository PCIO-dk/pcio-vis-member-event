<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCIO_VIS_Event_Roles_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/event-roles', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_roles' ],
                'permission_callback' => [ $this, 'can_read' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_role' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => self::write_args(),
            ],
        ] );

        register_rest_route( $ns, '/event-roles/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_role' ],
                'permission_callback' => [ $this, 'can_read' ],
                'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_role' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ] + self::write_args(),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_role' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'sanitize_callback' => 'absint' ] ],
            ],
        ] );
    }

    private static function write_args(): array {
        return [
            'name' => [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'description' => [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
        ];
    }

    public function can_read(): bool {
        return current_user_can( 'vis_member' );
    }

    public function can_write(): bool {
        return current_user_can( 'vis_edit_all_events' );
    }

    public function list_roles( WP_REST_Request $req ): WP_REST_Response {
        $roles = PCIO_VIS_Event_Roles_DB::get_all();
        foreach ( $roles as &$role ) {
            $role['id']          = (int) $role['id'];
            $role['usage_count'] = PCIO_VIS_Event_Roles_DB::count_usage( (int) $role['id'] );
        }
        unset( $role );
        return new WP_REST_Response( $roles, 200 );
    }

    public function get_role( WP_REST_Request $req ): WP_REST_Response {
        $id   = (int) $req->get_param( 'id' );
        $role = PCIO_VIS_Event_Roles_DB::get( $id );
        if ( ! $role ) {
            return new WP_REST_Response( [ 'message' => __( 'Event role not found.', 'pcio-vis-member-event' ) ], 404 );
        }
        $role['id']          = (int) $role['id'];
        $role['usage_count'] = PCIO_VIS_Event_Roles_DB::count_usage( $id );
        return new WP_REST_Response( $role, 200 );
    }

    public function create_role( WP_REST_Request $req ): WP_REST_Response {
        $params = (array) $req->get_json_params();
        if ( empty( trim( (string) ( $params['name'] ?? '' ) ) ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Role name is required.', 'pcio-vis-member-event' ) ], 400 );
        }

        $id = PCIO_VIS_Event_Roles_DB::create( $params );
        if ( ! $id ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not create event role.', 'pcio-vis-member-event' ) ], 500 );
        }

        $role = PCIO_VIS_Event_Roles_DB::get( (int) $id );
        if ( $role ) {
            $role['id']          = (int) $role['id'];
            $role['usage_count'] = 0;
        }
        return new WP_REST_Response( $role, 201 );
    }

    public function update_role( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        if ( ! PCIO_VIS_Event_Roles_DB::get( $id ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Event role not found.', 'pcio-vis-member-event' ) ], 404 );
        }

        $params = (array) $req->get_json_params();
        if ( array_key_exists( 'name', $params ) && empty( trim( (string) $params['name'] ) ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Role name cannot be empty.', 'pcio-vis-member-event' ) ], 400 );
        }

        $ok = PCIO_VIS_Event_Roles_DB::update( $id, $params );
        if ( ! $ok ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not update event role.', 'pcio-vis-member-event' ) ], 500 );
        }

        $role = PCIO_VIS_Event_Roles_DB::get( $id );
        if ( $role ) {
            $role['id']          = (int) $role['id'];
            $role['usage_count'] = PCIO_VIS_Event_Roles_DB::count_usage( $id );
        }
        return new WP_REST_Response( $role, 200 );
    }

    public function delete_role( WP_REST_Request $req ): WP_REST_Response {
        $id = (int) $req->get_param( 'id' );
        if ( ! PCIO_VIS_Event_Roles_DB::get( $id ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Event role not found.', 'pcio-vis-member-event' ) ], 404 );
        }

        $ok = PCIO_VIS_Event_Roles_DB::delete( $id );
        if ( ! $ok ) {
            return new WP_REST_Response( [ 'message' => __( 'Could not delete event role.', 'pcio-vis-member-event' ) ], 500 );
        }

        return new WP_REST_Response( [ 'deleted' => true ], 200 );
    }
}
