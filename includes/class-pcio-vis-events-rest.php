<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCIO_VIS_Events_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/events', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_events' ],
                'permission_callback' => [ $this, 'can_read' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_event' ],
                'permission_callback' => [ $this, 'can_create' ],
            ],
        ] );

        register_rest_route( $ns, '/events/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_event' ],
                'permission_callback' => [ $this, 'can_read' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,   // PUT + PATCH
                'callback'            => [ $this, 'update_event' ],
                'permission_callback' => [ $this, 'can_update' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_event' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ],
            ],
        ] );

        // Upload a cropped image (base64) for an event and assign it to a slot.
        register_rest_route( $ns, '/events/(?P<id>\d+)/image', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'upload_image' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ],
            ],
        ] );

        // Generate a short version of a description via the WordPress AI Client (only when a provider is connected).
        register_rest_route( $ns, '/events/summarize', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'summarize' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [
                    'text' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => static fn( $v ) => sanitize_textarea_field( wp_strip_all_tags( (string) $v ) ),
                    ],
                ],
            ],
        ] );

        register_rest_route( $ns, '/events/check-url', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'check_url' ],
                'permission_callback' => [ $this, 'can_create' ],
                'args'                => [
                    'url' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => static fn( $v ) => PCIO_VIS_Events_DB::sanitize_external_url( $v ),
                    ],
                ],
            ],
        ] );
    }

    // ── Permissions ───────────────────────────────────────────────

    /** Any logged-in member may read events. */
    public function can_read(): bool {
        return current_user_can( 'vis_member' );
    }

    /** Volunteer+ may create their own events. */
    public function can_create(): bool {
        return current_user_can( 'vis_create_own_events' );
    }

    /** Event Manager+ may edit or delete any event. */
    public function can_write(): bool {
        return current_user_can( 'vis_edit_all_events' );
    }

    /** Publishers may change only publication flags unless they can also edit events. */
    public function can_update( WP_REST_Request $req ): bool {
        if ( $this->can_write() ) {
            return true;
        }
        $params = (array) $req->get_json_params();
        return current_user_can( 'vis_publish_events' ) && (bool) $params
            && ! array_diff( array_keys( $params ), PCIO_VIS_Events_DB::PUBLISH_FIELDS );
    }

    /** Reject unauthorized or invalid publication flag input. */
    private static function publication_error( array $params ): ?WP_REST_Response {
        foreach ( PCIO_VIS_Events_DB::PUBLISH_FIELDS as $field ) {
            if ( ! array_key_exists( $field, $params ) ) {
                continue;
            }
            if ( ! current_user_can( 'vis_publish_events' ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Only publishers may change event publication settings.', 'pcio-vis-member-event' ) ], 403 );
            }
            if ( ! in_array( $params[ $field ], [ true, false, 0, 1, '0', '1' ], true ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Invalid publication flag.', 'pcio-vis-member-event' ) ], 400 );
            }
        }
        return null;
    }

    // ── Handlers ──────────────────────────────────────────────────

    /** Return an AI-generated short version of the posted description text. */
    public function summarize( WP_REST_Request $req ): WP_REST_Response {
        if ( ! PCIO_VIS_AI::is_available() ) {
            return new WP_REST_Response( [ 'message' => __( 'No AI provider is connected. Set one up under Settings → Connectors.', 'pcio-vis-member-event' ) ], 400 );
        }
        $result = PCIO_VIS_AI::shorten( (string) $req->get_param( 'text' ) );
        if ( is_wp_error( $result ) ) {
            $status = 'pcio_vis_ai_empty' === $result->get_error_code() ? 400 : 502;
            return new WP_REST_Response( [ 'message' => $result->get_error_message() ], $status );
        }
        return new WP_REST_Response( [ 'text' => $result ], 200 );
    }

    /**
     * Check that an external ticket-sale URL answers with HTTP 200. Done
     * server-side because browsers block cross-origin requests (CORS); the
     * wp_safe_remote_* functions refuse private/local hosts.
     */
    public function check_url( WP_REST_Request $req ): WP_REST_Response {
        $url = (string) $req->get_param( 'url' );
        if ( '' === $url || ! wp_http_validate_url( $url ) ) {
            return new WP_REST_Response( [ 'ok' => false, 'status' => 0 ], 200 );
        }

        $args     = [ 'timeout' => 8, 'redirection' => 5 ];
        $response = wp_safe_remote_head( $url, $args );
        $status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

        // Some servers reject HEAD; retry those with a GET.
        if ( in_array( $status, [ 0, 403, 405, 501 ], true ) ) {
            $response = wp_safe_remote_get( $url, $args + [ 'limit_response_size' => 65536 ] );
            $status   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
        }

        return new WP_REST_Response( [ 'ok' => 200 === $status, 'status' => $status ], 200 );
    }

    public function list_events( WP_REST_Request $req ): WP_REST_Response {
        try {
            $start = sanitize_text_field( $req->get_param( 'start' ) ?? '' );
            $end   = sanitize_text_field( $req->get_param( 'end' )   ?? '' );
            if ( ! $start || ! $end ) {
                $start = gmdate( 'Y-m-d', strtotime( '-60 days' ) );
                $end   = gmdate( 'Y-m-d', strtotime( '+120 days' ) );
            }
            $events = PCIO_VIS_Events_DB::get_range( $start, $end, ! PCIO_VIS_Events_DB::can_view_unpublished() );
            if ( rest_sanitize_boolean( $req->get_param( 'filtered' ) ) ) {
                $events = PCIO_VIS_Calendar_Filter::apply_for_user( $events, get_current_user_id() );
            }
            return new WP_REST_Response( $events, 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function get_event( WP_REST_Request $req ): WP_REST_Response {
        try {
            $event = PCIO_VIS_Events_DB::get( (int) $req->get_param( 'id' ) );
            if ( ! $event || ( ! PCIO_VIS_Events_DB::is_published( $event ) && ! PCIO_VIS_Events_DB::can_view_unpublished() ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Not found.', 'pcio-vis-member-event' ) ], 404 );
            }
            return new WP_REST_Response( $event, 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function create_event( WP_REST_Request $req ): WP_REST_Response {
        try {
            $params = (array) $req->get_json_params();
            $error = self::publication_error( $params );
            if ( $error ) {
                return $error;
            }
            if ( ! PCIO_VIS_Event_Types_DB::exists( $params['event_type_id'] ?? 0 ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Choose an event type.', 'pcio-vis-member-event' ) ], 400 );
            }
            if ( ! self::dates_valid( $params['start_datetime'] ?? '', $params['end_datetime'] ?? '' ) ) {
                return new WP_REST_Response( [ 'message' => __( 'End cannot be before the start.', 'pcio-vis-member-event' ) ], 400 );
            }
            if ( ! self::pre_start_times_valid( $params, $params['start_datetime'] ?? '' ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Get-in and door opening cannot be after the start.', 'pcio-vis-member-event' ) ], 400 );
            }
            // New events always start with the default address from the General settings.
            $params['location'] = PCIO_VIS_Events_DB::get_default_location();
            $id = PCIO_VIS_Events_DB::create( $params );
            if ( ! $id ) {
                return new WP_REST_Response( [ 'message' => self::db_error_message( __( 'Could not create event.', 'pcio-vis-member-event' ) ) ], 500 );
            }
            // Apply default resources defined on the chosen event type.
            $type_id = absint( $params['event_type_id'] ?? 0 );
            if ( $type_id > 0 ) {
                $defaults = PCIO_VIS_Resources_DB::get_default_ids_for_type( $type_id );
                if ( ! empty( $defaults ) ) {
                    PCIO_VIS_Resources_DB::set_for_event( (int) $id, $defaults );
                }
            }
            return new WP_REST_Response( PCIO_VIS_Events_DB::get( (int) $id ), 201 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function update_event( WP_REST_Request $req ): WP_REST_Response {
        try {
            $id     = (int) $req->get_param( 'id' );
            $params = (array) $req->get_json_params();
            $error = self::publication_error( $params );
            if ( $error ) {
                return $error;
            }
            if ( array_key_exists( 'event_type_id', $params ) && ! PCIO_VIS_Event_Types_DB::exists( $params['event_type_id'] ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Choose an event type.', 'pcio-vis-member-event' ) ], 400 );
            }

            // Resolve effective start/end against the stored values for partial updates.
            $existing = PCIO_VIS_Events_DB::get( $id );
            $start    = array_key_exists( 'start_datetime', $params ) ? $params['start_datetime'] : ( $existing['start_datetime'] ?? '' );
            $end      = array_key_exists( 'end_datetime', $params )   ? $params['end_datetime']   : ( $existing['end_datetime']   ?? '' );
            if ( ! self::dates_valid( $start, $end ) ) {
                return new WP_REST_Response( [ 'message' => __( 'End cannot be before the start.', 'pcio-vis-member-event' ) ], 400 );
            }
            if ( $existing ) {
                $params = self::follow_start_shift( $params, $existing );
            }
            if ( ! self::pre_start_times_valid( $params, $start ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Get-in and door opening cannot be after the start.', 'pcio-vis-member-event' ) ], 400 );
            }

            $ok = PCIO_VIS_Events_DB::update( $id, $params );
            if ( ! $ok ) {
                return new WP_REST_Response( [ 'message' => self::db_error_message( __( 'Could not update event.', 'pcio-vis-member-event' ) ) ], 500 );
            }
            return new WP_REST_Response( PCIO_VIS_Events_DB::get( $id ), 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * Append the underlying database error to a failure message, but only when
     * WP_DEBUG is enabled, so a 500 can be diagnosed without leaking SQL details
     * in production.
     */
    private static function db_error_message( string $base ): string {
        global $wpdb;
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $wpdb->last_error ) ) {
            return $base . ' [' . $wpdb->last_error . ']';
        }
        return $base;
    }

    /**
     * True when there is no end, or the end is on/after the start.
     */
    private static function dates_valid( $start, $end ): bool {
        if ( empty( $end ) || empty( $start ) ) {
            return true;
        }
        return strtotime( (string) $end ) >= strtotime( (string) $start );
    }

    /**
     * True when the supplied get-in / door-opening times are empty or not after the start.
     *
     * @param array  $params Request params.
     * @param string $start  Effective start datetime.
     */
    private static function pre_start_times_valid( array $params, $start ): bool {
        $start_ts = strtotime( (string) $start );
        if ( ! $start_ts ) {
            return true;
        }
        foreach ( PCIO_VIS_Events_DB::PRE_START_FIELDS as $field ) {
            if ( empty( $params[ $field ] ) ) {
                continue;
            }
            $ts = strtotime( (string) $params[ $field ] );
            if ( $ts && $ts > $start_ts ) {
                return false;
            }
        }
        return true;
    }

    /**
     * When only the start moves (e.g. calendar drag), move stored get-in /
     * door-opening times by the same amount so they stay relative to the start.
     *
     * @param array $params   Request params.
     * @param array $existing Stored event row.
     */
    private static function follow_start_shift( array $params, array $existing ): array {
        if ( empty( $params['start_datetime'] ) || empty( $existing['start_datetime'] ) ) {
            return $params;
        }
        $delta = strtotime( (string) $params['start_datetime'] . ' UTC' ) - strtotime( (string) $existing['start_datetime'] . ' UTC' );
        if ( 0 === $delta ) {
            return $params;
        }
        foreach ( PCIO_VIS_Events_DB::PRE_START_FIELDS as $field ) {
            if ( array_key_exists( $field, $params ) || empty( $existing[ $field ] ) ) {
                continue;
            }
            $params[ $field ] = gmdate( 'Y-m-d H:i:s', strtotime( (string) $existing[ $field ] . ' UTC' ) + $delta );
        }
        return $params;
    }

    public function delete_event( WP_REST_Request $req ): WP_REST_Response {
        try {
            if ( ! PCIO_VIS_Events_DB::delete( (int) $req->get_param( 'id' ) ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not delete event.', 'pcio-vis-member-event' ) ], 500 );
            }
            return new WP_REST_Response( null, 204 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    /**
     * Save a cropped image (sent as a base64 data URL) as a new WP attachment
     * and assign it to the event's banner or thumbnail slot.
     */
    public function upload_image( WP_REST_Request $req ): WP_REST_Response {
        try {
            $event_id = (int) $req->get_param( 'id' );
            $event    = PCIO_VIS_Events_DB::get( $event_id );
            if ( ! $event ) {
                return new WP_REST_Response( [ 'message' => __( 'Not found.', 'pcio-vis-member-event' ) ], 404 );
            }

            $params = (array) $req->get_json_params();
            $type   = sanitize_key( $params['type'] ?? '' );
            $types  = PCIO_VIS_Plugin::IMAGE_TYPES;
            if ( ! isset( $types[ $type ] ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Unknown image type.', 'pcio-vis-member-event' ) ], 400 );
            }

            $data_url = (string) ( $params['data'] ?? '' );
            if ( ! preg_match( '#^data:image/(png|jpe?g);base64,#i', $data_url, $m ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Invalid image data.', 'pcio-vis-member-event' ) ], 400 );
            }
            $ext     = strtolower( $m[1] ) === 'png' ? 'png' : 'jpg';
            $b64     = substr( $data_url, strpos( $data_url, ',' ) + 1 );
            $decoded = base64_decode( $b64, true );
            if ( $decoded === false || $decoded === '' ) {
                return new WP_REST_Response( [ 'message' => __( 'Invalid image data.', 'pcio-vis-member-event' ) ], 400 );
            }
            // Cap decoded payload size (~12 MB) to avoid abuse.
            if ( strlen( $decoded ) > 12 * 1024 * 1024 ) {
                return new WP_REST_Response( [ 'message' => __( 'Image is too large.', 'pcio-vis-member-event' ) ], 413 );
            }

            $filename = sprintf( 'event-%d-%s-%d.%s', $event_id, $type, time(), $ext );
            $upload   = wp_upload_bits( $filename, null, $decoded );
            if ( ! empty( $upload['error'] ) ) {
                return new WP_REST_Response( [ 'message' => $upload['error'] ], 500 );
            }

            $filetype   = wp_check_filetype( $upload['file'] );
            $attachment = [
                'post_mime_type' => $filetype['type'],
                'post_title'     => sanitize_file_name( $filename ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ];
            $attach_id = wp_insert_attachment( $attachment, $upload['file'] );
            if ( is_wp_error( $attach_id ) || ! $attach_id ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not create attachment.', 'pcio-vis-member-event' ) ], 500 );
            }
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $meta = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
            wp_update_attachment_metadata( $attach_id, $meta );

            // Assign to the correct event column.
            $column = $types[ $type ]['column'];
            $ok     = PCIO_VIS_Events_DB::update( $event_id, [ $column => $attach_id ] );
            if ( ! $ok ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not update event.', 'pcio-vis-member-event' ) ], 500 );
            }

            return new WP_REST_Response( [
                'image_id' => (int) $attach_id,
                'url'      => wp_get_attachment_image_url( $attach_id, 'large' ),
                'width'    => (int) ( $meta['width']  ?? 0 ),
                'height'   => (int) ( $meta['height'] ?? 0 ),
            ], 201 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }
}
