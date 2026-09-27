<?php
/**
 * Front-end template: Calendar
 * Served at /vis/calendar/
 *
 * Access control (login-required) is enforced by PCIO_VIS_Plugin::serve_templates().
 * FullCalendar + calendar.js are enqueued by serve_templates() before this runs.
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
    <title>Vis &mdash; <?php esc_html_e( 'Calendar', 'pcio-vis-member-event' ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="pcio-me-body">

<div id="pcio-me-wrap">

    <?php $pcio_me_active_page = 'calendar'; require __DIR__ . '/_nav.php'; ?>

    <main class="me-main">
        <div id="pcio-me-app">
            <div class="me-loading">
                <div class="me-spinner"></div>
                <span><?php esc_html_e( 'Loading&hellip;', 'pcio-vis-member-event' ); ?></span>
            </div>
        </div>
    </main>

</div>

<?php wp_footer(); ?>
</body>
</html>
