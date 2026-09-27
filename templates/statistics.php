<?php
/**
 * Front-end template: Statistics dashboard
 * Served at /vis/statistics/
 *
 * Access control (vis_manage_users) is enforced by PCIO_VIS_Plugin::serve_templates().
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
    <title><?php esc_html_e( 'Statistics', 'pcio-vis-member-event' ); ?> &mdash; Vis</title>
    <?php wp_head(); ?>
</head>
<body class="pcio-me-body">

<div id="pcio-me-wrap">

    <?php $pcio_me_active_page = 'members'; require __DIR__ . '/_nav.php'; ?>

    <main class="me-main">
        <div id="pcio-me-app"></div>
    </main>

</div>

<?php wp_footer(); ?>
</body>
</html>
