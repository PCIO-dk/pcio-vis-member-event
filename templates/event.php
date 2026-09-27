<?php
/**
 * Front-end template: Event detail page
 * Served at /vis/events/{id}/
 *
 * Access control + asset enqueue handled by PCIO_VIS_Plugin::serve_templates().
 * $pcio_me_event_id is available as PCIO_ME.eventId in JS.
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
    <title>Vis &mdash; <?php esc_html_e( 'Event', 'pcio-vis-member-event' ); ?></title>
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

        <?php
        /**
         * Action: pcio_me_after_event_main
         * Renders extra server-side content below the event app (e.g. the
         * Photo Gallery section for this event).
         *
         * @param int $event_id  Current event ID.
         */
        do_action( 'pcio_me_after_event_main', (int) get_query_var( 'pcio_me_id' ) );
        ?>
    </main>

</div>

<?php wp_footer(); ?>
</body>
</html>
