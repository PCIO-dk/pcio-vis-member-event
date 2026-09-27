<?php
/**
 * Front-end template: Workgroups management page.
 * Served at /vis/workgroups by PCIO_VIS_Plugin::serve_templates().
 * Access control (login + vis_manage_users) is enforced before this loads.
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
    <title>Vis &mdash; <?php esc_html_e( 'Workgroups', 'pcio-vis-member-event' ); ?></title>
    <?php wp_head(); ?>
</head>
<body class="pcio-me-body">

<div id="pcio-me-wrap">

    <?php
    $pcio_me_active_page = 'workgroups';
    $pcio_vis_nav = PCIO_VIS_PLUGIN_DIR . 'templates/_nav.php';
    if ( file_exists( $pcio_vis_nav ) ) {
        require $pcio_vis_nav;
    }
    ?>

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
