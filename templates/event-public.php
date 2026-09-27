<?php
/**
 * Front-end template: Public event detail page
 * Served at /events/{slug}/ — no login required, indexable.
 *
 * Uses the active theme's site header and footer (get_header/get_footer) and
 * lays the event out full-width: a banner, a two-column body (description on
 * the left, key info + enlist on the right) and the thumbnail at the bottom.
 *
 * Provided by PCIO_VIS_Plugin::serve_public_event():
 *   $pcio_me_event  array  Raw event row.
 *   $pcio_me_view   array  Prepared view data (urls, dates, signup state…).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$pcio_me_v = isset( $pcio_me_view ) && is_array( $pcio_me_view ) ? $pcio_me_view : [];
if ( empty( $pcio_me_v ) ) {
    return;
}

// Social meta for the <head>.
add_action( 'wp_head', function () use ( $pcio_me_v ) {
    $img = $pcio_me_v['thumb_url'] ?: $pcio_me_v['banner_url'];
    echo '<meta property="og:title" content="' . esc_attr( $pcio_me_v['title'] ) . '">' . "\n";
    if ( $img ) {
        echo '<meta property="og:image" content="' . esc_url( $img ) . '">' . "\n";
    }
    echo '<meta property="og:type" content="event">' . "\n";
    echo '<meta property="og:url" content="' . esc_url( $pcio_me_v['permalink'] ) . '">' . "\n";
}, 5 );

get_header();
?>

<div class="me-pe" id="me-sc-event-<?php echo esc_attr( $pcio_me_v['id'] ); ?>"
     style="--evt-color:<?php echo esc_attr( $pcio_me_v['color'] ); ?>">

    <?php if ( ! empty( $pcio_me_v['banner_url'] ) ) : ?>
    <div class="me-pe-banner" style="background-image:url(<?php echo esc_url( $pcio_me_v['banner_url'] ); ?>)">
        <div class="me-pe-banner-date">
            <div class="me-pe-banner-day"><?php echo esc_html( $pcio_me_v['day_num'] ); ?></div>
            <div class="me-pe-banner-mon"><?php echo esc_html( $pcio_me_v['mon_abbr'] ); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <h1 class="me-pe-title"><?php echo esc_html( $pcio_me_v['title'] ); ?></h1>

    <div class="me-pe-body">
        <div class="me-pe-main">
            <?php if ( ! empty( $pcio_me_v['description'] ) ) : ?>
                <?php echo wp_kses_post( $pcio_me_v['description'] ); ?>
            <?php else : ?>
                <p class="me-pe-empty"><?php esc_html_e( 'No description.', 'pcio-vis-member-event' ); ?></p>
            <?php endif; ?>
        </div>

        <aside class="me-pe-side">
            <h2 class="me-pe-side-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
                <?php esc_html_e( 'Time', 'pcio-vis-member-event' ); ?>
            </h2>
            <p class="me-pe-side-text"><?php echo esc_html( $pcio_me_v['date_str'] ); ?></p>

            <?php if ( ! empty( $pcio_me_v['location'] ) ) : ?>
            <h2 class="me-pe-side-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <?php esc_html_e( 'Place', 'pcio-vis-member-event' ); ?>
            </h2>
            <p class="me-pe-side-text"><?php echo esc_html( $pcio_me_v['location'] ); ?></p>
            <?php endif; ?>

            <?php
            /**
             * Filter: pcio_me_event_signup_heading
             * The right-bar box heading. Extensions change it to reflect the
             * event type — "Sign up" for member events, "Tickets" for
             * ticket-sales events.
             *
             * @param string $heading  Default heading.
             * @param int    $event_id Current event ID.
             */
            $pcio_me_signup_heading = apply_filters( 'pcio_me_event_signup_heading', __( 'Sign up', 'pcio-vis-member-event' ), $pcio_me_v['id'] );

            /**
             * Filter: pcio_me_event_signup_box
             * Lets an extension take over the right-bar box body (e.g. the Tickets
             * plugin renders a "Buy tickets" button here). When it returns a
             * non-empty string the core member-signup controls are skipped, so the
             * box shows either member signup OR ticket purchase — never both.
             *
             * @param string $html     Default (empty) — provide HTML to take over.
             * @param int    $event_id Current event ID.
             * @param array  $view     Prepared view data.
             */
            $pcio_me_signup_box = apply_filters( 'pcio_me_event_signup_box', '', $pcio_me_v['id'], $pcio_me_v );

            $pcio_me_has_box = ( '' !== $pcio_me_signup_box );
            // Omit the whole invitation box when the event takes no sign-ups
            // ('none') and no extension (e.g. Tickets) provided a box.
            $pcio_me_show_signup = $pcio_me_has_box
                || 'simple' === ( $pcio_me_v['signup_mode'] ?? 'simple' );
            ?>
            <?php if ( $pcio_me_show_signup ) : ?>
            <h2 class="me-pe-side-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                <?php echo esc_html( $pcio_me_signup_heading ); ?>
            </h2>
            <?php if ( $pcio_me_has_box ) : ?>
                <?php
                /*
                 * The box body comes from an extension via the pcio_me_event_signup_box
                 * filter (e.g. the Tickets plugin's "Buy tickets" link, or a signup form).
                 * The extension escapes its own dynamic values; wp_kses() here is a final
                 * output-escaping safety net that still permits the interactive controls
                 * an extension may legitimately render.
                 */
                $pcio_me_box_allowed = array_merge(
                    wp_kses_allowed_html( 'post' ),
                    [
                        'form'   => [ 'action' => true, 'method' => true, 'class' => true, 'id' => true, 'novalidate' => true ],
                        'input'  => [ 'type' => true, 'name' => true, 'value' => true, 'class' => true, 'id' => true, 'placeholder' => true, 'min' => true, 'max' => true, 'step' => true, 'required' => true, 'checked' => true, 'disabled' => true, 'readonly' => true ],
                        'button' => [ 'type' => true, 'name' => true, 'value' => true, 'class' => true, 'id' => true, 'disabled' => true, 'aria-disabled' => true ],
                        'select' => [ 'name' => true, 'class' => true, 'id' => true, 'required' => true, 'disabled' => true, 'multiple' => true ],
                        'option' => [ 'value' => true, 'selected' => true, 'disabled' => true ],
                        'label'  => [ 'for' => true, 'class' => true ],
                        'span'   => [ 'class' => true, 'id' => true, 'style' => true, 'aria-disabled' => true, 'aria-hidden' => true ],
                        'a'      => [ 'href' => true, 'class' => true, 'id' => true, 'style' => true, 'target' => true, 'rel' => true, 'aria-disabled' => true ],
                    ]
                );
                echo wp_kses( $pcio_me_signup_box, $pcio_me_box_allowed );
                ?>
            <?php elseif ( $pcio_me_v['can_signup'] ) : ?>
                <div class="me-sc-signup-area">
                    <p class="me-sc-signup-label"><?php esc_html_e( 'Your response', 'pcio-vis-member-event' ); ?></p>
                    <div class="me-sc-signup-group" role="group"
                         aria-label="<?php esc_attr_e( 'Your response', 'pcio-vis-member-event' ); ?>">
                        <?php
                        $pcio_me_statuses = [
                            'joining'     => __( 'Joining',     'pcio-vis-member-event' ),
                            'interested'  => __( 'Interested',  'pcio-vis-member-event' ),
                            'not_joining' => __( 'Not joining', 'pcio-vis-member-event' ),
                        ];
                        foreach ( $pcio_me_statuses as $pcio_me_val => $pcio_me_label ) :
                            $pcio_me_active = $pcio_me_v['my_status'] === $pcio_me_val ? ' me-active' : '';
                            ?>
                        <button type="button"
                                class="me-sc-signup-btn me-sc-btn-<?php echo esc_attr( str_replace( '_', '-', $pcio_me_val ) ); ?><?php echo esc_attr( $pcio_me_active ); ?>"
                                data-status="<?php echo esc_attr( $pcio_me_val ); ?>">
                            <?php echo esc_html( $pcio_me_label ); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ( ! $pcio_me_v['is_logged'] ) : ?>
                <p class="me-pe-side-text"><?php esc_html_e( 'Log in to sign up for this event.', 'pcio-vis-member-event' ); ?></p>
                <a class="me-btn me-btn-primary me-pe-login" href="<?php echo esc_url( $pcio_me_v['login_url'] ); ?>">
                    <?php esc_html_e( 'Log in', 'pcio-vis-member-event' ); ?>
                </a>
            <?php else : ?>
                <p class="me-pe-side-text"><?php esc_html_e( 'You cannot sign up for this event.', 'pcio-vis-member-event' ); ?></p>
            <?php endif; ?>
            <?php endif; /* $pcio_me_show_signup */ ?>
        </aside>
    </div>

    <?php if ( ! empty( $pcio_me_v['thumb_url'] ) ) : ?>
    <figure class="me-pe-thumb">
        <img src="<?php echo esc_url( $pcio_me_v['thumb_url'] ); ?>" alt="<?php echo esc_attr( $pcio_me_v['title'] ); ?>">
    </figure>
    <?php endif; ?>

    <?php
    /**
     * Action: pcio_me_after_event_main
     * Lets extensions (e.g. the photo gallery) render below the public page.
     *
     * @param int $event_id Current event ID.
     */
    do_action( 'pcio_me_after_event_main', $pcio_me_v['id'] );
    ?>
</div>

<?php
get_footer();

