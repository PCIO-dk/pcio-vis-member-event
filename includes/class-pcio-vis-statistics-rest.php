<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for the Statistics page.
 *
 * Routes (namespace pcio-vis/v1):
 *   GET  /statistics                  aggregated data for the stats dashboard
 *   POST /statistics/sponsor-click    record a sponsor link click (public)
 */
class PCIO_VIS_Statistics_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/statistics', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_statistics' ],
            'permission_callback' => [ $this, 'can_view' ],
        ] );

        register_rest_route( $ns, '/statistics/sponsor-click', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'record_sponsor_click' ],
            'permission_callback' => static function () { return true; }, // intentionally public — only appends a URL row
            'args'                => [
                'url' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                    'validate_callback' => static function ( $v ): bool {
                        return is_string( $v ) && '' !== trim( $v );
                    },
                ],
            ],
        ] );
    }

    // ── Permissions ───────────────────────────────────────────────

    public function can_view(): bool {
        return current_user_can( 'vis_manage_users' );
    }

    // ── Handlers ──────────────────────────────────────────────────

    public function get_statistics( WP_REST_Request $req ): WP_REST_Response {
        try {
            return new WP_REST_Response(
                [
                    'members_by_year' => PCIO_VIS_Statistics_DB::get_members_by_year(),
                    'page_views'      => PCIO_VIS_Statistics_DB::get( 'page_views' ),
                    'login_count'              => PCIO_VIS_Statistics_DB::get( 'login_count' ),
                    'sponsor_clicks'           => PCIO_VIS_Statistics_DB::get_sponsor_totals(),
                    'total_members'            => count( PCIO_VIS_DB::get_all() ),
                    'active_subscriptions'     => PCIO_VIS_Statistics_DB::active_subscription_count(),
                ],
                200
            );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function record_sponsor_click( WP_REST_Request $req ): WP_REST_Response {
        $url = (string) $req->get_param( 'url' );
        if ( '' === $url ) {
            return new WP_REST_Response( [ 'message' => __( 'URL is required.', 'pcio-vis-member-event' ) ], 400 );
        }
        try {
            PCIO_VIS_Statistics_DB::record_sponsor_click( $url );
            return new WP_REST_Response( [ 'ok' => true ], 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }
}
