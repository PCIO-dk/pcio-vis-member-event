<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST endpoints for the current user's personal calendar filter.
 *
 * GET    /calendar-filter  saved (or default) settings, active flag and picker options
 * PUT    /calendar-filter  save settings
 * DELETE /calendar-filter  clear settings (back to the default view)
 */
class PCIO_VIS_Calendar_Filter_Rest {

    public function register(): void {
        register_rest_route( PCIO_VIS_REST_NS, '/calendar-filter', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_filter' ],
                'permission_callback' => [ $this, 'can_use' ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'save_filter' ],
                'permission_callback' => [ $this, 'can_use' ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'clear_filter' ],
                'permission_callback' => [ $this, 'can_use' ],
            ],
        ] );

        register_rest_route( PCIO_VIS_REST_NS, '/calendar-filter/neighbours/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_neighbours' ],
            'permission_callback' => [ $this, 'can_use' ],
            'args'                => [
                'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
            ],
        ] );
    }

    /**
     * Previous and next event (time order) matching the user's filter.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_neighbours( WP_REST_Request $req ) {
        $event          = PCIO_VIS_Events_DB::get( absint( $req['id'] ) );
        $published_only = ! PCIO_VIS_Events_DB::can_view_unpublished();
        if ( null === $event || ( $published_only && 1 !== (int) $event['publish_homepage'] ) ) {
            return new WP_Error( 'pcio_vis_not_found', __( 'Event not found.', 'pcio-vis-member-event' ), [ 'status' => 404 ] );
        }
        $found = PCIO_VIS_Calendar_Filter::neighbours( $event, get_current_user_id(), $published_only );
        return new WP_REST_Response( [
            'filtered' => null !== PCIO_VIS_Calendar_Filter_DB::get( get_current_user_id() ),
            'prev'     => self::summary( $found['prev'] ),
            'next'     => self::summary( $found['next'] ),
        ], 200 );
    }

    /** Minimal public fields of a neighbouring event. */
    private static function summary( ?array $event ): ?array {
        if ( null === $event ) {
            return null;
        }
        return [
            'id'             => (int) $event['id'],
            'title'          => (string) $event['title'],
            'start_datetime' => (string) $event['start_datetime'],
            'all_day'        => ! empty( $event['all_day'] ),
        ];
    }

    public function can_use(): bool {
        return current_user_can( 'vis_member' );
    }

    public function get_filter(): WP_REST_Response {
        $saved = PCIO_VIS_Calendar_Filter_DB::get( get_current_user_id() );
        return new WP_REST_Response( [
            'active'  => null !== $saved,
            'filter'  => $saved ?? PCIO_VIS_Calendar_Filter_DB::defaults(),
            'options' => $this->options(),
        ], 200 );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function save_filter( WP_REST_Request $req ) {
        $params = (array) $req->get_json_params();
        $date   = isset( $params['date'] ) && is_array( $params['date'] ) ? $params['date'] : [];
        $from   = sanitize_text_field( (string) ( $date['from'] ?? '' ) );
        $to     = sanitize_text_field( (string) ( $date['to'] ?? '' ) );
        if ( '' !== $from && '' !== $to && $to < $from ) {
            return new WP_Error( 'pcio_vis_filter_dates', __( 'The end date cannot be before the start date.', 'pcio-vis-member-event' ), [ 'status' => 400 ] );
        }

        $saved = PCIO_VIS_Calendar_Filter_DB::save( get_current_user_id(), $params );
        if ( null === $saved ) {
            return new WP_Error( 'pcio_vis_filter_save', __( 'Could not save the filter.', 'pcio-vis-member-event' ), [ 'status' => 500 ] );
        }
        return new WP_REST_Response( [ 'active' => true, 'filter' => $saved ], 200 );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public function clear_filter() {
        if ( ! PCIO_VIS_Calendar_Filter_DB::delete( get_current_user_id() ) ) {
            return new WP_Error( 'pcio_vis_filter_clear', __( 'Could not clear the filter.', 'pcio-vis-member-event' ), [ 'status' => 500 ] );
        }
        return new WP_REST_Response( [ 'active' => false, 'filter' => PCIO_VIS_Calendar_Filter_DB::defaults() ], 200 );
    }

    /** Values for the filter dialog's pickers. */
    private function options(): array {
        $user  = wp_get_current_user();
        $types = array_map(
            static fn( $t ) => [
                'id'           => (int) $t['id'],
                'name'         => (string) $t['name'],
                'access'       => (string) ( $t['access'] ?? '' ),
                'in_cafe_time' => ! empty( $t['in_cafe_time'] ),
            ],
            PCIO_VIS_Event_Types_DB::get_all()
        );
        $roles = array_map(
            static fn( $r ) => [ 'id' => (int) $r['id'], 'name' => (string) $r['name'] ],
            PCIO_VIS_Event_Roles_DB::get_all()
        );
        return [
            'me'      => $user->user_email ? PCIO_VIS_DB::find_id_by_email( $user->user_email ) : 0,
            'members' => PCIO_VIS_Event_Crew_DB::get_assigned_members(),
            'types'   => $types,
            'roles'   => $roles,
        ];
    }
}
