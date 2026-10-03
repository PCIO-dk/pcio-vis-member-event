<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST endpoint: POST /pcio-vis/v1/member-signup
 *
 * Public — CSRF-guarded by form nonce. Validates core fields, delegates extra
 * validation and optional payment initiation to extensions via filters, then
 * either creates the member directly (no payment) or returns a redirect URL.
 *
 * Filters:
 *   pcio_me_signup_validate_extra( array $result, array $params ) → array
 *     $result default: ['valid' => true, 'message' => '', 'extra' => []]
 *     Extension sets 'valid' => false + 'message' on failure, or adds sanitized
 *     extension-specific fields to 'extra' on success.
 *
 *   pcio_me_signup_initiate_payment( array|null $result, array $payload ) → array|null
 *     Return ['payment_url' => string, 'order_id' => int] to redirect to payment.
 *     Return null to proceed with direct member creation.
 */
class PCIO_VIS_Member_Signup_Rest {

    public function register(): void {
        register_rest_route( PCIO_VIS_REST_NS, '/member-signup', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle' ],
            'permission_callback' => '__return_true', // Public sign-up; CSRF via form nonce.
        ] );
    }

    public function handle( WP_REST_Request $req ): WP_REST_Response {
        if ( ! wp_verify_nonce( (string) $req->get_param( 'nonce' ), 'pcio_me_member_signup' ) ) {
            return new WP_REST_Response(
                [ 'message' => __( 'Security check failed.', 'pcio-vis-member-event' ) ],
                403
            );
        }

        $name    = sanitize_text_field( (string) ( $req->get_param( 'name' )    ?? '' ) );
        $email   = sanitize_email( (string) ( $req->get_param( 'email' )   ?? '' ) );
        $phone   = sanitize_text_field( (string) ( $req->get_param( 'phone' )   ?? '' ) );
        $address = sanitize_textarea_field( (string) ( $req->get_param( 'address' ) ?? '' ) );

        if ( '' === $name ) {
            return new WP_REST_Response( [ 'message' => __( 'Name is required.', 'pcio-vis-member-event' ) ], 400 );
        }
        if ( '' === $email || ! is_email( $email ) ) {
            return new WP_REST_Response( [ 'message' => __( 'A valid email address is required.', 'pcio-vis-member-event' ) ], 400 );
        }
        if ( PCIO_VIS_DB::find_id_by_email( $email ) > 0 ) {
            return new WP_REST_Response(
                [ 'message' => __( 'A membership with that email address already exists.', 'pcio-vis-member-event' ) ],
                409
            );
        }

        // Extensions validate and sanitize their own fields; they append sanitized values to 'extra'.
        $ext = apply_filters( 'pcio_me_signup_validate_extra', [ 'valid' => true, 'message' => '', 'extra' => [] ], $req->get_params() );
        if ( ! ( $ext['valid'] ?? true ) ) {
            return new WP_REST_Response( [ 'message' => (string) ( $ext['message'] ?? __( 'Validation failed.', 'pcio-vis-member-event' ) ) ], 400 );
        }

        $payload = array_merge(
            [
                'name'    => $name,
                'email'   => $email,
                'phone'   => $phone,
                'address' => $address,
            ],
            (array) ( $ext['extra'] ?? [] )
        );

        // Extension (e.g. boat) may initiate payment and return a redirect URL.
        $payment = apply_filters( 'pcio_me_signup_initiate_payment', null, $payload );

        if ( is_array( $payment ) && ! empty( $payment['payment_url'] ) && ! empty( $payment['order_id'] ) ) {
            set_transient(
                'pcio_me_signup_' . (int) $payment['order_id'],
                $payload,
                24 * HOUR_IN_SECONDS
            );
            return new WP_REST_Response( [ 'redirect' => $payment['payment_url'] ], 200 );
        }

        // Direct path: create member and provision WP account immediately.
        $member_id = PCIO_VIS_DB::create_with_next_number( [
            'name'    => $name,
            'email'   => $email,
            'phone'   => $phone,
            'address' => $address,
        ] );

        if ( ! $member_id ) {
            return new WP_REST_Response(
                [ 'message' => __( 'Could not create membership record. Please try again.', 'pcio-vis-member-event' ) ],
                500
            );
        }

        $member_id = (int) $member_id;
        $provision = PCIO_VIS_Caps::provision_member_wp_account( $member_id, $email, $name );

        /**
         * Fires after a new member is created via the direct (no-payment) signup path.
         *
         * @param int   $member_id     Newly created member ID.
         * @param null  $order_id      Always null on the direct path.
         * @param array $payload       Sanitized form data (core + extension fields).
         * @param bool  $is_new_member Always true on the direct path.
         */
        do_action( 'pcio_me_member_signup_complete', $member_id, null, $payload, true );

        PCIO_VIS_Member_Signup_Fulfillment::send_welcome( $member_id, null, $payload, $provision );

        return new WP_REST_Response(
            [
                'success' => true,
                'message' => __( 'Registration complete. Check your email for your login details.', 'pcio-vis-member-event' ),
            ],
            200
        );
    }
}
