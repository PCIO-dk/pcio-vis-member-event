<?php
/**
 * Front-end template: Landing page
 * Served at /vis/
 *
 * Access control (login-required) is enforced by PCIO_VIS_Plugin::serve_templates().
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

show_admin_bar( false );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Vis</title>
    <?php wp_head(); ?>
</head>
<body class="pcio-me-body">

<div id="pcio-me-wrap">

    <?php $pcio_me_active_page = 'home'; require __DIR__ . '/_nav.php'; ?>

    <main class="me-main">
        <div class="me-home-hero">
            <h1 class="me-home-title">Vis</h1>
            <p class="me-home-sub">
                <?php esc_html_e( 'Voluntary Information System', 'pcio-vis-member-event' ); ?>
            </p>
        </div>

        <div class="me-home-grid">

            <a class="me-home-card" href="<?php echo esc_url( home_url( 'vis/members' ) ); ?>">
                <div class="me-home-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
                <div class="me-home-card-body">
                    <h2 class="me-home-card-title">
                        <?php esc_html_e( 'Members', 'pcio-vis-member-event' ); ?>
                    </h2>
                    <p class="me-home-card-desc">
                        <?php esc_html_e( 'Browse, add and edit members of the organisation.', 'pcio-vis-member-event' ); ?>
                    </p>
                </div>
                <div class="me-home-card-arrow">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </div>
            </a>

            <a class="me-home-card" href="<?php echo esc_url( home_url( 'vis/calendar' ) ); ?>">
                <div class="me-home-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8"  y1="2" x2="8"  y2="6"/>
                        <line x1="3"  y1="10" x2="21" y2="10"/>
                    </svg>
                </div>
                <div class="me-home-card-body">
                    <h2 class="me-home-card-title">
                        <?php esc_html_e( 'Calendar', 'pcio-vis-member-event' ); ?>
                    </h2>
                    <p class="me-home-card-desc">
                        <?php esc_html_e( 'Manage events, sign-ups and the organisation calendar.', 'pcio-vis-member-event' ); ?>
                    </p>
                </div>
                <div class="me-home-card-arrow">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </div>
            </a>


            <?php if ( current_user_can( 'vis_manage_settings' ) ) : ?>
            <a class="me-home-card" href="<?php echo esc_url( home_url( 'vis/mails' ) ); ?>">
                <div class="me-home-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                        <polyline points="22,6 12,13 2,6"/>
                    </svg>
                </div>
                <div class="me-home-card-body">
                    <h2 class="me-home-card-title">
                        <?php esc_html_e( 'Mails', 'pcio-vis-member-event' ); ?>
                    </h2>
                    <p class="me-home-card-desc">
                        <?php esc_html_e( 'Compose and send bulk emails to all members.', 'pcio-vis-member-event' ); ?>
                    </p>
                </div>
                <div class="me-home-card-arrow">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </div>
            </a>

            <a class="me-home-card" href="<?php echo esc_url( home_url( 'vis/newsletters' ) ); ?>">
                <div class="me-home-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                    </svg>
                </div>
                <div class="me-home-card-body">
                    <h2 class="me-home-card-title">
                        <?php esc_html_e( 'Newsletters', 'pcio-vis-member-event' ); ?>
                    </h2>
                    <p class="me-home-card-desc">
                        <?php esc_html_e( 'Generated newsletter PDFs.', 'pcio-vis-member-event' ); ?>
                    </p>
                </div>
                <div class="me-home-card-arrow">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </div>
            </a>
            <?php endif; ?>

            <a class="me-home-card" href="<?php echo esc_url( home_url( 'vis/documents' ) ); ?>">
                <div class="me-home-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                        <line x1="16" y1="13" x2="8" y2="13"/>
                        <line x1="16" y1="17" x2="8" y2="17"/>
                        <polyline points="10 9 9 9 8 9"/>
                    </svg>
                </div>
                <div class="me-home-card-body">
                    <h2 class="me-home-card-title">
                        <?php esc_html_e( 'Documents', 'pcio-vis-member-event' ); ?>
                    </h2>
                    <p class="me-home-card-desc">
                        <?php esc_html_e( 'Upload and manage organisation documents by category.', 'pcio-vis-member-event' ); ?>
                    </p>
                </div>
                <div class="me-home-card-arrow">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </div>
            </a>

            <?php if ( current_user_can( 'vis_manage_finance' ) ) : ?>
            <a class="me-home-card" href="<?php echo esc_url( home_url( 'vis/finance' ) ); ?>">
                <div class="me-home-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="1" x2="12" y2="23"/>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                    </svg>
                </div>
                <div class="me-home-card-body">
                    <h2 class="me-home-card-title">
                        <?php esc_html_e( 'Finance', 'pcio-vis-member-event' ); ?>
                    </h2>
                    <p class="me-home-card-desc">
                        <?php esc_html_e( 'Cash journal and financial records of the organisation.', 'pcio-vis-member-event' ); ?>
                    </p>
                </div>
                <div class="me-home-card-arrow">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </div>
            </a>
            <?php endif; ?>

            <?php if ( current_user_can( 'vis_manage_users' ) ) : ?>
            <a class="me-home-card" href="<?php echo esc_url( home_url( 'vis/members/statistics' ) ); ?>">
                <div class="me-home-card-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"/>
                        <line x1="12" y1="20" x2="12" y2="4"/>
                        <line x1="6"  y1="20" x2="6"  y2="14"/>
                        <line x1="2"  y1="20" x2="22" y2="20"/>
                    </svg>
                </div>
                <div class="me-home-card-body">
                    <h2 class="me-home-card-title">
                        <?php esc_html_e( 'Statistics', 'pcio-vis-member-event' ); ?>
                    </h2>
                    <p class="me-home-card-desc">
                        <?php esc_html_e( 'Member growth, page views, logins and sponsor clicks.', 'pcio-vis-member-event' ); ?>
                    </p>
                </div>
                <div class="me-home-card-arrow">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </div>
            </a>
            <?php endif; ?>

            <?php
            /**
             * Action: pcio_me_home_cards
             * Lets extensions add their own cards to the /vis/ landing page.
             * Echo one or more <a class="me-home-card">…</a> blocks.
             */
            do_action( 'pcio_me_home_cards' );
            ?>

        </div>
    </main>

</div>

<?php wp_footer(); ?>
</body>
</html>
