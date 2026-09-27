<?php
/**
 * Sub-navigation bar for the Members section (/vis/members/*).
 * Expects $pcio_me_subnav_active to be set by the including template.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$pcio_me_subnav_active = isset( $pcio_me_subnav_active ) ? (string) $pcio_me_subnav_active : '';
?>
<style>
.me-members-subnav {
    display: flex;
    gap: .25rem;
    padding: .6rem 1.5rem .1rem;
    border-bottom: 2px solid #e5e7eb;
    background: #fff;
}
.me-members-subnav-link {
    padding: .4rem .85rem .55rem;
    font-size: .875rem;
    font-weight: 500;
    color: #4b5563;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    border-radius: 4px 4px 0 0;
    transition: color .12s;
}
.me-members-subnav-link:hover { color: #1d6fb8; }
.me-members-subnav-active {
    color: #1d6fb8;
    border-bottom-color: #1d6fb8;
    font-weight: 600;
}
</style>
<nav class="me-members-subnav" aria-label="<?php esc_attr_e( 'Members section', 'pcio-vis-member-event' ); ?>">
    <a class="me-members-subnav-link<?php echo 'members' === $pcio_me_subnav_active ? ' me-members-subnav-active' : ''; ?>"
       href="<?php echo esc_url( home_url( 'vis/members' ) ); ?>">
        <?php esc_html_e( 'Member list', 'pcio-vis-member-event' ); ?>
    </a>
    <?php if ( current_user_can( 'vis_manage_users' ) ) : ?>
    <a class="me-members-subnav-link<?php echo 'statistics' === $pcio_me_subnav_active ? ' me-members-subnav-active' : ''; ?>"
       href="<?php echo esc_url( home_url( 'vis/members/statistics' ) ); ?>">
        <?php esc_html_e( 'Statistics', 'pcio-vis-member-event' ); ?>
    </a>
    <?php endif; ?>
</nav>
