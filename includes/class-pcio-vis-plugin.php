<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCIO_VIS_Plugin {

    private static ?PCIO_VIS_Plugin $_instance = null;

    /**
     * Pages served via rewrite rules.
     * Key   = query var value  (pcio_me_page)
     * Value = template file basename inside /templates/
     */
    const PAGES = [
        'home'        => 'home.php',
        'members'     => 'members.php',
        'workgroups'  => 'workgroups.php',
        'calendar'    => 'calendar.php',
        'event'       => 'event.php',
        'mails'       => 'mails.php',
        'newsletters' => 'newsletters.php',
        'documents'   => 'documents.php',
        'finance'     => 'finance.php',
        'statistics'  => 'statistics.php',
    ];

    /**
     * Target pixel dimensions for each event image type.
     * - banner: wide header used on the front page / detail banner (16:7).
     * - thumb:  square-ish tile (deck) image used in the [pcio_me_events] grid (3:2).
     */
    const IMAGE_TYPES = [
        'banner' => [ 'width' => 1200, 'height' => 525, 'column' => 'image_id' ],
        'thumb'  => [ 'width' => 600,  'height' => 400, 'column' => 'thumb_image_id' ],
    ];

    /**
     * Read the real pixel size of a WP attachment.
     *
     * @param int $attachment_id Attachment post ID.
     * @return array{width:int,height:int}|null Null when unknown.
     */
    public static function attachment_size( int $attachment_id ): ?array {
        if ( $attachment_id < 1 ) {
            return null;
        }
        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
            return [ 'width' => (int) $meta['width'], 'height' => (int) $meta['height'] ];
        }
        // Fallback: read straight from the file on disk.
        $path = get_attached_file( $attachment_id );
        if ( $path && file_exists( $path ) ) {
            $size = @getimagesize( $path );
            if ( is_array( $size ) && ! empty( $size[0] ) && ! empty( $size[1] ) ) {
                return [ 'width' => (int) $size[0], 'height' => (int) $size[1] ];
            }
        }
        return null;
    }

    public static function instance(): PCIO_VIS_Plugin {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    private function __construct() {
        add_action( 'admin_init',        [ 'PCIO_VIS_Installer', 'maybe_upgrade' ] );
        add_action( 'admin_init',        [ 'PCIO_VIS_Member_Signup_Fulfillment', 'seed_mail' ] );
        add_action( 'rest_api_init',     [ $this, 'register_rest'     ] );
        add_action( 'init',              [ $this, 'register_rewrites'  ] );
        add_action( 'template_redirect', [ $this, 'serve_templates'    ] );
        add_action( 'admin_bar_menu',    [ $this, 'add_admin_bar_node' ], 90 );
        add_action( 'wp_login',          [ $this, 'on_user_login'      ], 10, 2 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_front_end' ] );
        ( new PCIO_VIS_Member_Signup() )->register();
        ( new PCIO_VIS_Member_Signup_Fulfillment() )->register();
        add_shortcode( 'pcio_me_members',       [ $this, 'shortcode_members'       ] );
        add_shortcode( 'pcio_me_documents',     [ $this, 'shortcode_documents'     ] );
        add_shortcode( 'pcio_me_edit_profile',  [ $this, 'shortcode_edit_profile'  ] );
        add_shortcode( 'pcio_me_event',         [ $this, 'shortcode_event'         ] );
        add_shortcode( 'pcio_me_events',        [ $this, 'shortcode_events'        ] );
        add_shortcode( 'pcio_me_upcoming_events', [ $this, 'shortcode_upcoming_events' ] );
        add_shortcode( 'pcio_me_event_roller',  [ $this, 'shortcode_event_roller'  ] );
        add_shortcode( 'pcio_me_newsletters',    [ $this, 'shortcode_newsletters'    ] );
        add_shortcode( 'pcio_me_member_count',   [ $this, 'shortcode_member_count'   ] );
        add_shortcode( 'pcio_me_rolling_text',   [ $this, 'shortcode_rolling_text'   ] );
        PCIO_VIS_Caps::init();
        PCIO_VIS_Sync::init();
        PCIO_VIS_Recurrence::init();
        if ( is_admin() ) {
            ( new PCIO_VIS_Admin() )->init();
        }
    }

    // ── REST ──────────────────────────────────────────────────────

    public function register_rest(): void {
        ( new PCIO_VIS_Rest()             )->register();
        ( new PCIO_VIS_Member_Signup_Rest() )->register();
        ( new PCIO_VIS_Events_Rest()      )->register();
        ( new PCIO_VIS_Mails_Rest()       )->register();
        ( new PCIO_VIS_Newsletters_Rest() )->register();
        ( new PCIO_VIS_Docs_Rest()        )->register();
        ( new PCIO_VIS_Signups_Rest()     )->register();
        ( new PCIO_VIS_Journal_Rest()     )->register();
        ( new PCIO_VIS_Statistics_Rest()  )->register();
        ( new PCIO_VIS_Event_Types_Rest() )->register();
        ( new PCIO_VIS_Resources_Rest()   )->register();
        ( new PCIO_VIS_Recurrence_Rest()  )->register();
        ( new PCIO_VIS_Workgroups_Rest()    )->register();
        ( new PCIO_VIS_Rolling_Text_Rest() )->register();
    }

    // ── Front-end URL rewrites ─────────────────────────────────────
    // Registers /vis/<page>/ → index.php?pcio_me_page=<page>

    public function register_rewrites(): void {
        // Root landing page: /vis/
        add_rewrite_rule( '^vis/?$', 'index.php?pcio_me_page=home', 'top' );

        // Event detail: /vis/events/{id}/
        add_rewrite_rule(
            '^vis/events/(\d+)/?$',
            'index.php?pcio_me_page=event&pcio_me_id=$matches[1]',
            'top'
        );
        add_rewrite_tag( '%pcio_me_id%', '(\d+)' );

        // Public event permalink: /events/{slug}/  (no login required)
        add_rewrite_rule(
            '^events/([^/]+)/?$',
            'index.php?pcio_me_event_slug=$matches[1]',
            'top'
        );
        add_rewrite_tag( '%pcio_me_event_slug%', '([^&]+)' );

        // Sub-pages: /vis/<page>/
        foreach ( array_keys( self::PAGES ) as $slug ) {
            if ( $slug === 'home'       ) continue;
            if ( $slug === 'event'      ) continue; // handled above
            if ( $slug === 'statistics' ) continue; // handled below
            add_rewrite_rule(
                '^vis/' . preg_quote( $slug, '/' ) . '/?$',
                'index.php?pcio_me_page=' . $slug,
                'top'
            );
        }
        // Statistics lives under the Members section.
        add_rewrite_rule( '^vis/members/statistics/?$', 'index.php?pcio_me_page=statistics', 'top' );
        add_rewrite_tag( '%pcio_me_page%', '([^&]+)' );
    }

    // ── Public event permalink ────────────────────────────────────

    /**
     * Serve the public, no-login event page at /events/{slug}/.
     * Looks the event up by slug and renders the public template, or a 404.
     */
    private function serve_public_event( string $slug ): void {
        $event = PCIO_VIS_Events_DB::get_by_slug( sanitize_title( $slug ) );
        if ( ! $event ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            return;
        }

        $template = PCIO_VIS_PLUGIN_DIR . 'templates/event-public.php';
        if ( ! file_exists( $template ) ) {
            return;
        }

        $event_id = (int) $event['id'];
        $assets   = plugins_url( 'assets/', PCIO_VIS_PLUGIN_FILE );

        // Stylesheet for the public detail layout. Enqueue before the theme header.
        wp_enqueue_style( 'pcio-me-app', $assets . 'members.css', [], '1.3.0' );
        wp_enqueue_style( 'pcio-me-public-event', $assets . 'public-event.css', [ 'pcio-me-app' ], '1.0.3' );

        // Resolve both images.
        $banner_id   = ! empty( $event['image_id'] ) ? (int) $event['image_id'] : 0;
        $thumb_id    = ! empty( $event['thumb_image_id'] ) ? (int) $event['thumb_image_id'] : 0;
        $banner_url  = $banner_id ? wp_get_attachment_image_url( $banner_id, 'large' ) : ( $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '' );
        $thumb_url   = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '';

        // Date label + start/day badge.
        $tz       = wp_timezone();
        $all_day  = (bool) intval( $event['all_day'] );
        $start_dt = new DateTimeImmutable( $event['start_datetime'], $tz );
        $date_str = $this->format_event_date_range( $event );
        $day_num  = wp_date( 'j', $start_dt->getTimestamp() );
        $mon_abbr = wp_date( 'M', $start_dt->getTimestamp() );

        // Signup status for the right-hand "enlist" box.
        $can_signup = false;
        $my_status  = null;
        if ( current_user_can( 'vis_member' ) ) {
            $member_id = PCIO_VIS_Signups_DB::get_member_id_for_current_user();
            if ( $member_id ) {
                $can_signup = true;
                $row        = PCIO_VIS_Signups_DB::get_for_member_event( $member_id, $event_id );
                $my_status  = $row ? $row['status'] : null;
            }
        }

        /**
         * Allow extensions to disable the built-in member signup UI for an event.
         *
         * Used by the Tickets extension to hide the join/interested/not-joining
         * controls when an event sells tickets instead of taking member signups.
         *
         * @param bool $enabled  Whether member signup is offered for this event.
         * @param int  $event_id Current event ID.
         */
        if ( $can_signup && ! apply_filters( 'pcio_me_event_signup_enabled', true, $event_id ) ) {
            $can_signup = false;
        }

        // Core join/interested/not-joining is only offered in 'simple' mode.
        // 'none' shows no invitation UI; 'tickets' is handled by the Tickets extension.
        $signup_mode = $event['signup_mode'] ?? 'simple';
        if ( 'simple' !== $signup_mode ) {
            $can_signup = false;
        }

        $permalink = home_url( 'events/' . rawurlencode( $event['slug'] ?? '' ) . '/' );

        if ( $can_signup ) {
            wp_enqueue_script( 'pcio-me-event-signup', $assets . 'event-signup.js', [], '1.0.1', true );
            wp_add_inline_script(
                'pcio-me-event-signup',
                'const PCIO_VIS_EVENT = ' . wp_json_encode( [
                    'rest'          => rest_url( PCIO_VIS_REST_NS ),
                    'nonce'         => wp_create_nonce( 'wp_rest' ),
                    'eventId'       => $event_id,
                    'currentStatus' => $my_status,
                    'i18n'          => [
                        'joining'    => __( 'Joining',     'pcio-vis-member-event' ),
                        'interested' => __( 'Interested',  'pcio-vis-member-event' ),
                        'notJoining' => __( 'Not joining', 'pcio-vis-member-event' ),
                        'errSave'    => __( 'Failed to save your response. Please try again.', 'pcio-vis-member-event' ),
                    ],
                ] ) . ';',
                'before'
            );
        }

        // Exposed to the template. phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        $pcio_me_event   = $event;
        $pcio_me_view    = [
            'id'         => $event_id,
            'title'      => (string) $event['title'],
            'description'=> (string) ( $event['description'] ?? '' ),
            'location'   => (string) ( $event['location'] ?? '' ),
            'color'      => ! empty( $event['color'] ) ? $event['color'] : '#3b82f6',
            'banner_url' => $banner_url,
            'thumb_url'  => $thumb_url,
            'date_str'   => $date_str,
            'day_num'    => $day_num,
            'mon_abbr'   => $mon_abbr,
            'can_signup' => $can_signup,
            'signup_mode'=> $signup_mode,
            'my_status'  => $my_status,
            'is_logged'  => is_user_logged_in(),
            'login_url'  => wp_login_url( $permalink ),
            'permalink'  => $permalink,
        ];
        // phpcs:enable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        require $template;
        exit;
    }

    /**
     * Build a human date/time range string for an event row, using the site
     * date/time format options and timezone. Shared by the public page.
     */
    private function format_event_date_range( array $event ): string {
        $tz      = wp_timezone();
        $all_day = (bool) intval( $event['all_day'] );
        $start   = ( new DateTimeImmutable( $event['start_datetime'], $tz ) )->getTimestamp();
        $end     = ! empty( $event['end_datetime'] )
                       ? ( new DateTimeImmutable( $event['end_datetime'], $tz ) )->getTimestamp()
                       : null;
        if ( $all_day && $end ) {
            $end = strtotime( '-1 day', $end ); // stored exclusive → inclusive
        }
        $date_format = get_option( 'date_format' );
        $time_format = get_option( 'time_format' );
        // Prefix the weekday so the public page reads like "Wednesday, 17/06/2026 20:00".
        $start_fmt = 'l, ' . $date_format;
        if ( $all_day ) {
            $date_str = wp_date( $start_fmt, $start );
            if ( $end && $end !== $start ) {
                $date_str .= ' – ' . wp_date( $date_format, $end );
            }
            return $date_str;
        }
        $date_str = wp_date( $start_fmt . ' ' . $time_format, $start );
        if ( $end ) {
            $same_day = wp_date( 'Y-m-d', $start ) === wp_date( 'Y-m-d', $end );
            $date_str .= ' – ' . wp_date( $same_day ? $time_format : $date_format . ' ' . $time_format, $end );
        }
        return $date_str;
    }

    /**
     * The sign-up / invitation modes an event can use, as value => label pairs.
     * Extensions add their own (e.g. Tickets adds 'tickets') via the filter.
     *
     * @return array<string,string>
     */
    public static function get_signup_modes(): array {
        $modes = [
            'none'   => __( 'No sign-up (information only)', 'pcio-vis-member-event' ),
            'simple' => __( 'Simple: join / interested / not joining', 'pcio-vis-member-event' ),
        ];

        /**
         * Filter: pcio_me_event_signup_modes
         * Lets extensions add sign-up modes to the event editor dropdown. The
         * Tickets extension adds a 'tickets' option when it is active.
         *
         * @param array<string,string> $modes value => label pairs.
         */
        $modes = apply_filters( 'pcio_me_event_signup_modes', $modes );

        return is_array( $modes ) ? $modes : [];
    }

    // ── Admin bar ─────────────────────────────────────────────────

    /**
     * Adds a "Vis" node to the WordPress admin bar so members can jump to the
     * /vis/ landing page from both the front-end homepage and wp-admin.
     */
    public function add_admin_bar_node( WP_Admin_Bar $bar ): void {
        if ( ! current_user_can( 'vis_member' ) ) {
            return;
        }
        $bar->add_node( [
            'id'    => 'pcio-me-vis',
            'title' => __( 'Vis', 'pcio-vis-member-event' ),
            'href'  => home_url( 'vis' ),
            'meta'  => [ 'title' => __( 'Open the Vis member area', 'pcio-vis-member-event' ) ],
        ] );

        // Build the same core items as _nav.php, then apply the shared filter so
        // extensions (Shop, Deliveries, Boats, Galleries…) appear here automatically.
        $items = [
            'members'   => [ 'label' => __( 'Members',    'pcio-vis-member-event' ), 'url' => home_url( 'vis/members'    ) ],
            'calendar'  => [ 'label' => __( 'Calendar',   'pcio-vis-member-event' ), 'url' => home_url( 'vis/calendar'   ) ],
            'documents' => [ 'label' => __( 'Documents',  'pcio-vis-member-event' ), 'url' => home_url( 'vis/documents'  ) ],
        ];
        if ( current_user_can( 'vis_manage_finance' ) ) {
            $items['finance'] = [ 'label' => __( 'Finance', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/finance' ) ];
        }
        if ( current_user_can( 'vis_manage_users' ) ) {
            $items['workgroups'] = [ 'label' => __( 'Workgroups', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/workgroups' ) ];
            $items['statistics'] = [ 'label' => __( 'Statistics', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/members/statistics' ) ];
        }
        if ( current_user_can( 'vis_manage_settings' ) ) {
            $items['mails']       = [ 'label' => __( 'Mails',       'pcio-vis-member-event' ), 'url' => home_url( 'vis/mails'       ) ];
            $items['newsletters'] = [ 'label' => __( 'Newsletters', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/newsletters' ) ];
        }
        $items = (array) apply_filters( 'pcio_me_nav_items', $items, '' );

        foreach ( $items as $key => $item ) {
            if ( empty( $item['url'] ) ) {
                continue;
            }
            $bar->add_node( [
                'parent' => 'pcio-me-vis',
                'id'     => 'pcio-me-vis-' . sanitize_key( is_string( $key ) ? $key : ( $item['label'] ?? '' ) ),
                'title'  => (string) ( $item['label'] ?? '' ),
                'href'   => (string) $item['url'],
            ] );
        }
    }

    // ── Template serving ──────────────────────────────────────────

    public function serve_templates(): void {
        // Public event permalink: /events/{slug}/ — no login required.
        $event_slug = get_query_var( 'pcio_me_event_slug' );
        if ( $event_slug ) {
            $this->serve_public_event( (string) $event_slug );
            return;
        }

        $page = get_query_var( 'pcio_me_page' );
        if ( ! $page ) {
            return;
        }
        // Validate against known pages to prevent path traversal
        if ( ! isset( self::PAGES[ $page ] ) ) {
            return;
        }
        // Require login for all pages
        if ( ! is_user_logged_in() ) {
            auth_redirect();
            exit;
        }

        // Require the minimum capability for each page
        $page_caps = [
            'home'        => 'vis_member',
            'members'     => 'vis_manage_users',
            'workgroups'  => 'vis_manage_users',
            'calendar'    => 'vis_member',
            'event'       => 'vis_member',
            'mails'       => 'vis_manage_settings',
            'newsletters' => 'vis_manage_settings',
            'documents'   => 'vis_member',
            'finance'     => 'vis_manage_finance',
            'statistics'  => 'vis_manage_users',
        ];
        $required = $page_caps[ $page ] ?? 'vis_member';
        $has_access = current_user_can( $required );
        // The member directory is also viewable (read-only) by any Vis member.
        if ( ! $has_access && 'members' === $page ) {
            $has_access = current_user_can( 'vis_member' );
        }
        if ( ! $has_access ) {
            wp_die(
                esc_html__( 'You do not have permission to access this page.', 'pcio-vis-member-event' ),
                esc_html__( 'Access denied', 'pcio-vis-member-event' ),
                [ 'response' => 403 ]
            );
        }

        $template = PCIO_VIS_PLUGIN_DIR . 'templates/' . self::PAGES[ $page ];
        if ( ! file_exists( $template ) ) {
            return;
        }

        $assets = plugins_url( 'assets/', PCIO_VIS_PLUGIN_FILE );

        // Shared stylesheet loaded on every vis/ page.
        wp_enqueue_style( 'pcio-me-app', $assets . 'members.css', [], '1.3.4' );

        // Page-specific JS
        if ( $page === 'members' ) {
            wp_enqueue_script( 'pcio-me-members', $assets . 'members.js', [], '1.2.3', true );
            wp_add_inline_script(
                'pcio-me-members',
                'const PCIO_ME = ' . wp_json_encode( [
                    'rest'         => rest_url( PCIO_VIS_REST_NS ),
                    'nonce'        => wp_create_nonce( 'wp_rest' ),
                    'canEdit'      => current_user_can( 'vis_manage_users' ),
                    'ownMemberId'  => PCIO_VIS_Roles_DB::get_member_id_by_wp_user( get_current_user_id() ),
                    'memberFields' => PCIO_VIS_DB::get_field_definitions(),
                    'i18n'         => [
                        'pageTitle'      => __( 'Members',                                         'pcio-vis-member-event' ),
                        'btnNew'         => __( '+ New Member',                                    'pcio-vis-member-event' ),
                        'searchPh'       => __( 'Quick search all columns…',                  'pcio-vis-member-event' ),
                        'titleCreate'    => __( 'New Member',                                      'pcio-vis-member-event' ),
                        'titleEdit'      => __( 'Edit Member',                                     'pcio-vis-member-event' ),
                        'closeDialog'    => __( 'Close dialog',                                    'pcio-vis-member-event' ),
                        'fieldMemberNum' => __( 'Member #',                                        'pcio-vis-member-event' ),
                        'phMemberNum'    => __( 'e.g. 1042',                                       'pcio-vis-member-event' ),
                        'fieldSubNum'    => __( 'Membership #',                                     'pcio-vis-member-event' ),
                        'phSubNum'       => __( '0 = none',                                         'pcio-vis-member-event' ),
                        'fieldName'      => __( 'Name',                                            'pcio-vis-member-event' ),
                        'phName'         => __( 'Full name',                                       'pcio-vis-member-event' ),
                        'fieldEmail'     => __( 'Email',                                           'pcio-vis-member-event' ),
                        'phEmail'        => __( 'name@example.com',                                'pcio-vis-member-event' ),
                        'fieldPhone'     => __( 'Phone',                                           'pcio-vis-member-event' ),
                        'phPhone'        => __( '+45 00 00 00 00',                                 'pcio-vis-member-event' ),
                        'fieldAddress'   => __( 'Address',                                         'pcio-vis-member-event' ),
                        'phAddress'      => __( 'Street, city, postal code…',                 'pcio-vis-member-event' ),
                        'btnCancel'      => __( 'Cancel',                                          'pcio-vis-member-event' ),
                        'btnSave'        => __( 'Save member',                                     'pcio-vis-member-event' ),
                        'titleDelete'    => __( 'Delete Member',                                   'pcio-vis-member-event' ),
                        'confirmDel'     => __( 'Confirm deletion',                                'pcio-vis-member-event' ),
                        'cannotUndo'     => __( 'This action cannot be undone.',                   'pcio-vis-member-event' ),
                        'btnDelete'      => __( 'Delete',                                          'pcio-vis-member-event' ),
                        /* translators: %s: member name or "this member" */
                        'deletePrompt'   => __( 'Delete %s?',                                      'pcio-vis-member-event' ),
                        'thisMember'     => __( 'this member',                                     'pcio-vis-member-event' ),
                        'errRoleOptions' => __( 'Could not load role options: ',                  'pcio-vis-member-event' ),
                        'errorLoad'      => __( 'Failed to load members: ',                        'pcio-vis-member-event' ),
                        'emptyNone'      => __( 'No members yet. Click + New Member to add one.',  'pcio-vis-member-event' ),
                        'emptyFiltered'  => __( 'No members match the current filters.',           'pcio-vis-member-event' ),
                        'errName'        => __( 'Name is required.',                               'pcio-vis-member-event' ),
                        'errEmail'       => __( 'Enter a valid email address.',                    'pcio-vis-member-event' ),
                        'stateSaving'    => __( 'Saving…',                                    'pcio-vis-member-event' ),
                        'stateDeleting'  => __( 'Deleting…',                                  'pcio-vis-member-event' ),
                        'toastCreated'   => __( 'Member created.',                                 'pcio-vis-member-event' ),
                        'toastUpdated'   => __( 'Member updated.',                                 'pcio-vis-member-event' ),
                        'toastDeleted'   => __( 'Member deleted.',                                 'pcio-vis-member-event' ),
                        'errSave'        => __( 'Save failed: ',                                   'pcio-vis-member-event' ),
                        'errDelete'      => __( 'Delete failed: ',                                 'pcio-vis-member-event' ),
                        'filterWord'     => __( 'Filter',                                          'pcio-vis-member-event' ),
                        'memberSingular' => __( 'member',                                          'pcio-vis-member-event' ),
                        'memberPlural'   => __( 'members',                                         'pcio-vis-member-event' ),
                        'showing'        => __( 'Showing',                                         'pcio-vis-member-event' ),
                        'of'             => __( 'of',                                              'pcio-vis-member-event' ),
                        'totalSuffix'    => __( 'total',                                           'pcio-vis-member-event' ),
                        // Role assignment
                        'colVisRole'     => __( 'Vis Role',                                        'pcio-vis-member-event' ),
                        'colWpRole'      => __( 'WP Role',                                         'pcio-vis-member-event' ),
                        'fieldVisRole'   => __( 'Event-system role',                               'pcio-vis-member-event' ),
                        'fieldWpRole'    => __( 'WordPress role',                                  'pcio-vis-member-event' ),
                        'fieldWpUser'    => __( 'WP User ID',                                      'pcio-vis-member-event' ),
                        'phWpUser'       => __( 'WP user ID (optional)',                           'pcio-vis-member-event' ),
                        'noRole'         => __( '— none —',                                        'pcio-vis-member-event' ),
                        'roleSectionHdr' => __( 'Role assignment',                                 'pcio-vis-member-event' ),
                        'errRoleSave'    => __( 'Role save failed: ',                              'pcio-vis-member-event' ),
                        // Volunteer flag
                        'fieldVolunteer' => __( 'Volunteer',                                          'pcio-vis-member-event' ),
                        'volunteerBadge' => __( 'Volunteer',                                          'pcio-vis-member-event' ),
                        // WordPress account (membership marker)
                        'confirmDiscard' => __( 'You have unsaved changes. Discard them?',         'pcio-vis-member-event' ),
                        'btnCopyEmails'  => __( 'Copy emails',                                     'pcio-vis-member-event' ),
                        'toastCopied'    => __( 'email address(es) copied to clipboard.',           'pcio-vis-member-event' ),
                        'toastCopyNone'  => __( 'No email addresses in current view.',              'pcio-vis-member-event' ),
                        'toastCopyFail'  => __( 'Could not copy to clipboard.',                    'pcio-vis-member-event' ),
                    ],
                    'subnav' => array_values( array_filter( [
                        [
                            'label'  => __( 'Members', 'pcio-vis-member-event' ),
                            'url'    => home_url( 'vis/members' ),
                            'active' => true,
                        ],
                        current_user_can( 'vis_manage_users' ) ? [
                            'label'  => __( 'Workgroups', 'pcio-vis-member-event' ),
                            'url'    => home_url( 'vis/workgroups' ),
                            'active' => false,
                        ] : null,
                        current_user_can( 'vis_manage_users' ) ? [
                            'label'  => __( 'Statistics', 'pcio-vis-member-event' ),
                            'url'    => home_url( 'vis/members/statistics' ),
                            'active' => false,
                        ] : null,
                    ] ) ),
                    'typeTabs' => [
                        [ 'type' => 'all',        'label' => __( 'Members',    'pcio-vis-member-event' ) ],
                        [ 'type' => 'volunteers',  'label' => __( 'Volunteers', 'pcio-vis-member-event' ) ],
                        [ 'type' => 'non_active',  'label' => __( 'Other',      'pcio-vis-member-event' ) ],
                    ],
                ] ) . ';',
                'before'
            );
        } elseif ( $page === 'event' ) {
            $event_id = (int) get_query_var( 'pcio_me_id' );
            if ( ! $event_id ) {
                wp_safe_redirect( home_url( 'vis/calendar' ) );
                exit;
            }
            // Event managers can upload images via the Photos tab.
            if ( current_user_can( 'vis_edit_all_events' ) ) {
                wp_enqueue_media();
            }
            // Resolve current event image URLs + actual sizes for the Photos tab initial render.
            $event_row      = PCIO_VIS_Events_DB::get( $event_id );
            $event_image_id  = $event_row ? (int) ( $event_row['image_id'] ?? 0 ) : 0;
            $event_image_url = $event_image_id
                ? wp_get_attachment_image_url( $event_image_id, 'large' )
                : false;
            $event_thumb_id  = $event_row ? (int) ( $event_row['thumb_image_id'] ?? 0 ) : 0;
            $event_thumb_url = $event_thumb_id
                ? wp_get_attachment_image_url( $event_thumb_id, 'large' )
                : false;
            $banner_size = self::attachment_size( $event_image_id );
            $thumb_size  = self::attachment_size( $event_thumb_id );
            wp_enqueue_style(
                'quill-snow',
                $assets . 'lib/quill/quill.snow.css',
                [], '2.0.3'
            );
            wp_enqueue_script(
                'quill',
                $assets . 'lib/quill/quill.js',
                [], '2.0.3', true
            );
            // Cropper.js — zoom / pan / crop editor for the Photos tab (editors only).
            if ( current_user_can( 'vis_edit_all_events' ) ) {
                // Cropper.js v2 ships no stylesheet; styling lives inside its web components.
                wp_enqueue_script(
                    'cropperjs',
                    $assets . 'lib/cropperjs/cropper.min.js',
                    [], '2.1.1', true
                );
            }
            wp_enqueue_script( 'pcio-me-event', $assets . 'event.js', [ 'quill' ], '1.2.2', true );

            // Recurrence tab (editors only) — depends on the event script for its shared PCIO_ME config.
            if ( current_user_can( 'vis_edit_all_events' ) ) {
                wp_enqueue_style(
                    'pcio-me-event-recurrence',
                    $assets . 'event-recurrence.css',
                    [], '1.0.0'
                );
                wp_enqueue_script(
                    'pcio-me-event-recurrence',
                    $assets . 'event-recurrence.js',
                    [ 'pcio-me-event' ], '1.0.0', true
                );
            }

            /**
             * Allow extensions to register extra tabs on the event detail page.
             * Each entry must be an array with keys: id, label, html.
             *
             * @param array $tabs     Extra tab definitions.
             * @param int   $event_id Current event ID.
             */
            $extra_tabs_raw = apply_filters( 'pcio_me_event_tabs', [], $event_id );
            $extra_tabs     = [];
            if ( is_array( $extra_tabs_raw ) ) {
                foreach ( $extra_tabs_raw as $tab ) {
                    if ( ! is_array( $tab ) || empty( $tab['id'] ) ) {
                        continue;
                    }
                    $extra_tabs[] = [
                        'id'    => (string) $tab['id'],
                        'label' => (string) ( $tab['label'] ?? $tab['id'] ),
                        'html'  => (string) ( $tab['html'] ?? '' ),
                    ];
                }
            }

            wp_add_inline_script(
                'pcio-me-event',
                'const PCIO_ME = ' . wp_json_encode( [
                    'rest'       => rest_url( PCIO_VIS_REST_NS ),
                    'nonce'      => wp_create_nonce( 'wp_rest' ),
                    'canEdit'    => current_user_can( 'vis_edit_all_events' ),
                    'eventId'    => $event_id,
                    'calUrl'     => home_url( 'vis/calendar' ),
                    'eventBaseUrl' => home_url( 'vis/events/' ),
                    'imageId'    => $event_image_id ?: null,
                    'imageUrl'   => $event_image_url ?: null,
                    'thumbImageId'  => $event_thumb_id ?: null,
                    'thumbImageUrl' => $event_thumb_url ?: null,
                    'imageMeta'  => [
                        'banner' => [
                            'targetW' => self::IMAGE_TYPES['banner']['width'],
                            'targetH' => self::IMAGE_TYPES['banner']['height'],
                            'actualW' => $banner_size['width']  ?? null,
                            'actualH' => $banner_size['height'] ?? null,
                        ],
                        'thumb' => [
                            'targetW' => self::IMAGE_TYPES['thumb']['width'],
                            'targetH' => self::IMAGE_TYPES['thumb']['height'],
                            'actualW' => $thumb_size['width']  ?? null,
                            'actualH' => $thumb_size['height'] ?? null,
                        ],
                    ],
                    'eventsPublicBase' => home_url( 'events/' ),
                    'extraTabs'  => $extra_tabs,
                    'signupModes' => self::get_signup_modes(),
                    'i18n'      => [
                        'tabBasic'       => __( 'Basic',                                          'pcio-vis-member-event' ),
                        'tabDesc'        => __( 'Description',                                    'pcio-vis-member-event' ),
                        'tabResources'   => __( 'Resources',                                      'pcio-vis-member-event' ),
                        'tabGuests'      => __( 'Guests',                                         'pcio-vis-member-event' ),
                        'tabPhotos'      => __( 'Photos',                                         'pcio-vis-member-event' ),
                        'tabPublish'     => __( 'Publish',                                        'pcio-vis-member-event' ),
                        'publishHeading' => __( 'Publish & share',                               'pcio-vis-member-event' ),
                        'permalinkLabel' => __( 'Public link (permalink)',                        'pcio-vis-member-event' ),
                        'permalinkHelp'  => __( 'Anyone with this link can view the event — no login required.', 'pcio-vis-member-event' ),
                        'shortcodeLabel' => __( 'Page embed code',                                'pcio-vis-member-event' ),
                        'shortcodeHelp'  => __( 'Paste this shortcode into any WordPress page or post to display the event there.', 'pcio-vis-member-event' ),
                        'copyBtn'        => __( 'Copy',                                           'pcio-vis-member-event' ),
                        'copiedMsg'      => __( 'Copied to clipboard.',                           'pcio-vis-member-event' ),
                        'colorBlue'      => __( 'Blue',                                           'pcio-vis-member-event' ),
                        'colorGreen'     => __( 'Green',                                          'pcio-vis-member-event' ),
                        'colorRed'       => __( 'Red',                                            'pcio-vis-member-event' ),
                        'colorPurple'    => __( 'Purple',                                         'pcio-vis-member-event' ),
                        'colorAmber'     => __( 'Amber',                                          'pcio-vis-member-event' ),
                        'colorTeal'      => __( 'Teal',                                           'pcio-vis-member-event' ),
                        'colorPink'      => __( 'Pink',                                           'pcio-vis-member-event' ),
                        'colorSlate'     => __( 'Slate',                                          'pcio-vis-member-event' ),
                        'backToCalendar' => __( 'Calendar',                                       'pcio-vis-member-event' ),
                        'stateSaving'    => __( 'Saving…',                                   'pcio-vis-member-event' ),
                        'stateSaved'     => __( '✓ Saved',                                   'pcio-vis-member-event' ),
                        'stateError'     => __( '✕ Error',                                   'pcio-vis-member-event' ),
                        'btnDeleteEvt'   => __( 'Delete event',                                   'pcio-vis-member-event' ),
                        'titleDeleteEvt' => __( 'Delete Event',                                   'pcio-vis-member-event' ),
                        'confirmDel'     => __( 'Confirm deletion',                               'pcio-vis-member-event' ),
                        'cannotUndo'     => __( 'This action cannot be undone.',                  'pcio-vis-member-event' ),
                        'btnCancel'      => __( 'Cancel',                                         'pcio-vis-member-event' ),
                        'btnDelete'      => __( 'Delete',                                         'pcio-vis-member-event' ),
                        'stateDeleting'  => __( 'Deleting…',                                 'pcio-vis-member-event' ),
                        'errDelete'      => __( 'Delete failed: ',                                'pcio-vis-member-event' ),
                        'closeLabel'     => __( 'Close',                                          'pcio-vis-member-event' ),
                        'fieldTitle'     => __( 'Title',                                          'pcio-vis-member-event' ),
                        'allDayEvt'      => __( 'All-day event',                                  'pcio-vis-member-event' ),
                        'fieldStartDate' => __( 'Start date',                                     'pcio-vis-member-event' ),
                        'fieldStartTime' => __( 'Start time',                                     'pcio-vis-member-event' ),
                        'fieldEndDate'   => __( 'End date',                                       'pcio-vis-member-event' ),
                        'fieldEndTime'   => __( 'End time',                                       'pcio-vis-member-event' ),
                        'fieldLocation'  => __( 'Location',                                       'pcio-vis-member-event' ),
                        'phLocation'     => __( 'Venue or address',                               'pcio-vis-member-event' ),
                        'fieldColour'    => __( 'Colour',                                         'pcio-vis-member-event' ),
                        'fieldEventType' => __( 'Event type',                                     'pcio-vis-member-event' ),
                        'fieldSignupMode'=> __( 'Sign-up',                                        'pcio-vis-member-event' ),
                        'noEventType'    => __( '— None —',                                       'pcio-vis-member-event' ),
                        'fieldResources' => __( 'Resources',                                      'pcio-vis-member-event' ),
                        'noResources'    => __( 'No resources defined yet. Add them in the Calendar → Resources tab.', 'pcio-vis-member-event' ),
                        'conflictWarning'=> __( 'Resource conflict: ',                            'pcio-vis-member-event' ),
                        'fieldDesc'      => __( 'Description',                                    'pcio-vis-member-event' ),
                        'phDesc'         => __( 'Describe the event…',                       'pcio-vis-member-event' ),
                        'btnSaveDesc'    => __( 'Save description',                               'pcio-vis-member-event' ),
                        'btnHtmlSource'  => __( 'Edit HTML',                                      'pcio-vis-member-event' ),
                        'btnVisualEditor'=> __( 'Visual editor',                                  'pcio-vis-member-event' ),
                        'noDesc'         => __( 'No description.',                                'pcio-vis-member-event' ),
                        'comingSoon'     => __( 'This section is coming soon.',                   'pcio-vis-member-event' ),
                        'errNoId'        => __( 'No event ID.',                                   'pcio-vis-member-event' ),
                        'errLoad'        => __( 'Failed to load event: ',                         'pcio-vis-member-event' ),
                        'unsavedWarning' => __( 'You have unsaved changes. Leave without saving?', 'pcio-vis-member-event' ),
                        'errTitleReq'    => __( 'Title is required.',                             'pcio-vis-member-event' ),
                        'errEndBeforeStart' => __( 'End cannot be before the start.',             'pcio-vis-member-event' ),
                        // Photos tab
                        'noImage'        => __( 'No image set for this event.',                   'pcio-vis-member-event' ),
                        'btnChooseImage' => __( 'Choose from media library',                      'pcio-vis-member-event' ),
                        'btnChangeImage' => __( 'Change image',                                   'pcio-vis-member-event' ),
                        'btnRemoveImage' => __( 'Remove image',                                   'pcio-vis-member-event' ),
                        'imgSizeHint'    => __( 'Recommended image size: a wide landscape image of about 1200 × 525 px (≈16:7). It is used as the cover image and in the front-page event roller.', 'pcio-vis-member-event' ),
                        'bannerLabel'    => __( 'Banner image',                                   'pcio-vis-member-event' ),
                        'thumbLabel'     => __( 'Thumbnail (tile) image',                         'pcio-vis-member-event' ),
                        'bannerHint'     => __( 'Wide landscape image shown as the cover and in the front-page event roller.', 'pcio-vis-member-event' ),
                        'thumbHint'      => __( 'Tile image shown in the event grid (the [pcio_me_events] deck).', 'pcio-vis-member-event' ),
                        'targetSize'     => __( 'Target size',                                    'pcio-vis-member-event' ),
                        'actualSize'     => __( 'Current size',                                   'pcio-vis-member-event' ),
                        'sizeUnknown'    => __( 'unknown',                                        'pcio-vis-member-event' ),
                        'sizeMismatch'   => __( '⚠ Does not match the target size — please crop or replace it.', 'pcio-vis-member-event' ),
                        'sizeOk'         => __( '✓ Matches the target size.',               'pcio-vis-member-event' ),
                        'btnCropImage'   => __( 'Adjust / crop',                                  'pcio-vis-member-event' ),
                        'cropTitle'      => __( 'Adjust image',                                   'pcio-vis-member-event' ),
                        'cropHelp'       => __( 'Drag to move, scroll or pinch to zoom. The selection is cropped to the target size.', 'pcio-vis-member-event' ),
                        'cropZoomIn'     => __( 'Zoom in',                                        'pcio-vis-member-event' ),
                        'cropZoomOut'    => __( 'Zoom out',                                       'pcio-vis-member-event' ),
                        'cropReset'      => __( 'Reset',                                          'pcio-vis-member-event' ),
                        'cropApply'      => __( 'Crop & save',                                    'pcio-vis-member-event' ),
                        'cropChoose'     => __( 'Choose a different image',                       'pcio-vis-member-event' ),
                        'cropNoSource'   => __( 'Select an image first, then adjust it.',         'pcio-vis-member-event' ),
                        'cropLibMissing' => __( 'The crop editor failed to load. Please reload the page.', 'pcio-vis-member-event' ),
                        'mediaTitle'     => __( 'Select Event Image',                             'pcio-vis-member-event' ),
                        'mediaBtn'       => __( 'Use this image',                                 'pcio-vis-member-event' ),
                        'noMediaLib'     => __( 'WordPress media library is not available.',      'pcio-vis-member-event' ),
                        'imgSaving'      => __( 'Saving image…',                             'pcio-vis-member-event' ),
                        'imgSaved'       => __( '✓ Saved',                                   'pcio-vis-member-event' ),
                        'imgError'       => __( '✕ Error',                                   'pcio-vis-member-event' ),                        // Guests tab
                        'guestName'          => __( 'Name',                                       'pcio-vis-member-event' ),
                        'guestEmail'         => __( 'Email',                                      'pcio-vis-member-event' ),
                        'guestJoinDate'      => __( 'Join date',                                  'pcio-vis-member-event' ),
                        'guestStatus'        => __( 'Status',                                     'pcio-vis-member-event' ),
                        'statusJoining'      => __( 'Joining',                                    'pcio-vis-member-event' ),
                        'statusNotJoining'   => __( 'Not joining',                                'pcio-vis-member-event' ),
                        'statusArrived'      => __( 'Arrived',                                    'pcio-vis-member-event' ),
                        'statusInterested'   => __( 'Interested',                                 'pcio-vis-member-event' ),
                        'guestCntJoining'    => __( 'Joining',                                    'pcio-vis-member-event' ),
                        'guestCntNotJoining' => __( 'Not joining',                                'pcio-vis-member-event' ),
                        'guestCntArrived'    => __( 'Arrived',                                    'pcio-vis-member-event' ),
                        'guestCntInterested' => __( 'Interested',                                 'pcio-vis-member-event' ),
                        'guestCntNotAnswered'=> __( 'Not answered',                               'pcio-vis-member-event' ),
                        'guestLoading'       => __( 'Loading…',                               'pcio-vis-member-event' ),
                        'guestEmpty'         => __( 'No guests yet.',                             'pcio-vis-member-event' ),
                        'guestSearchPh'      => __( 'Quick search…',                          'pcio-vis-member-event' ),
                        'guestSaveErr'       => __( 'Could not update status.',                   'pcio-vis-member-event' ),
                        'guestAddPh'         => __( 'Add guest',                                  'pcio-vis-member-event' ),
                        'guestAddBtn'        => __( 'Add',                                        'pcio-vis-member-event' ),
                        'guestAddStatus'     => __( 'Status',                                     'pcio-vis-member-event' ),
                        // Recurrence tab
                        'tabRecurrence'      => __( 'Recurrence',                                 'pcio-vis-member-event' ),
                        'recIntro'           => __( 'Recurring events are copies of the first event in the series. Only the first event defines the repeat rules, so make sure everything on it is correct before creating copies.', 'pcio-vis-member-event' ),
                        'recNotAnchor'       => __( 'This is not the first event in the series. Only the first event can define the repeat rules.', 'pcio-vis-member-event' ),
                        /* translators: %1$d: this event's position in the series; %2$d: total number of events in the series. */
                        'recPositionOf'      => __( 'Event %1$d of %2$d in the series', 'pcio-vis-member-event' ),
                        'recFirst'           => __( 'First',                                      'pcio-vis-member-event' ),
                        'recPrev'            => __( '‹ Previous',                                 'pcio-vis-member-event' ),
                        'recNext'            => __( 'Next ›',                                     'pcio-vis-member-event' ),
                        'recLast'            => __( 'Last',                                       'pcio-vis-member-event' ),
                        'recEnable'          => __( 'Make this a recurring event',                'pcio-vis-member-event' ),
                        'recResolve'         => __( 'Resolve to standalone events',               'pcio-vis-member-event' ),
                        'recDeleteAll'       => __( 'Delete all',                                 'pcio-vis-member-event' ),
                        'recDeleteOthers'    => __( 'Delete others',                              'pcio-vis-member-event' ),
                        'recRules'           => __( 'Repeat rules',                               'pcio-vis-member-event' ),
                        'recOccurrences'     => __( 'Number of occurrences',                      'pcio-vis-member-event' ),
                        'recUntil'           => __( 'Repeat until',                               'pcio-vis-member-event' ),
                        'recEvery'           => __( 'Every',                                      'pcio-vis-member-event' ),
                        'recInterval'        => __( 'Repeat after',                               'pcio-vis-member-event' ),
                        'recWeek'            => __( 'Week',                                       'pcio-vis-member-event' ),
                        'recWeekday'         => __( 'Weekday',                                    'pcio-vis-member-event' ),
                        'recMonthDay'        => __( 'Day of month',                               'pcio-vis-member-event' ),
                        'recMonth'           => __( 'Month',                                      'pcio-vis-member-event' ),
                        'recFreqDay'         => __( 'Day',                                        'pcio-vis-member-event' ),
                        'recFreqWeek'        => __( 'Week',                                       'pcio-vis-member-event' ),
                        'recFreqMonth'       => __( 'Month',                                      'pcio-vis-member-event' ),
                        'recFreqMonthday'    => __( 'Month-day',                                  'pcio-vis-member-event' ),
                        'recFreqYearday'     => __( 'Year-day',                                   'pcio-vis-member-event' ),
                        'recFreqYear'        => __( 'Year',                                       'pcio-vis-member-event' ),
                        'recNone'            => __( '— None —',                                  'pcio-vis-member-event' ),
                        'recOrdFirst'        => __( 'First',                                      'pcio-vis-member-event' ),
                        'recOrdSecond'       => __( 'Second',                                     'pcio-vis-member-event' ),
                        'recOrdThird'        => __( 'Third',                                      'pcio-vis-member-event' ),
                        'recOrdFourth'       => __( 'Fourth',                                     'pcio-vis-member-event' ),
                        'recOrdLast'         => __( 'Last',                                       'pcio-vis-member-event' ),
                        'recMon'             => __( 'Monday',                                     'pcio-vis-member-event' ),
                        'recTue'             => __( 'Tuesday',                                    'pcio-vis-member-event' ),
                        'recWed'             => __( 'Wednesday',                                  'pcio-vis-member-event' ),
                        'recThu'             => __( 'Thursday',                                   'pcio-vis-member-event' ),
                        'recFri'             => __( 'Friday',                                     'pcio-vis-member-event' ),
                        'recSat'             => __( 'Saturday',                                   'pcio-vis-member-event' ),
                        'recSun'             => __( 'Sunday',                                     'pcio-vis-member-event' ),
                        'recMonthJan'        => __( 'January',                                    'pcio-vis-member-event' ),
                        'recMonthFeb'        => __( 'February',                                   'pcio-vis-member-event' ),
                        'recMonthMar'        => __( 'March',                                      'pcio-vis-member-event' ),
                        'recMonthApr'        => __( 'April',                                      'pcio-vis-member-event' ),
                        'recMonthMay'        => __( 'May',                                        'pcio-vis-member-event' ),
                        'recMonthJun'        => __( 'June',                                       'pcio-vis-member-event' ),
                        'recMonthJul'        => __( 'July',                                       'pcio-vis-member-event' ),
                        'recMonthAug'        => __( 'August',                                     'pcio-vis-member-event' ),
                        'recMonthSep'        => __( 'September',                                  'pcio-vis-member-event' ),
                        'recMonthOct'        => __( 'October',                                    'pcio-vis-member-event' ),
                        'recMonthNov'        => __( 'November',                                   'pcio-vis-member-event' ),
                        'recMonthDec'        => __( 'December',                                   'pcio-vis-member-event' ),
                        'recRecalculate'     => __( 'Recalculate',                                'pcio-vis-member-event' ),
                        'recColEventId'      => __( 'Event ID',                                   'pcio-vis-member-event' ),
                        'recColStart'        => __( 'Start date',                                 'pcio-vis-member-event' ),
                        'recColCreated'      => __( 'Created?',                                   'pcio-vis-member-event' ),
                        'recYes'             => __( 'Yes',                                        'pcio-vis-member-event' ),
                        'recNo'              => __( 'No',                                         'pcio-vis-member-event' ),
                        'recToDelete'        => __( 'To be deleted',                             'pcio-vis-member-event' ),
                        'recReconcile'       => __( 'Create and delete to match the rules',       'pcio-vis-member-event' ),
                        /* translators: 1: number of events to create, 2: number to delete. */
                        'recDiffText'        => __( '%1$d event(s) will be created and %2$d will be deleted so the series matches the rules.', 'pcio-vis-member-event' ),
                        'recNoChanges'       => __( 'The series matches the rules.',              'pcio-vis-member-event' ),
                        'recSaving'          => __( 'Saving…',                                   'pcio-vis-member-event' ),
                        'recWorking'         => __( 'Working…',                                  'pcio-vis-member-event' ),
                        'recConfirmResolve'  => __( 'Resolve this series into standalone events?', 'pcio-vis-member-event' ),
                        'recConfirmDelAll'   => __( 'Delete every event in this series? This cannot be undone.', 'pcio-vis-member-event' ),
                        'recConfirmDelOthers'=> __( 'Delete every other event in this series and keep this one?', 'pcio-vis-member-event' ),
                        'recErr'             => __( 'Something went wrong. Please try again.',     'pcio-vis-member-event' ),
                    ],
                ] ) . ';',
                'before'
            );
        } elseif ( $page === 'calendar' ) {
            $fc_locale = strtolower( substr( str_replace( '_', '-', get_locale() ), 0, 2 ) );
            wp_enqueue_script(
                'fullcalendar',
                $assets . 'lib/fullcalendar/index.global.min.js',
                [], '6.1.15', true
            );
            // Load the bundled Danish locale for da sites; other locales fall back
            // to FullCalendar's built-in English (only 'da' is bundled locally).
            $cal_deps = [ 'fullcalendar' ];
            if ( $fc_locale === 'da' ) {
                wp_enqueue_script(
                    'fullcalendar-locale',
                    $assets . 'lib/fullcalendar/locales/da.global.min.js',
                    [ 'fullcalendar' ], '6.1.15', true
                );
                // Depend on the locale so it is registered before the calendar
                // initialises; otherwise the localised header title can render blank.
                $cal_deps[] = 'fullcalendar-locale';
            }
            wp_enqueue_script( 'pcio-me-calendar', $assets . 'calendar.js', $cal_deps, '1.4.5', true );
            wp_add_inline_script(
                'pcio-me-calendar',
                'const PCIO_ME = ' . wp_json_encode( [
                    'rest'         => rest_url( PCIO_VIS_REST_NS ),
                    'nonce'        => wp_create_nonce( 'wp_rest' ),
                    'canEdit'      => current_user_can( 'vis_create_own_events' ),
                    'fcLocale'     => $fc_locale,
                    'eventBaseUrl' => home_url( 'vis/events/' ),
                    'i18n'         => [
                        'pageTitle'     => __( 'Calendar',                        'pcio-vis-member-event' ),
                        'btnNew'        => __( '+ New Event',                      'pcio-vis-member-event' ),
                        'titleCreate'   => __( 'New Event',                        'pcio-vis-member-event' ),
                        'fieldTitle'    => __( 'Title',                           'pcio-vis-member-event' ),
                        'phTitle'       => __( 'Event title',                     'pcio-vis-member-event' ),
                        'allDayEvt'     => __( 'All-day event',                   'pcio-vis-member-event' ),
                        'fieldStartDate'=> __( 'Start date',                      'pcio-vis-member-event' ),
                        'fieldStartTime'=> __( 'Start time',                      'pcio-vis-member-event' ),
                        'fieldEndDate'  => __( 'End date',                        'pcio-vis-member-event' ),
                        'fieldEndTime'  => __( 'End time',                        'pcio-vis-member-event' ),
                        'fieldLocation' => __( 'Location',                        'pcio-vis-member-event' ),
                        'phLocation'    => __( 'Venue or address',               'pcio-vis-member-event' ),
                        'fieldColour'   => __( 'Colour',                          'pcio-vis-member-event' ),
                        'btnDeleteEvt'  => __( 'Delete event',                    'pcio-vis-member-event' ),
                        'btnCancel'     => __( 'Cancel',                          'pcio-vis-member-event' ),
                        'btnSave'       => __( 'Save event',                      'pcio-vis-member-event' ),
                        'titleDelete'   => __( 'Delete Event',                    'pcio-vis-member-event' ),
                        'confirmDel'    => __( 'Confirm deletion',               'pcio-vis-member-event' ),
                        'cannotUndo'    => __( 'This action cannot be undone.',   'pcio-vis-member-event' ),
                        'btnDelete'     => __( 'Delete',                          'pcio-vis-member-event' ),
                        'closeLabel'    => __( 'Close',                           'pcio-vis-member-event' ),
                        'colorBlue'     => __( 'Blue',                            'pcio-vis-member-event' ),
                        'colorGreen'    => __( 'Green',                           'pcio-vis-member-event' ),
                        'colorRed'      => __( 'Red',                             'pcio-vis-member-event' ),
                        'colorPurple'   => __( 'Purple',                          'pcio-vis-member-event' ),
                        'colorAmber'    => __( 'Amber',                           'pcio-vis-member-event' ),
                        'colorTeal'     => __( 'Teal',                            'pcio-vis-member-event' ),
                        'colorPink'     => __( 'Pink',                            'pcio-vis-member-event' ),
                        'colorSlate'    => __( 'Slate',                           'pcio-vis-member-event' ),
                        /* translators: %s: colour name (e.g. "Blue", "Red") */
                        'colorAria'     => __( 'Color: %s',                       'pcio-vis-member-event' ),
                        'btnToday'      => __( 'Today',                           'pcio-vis-member-event' ),
                        'btnMonth'      => __( 'Month',                           'pcio-vis-member-event' ),
                        'btnWeek'       => __( 'Week',                            'pcio-vis-member-event' ),
                        'btnDay'        => __( 'Day',                             'pcio-vis-member-event' ),
                        'btnList'       => __( 'List',                            'pcio-vis-member-event' ),
                        /* translators: Week-view title prefix, e.g. "Week 37". */
                        'weekLabel'     => __( 'Week',                            'pcio-vis-member-event' ),
                        'errFcLoad'     => __( 'FullCalendar could not be loaded.', 'pcio-vis-member-event' ),
                        'errLoadEvents' => __( 'Could not load events: ',         'pcio-vis-member-event' ),
                        'toastMoved'    => __( 'Event moved.',                    'pcio-vis-member-event' ),
                        'errMove'       => __( 'Could not move event: ',          'pcio-vis-member-event' ),
                        'toastResized'  => __( 'Event resized.',                  'pcio-vis-member-event' ),
                        'errResize'     => __( 'Could not resize event: ',        'pcio-vis-member-event' ),
                        'errTitleReq'   => __( 'Title is required.',              'pcio-vis-member-event' ),
                        'errStartReq'   => __( 'Start date is required.',         'pcio-vis-member-event' ),
                        'stateSaving'   => __( 'Saving…',                         'pcio-vis-member-event' ),
                        'errSave'       => __( 'Save failed: ',                   'pcio-vis-member-event' ),
                        'stateDeleting' => __( 'Deleting…',                       'pcio-vis-member-event' ),
                        'errDelete'     => __( 'Delete failed: ',                 'pcio-vis-member-event' ),
                        'toastDeleted'  => __( 'Event deleted.',                  'pcio-vis-member-event' ),
                        /* translators: %s: event title or "this event" */
                        'deletePrompt'  => __( 'Delete %s?',                      'pcio-vis-member-event' ),
                        'thisEvent'     => __( 'this event',                      'pcio-vis-member-event' ),
                        // Calendar tabs
                        'tabCalendar'      => __( 'Calendar',      'pcio-vis-member-event' ),
                        'tabEventTypes'    => __( 'Event Types',   'pcio-vis-member-event' ),
                        'tabResources'     => __( 'Resources',     'pcio-vis-member-event' ),
                        // Event type fields
                        'fieldEventType'   => __( 'Event type',    'pcio-vis-member-event' ),
                        'noEventType'      => __( '— None —',      'pcio-vis-member-event' ),
                        'accessPublic'     => __( 'Public',        'pcio-vis-member-event' ),
                        'accessMembers'    => __( 'Members only',  'pcio-vis-member-event' ),
                        'accessVolunteers' => __( 'Volunteers only', 'pcio-vis-member-event' ),
                        'btnEdit'          => __( 'Edit',          'pcio-vis-member-event' ),
                        'btnNewType'       => __( '+ New type',    'pcio-vis-member-event' ),
                        'titleNewType'     => __( 'New Event Type', 'pcio-vis-member-event' ),
                        'fieldTypeName'    => __( 'Name',          'pcio-vis-member-event' ),
                        'fieldTypeAccess'  => __( 'Access',        'pcio-vis-member-event' ),
                        'fieldTypeColor'   => __( 'Colour',        'pcio-vis-member-event' ),
                        'toastTypeCreated' => __( 'Event type created.', 'pcio-vis-member-event' ),
                        'toastTypeUpdated' => __( 'Event type updated.', 'pcio-vis-member-event' ),
                        'toastTypeDeleted' => __( 'Event type deleted.', 'pcio-vis-member-event' ),
                        // Resource fields
                        'btnNewResource'      => __( '+ New resource',  'pcio-vis-member-event' ),
                        'titleNewResource'    => __( 'New Resource',     'pcio-vis-member-event' ),
                        'fieldResourceName'   => __( 'Name',             'pcio-vis-member-event' ),
                        'fieldResourceDesc'   => __( 'Description',      'pcio-vis-member-event' ),
                        'fieldResources'      => __( 'Resources',        'pcio-vis-member-event' ),
                        'conflictWarning'     => __( 'Resource conflict: ', 'pcio-vis-member-event' ),
                        'toastRscCreated'     => __( 'Resource created.', 'pcio-vis-member-event' ),
                        'toastRscUpdated'     => __( 'Resource updated.', 'pcio-vis-member-event' ),
                        'toastRscDeleted'     => __( 'Resource deleted.', 'pcio-vis-member-event' ),
                        // Rolling Text tab
                        'tabRollingText'      => __( 'Rolling Texts', 'pcio-vis-member-event' ),
                        'btnNewRollingText'   => __( '+ New entry', 'pcio-vis-member-event' ),
                        'titleNewRollingText' => __( 'New Rolling Text', 'pcio-vis-member-event' ),
                        'titleEditRollingText'=> __( 'Edit Rolling Text', 'pcio-vis-member-event' ),
                        'fieldRollerId'       => __( 'Roller ID', 'pcio-vis-member-event' ),
                        'fieldStartAt'        => __( 'Start', 'pcio-vis-member-event' ),
                        'fieldStopAt'         => __( 'Stop', 'pcio-vis-member-event' ),
                        'fieldRollingText'    => __( 'Text', 'pcio-vis-member-event' ),
                        'filterRollerId'      => __( 'Filter by roller ID', 'pcio-vis-member-event' ),
                        'toastRtCreated'      => __( 'Rolling text created.', 'pcio-vis-member-event' ),
                        'toastRtUpdated'      => __( 'Rolling text updated.', 'pcio-vis-member-event' ),
                        'toastRtDeleted'      => __( 'Rolling text deleted.', 'pcio-vis-member-event' ),
                    ],
                    'canManageTypes' => current_user_can( 'vis_edit_all_events' ),
                ] ) . ';',
                'before'
            );
        } elseif ( $page === 'mails' ) {
            wp_enqueue_media();
            wp_enqueue_style(
                'quill-snow',
                $assets . 'lib/quill/quill.snow.css',
                [], '2.0.3'
            );
            wp_enqueue_script(
                'quill',
                $assets . 'lib/quill/quill.js',
                [], '2.0.3', true
            );
            wp_enqueue_script( 'pcio-me-mails', $assets . 'mails.js', [ 'quill' ], '1.1.0', true );
            wp_add_inline_script(
                'pcio-me-mails',
                'const PCIO_ME = ' . wp_json_encode( [
                    'rest'    => rest_url( PCIO_VIS_REST_NS ),
                    'nonce'   => wp_create_nonce( 'wp_rest' ),
                    'canEdit' => current_user_can( 'vis_manage_settings' ),
                    'i18n'    => [
                        'pageTitle'     => __( 'Mails',                                                              'pcio-vis-member-event' ),
                        'btnNew'        => __( '+ New Mail',                                                         'pcio-vis-member-event' ),
                        'colSubject'    => __( 'Subject',                                                            'pcio-vis-member-event' ),
                        'colCreated'    => __( 'Created',                                                            'pcio-vis-member-event' ),
                        'colStatus'     => __( 'Status',                                                             'pcio-vis-member-event' ),
                        'titleCreate'   => __( 'New Mail',                                                           'pcio-vis-member-event' ),
                        'titleEdit'     => __( 'Edit Mail',                                                          'pcio-vis-member-event' ),
                        'closeLabel'    => __( 'Close',                                                              'pcio-vis-member-event' ),
                        'fieldSubject'  => __( 'Subject',                                                            'pcio-vis-member-event' ),
                        'phSubject'     => __( 'Mail subject…',                                                 'pcio-vis-member-event' ),
                        'fieldContent'  => __( 'Content',                                                            'pcio-vis-member-event' ),
                        'btnCancel'     => __( 'Cancel',                                                             'pcio-vis-member-event' ),
                        'btnSaveDraft'  => __( 'Save draft',                                                         'pcio-vis-member-event' ),
                        'btnSendToAll'        => __( 'Send…',                                                                   'pcio-vis-member-event' ),
                        'titleSendMail'       => __( 'Send Mail',                                                               'pcio-vis-member-event' ),
                        'sendQ'               => __( 'Send to all members?',                                                    'pcio-vis-member-event' ),
                        'sendInfo'            => __( 'This will send the mail to the selected recipients.',                     'pcio-vis-member-event' ),
                        'loadingCount'        => __( 'Loading member count…',                                             'pcio-vis-member-event' ),
                        'btnSendNow'          => __( 'Send now',                                                                'pcio-vis-member-event' ),
                        'sendTargetLabel'     => __( 'Send to',                                                                 'pcio-vis-member-event' ),
                        'sendTargetAll'       => __( 'All members',                                                             'pcio-vis-member-event' ),
                        'sendTargetAddresses' => __( 'Specific addresses',                                                      'pcio-vis-member-event' ),
                        'sendTargetGroup'     => __( 'Group',                                                                   'pcio-vis-member-event' ),
                        'sendGroupLabel'      => __( 'Group',                                                                   'pcio-vis-member-event' ),
                        'sendAddressesLabel'  => __( 'Email addresses',                                                         'pcio-vis-member-event' ),
                        'sendAddressesPh'     => __( 'alice@example.com; bob@example.com',                                      'pcio-vis-member-event' ),
                        'sendAddressesRequired' => __( 'Enter at least one email address.',                                     'pcio-vis-member-event' ),
                        /* translators: %s: comma-separated list of invalid email addresses. */
                        'sendAddressesInvalid'  => __( 'Invalid address(es): %s',                                               'pcio-vis-member-event' ),
                        'titleDelete'   => __( 'Delete Mail',                                                        'pcio-vis-member-event' ),
                        'confirmDel'    => __( 'Confirm deletion',                                                   'pcio-vis-member-event' ),
                        'cannotUndo'    => __( 'This action cannot be undone.',                                      'pcio-vis-member-event' ),
                        'btnDelete'     => __( 'Delete',                                                             'pcio-vis-member-event' ),
                        'statusDraft'   => __( 'Draft',                                                              'pcio-vis-member-event' ),
                        'statusSentPfx' => __( 'Sent',                                                               'pcio-vis-member-event' ),
                        'noMailsYet'    => __( 'No mails yet.',                                                      'pcio-vis-member-event' ),
                        'errorLoad'     => __( 'Failed to load mails: ',                                             'pcio-vis-member-event' ),
                        'filterWord'    => __( 'Filter',                                                             'pcio-vis-member-event' ),
                        'stateSaving'   => __( 'Saving…',                                                      'pcio-vis-member-event' ),
                        'stateSending'  => __( 'Sending…',                                                     'pcio-vis-member-event' ),
                        'stateDeleting' => __( 'Deleting…',                                                    'pcio-vis-member-event' ),
                        'toastCreated'  => __( 'Mail created.',                                                      'pcio-vis-member-event' ),
                        'toastUpdated'  => __( 'Mail updated.',                                                      'pcio-vis-member-event' ),
                        'toastDeleted'  => __( 'Mail deleted.',                                                      'pcio-vis-member-event' ),
                        'errSave'       => __( 'Save failed: ',                                                      'pcio-vis-member-event' ),
                        'errLoadMail'   => __( 'Failed to load mail: ',                                              'pcio-vis-member-event' ),
                        'errDelete'     => __( 'Delete failed: ',                                                    'pcio-vis-member-event' ),
                        'errSend'       => __( 'Send failed: ',                                                      'pcio-vis-member-event' ),
                        'mailSingular'  => __( 'mail',                                                               'pcio-vis-member-event' ),
                        'mailPlural'    => __( 'mails',                                                              'pcio-vis-member-event' ),
                        'showing'       => __( 'Showing',                                                            'pcio-vis-member-event' ),
                        'of'            => __( 'of',                                                                 'pcio-vis-member-event' ),
                        'totalSuffix'   => __( 'total',                                                              'pcio-vis-member-event' ),
                        /* translators: %d: number of members the mail was sent to. */
                        'sentToFmt'     => __( 'Sent to %d member(s)',                                               'pcio-vis-member-event' ),
                        /* translators: %d: number of failed deliveries. */
                        'sentFailedFmt' => __( ' (%d failed)',                                                       'pcio-vis-member-event' ),
                        'phQuill'       => __( 'Write the mail content here…',                                  'pcio-vis-member-event' ),
                        'subjectReq'    => __( 'Subject is required.',                                               'pcio-vis-member-event' ),
                        'editTitle'     => __( 'Edit',                                                               'pcio-vis-member-event' ),
                        'sendTitle'     => __( 'Send to all members',                                                'pcio-vis-member-event' ),
                        'deleteTitle'   => __( 'Delete',                                                             'pcio-vis-member-event' ),
                        'attachmentsLabel'  => __( 'Attachments',                                                    'pcio-vis-member-event' ),
                        'btnAddAttachment'  => __( 'Add attachment…',                                              'pcio-vis-member-event' ),
                        'noMediaLib'        => __( 'WordPress media library is not available.',                      'pcio-vis-member-event' ),
                        'mediaTitle'        => __( 'Select Attachment',                                              'pcio-vis-member-event' ),
                        'mediaBtn'          => __( 'Attach',                                                         'pcio-vis-member-event' ),
                        'removeAttachment'  => __( 'Remove attachment',                                              'pcio-vis-member-event' ),
                        'newsletterToggle'     => __( 'This mail is a newsletter',                                    'pcio-vis-member-event' ),
                        'newsletterHint'       => __( 'A newsletter appends a list of upcoming events and a footer, and can be generated to a PDF document.', 'pcio-vis-member-event' ),
                        'newsletterMaxDate'    => __( 'Include events until',                                         'pcio-vis-member-event' ),
                        'newsletterMaxDateHint' => __( 'Defaults to the end of next month.',                          'pcio-vis-member-event' ),
                        'newsletterFooter'     => __( 'Footer',                                                       'pcio-vis-member-event' ),
                        'newsletterFooterPh'   => __( 'Closing text shown at the bottom of the newsletter…',     'pcio-vis-member-event' ),
                        'newsletterGenerate'   => __( 'Generate newsletter PDF',                                      'pcio-vis-member-event' ),
                        'newsletterGenerating' => __( 'Generating…',                                            'pcio-vis-member-event' ),
                        'newsletterDone'       => __( 'Newsletter generated',                                         'pcio-vis-member-event' ),
                        'newsletterFailed'     => __( 'Generation failed: ',                                          'pcio-vis-member-event' ),
                        'newsletterOpen'       => __( 'Open PDF',                                                     'pcio-vis-member-event' ),
                        'newsletterEvents'     => __( 'events',                                                       'pcio-vis-member-event' ),
                        'newsletterBadge'      => __( 'Newsletter',                                                   'pcio-vis-member-event' ),
                        'systemBadge'          => __( 'System',                                                       'pcio-vis-member-event' ),
                        'availableTagsLabel'   => __( 'Available tags',                                               'pcio-vis-member-event' ),
                        'systemMailNoDelete'   => __( 'System mails cannot be deleted.',                               'pcio-vis-member-event' ),
                    ],
                ] ) . ';',
                'before'
            );
        } elseif ( $page === 'newsletters' ) {
            wp_enqueue_style( 'pcio-me-newsletters', $assets . 'newsletters.css', [ 'pcio-me-app' ], '1.0.0' );
            wp_enqueue_script( 'pcio-me-newsletters', $assets . 'newsletters.js', [], '1.0.0', true );
            wp_add_inline_script(
                'pcio-me-newsletters',
                'const PCIO_ME = ' . wp_json_encode( [
                    'rest'    => rest_url( PCIO_VIS_REST_NS ),
                    'nonce'   => wp_create_nonce( 'wp_rest' ),
                    'canEdit' => current_user_can( 'vis_manage_settings' ),
                    'mailsUrl' => home_url( 'vis/mails' ),
                    'i18n'    => [
                        'pageTitle'    => __( 'Newsletters',                          'pcio-vis-member-event' ),
                        'colTitle'     => __( 'Title',                                'pcio-vis-member-event' ),
                        'colEvents'    => __( 'Events',                               'pcio-vis-member-event' ),
                        'colMaxDate'   => __( 'Until',                                'pcio-vis-member-event' ),
                        'colGenerated' => __( 'Generated',                            'pcio-vis-member-event' ),
                        'colFile'      => __( 'PDF',                                  'pcio-vis-member-event' ),
                        'searchPh'     => __( 'Quick search all columns…',      'pcio-vis-member-event' ),
                        'filterWord'   => __( 'Filter',                               'pcio-vis-member-event' ),
                        'open'         => __( 'Open PDF',                             'pcio-vis-member-event' ),
                        'deleteTitle'  => __( 'Delete',                               'pcio-vis-member-event' ),
                        'titleDelete'  => __( 'Delete Newsletter',                    'pcio-vis-member-event' ),
                        'confirmDel'   => __( 'Confirm deletion',                     'pcio-vis-member-event' ),
                        'cannotUndo'   => __( 'This deletes the newsletter and its PDF. This cannot be undone.', 'pcio-vis-member-event' ),
                        'btnCancel'    => __( 'Cancel',                               'pcio-vis-member-event' ),
                        'btnDelete'    => __( 'Delete',                               'pcio-vis-member-event' ),
                        'closeLabel'   => __( 'Close',                                'pcio-vis-member-event' ),
                        'emptyNone'    => __( 'No newsletters generated yet. Create a newsletter mail and click “Generate newsletter PDF”.', 'pcio-vis-member-event' ),
                        'emptyFiltered' => __( 'No newsletters match your filters.', 'pcio-vis-member-event' ),
                        'newsletterSingular' => __( 'newsletter',                     'pcio-vis-member-event' ),
                        'newsletterPlural'   => __( 'newsletters',                    'pcio-vis-member-event' ),
                        'showing'      => __( 'Showing',                              'pcio-vis-member-event' ),
                        'of'           => __( 'of',                                   'pcio-vis-member-event' ),
                        'totalSuffix'  => __( 'total',                                'pcio-vis-member-event' ),
                        'errLoad'      => __( 'Failed to load newsletters: ',         'pcio-vis-member-event' ),
                        'errDelete'    => __( 'Delete failed: ',                      'pcio-vis-member-event' ),
                        'toastDeleted' => __( 'Newsletter deleted.',                  'pcio-vis-member-event' ),
                        'gotoMails'    => __( 'Go to Mails',                          'pcio-vis-member-event' ),
                    ],
                ] ) . ';',
                'before'
            );
        } elseif ( $page === 'documents' ) {
            // wp_enqueue_media() loads the WP media library JS (needed for the file picker)
            wp_enqueue_media();
            wp_enqueue_script( 'pcio-me-documents', $assets . 'documents.js', [], '1.0.0', true );
            wp_add_inline_script(
                'pcio-me-documents',
                'const PCIO_ME = ' . wp_json_encode( [
                    'rest'    => rest_url( PCIO_VIS_REST_NS ),
                    'nonce'   => wp_create_nonce( 'wp_rest' ),
                    'canEdit' => current_user_can( 'vis_publish_events' ),
                    'i18n'    => [
                        'pageTitle'     => __( 'Documents',                                              'pcio-vis-member-event' ),
                        'btnNew'        => __( '+ Add Document',                                        'pcio-vis-member-event' ),
                        'searchPh'      => __( 'Quick search…',                                    'pcio-vis-member-event' ),
                        'colType'       => __( 'Type',                                                   'pcio-vis-member-event' ),
                        'colName'       => __( 'Name',                                                   'pcio-vis-member-event' ),
                        'colDate'       => __( 'Date',                                                   'pcio-vis-member-event' ),
                        'colFile'       => __( 'File',                                                   'pcio-vis-member-event' ),
                        'titleCreate'   => __( 'Add Document',                                          'pcio-vis-member-event' ),
                        'titleEdit'     => __( 'Edit Document',                                         'pcio-vis-member-event' ),
                        'closeDialog'   => __( 'Close dialog',                                          'pcio-vis-member-event' ),
                        'fieldType'     => __( 'Document type',                                         'pcio-vis-member-event' ),
                        'phType'        => __( 'Select type…',                                     'pcio-vis-member-event' ),
                        'fieldDate'     => __( 'Document date',                                         'pcio-vis-member-event' ),
                        'fieldName'     => __( 'Display name',                                          'pcio-vis-member-event' ),
                        'phName'        => __( 'e.g. Annual Report 2025',                               'pcio-vis-member-event' ),
                        'fieldFile'     => __( 'File',                                                   'pcio-vis-member-event' ),
                        'noFileChosen'  => __( 'No file chosen',                                        'pcio-vis-member-event' ),
                        'btnChooseFile' => __( 'Choose from media library',                             'pcio-vis-member-event' ),
                        'mediaTitle'    => __( 'Select Document',                                       'pcio-vis-member-event' ),
                        'mediaBtn'      => __( 'Use this document',                                     'pcio-vis-member-event' ),
                        'noMediaLib'    => __( 'WordPress media library is not available.',             'pcio-vis-member-event' ),
                        'btnCancel'     => __( 'Cancel',                                                'pcio-vis-member-event' ),
                        'btnSave'       => __( 'Save document',                                         'pcio-vis-member-event' ),
                        'titleDelete'   => __( 'Delete Document',                                       'pcio-vis-member-event' ),
                        'confirmDel'    => __( 'Confirm deletion',                                      'pcio-vis-member-event' ),
                        'cannotUndo'    => __( 'This action cannot be undone.',                         'pcio-vis-member-event' ),
                        'btnDelete'     => __( 'Delete',                                                'pcio-vis-member-event' ),
                        'btnEdit'       => __( 'Edit',                                                  'pcio-vis-member-event' ),
                        'emptyNone'     => __( 'No documents yet. Click + Add Document to add one.',   'pcio-vis-member-event' ),
                        'emptyFiltered' => __( 'No documents match the current filters.',              'pcio-vis-member-event' ),
                        'errorLoad'     => __( 'Failed to load documents: ',                            'pcio-vis-member-event' ),
                        'stateSaving'   => __( 'Saving…',                                         'pcio-vis-member-event' ),
                        'stateDeleting' => __( 'Deleting…',                                       'pcio-vis-member-event' ),
                        'toastCreated'  => __( 'Document added.',                                       'pcio-vis-member-event' ),
                        'toastUpdated'  => __( 'Document updated.',                                     'pcio-vis-member-event' ),
                        'toastDeleted'  => __( 'Document deleted.',                                     'pcio-vis-member-event' ),
                        'errSave'       => __( 'Save failed: ',                                         'pcio-vis-member-event' ),
                        'errDelete'     => __( 'Delete failed: ',                                       'pcio-vis-member-event' ),
                        'errTypeReq'    => __( 'Document type is required.',                            'pcio-vis-member-event' ),
                        'errNameReq'    => __( 'Display name is required.',                             'pcio-vis-member-event' ),
                        'errFileReq'    => __( 'Please choose a file.',                                 'pcio-vis-member-event' ),
                        'filterWord'    => __( 'Filter',                                                'pcio-vis-member-event' ),
                        'docSingular'   => __( 'document',                                              'pcio-vis-member-event' ),
                        'docPlural'     => __( 'documents',                                             'pcio-vis-member-event' ),
                        'showing'       => __( 'Showing',                                               'pcio-vis-member-event' ),
                        'of'            => __( 'of',                                                    'pcio-vis-member-event' ),
                        'totalSuffix'   => __( 'total',                                                 'pcio-vis-member-event' ),
                        'download'      => __( 'Download',                                              'pcio-vis-member-event' ),
                    ],
                ] ) . ';',
                'before'
            );
        } elseif ( $page === 'finance' ) {
            // The WP media library powers the "attach a receipt" picker.
            wp_enqueue_media();
            wp_enqueue_style( 'pcio-me-finance', $assets . 'finance.css', [ 'pcio-me-app' ], '1.0.3' );
            wp_enqueue_script( 'pcio-me-finance', $assets . 'finance.js', [], '1.0.3', true );

            $currency = apply_filters( 'pcio_me_finance_currency', 'DKK' );
            $can_edit = current_user_can( 'vis_manage_finance' );

            // Core list: the transaction journal (Kasseklade). Extensions append
            // more lists (e.g. the Tickets plugin's read-only Orders list).
            $lists = [
                [
                    'id'       => 'journal',
                    'label'    => __( 'Journal', 'pcio-vis-member-event' ),
                    'rest'     => rest_url( PCIO_VIS_REST_NS . '/finance/journal' ),
                    'editable' => true,
                    'canEdit'  => $can_edit,
                    'columns'  => [
                        [ 'key' => 'entry_date',    'label' => __( 'Date',          'pcio-vis-member-event' ), 'type' => 'datetime', 'sortable' => true, 'form' => true ],
                        [ 'key' => 'title',         'label' => __( 'Title',         'pcio-vis-member-event' ), 'type' => 'text',     'sortable' => true, 'form' => true ],
                        [ 'key' => 'member_name',   'label' => __( 'Member',        'pcio-vis-member-event' ), 'type' => 'text',     'sortable' => true, 'form' => true ],
                        [ 'key' => 'member_number', 'label' => __( 'Member #',      'pcio-vis-member-event' ), 'type' => 'text',     'sortable' => true, 'form' => true ],
                        [ 'key' => 'amount_cents',  'label' => __( 'Amount',        'pcio-vis-member-event' ), 'type' => 'money',    'sortable' => true, 'form' => true ],
                        [ 'key' => 'balance_cents', 'label' => __( 'Total',         'pcio-vis-member-event' ), 'type' => 'money',    'sortable' => true, 'form' => false ],
                        [ 'key' => 'verified',      'label' => __( 'Verified',      'pcio-vis-member-event' ), 'type' => 'bool',     'sortable' => true, 'form' => true ],
                        [ 'key' => 'media_id',      'label' => __( 'Attachment',    'pcio-vis-member-event' ), 'type' => 'media',    'sortable' => false, 'form' => true ],
                    ],
                ],
            ];

            /**
             * Filter: pcio_me_finance_lists
             * Lets extensions register additional Finance lists (data tables).
             * Each list is an array with keys: id, label, rest, editable, canEdit,
             * columns[] ({ key, label, type, sortable, form }).
             *
             * @param array $lists Existing list descriptors.
             */
            $lists = apply_filters( 'pcio_me_finance_lists', $lists );

            wp_add_inline_script(
                'pcio-me-finance',
                'window.PCIO_VIS_FINANCE = ' . wp_json_encode( [
                    'rest'     => rest_url( PCIO_VIS_REST_NS ),
                    'nonce'    => wp_create_nonce( 'wp_rest' ),
                    'currency' => $currency,
                    'locale'   => str_replace( '_', '-', get_locale() ),
                    'lists'    => array_values( $lists ),
                    'i18n'     => [
                        'pageTitle'     => __( 'Finance',                                          'pcio-vis-member-event' ),
                        'btnNew'        => __( '+ New entry',                                       'pcio-vis-member-event' ),
                        'searchPh'      => __( 'Quick search all columns…',                    'pcio-vis-member-event' ),
                        'titleCreate'   => __( 'New entry',                                        'pcio-vis-member-event' ),
                        'titleEdit'     => __( 'Edit entry',                                       'pcio-vis-member-event' ),
                        'closeDialog'   => __( 'Close dialog',                                     'pcio-vis-member-event' ),
                        'btnCancel'     => __( 'Cancel',                                           'pcio-vis-member-event' ),
                        'btnSave'       => __( 'Save entry',                                       'pcio-vis-member-event' ),
                        'titleDelete'   => __( 'Delete entry',                                     'pcio-vis-member-event' ),
                        'confirmDel'    => __( 'Confirm deletion',                                 'pcio-vis-member-event' ),
                        'cannotUndo'    => __( 'This action cannot be undone.',                    'pcio-vis-member-event' ),
                        'btnDelete'     => __( 'Delete',                                           'pcio-vis-member-event' ),
                        'btnEdit'       => __( 'Edit',                                             'pcio-vis-member-event' ),
                        'errorLoad'     => __( 'Failed to load: ',                                 'pcio-vis-member-event' ),
                        'emptyNone'     => __( 'No entries yet.',                                  'pcio-vis-member-event' ),
                        'emptyFiltered' => __( 'No entries match the current filters.',            'pcio-vis-member-event' ),
                        'errAmount'     => __( 'Enter a valid amount.',                            'pcio-vis-member-event' ),
                        'hintNegative'  => __( 'Use a negative amount for money paid out.',         'pcio-vis-member-event' ),
                        'stateSaving'   => __( 'Saving…',                                     'pcio-vis-member-event' ),
                        'stateDeleting' => __( 'Deleting…',                                   'pcio-vis-member-event' ),
                        'toastCreated'  => __( 'Entry created.',                                   'pcio-vis-member-event' ),
                        'toastUpdated'  => __( 'Entry updated.',                                   'pcio-vis-member-event' ),
                        'toastSaved'    => __( 'Saved.',                                           'pcio-vis-member-event' ),
                        'toastDeleted'  => __( 'Entry deleted.',                                   'pcio-vis-member-event' ),
                        'errSave'       => __( 'Save failed: ',                                    'pcio-vis-member-event' ),
                        'errDelete'     => __( 'Delete failed: ',                                  'pcio-vis-member-event' ),
                        'filterWord'    => __( 'Filter',                                           'pcio-vis-member-event' ),
                        'showing'       => __( 'Showing',                                          'pcio-vis-member-event' ),
                        'of'            => __( 'of',                                               'pcio-vis-member-event' ),
                        'totalSuffix'   => __( 'total',                                            'pcio-vis-member-event' ),
                        'confirmDiscard'=> __( 'You have unsaved changes. Discard them?',          'pcio-vis-member-event' ),
                        'fieldAttach'   => __( 'Attachment',                                       'pcio-vis-member-event' ),
                        'btnChooseFile' => __( 'Choose from media library',                        'pcio-vis-member-event' ),
                        'btnRemoveFile' => __( 'Remove',                                           'pcio-vis-member-event' ),
                        'noFileChosen'  => __( 'No file chosen',                                   'pcio-vis-member-event' ),
                        'mediaTitle'    => __( 'Select Attachment',                                'pcio-vis-member-event' ),
                        'mediaBtn'      => __( 'Use this file',                                    'pcio-vis-member-event' ),
                        'noMediaLib'    => __( 'WordPress media library is not available.',        'pcio-vis-member-event' ),
                        'viewLabel'     => __( 'Open',                                             'pcio-vis-member-event' ),
                    ],
                ] ) . ';',
                'before'
            );
        } elseif ( $page === 'statistics' ) {
            wp_enqueue_script( 'pcio-me-chartjs',    $assets . 'lib/chart.umd.min.js', [],                    '4.4.4', true );
            wp_enqueue_style(  'pcio-me-statistics', $assets . 'statistics.css',       [ 'pcio-me-app' ],     '1.0.0' );
            wp_enqueue_script( 'pcio-me-statistics', $assets . 'statistics.js',        [ 'pcio-me-chartjs' ], '1.0.2', true );

            wp_add_inline_script(
                'pcio-me-statistics',
                'window.PCIO_VIS_STATS = ' . wp_json_encode( [
                    'rest'  => rest_url( PCIO_VIS_REST_NS ),
                    'nonce' => wp_create_nonce( 'wp_rest' ),
                    'i18n'  => [
                        'loading'        => __( 'Loading…',                   'pcio-vis-member-event' ),
                        'errorLoad'      => __( 'Failed to load: ',           'pcio-vis-member-event' ),
                        'chartTitle'     => __( 'Member changes by year',     'pcio-vis-member-event' ),
                        'labelJoined'    => __( 'Joined',                     'pcio-vis-member-event' ),
                        'labelLeft'      => __( 'Left',                       'pcio-vis-member-event' ),
                        'noData'         => __( 'No join/leave data yet.',    'pcio-vis-member-event' ),
                        'countersTitle'  => __( 'Activity counters',          'pcio-vis-member-event' ),
                        'totalMembers'   => __( 'Total members',              'pcio-vis-member-event' ),
                        'pageViews'      => __( 'Front page views',           'pcio-vis-member-event' ),
                        'logins'         => __( 'Logins',                     'pcio-vis-member-event' ),
                        'activeSubs'     => __( 'Active memberships',         'pcio-vis-member-event' ),
                        'sponsorTitle'   => __( 'Sponsor link clicks',        'pcio-vis-member-event' ),
                        'noSponsorClicks'=> __( 'No sponsor clicks recorded yet.', 'pcio-vis-member-event' ),
                        'sponsorUrl'     => __( 'URL',                        'pcio-vis-member-event' ),
                        'sponsorClicks'  => __( 'Clicks',                     'pcio-vis-member-event' ),
                    ],
                    'subnav' => [
                        [
                            'label'  => __( 'Members', 'pcio-vis-member-event' ),
                            'url'    => home_url( 'vis/members' ),
                            'active' => false,
                        ],
                        [
                            'label'  => __( 'Workgroups', 'pcio-vis-member-event' ),
                            'url'    => home_url( 'vis/workgroups' ),
                            'active' => false,
                        ],
                        [
                            'label'  => __( 'Statistics', 'pcio-vis-member-event' ),
                            'url'    => home_url( 'vis/members/statistics' ),
                            'active' => true,
                        ],
                    ],
                ] ) . ';',
                'before'
            );
        } elseif ( $page === 'workgroups' ) {
            wp_enqueue_script( 'pcio-me-workgroups', $assets . 'workgroups.js', [], '1.1.0', true );
            wp_add_inline_script(
                'pcio-me-workgroups',
                'const PCIO_ME = ' . wp_json_encode( [
                    'rest'    => rest_url( PCIO_VIS_REST_NS ),
                    'nonce'   => wp_create_nonce( 'wp_rest' ),
                    'canEdit' => current_user_can( 'vis_manage_users' ),
                    'i18n'    => [
                        'pageTitle'       => __( 'Workgroups',                         'pcio-vis-member-event' ),
                        'btnNew'          => __( '+ New workgroup',                    'pcio-vis-member-event' ),
                        'fieldName'       => __( 'Name',                               'pcio-vis-member-event' ),
                        'fieldDesc'       => __( 'Description',                        'pcio-vis-member-event' ),
                        'fieldColor'      => __( 'Colour',                             'pcio-vis-member-event' ),
                        'btnSave'         => __( 'Save',                               'pcio-vis-member-event' ),
                        'btnCancel'       => __( 'Cancel',                             'pcio-vis-member-event' ),
                        'btnDelete'       => __( 'Delete',                             'pcio-vis-member-event' ),
                        'btnAddMember'    => __( 'Add volunteer',                      'pcio-vis-member-event' ),
                        'btnChairman'     => __( 'Chairman',                           'pcio-vis-member-event' ),
                        'btnRemove'       => __( 'Remove',                             'pcio-vis-member-event' ),
                        'toastCreated'    => __( 'Workgroup created.',                 'pcio-vis-member-event' ),
                        'toastUpdated'    => __( 'Workgroup updated.',                 'pcio-vis-member-event' ),
                        'toastDeleted'    => __( 'Workgroup deleted.',                 'pcio-vis-member-event' ),
                        'toastMemberAdded'   => __( 'Volunteer added.',               'pcio-vis-member-event' ),
                        'toastMemberRemoved' => __( 'Volunteer removed.',             'pcio-vis-member-event' ),
                        'confirmDelete'   => __( 'Delete this workgroup?',             'pcio-vis-member-event' ),
                        'noWorkgroups'    => __( 'No workgroups yet.',                 'pcio-vis-member-event' ),
                        'noMembers'       => __( 'No volunteers in this workgroup.',   'pcio-vis-member-event' ),
                        'searchPh'        => __( 'Search volunteers…',                'pcio-vis-member-event' ),
                        'errNameReq'      => __( 'Name is required.',                  'pcio-vis-member-event' ),
                        'errSave'         => __( 'Save failed: ',                      'pcio-vis-member-event' ),
                        'errLoad'         => __( 'Failed to load: ',                   'pcio-vis-member-event' ),
                        'memberCount'     => __( 'volunteers',                         'pcio-vis-member-event' ),
                        'btnCopyEmails'   => __( 'Copy emails',                           'pcio-vis-member-event' ),
                        'toastCopied'     => __( 'email address(es) copied to clipboard.', 'pcio-vis-member-event' ),
                        'toastCopyNone'   => __( 'No email addresses in this workgroup.', 'pcio-vis-member-event' ),
                        'toastCopyFail'   => __( 'Could not copy to clipboard.',          'pcio-vis-member-event' ),
                    ],
                    'subnav' => [
                        [ 'label' => __( 'Members', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/members' ), 'active' => false ],
                        [ 'label' => __( 'Workgroups', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/workgroups' ), 'active' => true ],
                        [ 'label' => __( 'Statistics', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/members/statistics' ), 'active' => false ],
                    ],
                ] ) . ';',
                'before'
            );
        }

        require $template;
        exit;
    }

    // ── Shortcode [pcio_me_documents] ──────────────────────────────
    // Public document list filtered by type, sorted date DESC. No login required.
    //
    // Attributes:
    //   type   (string, required) — the document type name/slug
    //   limit  (int, default 0)   — max rows; 0 = all
    //
    // Example: [pcio_me_documents type="annual-report" limit="5"]

    public function shortcode_documents( array $atts ): string {
        $atts = shortcode_atts(
            [ 'type' => '', 'limit' => 0 ],
            $atts,
            'pcio_me_documents'
        );

        $type  = sanitize_key( $atts['type'] );
        $limit = absint( $atts['limit'] );

        if ( ! $type ) {
            return '';
        }

        $docs = PCIO_VIS_Docs_DB::get_by_type( $type, $limit );

        ob_start();
        if ( empty( $docs ) ) {
            echo '<p class="pcio-me-docs-empty">'
                . esc_html__( 'No documents found.', 'pcio-vis-member-event' )
                . '</p>';
        } else {
            echo '<ul class="pcio-me-documents-list" style="list-style:none;padding-left:0">';
            foreach ( $docs as $d ) {
                $url  = $d['attachment_url'];
                $name = $d['display_name'];
                echo '<li class="pcio-me-documents-item">';
                if ( $url ) {
                    echo '<a class="pcio-me-doc-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
                        . esc_html( $name )
                        . '</a>';
                } else {
                    echo esc_html( $name );
                }
                if ( $d['document_date'] ) {
                    echo ' <time class="pcio-me-doc-date" datetime="' . esc_attr( $d['document_date'] ) . '">'
                        . esc_html( wp_date( get_option( 'date_format' ), strtotime( $d['document_date'] ) ) )
                        . '</time>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        return ob_get_clean();
    }

    // ── Shortcode [pcio_me_edit_profile] ──────────────────────────
    // Renders an inline edit form for the currently logged-in member.
    // Access: requires vis_member capability and a linked member record.
    //
    // Example: [pcio_me_edit_profile]

    public function shortcode_edit_profile( array $atts ): string {
        if ( ! is_user_logged_in() || ! current_user_can( 'vis_member' ) ) {
            return '<p>' . esc_html__( 'You must be logged in as a Vis member to edit your profile.', 'pcio-vis-member-event' ) . '</p>';
        }

        $member_id = PCIO_VIS_Roles_DB::get_member_id_by_wp_user( get_current_user_id() );
        if ( ! $member_id ) {
            return '<p>' . esc_html__( 'No member record is linked to your account.', 'pcio-vis-member-event' ) . '</p>';
        }

        $member = PCIO_VIS_DB::get( $member_id );
        if ( ! $member ) {
            return '<p>' . esc_html__( 'Could not load your member record.', 'pcio-vis-member-event' ) . '</p>';
        }

        $assets = plugins_url( 'assets/', PCIO_VIS_PLUGIN_FILE );
        wp_enqueue_style( 'pcio-me-app',     $assets . 'members.css', [],               '1.2.0' );
        wp_enqueue_script( 'pcio-me-profile', $assets . 'profile.js',  [],               '1.2.1', true );

        // Collect full boat objects already decorated onto the member row.
        $boats = array_values( (array) ( $member['boat_data'] ?? [] ) );

        // Subscription renewal URL (requires pcio-vis-products).
        $renew_url         = '';
        $renew_expire_date = '';
        if ( class_exists( 'PCIO_VIS_Products_DB' ) ) {
            $subs = PCIO_VIS_Products_DB::get_subscriptions_for_member( $member_id );
            if ( ! empty( $subs ) ) {
                $token = (string) ( $subs[0]['verify_token'] ?? '' );
                if ( $token ) {
                    $renew_url = home_url( 'shop/renew?token=' . rawurlencode( $token ) );
                }
                // Compute what the new expire date would be after a 1-year renewal.
                $current_expire = (string) ( $subs[0]['expire_date'] ?? '' );
                $base           = $current_expire && strtotime( $current_expire ) > time()
                    ? $current_expire
                    : gmdate( 'Y-m-d' );
                $renew_expire_date = gmdate( 'Y-m-d', strtotime( $base . ' +1 year' ) );
            }
        }

        $profile_config = [
            'rest'         => rest_url( PCIO_VIS_REST_NS ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'memberId'     => $member_id,
            'member'       => $member,
            'memberFields' => PCIO_VIS_DB::get_field_definitions(),
            'boats'        => $boats,
            'renewUrl'     => $renew_url,
            'renewExpireDate' => $renew_expire_date,
            'i18n'         => [
                'pageTitle'      => __( 'Edit your profile',                                   'pcio-vis-member-event' ),
                'fieldMemberNum' => __( 'Membership number',                                   'pcio-vis-member-event' ),
                'fieldName'      => __( 'Name',                                                'pcio-vis-member-event' ),
                'phName'         => __( 'Full name',                                           'pcio-vis-member-event' ),
                'fieldEmail'     => __( 'Email',                                               'pcio-vis-member-event' ),
                'phEmail'        => __( 'name@example.com',                                    'pcio-vis-member-event' ),
                'fieldPhone'     => __( 'Phone',                                               'pcio-vis-member-event' ),
                'phPhone'        => __( '+45 00 00 00 00',                                     'pcio-vis-member-event' ),
                'fieldAddress'   => __( 'Address',                                             'pcio-vis-member-event' ),
                'phAddress'      => __( 'Street, city, postal code…',                     'pcio-vis-member-event' ),
                // Subscription
                'btnRenew'       => __( 'Renew subscription',                                  'pcio-vis-member-event' ),
                // Actions
                'btnSave'        => __( 'Save changes',                                        'pcio-vis-member-event' ),
                'stateSaving'    => __( 'Saving…',                                        'pcio-vis-member-event' ),
                'toastSaved'     => __( 'Your profile has been updated.',                      'pcio-vis-member-event' ),
                'errName'        => __( 'Name is required.',                                   'pcio-vis-member-event' ),
                'errEmail'       => __( 'Enter a valid email address.',                        'pcio-vis-member-event' ),
                'errSave'        => __( 'Save failed: ',                                       'pcio-vis-member-event' ),
            ],
        ];

        /**
         * Filter: pcio_me_profile_config
         * Allows extensions to inject their own keys into the profile JS config.
         * The boat extension uses this to add boatRest, harboursRest and boat i18n.
         *
         * @param array $config
         */
        $profile_config = apply_filters( 'pcio_me_profile_config', $profile_config );

        wp_add_inline_script(
            'pcio-me-profile',
            'window.PCIO_ME_PROFILE = ' . wp_json_encode( $profile_config ) . ';',
            'before'
        );

        return '<div id="pcio-me-profile-app"></div>';
    }

    // ── Shortcode [pcio_me_members] ───────────────────────────────
    // Public member list with live search and boat info popups.
    // Extension plugins can override output via the pcio_me_members_html filter.

    public function shortcode_members( array $atts ): string {
        if ( ! current_user_can( 'vis_member' ) ) {
            return '';
        }
        $all_fields = PCIO_VIS_DB::get_field_definitions();
        // Only include fields appropriate for the public shortcode.
        // Extensions may tag a field with 'shortcode_col' => false to hide it here
        // while keeping it visible in the /vis/members admin table (list_col: true).
        $fields = array_values( array_filter(
            $all_fields,
            static fn( array $f ): bool => ( $f['shortcode_col'] ?? $f['list_col'] ?? true ) !== false
        ) );

        $members = PCIO_VIS_DB::get_all();

        /**
         * Filter: pcio_me_members_list
         * Modify or sort the member array before it is rendered.
         *
         * @param array $members
         */
        $members = apply_filters( 'pcio_me_members_list', $members );

        // Unique IDs for this shortcode instance (supports multiple on the same page).
        static $pcio_sc_inst = 0;
        ++$pcio_sc_inst;
        $table_id  = 'pcio-me-ml-' . $pcio_sc_inst;
        $search_id = 'pcio-me-ms-' . $pcio_sc_inst;
        $modal_id  = 'pcio-me-bm-' . $pcio_sc_inst;

        ob_start();

        if ( empty( $members ) ) {
            echo '<p>' . esc_html__( 'No members found.', 'pcio-vis-member-event' ) . '</p>';
        } else {
            ?>
            <div class="pcio-me-members-wrap">
                <div style="margin-bottom:10px">
                    <input type="search" id="<?php echo esc_attr( $search_id ); ?>"
                           placeholder="<?php esc_attr_e( 'Search…', 'pcio-vis-member-event' ); ?>"
                           style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:4px;min-width:260px;font-size:.9em">
                </div>

                <div class="pcio-me-members-list" style="overflow-x:auto">
                    <table class="pcio-me-members-table" id="<?php echo esc_attr( $table_id ); ?>">
                        <thead><tr>
                            <?php foreach ( $fields as $f ) : ?>
                                <th><?php echo esc_html( $f['label'] ); ?></th>
                            <?php endforeach; ?>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $members as $m ) :
                            // Build search haystack from all flat string values in the row
                            // (includes extension fields not shown as columns).
                            $search_parts = [];
                            foreach ( $m as $v ) {
                                if ( is_string( $v ) || is_numeric( $v ) ) {
                                    $search_parts[] = (string) $v;
                                }
                            }
                            $search_text = strtolower( implode( ' ', array_filter( $search_parts ) ) );
                        ?>
                        <tr data-search="<?php echo esc_attr( $search_text ); ?>">
                            <?php foreach ( $fields as $f ) :
                                $val = (string) ( $m[ $f['key'] ] ?? '' );
                                if ( $f['key'] === 'email' && $val ) {
                                    echo '<td><a href="mailto:' . esc_attr( $val ) . '">' . esc_html( $val ) . '</a></td>';
                                } else {
                                    /**
                                     * Filter: pcio_me_members_cell_html
                                     * Allow extensions to customise the HTML for a single table cell.
                                     * The returned HTML must be properly escaped by the caller.
                                     *
                                     * @param string $html   Default escaped cell content.
                                     * @param array  $member Full member row.
                                     * @param array  $field  Field definition.
                                     */
                                    $cell = apply_filters( 'pcio_me_members_cell_html', esc_html( $val ?: '–' ), $m, $f );
                                    echo '<td>' . wp_kses_post( $cell ) . '</td>';
                                }
                            endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Boat detail modal (position:fixed, shown on demand) -->
                <div id="<?php echo esc_attr( $modal_id ); ?>"
                     style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.55);align-items:center;justify-content:center"
                     role="dialog" aria-modal="true">
                    <div style="background:#fff;border-radius:8px;padding:1.5rem 1.75rem;max-width:500px;width:90%;position:relative;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)">
                        <button class="pcio-bm-close"
                                style="position:absolute;top:.5rem;right:.75rem;font-size:1.75rem;line-height:1;border:none;background:none;cursor:pointer;color:#64748b"
                                aria-label="<?php esc_attr_e( 'Close', 'pcio-vis-member-event' ); ?>">&times;</button>
                        <div class="pcio-bm-content"></div>
                    </div>
                </div>

                <script>
                ( function () {
                    var searchEl  = document.getElementById( <?php echo wp_json_encode( $search_id ); ?> );
                    var tableEl   = document.getElementById( <?php echo wp_json_encode( $table_id ); ?> );
                    var modalEl   = document.getElementById( <?php echo wp_json_encode( $modal_id ); ?> );
                    var closeBtn  = modalEl && modalEl.querySelector( '.pcio-bm-close' );
                    var contentEl = modalEl && modalEl.querySelector( '.pcio-bm-content' );

                    if ( searchEl && tableEl ) {
                        searchEl.addEventListener( 'input', function () {
                            var q = this.value.toLowerCase().trim();
                            Array.from( tableEl.tBodies[0].rows ).forEach( function ( row ) {
                                row.style.display = ( ! q || ( row.getAttribute( 'data-search' ) || '' ).includes( q ) ) ? '' : 'none';
                            } );
                        } );
                    }

                    if ( modalEl ) {
                        document.addEventListener( 'click', function ( e ) {
                            var btn = e.target.closest( '.pcio-boat-trigger' );
                            if ( ! btn ) return;
                            try {
                                contentEl.innerHTML = renderBoats( JSON.parse( btn.getAttribute( 'data-boat' ) || '[]' ) );
                                modalEl.style.display = 'flex';
                            } catch ( err ) {}
                            e.preventDefault();
                        } );
                        if ( closeBtn ) {
                            closeBtn.addEventListener( 'click', function () { modalEl.style.display = 'none'; } );
                        }
                        modalEl.addEventListener( 'click', function ( e ) {
                            if ( e.target === modalEl ) modalEl.style.display = 'none';
                        } );
                        document.addEventListener( 'keydown', function ( e ) {
                            if ( e.key === 'Escape' && modalEl.style.display !== 'none' ) modalEl.style.display = 'none';
                        } );
                    }

                    function renderBoats( boats ) {
                        return boats.map( function ( b ) {
                            var rows = [
                                [ <?php echo wp_json_encode( __( 'Type',     'pcio-vis-member-event' ) ); ?>, b.boat_type    ],
                                [ <?php echo wp_json_encode( __( 'Model',    'pcio-vis-member-event' ) ); ?>, b.boat_model   ],
                                [ <?php echo wp_json_encode( __( 'Built',    'pcio-vis-member-event' ) ); ?>, b.build_year   ],
                                [ <?php echo wp_json_encode( __( 'Sail no.', 'pcio-vis-member-event' ) ); ?>, b.sail_id      ],
                                [ <?php echo wp_json_encode( __( 'MMSI',     'pcio-vis-member-event' ) ); ?>, b.mmsi         ],
                                [ <?php echo wp_json_encode( __( 'Harbour',  'pcio-vis-member-event' ) ); ?>, b.home_harbour ],
                                [ <?php echo wp_json_encode( __( 'Note',     'pcio-vis-member-event' ) ); ?>, b.note         ],
                            ].filter( function ( r ) { return r[1]; } );
                            return '<h3 style="margin:0 0 .75rem;font-size:1.1rem">' + esc( b.boat_name ) + '</h3>'
                                + '<dl style="display:grid;grid-template-columns:auto 1fr;gap:4px 16px;margin:0;font-size:.9rem">'
                                + rows.map( function ( r ) {
                                    return '<dt style="font-weight:600;color:#475569">' + esc( r[0] ) + '</dt>'
                                        + '<dd style="margin:0">' + esc( String( r[1] ) ) + '</dd>';
                                } ).join( '' )
                                + '</dl>';
                        } ).join( '<hr style="margin:.75rem 0;border:none;border-top:1px solid #e2e8f0">' );
                    }

                    function esc( s ) {
                        return String( s || '' )
                            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
                            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
                    }
                }() );
                </script>
            </div>
            <?php
        }

        $html = ob_get_clean();

        /**
         * Filter: pcio_me_members_html
         * Replace the entire shortcode output. If the filter returns a different
         * string, wp_kses_post() is applied to the result for safety.
         *
         * @param string $html    Default rendered HTML.
         * @param array  $members Member list (with meta merged in).
         * @param array  $fields  All field definitions (including hidden ones).
         */
        $filtered = apply_filters( 'pcio_me_members_html', $html, $members, $all_fields );
        if ( $filtered !== $html ) {
            return wp_kses_post( $filtered );
        }
        return $html;
    }

    // ── Shortcode [pcio_me_event] ──────────────────────────────────
    // Renders a public-facing event card with image, info, and signup controls.
    //
    // Attributes:
    //   id  (int, required) — the event ID
    //
    // Example: [pcio_me_event id="42"]

    public function shortcode_event( array $atts ): string {
        $atts     = shortcode_atts( [ 'id' => 0 ], $atts, 'pcio_me_event' );
        $event_id = absint( $atts['id'] );
        if ( ! $event_id ) {
            return '';
        }

        $event = PCIO_VIS_Events_DB::get( $event_id );
        if ( ! $event ) {
            return '';
        }

        // Stylesheet (shared with the /vis/ app) — enqueue on any page using the shortcode.
        $assets = plugins_url( 'assets/', PCIO_VIS_PLUGIN_FILE );
        wp_enqueue_style( 'pcio-me-app', $assets . 'members.css', [], '1.3.0' );

        // Resolve cover image.
        $image_id  = ! empty( $event['image_id'] ) ? (int) $event['image_id'] : 0;
        $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : false;

        // Resolve current user's signup status.
        $can_signup = false;
        $my_status  = null;
        if ( current_user_can( 'vis_member' ) ) {
            $member_id = PCIO_VIS_Signups_DB::get_member_id_for_current_user();
            if ( $member_id ) {
                $can_signup = true;
                $row        = PCIO_VIS_Signups_DB::get_for_member_event( $member_id, $event_id );
                $my_status  = $row ? $row['status'] : null;
            }
        }

        // Core member signup buttons are only shown in 'simple' mode.
        if ( 'simple' !== ( $event['signup_mode'] ?? 'simple' ) ) {
            $can_signup = false;
        }

        // Format dates. Stored datetimes are in the site timezone, so parse them
        // as such to avoid an unwanted offset when wp_date() reformats them.
        $tz      = wp_timezone();
        $all_day = (bool) intval( $event['all_day'] );
        $start   = ( new DateTimeImmutable( $event['start_datetime'], $tz ) )->getTimestamp();
        $end     = $event['end_datetime']
                       ? ( new DateTimeImmutable( $event['end_datetime'], $tz ) )->getTimestamp()
                       : null;
        if ( $all_day && $end ) {
            // Stored as exclusive; display as inclusive.
            $end = strtotime( '-1 day', $end );
        }
        $date_format = get_option( 'date_format' );
        $time_format = get_option( 'time_format' );
        if ( $all_day ) {
            $date_str = wp_date( $date_format, $start );
            if ( $end && $end !== $start ) {
                $date_str .= ' – ' . wp_date( $date_format, $end );
            }
        } else {
            // Always show the start date and time.
            $date_str = wp_date( $date_format . ' ' . $time_format, $start );
            if ( $end ) {
                $same_day = wp_date( 'Y-m-d', $start ) === wp_date( 'Y-m-d', $end );
                // Same day: only the end time. Different day: end date and time.
                $date_str .= ' – ' . wp_date(
                    $same_day ? $time_format : $date_format . ' ' . $time_format,
                    $end
                );
            }
        }

        // Enqueue signup script only when the user can interact.
        if ( $can_signup ) {
            wp_enqueue_script( 'pcio-me-event-signup', $assets . 'event-signup.js', [], '1.0.1', true );
            wp_add_inline_script(
                'pcio-me-event-signup',
                'const PCIO_VIS_EVENT = ' . wp_json_encode( [
                    'rest'          => rest_url( PCIO_VIS_REST_NS ),
                    'nonce'         => wp_create_nonce( 'wp_rest' ),
                    'eventId'       => $event_id,
                    'currentStatus' => $my_status,
                    'i18n'          => [
                        'joining'     => __( 'Joining',      'pcio-vis-member-event' ),
                        'interested'  => __( 'Interested',   'pcio-vis-member-event' ),
                        'notJoining'  => __( 'Not joining',  'pcio-vis-member-event' ),
                        'errSave'     => __( 'Failed to save your response. Please try again.', 'pcio-vis-member-event' ),
                    ],
                ] ) . ';',
                'before'
            );
        }

        ob_start();
        $evt_color = ! empty( $event['color'] ) ? $event['color'] : '#3b82f6';
        ?>
        <div class="me-sc-event" id="me-sc-event-<?php echo esc_attr( $event_id ); ?>"
             style="--evt-color:<?php echo esc_attr( $evt_color ); ?>">

            <?php if ( $image_url ) : ?>
            <img class="me-sc-event-img"
                 src="<?php echo esc_url( $image_url ); ?>"
                 alt="<?php echo esc_attr( $event['title'] ); ?>">
            <?php endif; ?>

            <div class="me-sc-event-body">
                <h2 class="me-sc-event-title"><?php echo esc_html( $event['title'] ); ?></h2>

                <div class="me-sc-event-meta">
                    <span class="me-sc-event-meta-item">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <time datetime="<?php echo esc_attr( $event['start_datetime'] ); ?>"><?php echo esc_html( $date_str ); ?></time>
                    </span>
                    <?php if ( ! empty( $event['location'] ) ) : ?>
                    <span class="me-sc-event-meta-item">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <?php echo esc_html( $event['location'] ); ?>
                    </span>
                    <?php endif; ?>
                </div>

                <?php if ( ! empty( $event['description'] ) ) : ?>
                <div class="me-sc-event-desc">
                    <?php echo wp_kses_post( $event['description'] ); ?>
                </div>
                <?php endif; ?>

                <?php if ( $can_signup ) : ?>
                <div class="me-sc-signup-area">
                    <p class="me-sc-signup-label"><?php esc_html_e( 'Your response', 'pcio-vis-member-event' ); ?></p>
                    <div class="me-sc-signup-group" role="group"
                         aria-label="<?php esc_attr_e( 'Your response', 'pcio-vis-member-event' ); ?>">
                        <?php
                        $statuses = [
                            'joining'     => __( 'Joining',     'pcio-vis-member-event' ),
                            'interested'  => __( 'Interested',  'pcio-vis-member-event' ),
                            'not_joining' => __( 'Not joining', 'pcio-vis-member-event' ),
                        ];
                        foreach ( $statuses as $val => $label ) :
                            $active = $my_status === $val ? ' me-active' : '';
                            ?>
                        <button type="button"
                                class="me-sc-signup-btn me-sc-btn-<?php echo esc_attr( str_replace( '_', '-', $val ) ); ?><?php echo esc_attr( $active ); ?>"
                                data-status="<?php echo esc_attr( $val ); ?>">
                            <?php echo esc_html( $label ); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        /**
         * Action: pcio_me_after_event_main
         * Mirrors the /vis/events/{id}/ route so extensions (e.g. the photo
         * gallery) render below the [pcio_me_event] shortcode card too.
         *
         * @param int $event_id Current event ID.
         */
        do_action( 'pcio_me_after_event_main', $event_id );
        return ob_get_clean();
    }

    // ── Shortcode [pcio_me_upcoming_events] ────────────────────────
    // Compact, month-grouped agenda of upcoming events. Each month is a heading
    // (localised via wp_date, so Danish month names follow the site language),
    // then each event is listed as "day. title" linking to /events/{slug}/.
    //
    // Attributes:
    //   months (int) — how many months ahead to include, counting the current
    //                  month (default 3 → this month + the next two).
    //   limit  (int) — hard cap on the number of events shown (default 0 = no cap).
    //
    // Example: [pcio_me_upcoming_events]  or  [pcio_me_upcoming_events months="2" limit="10"]

    public function shortcode_upcoming_events( array $atts ): string {
        $atts = shortcode_atts(
            [ 'months' => 3, 'limit' => 0 ],
            $atts,
            'pcio_me_upcoming_events'
        );

        $months = max( 1, absint( $atts['months'] ) );
        $limit  = absint( $atts['limit'] );

        $tz = wp_timezone();
        // Window: start of today up to the first day of (current month + months).
        $end_dt = ( new DateTimeImmutable( 'first day of this month 00:00', $tz ) )->modify( "+{$months} months" );

        $events = PCIO_VIS_Events_DB::get_upcoming_until( $end_dt->format( 'Y-m-d H:i:s' ) );
        if ( $limit > 0 ) {
            $events = array_slice( $events, 0, $limit );
        }

        wp_enqueue_style(
            'pcio-me-upcoming-events',
            plugins_url( 'assets/upcoming-events.css', PCIO_VIS_PLUGIN_FILE ),
            [],
            '1.0.0'
        );

        $public_base  = home_url( 'events/' );
        $current_year = (int) wp_date( 'Y' );

        ob_start();
        ?>
        <div class="me-upcoming">
        <?php if ( empty( $events ) ) : ?>
            <p class="me-upcoming-none"><?php esc_html_e( 'No upcoming events.', 'pcio-vis-member-event' ); ?></p>
        <?php
        else :
            $current_group = '';
            foreach ( $events as $event ) :
                $start_dt = new DateTimeImmutable( $event['start_datetime'], $tz );
                $start    = $start_dt->getTimestamp();
                $group    = $start_dt->format( 'Y-m' );

                if ( $group !== $current_group ) :
                    if ( '' !== $current_group ) :
                        ?></div><?php // close the previous month's list.
                    endif;
                    $current_group = $group;
                    // "F" gives the full localised month name; add the year only when
                    // the window crosses into a different year.
                    $heading = wp_date( 'F', $start );
                    if ( (int) $start_dt->format( 'Y' ) !== $current_year ) {
                        $heading .= ' ' . $start_dt->format( 'Y' );
                    }
                    ?>
                    <h3 class="me-upcoming-month"><?php echo esc_html( $heading ); ?></h3>
                    <div class="me-upcoming-list">
                <?php endif;

                $slug = $event['slug'] ?? '';
                $link = $slug ? $public_base . rawurlencode( $slug ) . '/' : '';
                ?>
                <div class="me-upcoming-item">
                    <span class="me-upcoming-day"><?php echo esc_html( wp_date( 'j', $start ) ); ?>.</span>
                    <?php if ( $link ) : ?>
                        <a class="me-upcoming-title" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $event['title'] ); ?></a>
                    <?php else : ?>
                        <span class="me-upcoming-title"><?php echo esc_html( $event['title'] ); ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div><?php // close the final month's list. ?>
        <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── Shortcode [pcio_me_event_roller] ───────────────────────────
    // Rotating front-page slider of the next upcoming events from the
    // calendar. Each slide shows the cover image (or a colour placeholder),
    // an overlaid title + date, and a Read More button linking to the public
    // event page at /events/{slug}/.
    //
    // Attributes:
    //   limit     (int) — number of events to show (default 3)
    //   interval  (int) — auto-advance interval in seconds (default 10)
    //
    // Example: [pcio_me_event_roller]  or  [pcio_me_event_roller limit="5" interval="8"]

    public function shortcode_event_roller( array $atts ): string {
        $atts = shortcode_atts(
            [
                'limit'    => 3,
                'interval' => 10,
            ],
            $atts,
            'pcio_me_event_roller'
        );

        $limit    = max( 1, absint( $atts['limit'] ) );
        $interval = max( 2, absint( $atts['interval'] ) );

        $events = PCIO_VIS_Events_DB::get_upcoming( $limit );
        if ( empty( $events ) ) {
            return '';
        }

        $assets = plugins_url( 'assets/', PCIO_VIS_PLUGIN_FILE );
        wp_enqueue_style( 'pcio-me-event-roller', $assets . 'event-roller.css', [], '1.0.2' );
        wp_enqueue_script( 'pcio-me-event-roller', $assets . 'event-roller.js', [], '1.0.0', true );

        $tz          = wp_timezone();
        $time_format = get_option( 'time_format' );
        $public_base = home_url( 'events/' );

        ob_start();
        ?>
        <div class="me-roller" data-interval="<?php echo esc_attr( $interval ); ?>"
             role="region" aria-roledescription="carousel"
             aria-label="<?php esc_attr_e( 'Upcoming events', 'pcio-vis-member-event' ); ?>">
            <div class="me-roller-track">
                <?php foreach ( $events as $i => $event ) :
                    $image_id  = ! empty( $event['image_id'] ) ? (int) $event['image_id'] : 0;
                    $image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : false;
                    $color     = ! empty( $event['color'] ) ? $event['color'] : '#3b82f6';

                    $all_day  = (bool) intval( $event['all_day'] );
                    $start_dt = new DateTimeImmutable( $event['start_datetime'], $tz );
                    $start    = $start_dt->getTimestamp();
                    $date_str = $this->roller_human_date( $start_dt, $all_day, $time_format );

                    $link = $public_base . rawurlencode( $event['slug'] ?? '' ) . '/';
                    ?>
                <article class="me-roller-slide<?php echo $i === 0 ? ' is-active' : ''; ?>"
                         style="--evt-color:<?php echo esc_attr( $color ); ?>"
                         role="group" aria-roledescription="slide"
                         aria-hidden="<?php echo $i === 0 ? 'false' : 'true'; ?>">
                    <?php if ( $image_url ) : ?>
                    <img class="me-roller-img" src="<?php echo esc_url( $image_url ); ?>"
                         alt="<?php echo esc_attr( $event['title'] ); ?>"
                         <?php echo $i === 0 ? '' : 'loading="lazy"'; ?>>
                    <?php else : ?>
                    <div class="me-roller-img me-roller-img-placeholder" aria-hidden="true"></div>
                    <?php endif; ?>

                    <div class="me-roller-overlay">
                        <div class="me-roller-textbox">
                            <div class="me-roller-head">
                                <h3 class="me-roller-title"><?php echo esc_html( $event['title'] ); ?></h3>
                                <p class="me-roller-date"><?php echo esc_html( $date_str ); ?></p>
                            </div>
                            <?php if ( ! empty( $event['slug'] ) ) : ?>
                            <a class="me-roller-more" href="<?php echo esc_url( $link ); ?>">
                                <?php esc_html_e( 'Read More', 'pcio-vis-member-event' ); ?>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>

            <?php if ( count( $events ) > 1 ) : ?>
            <button class="me-roller-arrow me-roller-prev" type="button"
                    aria-label="<?php esc_attr_e( 'Previous event', 'pcio-vis-member-event' ); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
            </button>
            <button class="me-roller-arrow me-roller-next" type="button"
                    aria-label="<?php esc_attr_e( 'Next event', 'pcio-vis-member-event' ); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
            </button>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Human-friendly date label for the [pcio_me_event_roller] slider.
     *
     * - Today        → "Today" (+ time for timed events)
     * - Tomorrow     → "Tomorrow" (+ time)
     * - Otherwise    → weekday + day + month (e.g. "Monday 16 June"); the year
     *                  is appended only when the event is not in the current year.
     *
     * @param DateTimeImmutable $start_dt    Event start (site timezone).
     * @param bool              $all_day     Whether the event is all-day.
     * @param string            $time_format WordPress time format option.
     */
    private function roller_human_date( DateTimeImmutable $start_dt, bool $all_day, string $time_format ): string {
        $tz        = wp_timezone();
        $start     = $start_dt->getTimestamp();
        $today     = new DateTimeImmutable( 'today', $tz );
        $event_day = new DateTimeImmutable( $start_dt->format( 'Y-m-d' ), $tz );
        $diff_days = (int) $today->diff( $event_day )->format( '%r%a' );

        $time_str = $all_day ? '' : wp_date( $time_format, $start );

        if ( $diff_days === 0 ) {
            $date_part = __( 'Today', 'pcio-vis-member-event' );
        } elseif ( $diff_days === 1 ) {
            $date_part = __( 'Tomorrow', 'pcio-vis-member-event' );
        } else {
            $current_year = (int) wp_date( 'Y' );
            $event_year   = (int) $start_dt->format( 'Y' );
            // "Monday 16 June" — add the year only when it is not this year.
            $fmt       = $event_year !== $current_year ? 'l j F Y' : 'l j F';
            $date_part = wp_date( $fmt, $start );
        }

        return $time_str ? $date_part . ' · ' . $time_str : $date_part;
    }

    // ── Shortcode [pcio_me_events] ─────────────────────────────────
    // Renders all upcoming events as responsive tiles (a "deck") with a
    // client-side search box. The number of tiles per row adapts to screen size.
    //
    // Attributes:
    //   limit  (int) — maximum events to show (default 100)
    //   search (yes|no) — show the search box (default yes)
    //
    // Example: [pcio_me_events]

    public function shortcode_events( array $atts ): string {
        $atts = shortcode_atts(
            [
                'limit'  => 100,
                'search' => 'yes',
            ],
            $atts,
            'pcio_me_events'
        );

        $limit      = max( 1, absint( $atts['limit'] ) );
        $show_search = ! in_array( strtolower( (string) $atts['search'] ), [ 'no', 'false', '0' ], true );

        $events = PCIO_VIS_Events_DB::get_upcoming( $limit );

        $assets = plugins_url( 'assets/', PCIO_VIS_PLUGIN_FILE );
        wp_enqueue_style( 'pcio-me-events-list', $assets . 'events-list.css', [], '1.0.0' );
        if ( $show_search ) {
            wp_enqueue_script( 'pcio-me-events-list', $assets . 'events-list.js', [], '1.0.0', true );
        }

        $tz          = wp_timezone();
        $time_format = get_option( 'time_format' );
        $public_base = home_url( 'events/' );

        ob_start();
        ?>
        <div class="me-events">
            <?php if ( $show_search ) : ?>
            <div class="me-events-searchbar">
                <h2 class="me-events-heading"><?php esc_html_e( 'Upcoming events', 'pcio-vis-member-event' ); ?></h2>
                <input type="search" class="me-events-search" id="me-events-search"
                       placeholder="<?php esc_attr_e( 'Search…', 'pcio-vis-member-event' ); ?>" autocomplete="off">
            </div>
            <?php endif; ?>

            <?php if ( empty( $events ) ) : ?>
                <p class="me-events-none"><?php esc_html_e( 'No upcoming events.', 'pcio-vis-member-event' ); ?></p>
            <?php else : ?>
            <div class="me-events-grid" id="me-events-grid">
                <?php foreach ( $events as $event ) :
                    $thumb_id  = ! empty( $event['thumb_image_id'] ) ? (int) $event['thumb_image_id'] : 0;
                    $image_id  = ! empty( $event['image_id'] ) ? (int) $event['image_id'] : 0;
                    $pick_id   = $thumb_id ?: $image_id;
                    $image_url = $pick_id ? wp_get_attachment_image_url( $pick_id, 'large' ) : false;
                    $color     = ! empty( $event['color'] ) ? $event['color'] : '#3b82f6';

                    $all_day  = (bool) intval( $event['all_day'] );
                    $start_dt = new DateTimeImmutable( $event['start_datetime'], $tz );
                    $start    = $start_dt->getTimestamp();
                    $date_str = $this->roller_human_date( $start_dt, $all_day, $time_format );
                    $day_num  = wp_date( 'j', $start );
                    $mon_abbr = wp_date( 'M', $start );

                    $slug    = $event['slug'] ?? '';
                    $link    = $slug ? $public_base . rawurlencode( $slug ) . '/' : '';
                    $haystack = strtolower( $event['title'] . ' ' . ( $event['location'] ?? '' ) . ' ' . wp_strip_all_tags( $event['description'] ?? '' ) );
                    ?>
                <div class="me-events-deck" data-search="<?php echo esc_attr( $haystack ); ?>"
                     style="--evt-color:<?php echo esc_attr( $color ); ?>">
                    <a class="me-events-link" href="<?php echo esc_url( $link ); ?>">
                        <div class="me-events-thumb"<?php echo $image_url ? ' style="background-image:url(' . esc_url( $image_url ) . ')"' : ''; ?>>
                            <div class="me-events-date">
                                <div class="me-events-date-day"><?php echo esc_html( $day_num ); ?></div>
                                <div class="me-events-date-mon"><?php echo esc_html( $mon_abbr ); ?></div>
                            </div>
                        </div>
                        <div class="me-events-title"><?php echo esc_html( $event['title'] ); ?></div>
                        <div class="me-events-info">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
                            <span><?php echo esc_html( $date_str ); ?></span>
                        </div>
                        <?php if ( ! empty( $event['location'] ) ) : ?>
                        <div class="me-events-info">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            <span><?php echo esc_html( $event['location'] ); ?></span>
                        </div>
                        <?php endif; ?>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ( $show_search ) : ?>
            <p class="me-events-noresults me-hidden" id="me-events-noresults">
                <?php esc_html_e( 'No events match your search.', 'pcio-vis-member-event' ); ?>
            </p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * [pcio_me_newsletters] — public list of recently generated newsletter PDFs.
     *
     * Attributes:
     *   limit  Maximum newsletters to show (default 12).
     */
    public function shortcode_newsletters( array $atts ): string {
        $atts = shortcode_atts(
            [ 'limit' => 12 ],
            $atts,
            'pcio_me_newsletters'
        );

        $limit       = max( 1, absint( $atts['limit'] ) );
        $newsletters = PCIO_VIS_Newsletters_DB::get_recent( $limit );

        $assets = plugins_url( 'assets/', PCIO_VIS_PLUGIN_FILE );
        wp_enqueue_style( 'pcio-me-newsletters-public', $assets . 'newsletters-public.css', [], '1.0.0' );

        ob_start();
        ?>
        <div class="me-nl-public">
            <?php if ( empty( $newsletters ) ) : ?>
                <p class="me-nl-public-none"><?php esc_html_e( 'No newsletters published yet.', 'pcio-vis-member-event' ); ?></p>
            <?php else : ?>
            <ul class="me-nl-public-list">
                <?php foreach ( $newsletters as $nl ) :
                    if ( empty( $nl['url'] ) ) {
                        continue;
                    }
                    $ts   = ! empty( $nl['generated_at'] ) ? strtotime( (string) $nl['generated_at'] ) : false;
                    $when = $ts ? wp_date( get_option( 'date_format' ), $ts ) : '';
                    ?>
                <li class="me-nl-public-item">
                    <a class="me-nl-public-link" href="<?php echo esc_url( $nl['url'] ); ?>" target="_blank" rel="noopener">
                        <svg class="me-nl-public-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <span class="me-nl-public-title"><?php echo esc_html( $nl['title'] ); ?></span>
                    </a>
                    <?php if ( $when ) : ?>
                        <span class="me-nl-public-date"><?php echo esc_html( $when ); ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // ── [pcio_me_member_count] ─────────────────────────────────────

    /**
     * Renders an animated member count and increments the front-page-view counter.
     * Usage: [pcio_me_member_count]
     */
    public function shortcode_member_count(): string {
        PCIO_VIS_Statistics_DB::increment( 'page_views' );

        $count  = count( PCIO_VIS_DB::get_all() );
        $assets = plugins_url( 'assets/', PCIO_VIS_PLUGIN_FILE );

        wp_enqueue_style(  'pcio-me-member-count', $assets . 'member-count.css', [], '1.0.0' );
        wp_enqueue_script( 'pcio-me-member-count', $assets . 'member-count.js',  [], '1.0.0', true );

        return '<span class="pcio-me-member-count" data-target="' . esc_attr( (string) $count ) . '">0</span>';
    }

    // ── [pcio_me_rolling_text] ────────────────────────────────────

    /**
     * Renders a <marquee> of all currently active rolling-text entries for the
     * given roller id. Multiple simultaneous entries are joined with a long dash.
     *
     * Usage: [pcio_me_rolling_text id="homepage"]
     *
     * @param array $atts Shortcode attributes. 'id' is required.
     */
    public function shortcode_rolling_text( array $atts ): string {
        $atts      = shortcode_atts( [ 'id' => '' ], $atts, 'pcio_me_rolling_text' );
        $roller_id = sanitize_key( $atts['id'] );

        if ( $roller_id === '' ) {
            return '';
        }

        $entries = PCIO_VIS_Rolling_Text_DB::get_active( $roller_id );
        if ( empty( $entries ) ) {
            return '';
        }

        $parts = array_map(
            static function ( array $e ): string {
                return esc_html( $e['text'] );
            },
            $entries
        );

        return '<marquee class="pcio-me-rolling-text">' . implode( ' &#8212; ', $parts ) . '</marquee>';
    }

    // ── Login counter ─────────────────────────────────────────────

    /** Increment the login counter on every successful login. */
    public function on_user_login( string $user_login, WP_User $user ): void {
        PCIO_VIS_Statistics_DB::increment( 'login_count' );
    }

    // ── Front-end sponsor tracker ─────────────────────────────────

    /** Enqueue the sponsor-click tracker script on every public page. */
    public function enqueue_front_end(): void {
        $assets = plugins_url( 'assets/', PCIO_VIS_PLUGIN_FILE );
        wp_enqueue_script( 'pcio-me-sponsor-tracker', $assets . 'sponsor-tracker.js', [], '1.0.1', true );
        wp_add_inline_script(
            'pcio-me-sponsor-tracker',
            'window.PCIO_ME_TRACKER = ' . wp_json_encode( [
                'rest' => rest_url( PCIO_VIS_REST_NS ),
            ] ) . ';',
            'before'
        );
    }
}
