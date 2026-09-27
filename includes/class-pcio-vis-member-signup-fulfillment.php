<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Post-payment member creation and welcome-email dispatch.
 *
 * Hooks pcio_mep_order_confirmed at priority 15 — after pcio-vis-products (10)
 * creates the subscription and before extension fulfillment handlers (e.g. boat at 20)
 * that need the member ID already present.
 */
class PCIO_VIS_Member_Signup_Fulfillment {

    const MAIL_TYPE_WELCOME = 'member_welcome';

    public function register(): void {
        add_action( 'pcio_mep_order_confirmed', [ $this, 'on_order_confirmed' ], 15 );
        add_filter( 'pcio_me_mail_tags',        [ $this, 'register_mail_tags' ], 10, 2 );
    }

    /**
     * Seed the member_welcome system mail template if it does not exist yet.
     * Called on admin_init via class-pcio-vis-plugin.php.
     */
    public static function seed_mail(): void {
        if ( ! class_exists( 'PCIO_VIS_Mails_DB' ) ) {
            return;
        }
        if ( get_option( 'pcio_vis_welcome_mail_seeded' ) ) {
            return;
        }
        if ( PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_WELCOME ) ) {
            update_option( 'pcio_vis_welcome_mail_seeded', '1' );
            return;
        }
        PCIO_VIS_Mails_DB::create( [
            'subject'  => __( 'Welcome to {site_name}', 'pcio-vis-member-event' ),
            'content'  =>
                '<p>' . __( 'Hi {name},', 'pcio-vis-member-event' ) . '</p>'
                . '<p>' . __( 'Your membership has been confirmed. Your member number is <strong>{member_number}</strong>.', 'pcio-vis-member-event' ) . '</p>'
                . '<p>' . __( 'Set your password and log in: <a href="{login_url}">{login_url}</a>', 'pcio-vis-member-event' ) . '</p>'
                . '<p>{site_name}</p>',
            'mailtype' => self::MAIL_TYPE_WELCOME,
        ] );
        update_option( 'pcio_vis_welcome_mail_seeded', '1' );
    }

    /**
     * Fires on pcio_mep_order_confirmed (priority 15).
     * Reads the signup transient, creates the member record, provisions a WP account,
     * fires pcio_me_member_signup_complete, and sends the welcome email for new members.
     */
    public function on_order_confirmed( int $order_id ): void {
        $key  = 'pcio_me_signup_' . $order_id;
        $data = get_transient( $key );
        if ( false === $data ) {
            return; // Not a signup order.
        }
        delete_transient( $key ); // Prevent double-processing.

        $email = sanitize_email( (string) ( $data['email'] ?? '' ) );
        $name  = (string) ( $data['name'] ?? '' );

        $existing_id = PCIO_VIS_DB::find_id_by_email( $email );
        $is_new      = false;

        if ( $existing_id > 0 ) {
            // Products fulfillment (priority 10) may have already created a member.
            $existing = PCIO_VIS_DB::get( $existing_id );
            PCIO_VIS_DB::update( $existing_id, [
                'member_number' => $existing ? (string) $existing['member_number'] : '',
                'name'          => $name,
                'email'         => $email,
                'phone'         => (string) ( $data['phone'] ?? '' ),
                'address'       => (string) ( $data['address'] ?? '' ),
            ] );
            $member_id = $existing_id;
        } else {
            $member_id = PCIO_VIS_DB::create( [
                'member_number' => PCIO_VIS_DB::next_member_number(),
                'name'          => $name,
                'email'         => $email,
                'phone'         => (string) ( $data['phone'] ?? '' ),
                'address'       => (string) ( $data['address'] ?? '' ),
            ] );
            if ( ! $member_id ) {
               // error_log( 'pcio-vis-member-event: member creation failed for order ' . $order_id );
                return;
            }
            $is_new = true;
        }

        $member_id = (int) $member_id;
        $provision = PCIO_VIS_Caps::provision_member_wp_account( $member_id, $email, $name );

        /**
         * Fires after a signup order is confirmed and the member record is ready.
         *
         * @param int   $member_id     Member row ID.
         * @param int   $order_id      Products order ID.
         * @param array $data          Sanitized form data (core + extension fields).
         * @param bool  $is_new_member True when a new me_members row was just created.
         */
        do_action( 'pcio_me_member_signup_complete', $member_id, $order_id, $data, $is_new );

        if ( $is_new ) {
            self::send_welcome( $member_id, $order_id, $data, $provision );
        }
    }

    /**
     * Send the member_welcome system mail.
     *
     * Tag values are resolved from the member record and then extended by the
     * pcio_me_signup_welcome_vars filter (so extensions add subscription/boat tags).
     *
     * @param int      $member_id  Member row ID.
     * @param int|null $order_id   Products order ID; null on direct (no-payment) signup.
     * @param array    $payload    Sanitized form data.
     * @param array    $provision  Return value of PCIO_VIS_Caps::provision_member_wp_account().
     */
    public static function send_welcome( int $member_id, ?int $order_id, array $payload, array $provision ): void {
        $mail_tpl = PCIO_VIS_Mails_DB::get_by_mailtype( self::MAIL_TYPE_WELCOME );
        if ( ! $mail_tpl ) {
            return;
        }

        $member = PCIO_VIS_DB::get( $member_id );
        if ( ! $member ) {
            return;
        }

        $to = sanitize_email( (string) $member['email'] );
        if ( ! is_email( $to ) ) {
            return;
        }

        $login_url = self::build_login_url( $provision );

        $vars = [
            '{name}'          => (string) ( $member['name'] ?? '' ),
            '{member_number}' => (string) ( $member['member_number'] ?? '' ),
            '{site_name}'     => get_bloginfo( 'name' ),
            '{login_url}'     => $login_url,
            // Defaults for extension tags; overridden via pcio_me_signup_welcome_vars.
            '{subscription_name}' => '',
            '{expire_date}'       => '',
            '{boat_name}'         => '',
        ];

        /**
         * Filter the tag→value map used to render the member_welcome email.
         *
         * @param array    $vars      Tag => replacement-value pairs.
         * @param int      $member_id Member row ID.
         * @param int|null $order_id  Products order ID; null on direct signup.
         * @param array    $payload   Sanitized form data.
         */
        $vars = (array) apply_filters( 'pcio_me_signup_welcome_vars', $vars, $member_id, $order_id, $payload );

        $subject = str_replace( array_keys( $vars ), array_values( $vars ), (string) $mail_tpl['subject'] );
        $content = str_replace( array_keys( $vars ), array_values( $vars ), (string) $mail_tpl['content'] );

        $mail_data  = [ 'subject' => $subject, 'content' => $content, 'attachments' => [] ];
        $recipients = [ [ 'email' => $to ] ];

        $result = apply_filters( 'pcio_me_mailer_send', null, $mail_data, $recipients, [] );

        if ( null === $result || is_wp_error( $result ) ) {
            $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
            wp_mail( $to, $subject, $content, $headers );
        }
    }

    /**
     * Register core tag labels for the member_welcome mailtype in the mail editor.
     * Filter: pcio_me_mail_tags
     */
    public function register_mail_tags( array $tags, string $mailtype ): array {
        if ( self::MAIL_TYPE_WELCOME !== $mailtype ) {
            return $tags;
        }
        return array_merge( $tags, [
            [ 'tag' => '{name}',          'label' => __( 'Member name',               'pcio-vis-member-event' ) ],
            [ 'tag' => '{member_number}', 'label' => __( 'Member number',             'pcio-vis-member-event' ) ],
            [ 'tag' => '{site_name}',     'label' => __( 'Site name',                 'pcio-vis-member-event' ) ],
            [ 'tag' => '{login_url}',     'label' => __( 'Login / set-password URL',  'pcio-vis-member-event' ) ],
        ] );
    }

    /** Build the login/set-password URL from a provision result array. */
    private static function build_login_url( array $provision ): string {
        if ( $provision['wp_user_id'] < 1 ) {
            return set_url_scheme( wp_lostpassword_url(), 'https' );
        }
        if ( ! $provision['is_new'] ) {
            return set_url_scheme( wp_login_url(), 'https' );
        }
        $wp_user = get_user_by( 'id', $provision['wp_user_id'] );
        $key     = $wp_user ? get_password_reset_key( $wp_user ) : new WP_Error( 'no_user', '' );
        if ( ! is_wp_error( $key ) ) {
            return add_query_arg(
                [
                    'action' => 'rp',
                    'key'    => rawurlencode( $key ),
                    'login'  => rawurlencode( $wp_user->user_login ),
                ],
                set_url_scheme( wp_login_url(), 'https' )
            );
        }
        return set_url_scheme( wp_lostpassword_url(), 'https' );
    }
}
