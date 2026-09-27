<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the [pcio_me_member_signup] shortcode and enqueues its assets.
 *
 * Extensions inject extra form sections via the pcio_me_signup_extra_sections
 * filter, returning an array of ['id', 'title', 'html'] descriptors.
 * Extensions signal that a payment step is present via pcio_me_signup_has_payment.
 */
class PCIO_VIS_Member_Signup {

    public function register(): void {
        add_shortcode( 'pcio_me_member_signup', [ $this, 'render' ] );
    }

    /**
     * @param array<string,string>|string $atts
     */
    public function render( $atts ): string {
        $atts       = shortcode_atts( [ 'cancel_url' => '' ], $atts, 'pcio_me_member_signup' );
        $cancel_url = esc_url_raw( (string) $atts['cancel_url'] );
        if ( '' !== $cancel_url && ! str_contains( $cancel_url, '://' ) ) {
            $cancel_url = home_url( '/' . ltrim( $cancel_url, '/' ), 'https' );
        } elseif ( '' !== $cancel_url ) {
            $cancel_url = set_url_scheme( $cancel_url, 'https' );
        }

        /** @var array<array{id:string,title:string,html:string}> $extra_sections */
        $extra_sections = apply_filters( 'pcio_me_signup_extra_sections', [], $atts );

        $has_payment = (bool) apply_filters( 'pcio_me_signup_has_payment', false );

        $cfg = wp_json_encode( [
            'rest'       => rest_url( PCIO_VIS_REST_NS . '/member-signup' ),
            'nonce'      => wp_create_nonce( 'pcio_me_member_signup' ),
            'restNonce'  => wp_create_nonce( 'wp_rest' ),
            'cancelUrl'  => $cancel_url,
            'hasPayment' => $has_payment,
            'i18n'       => [
                'required'     => __( 'This field is required.', 'pcio-vis-member-event' ),
                'emailInvalid' => __( 'Please enter a valid email address.', 'pcio-vis-member-event' ),
                'serverError'  => __( 'An error occurred. Please try again.', 'pcio-vis-member-event' ),
            ],
        ] );

        wp_enqueue_style(
            'pcio-vis-member-signup',
            plugin_dir_url( PCIO_VIS_PLUGIN_FILE ) . 'assets/member-signup.css',
            [],
            PCIO_VIS_DB_VERSION
        );
        wp_enqueue_script(
            'pcio-vis-member-signup',
            plugin_dir_url( PCIO_VIS_PLUGIN_FILE ) . 'assets/member-signup.js',
            [],
            PCIO_VIS_DB_VERSION,
            true
        );
        wp_add_inline_script( 'pcio-vis-member-signup', 'window.PCIO_VIS_SIGNUP = ' . $cfg . ';', 'before' );

        ob_start();
        require PCIO_VIS_PLUGIN_DIR . 'templates/member-signup.php';
        return (string) ob_get_clean();
    }
}
