<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST endpoints for event recurrence series.
 *
 * Base: /events/{id}/recurrence
 */
class PCIO_VIS_Recurrence_Rest {

    public function register(): void {
        $ns   = PCIO_VIS_REST_NS;
        $args = [ 'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ];

        register_rest_route( $ns, '/events/(?P<id>\d+)/recurrence', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_recurrence' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => $args,
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'save_recurrence' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => $args,
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_series' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => $args,
            ],
        ] );

        register_rest_route( $ns, '/events/(?P<id>\d+)/recurrence/apply', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'apply_recurrence' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => $args,
            ],
        ] );

        register_rest_route( $ns, '/events/(?P<id>\d+)/recurrence/resolve', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'resolve_series' ],
                'permission_callback' => [ $this, 'can_manage' ],
                'args'                => $args,
            ],
        ] );
    }

    /** Only event managers may manage recurrence. */
    public function can_manage(): bool {
        return current_user_can( 'vis_edit_all_events' );
    }

    // ── Handlers ──────────────────────────────────────────────────

    public function get_recurrence( WP_REST_Request $req ): WP_REST_Response {
        $event_id = (int) $req->get_param( 'id' );
        if ( ! PCIO_VIS_Events_DB::get( $event_id ) ) {
            return new WP_REST_Response( [ 'message' => __( 'Not found.', 'pcio-vis-member-event' ) ], 404 );
        }
        return new WP_REST_Response( PCIO_VIS_Recurrence::context( $event_id ), 200 );
    }

    /**
     * Create or update the rule for this event's series.
     * Only the anchor (first) event of a series may define the rule.
     */
    public function save_recurrence( WP_REST_Request $req ): WP_REST_Response {
        $event_id = (int) $req->get_param( 'id' );
        $event    = PCIO_VIS_Events_DB::get( $event_id );
        if ( ! $event ) {
            return new WP_REST_Response( [ 'message' => __( 'Not found.', 'pcio-vis-member-event' ) ], 404 );
        }

        $params  = (array) $req->get_json_params();
        $rule_in = isset( $params['rule'] ) && is_array( $params['rule'] ) ? $params['rule'] : $params;

        $error = self::validate_rule( $rule_in );
        if ( $error ) {
            return new WP_REST_Response( [ 'message' => $error ], 400 );
        }

        $group_id = (int) ( $event['recurrence_group_id'] ?? 0 );

        if ( $group_id > 0 ) {
            // Existing series — only the anchor may edit the rule.
            $ctx = PCIO_VIS_Recurrence::context( $event_id );
            if ( ! $ctx['isAnchor'] ) {
                return new WP_REST_Response(
                    [ 'message' => __( 'Only the first event in the series can define the rules.', 'pcio-vis-member-event' ) ],
                    403
                );
            }
            PCIO_VIS_Recurrence_DB::update( $group_id, $rule_in );
        } else {
            // Standalone event — start a new series anchored on it.
            $group_id = PCIO_VIS_Recurrence_DB::create( $rule_in );
            if ( $group_id < 1 ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not create the series.', 'pcio-vis-member-event' ) ], 500 );
            }
            PCIO_VIS_Events_DB::set_recurrence_group( $event_id, $group_id );
        }

        return new WP_REST_Response( PCIO_VIS_Recurrence::context( $event_id ), 200 );
    }

    /** Create missing occurrences and delete extras to match the rule. */
    public function apply_recurrence( WP_REST_Request $req ): WP_REST_Response {
        $event_id = (int) $req->get_param( 'id' );
        $event    = PCIO_VIS_Events_DB::get( $event_id );
        if ( ! $event ) {
            return new WP_REST_Response( [ 'message' => __( 'Not found.', 'pcio-vis-member-event' ) ], 404 );
        }
        $group_id = (int) ( $event['recurrence_group_id'] ?? 0 );
        if ( $group_id < 1 ) {
            return new WP_REST_Response( [ 'message' => __( 'This event is not part of a series.', 'pcio-vis-member-event' ) ], 400 );
        }
        $changed = PCIO_VIS_Recurrence::reconcile( $group_id );
        $ctx     = PCIO_VIS_Recurrence::context( $event_id );
        $ctx['changed'] = $changed;
        return new WP_REST_Response( $ctx, 200 );
    }

    /** Resolve the series to standalone events. */
    public function resolve_series( WP_REST_Request $req ): WP_REST_Response {
        $event_id = (int) $req->get_param( 'id' );
        $event    = PCIO_VIS_Events_DB::get( $event_id );
        if ( ! $event ) {
            return new WP_REST_Response( [ 'message' => __( 'Not found.', 'pcio-vis-member-event' ) ], 404 );
        }
        $group_id = (int) ( $event['recurrence_group_id'] ?? 0 );
        if ( $group_id < 1 ) {
            return new WP_REST_Response( [ 'message' => __( 'This event is not part of a series.', 'pcio-vis-member-event' ) ], 400 );
        }
        PCIO_VIS_Recurrence::resolve( $group_id );
        return new WP_REST_Response( [ 'resolved' => true ], 200 );
    }

    /**
     * Delete the whole series (mode=all) or every event but this one (mode=others).
     */
    public function delete_series( WP_REST_Request $req ): WP_REST_Response {
        $event_id = (int) $req->get_param( 'id' );
        $event    = PCIO_VIS_Events_DB::get( $event_id );
        if ( ! $event ) {
            return new WP_REST_Response( [ 'message' => __( 'Not found.', 'pcio-vis-member-event' ) ], 404 );
        }
        $group_id = (int) ( $event['recurrence_group_id'] ?? 0 );
        if ( $group_id < 1 ) {
            return new WP_REST_Response( [ 'message' => __( 'This event is not part of a series.', 'pcio-vis-member-event' ) ], 400 );
        }
        $mode = sanitize_key( (string) $req->get_param( 'mode' ) );
        if ( $mode === 'others' ) {
            $deleted = PCIO_VIS_Recurrence::delete_others( $group_id, $event_id );
            return new WP_REST_Response( [ 'mode' => 'others', 'deleted' => $deleted ], 200 );
        }
        $deleted = PCIO_VIS_Recurrence::delete_all( $group_id );
        return new WP_REST_Response( [ 'mode' => 'all', 'deleted' => $deleted ], 200 );
    }

    // ── Validation ────────────────────────────────────────────────

    /**
     * Reject rules that can never terminate or are missing fields their frequency needs.
     *
     * @return string Empty when valid, otherwise a human-readable error.
     */
    private static function validate_rule( array $r ): string {
        $has_count = isset( $r['occurrences'] ) && $r['occurrences'] !== '' && (int) $r['occurrences'] > 0;
        $has_until = ! empty( $r['until_date'] );
        if ( ! $has_count && ! $has_until ) {
            return __( 'Set a number of occurrences and/or a repeat-until date.', 'pcio-vis-member-event' );
        }

        $freq     = (int) ( $r['freq_type'] ?? 0 );
        $interval = (int) ( $r['interval_n'] ?? 0 );
        if ( $freq < 1 || $freq > 6 ) {
            return __( 'Choose a valid repeat frequency.', 'pcio-vis-member-event' );
        }
        if ( $interval < 1 ) {
            return __( 'Repeat interval must be at least 1.', 'pcio-vis-member-event' );
        }

        $mask = PCIO_VIS_Recurrence_DB::weekdays_to_mask( $r['weekdays'] ?? 0 );
        switch ( $freq ) {
            case PCIO_VIS_Recurrence_DB::FREQ_WEEK:
                if ( $mask === 0 ) {
                    return __( 'Select at least one weekday.', 'pcio-vis-member-event' );
                }
                break;
            case PCIO_VIS_Recurrence_DB::FREQ_MONTH:
                if ( empty( $r['week_ordinal'] ) || empty( $r['weekday'] ) ) {
                    return __( 'Choose a week and a weekday.', 'pcio-vis-member-event' );
                }
                break;
            case PCIO_VIS_Recurrence_DB::FREQ_MONTHDAY:
                if ( empty( $r['month_day'] ) ) {
                    return __( 'Choose a day of the month.', 'pcio-vis-member-event' );
                }
                break;
            case PCIO_VIS_Recurrence_DB::FREQ_YEARDAY:
                if ( empty( $r['month_day'] ) || empty( $r['month'] ) ) {
                    return __( 'Choose a month and a day of the month.', 'pcio-vis-member-event' );
                }
                break;
            case PCIO_VIS_Recurrence_DB::FREQ_YEAR:
                if ( empty( $r['month'] ) || empty( $r['week_ordinal'] ) || empty( $r['weekday'] ) ) {
                    return __( 'Choose a month, a week and a weekday.', 'pcio-vis-member-event' );
                }
                break;
        }
        return '';
    }
}
