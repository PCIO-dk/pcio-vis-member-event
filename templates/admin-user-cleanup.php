<?php
/**
 * WordPress user cleanup report and administrator-only bulk actions.
 *
 * @package PCIO_VIS_Member_Event
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'WP User Cleanup', 'pcio-vis-member-event' ); ?></h1>
    <?php if ( is_wp_error( $result ) ) : ?>
        <div class="notice notice-warning"><p><?php echo esc_html( $result->get_error_message() ); ?></p></div>
    <?php endif; ?>
    <?php if ( is_array( $result ) ) : ?>
        <div class="notice <?php echo esc_attr( $result['failed'] ? 'notice-error' : ( $result['skipped'] ? 'notice-warning' : 'notice-success' ) ); ?>">
            <p>
                <?php
                printf(
                    /* translators: 1: deleted count, 2: skipped count, 3: failed count. */
                    esc_html__( 'Cleanup: %1$d deleted, %2$d skipped, %3$d failed.', 'pcio-vis-member-event' ),
                    count( $result['deleted'] ),
                    count( $result['skipped'] ),
                    count( $result['failed'] )
                );
                ?>
            </p>
            <?php if ( $result['skipped'] || $result['failed'] ) : ?>
                <ul>
                    <?php foreach ( array_merge( $result['skipped'], $result['failed'] ) as $pcio_vis_message ) : ?>
                        <li><?php echo esc_html( $pcio_vis_message ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ( is_wp_error( $orphans ) ) : ?>
        <div class="notice notice-error"><p><?php echo esc_html( $orphans->get_error_message() ); ?></p></div>
    <?php else : ?>
        <p>
            <?php
            printf(
                /* translators: %d: number of unlinked WordPress users. */
                esc_html( _n( '%d WordPress user is not linked to an active member.', '%d WordPress users are not linked to an active member.', count( $orphans ), 'pcio-vis-member-event' ) ),
                count( $orphans )
            );
            ?>
        </p>
        <p class="description"><?php esc_html_e( 'Delete All applies only to the unlinked users listed here. Users with WordPress content are kept. Account deletion cannot be undone.', 'pcio-vis-member-event' ); ?></p>
        <?php if ( ! $can_delete ) : ?>
            <p class="description"><?php esc_html_e( 'Only WordPress administrators with deletion permissions can delete these accounts. Multisite deletion is not supported.', 'pcio-vis-member-event' ); ?></p>
        <?php endif; ?>
        <p><a class="button" href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>"><?php esc_html_e( 'Open WordPress → Users', 'pcio-vis-member-event' ); ?></a></p>
        <?php if ( [] === $orphans ) : ?>
            <p><?php esc_html_e( 'Every WordPress user is linked to an active member. Nothing to clean up.', 'pcio-vis-member-event' ); ?></p>
        <?php else : ?>
            <form id="pcio-vis-user-cleanup" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-select-message="<?php esc_attr_e( 'Select at least one user to delete.', 'pcio-vis-member-event' ); ?>">
                <input type="hidden" name="action" value="pcio_vis_user_cleanup">
                <?php wp_nonce_field( 'pcio_vis_user_cleanup' ); ?>
                <?php if ( $can_delete ) : ?>
                    <div class="tablenav top">
                        <button class="button" type="submit" name="cleanup_mode" value="selected" data-confirm="<?php esc_attr_e( 'Permanently delete the selected eligible users? Users with WordPress content will be kept.', 'pcio-vis-member-event' ); ?>"><?php esc_html_e( 'Delete selected', 'pcio-vis-member-event' ); ?></button>
                        <button class="button" type="submit" name="cleanup_mode" value="all" data-confirm="<?php esc_attr_e( 'Permanently delete all eligible unlinked users listed here? Users with WordPress content will be kept.', 'pcio-vis-member-event' ); ?>"><?php esc_html_e( 'Delete All', 'pcio-vis-member-event' ); ?></button>
                    </div>
                <?php endif; ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column"><input type="checkbox" data-select-all <?php disabled( ! $can_delete ); ?> aria-label="<?php esc_attr_e( 'Select all eligible users', 'pcio-vis-member-event' ); ?>"></td>
                            <th scope="col"><?php esc_html_e( 'Username', 'pcio-vis-member-event' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Email', 'pcio-vis-member-event' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Registered', 'pcio-vis-member-event' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Status', 'pcio-vis-member-event' ); ?></th>
                            <th scope="col"><?php esc_html_e( 'Action', 'pcio-vis-member-event' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $orphans as $pcio_vis_user ) : ?>
                            <tr>
                                <th scope="row" class="check-column"><input type="checkbox" name="user_ids[]" value="<?php echo esc_attr( $pcio_vis_user['ID'] ); ?>" <?php disabled( ! $pcio_vis_user['can_delete'] ); ?> aria-label="<?php echo esc_attr( $pcio_vis_user['user_login'] ); ?>"></th>
                                <td><?php echo esc_html( $pcio_vis_user['user_login'] ); ?></td>
                                <td><?php echo esc_html( $pcio_vis_user['user_email'] ); ?></td>
                                <td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $pcio_vis_user['user_registered'] ) ); ?></td>
                                <td><?php echo esc_html( $pcio_vis_user['status'] ); ?></td>
                                <td><a href="<?php echo esc_url( get_edit_user_link( (int) $pcio_vis_user['ID'] ) ); ?>"><?php esc_html_e( 'Edit user', 'pcio-vis-member-event' ); ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
