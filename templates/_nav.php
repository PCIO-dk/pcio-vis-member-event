<?php
/**
 * Shared navigation bar for all vis/ templates.
 * Expects $pcio_me_active_page to be set by the including template.
 * e.g.:  $pcio_me_active_page = 'members';
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$pcio_me_active_page = isset( $pcio_me_active_page ) ? (string) $pcio_me_active_page : '';

$pcio_me_nav_items = [
    'members'   => [ 'label' => __( 'Members',   'pcio-vis-member-event' ), 'url' => home_url( 'vis/members'   ) ],
    'calendar'  => [ 'label' => __( 'Calendar',  'pcio-vis-member-event' ), 'url' => home_url( 'vis/calendar'  ) ],
    'documents' => [ 'label' => __( 'Documents', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/documents' ) ],
];

if ( current_user_can( 'vis_manage_finance' ) ) {
    $pcio_me_nav_items['finance'] = [ 'label' => __( 'Finance', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/finance' ) ];
}

if ( current_user_can( 'vis_manage_settings' ) ) {
    $pcio_me_nav_items['mails']       = [ 'label' => __( 'Mails',       'pcio-vis-member-event' ), 'url' => home_url( 'vis/mails'       ) ];
    $pcio_me_nav_items['newsletters'] = [ 'label' => __( 'Newsletters', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/newsletters' ) ];
}

/**
 * Filter: pcio_me_nav_items
 * Lets extensions add or remove front-end navigation items.
 * Each item: [ 'label' => string, 'url' => string ].
 *
 * @param array  $pcio_me_nav_items     Keyed list of nav items.
 * @param string $pcio_me_active_page   The current active page key.
 */
$pcio_me_nav_items = apply_filters( 'pcio_me_nav_items', $pcio_me_nav_items, $pcio_me_active_page );
?>
<nav class="me-nav">
    <a class="me-nav-brand" href="<?php echo esc_url( home_url( 'vis' ) ); ?>">Vis</a>

    <div class="me-nav-links">
        <?php foreach ( $pcio_me_nav_items as $pcio_me_key => $pcio_me_item ) : ?>
            <a class="me-nav-link<?php echo $pcio_me_active_page === $pcio_me_key ? ' me-nav-active' : ''; ?>"
               href="<?php echo esc_url( $pcio_me_item['url'] ); ?>">
                <?php echo esc_html( $pcio_me_item['label'] ); ?>
            </a>
        <?php endforeach; ?>

    <div class="me-nav-user">
        <a class="me-nav-link me-nav-ext"
           href="<?php echo esc_url( home_url( '/' ) ); ?>">
            <?php esc_html_e( 'Website', 'pcio-vis-member-event' ); ?>
        </a>
        <?php if ( current_user_can( 'vis_manage_settings' ) || current_user_can( 'manage_options' ) ) : ?>
            <a class="me-nav-link me-nav-ext"
               href="<?php echo esc_url( admin_url( 'admin.php?page=pcio-me-settings' ) ); ?>">
                <?php esc_html_e( 'WP Admin', 'pcio-vis-member-event' ); ?>
            </a>
        <?php endif; ?>
        <span class="me-nav-username"><?php echo esc_html( wp_get_current_user()->display_name ); ?></span>
        <a class="me-nav-link"
           href="<?php echo esc_url( wp_logout_url( home_url( 'vis' ) ) ); ?>">
            <?php esc_html_e( 'Log out', 'pcio-vis-member-event' ); ?>
        </a>
    </div>
</nav>
