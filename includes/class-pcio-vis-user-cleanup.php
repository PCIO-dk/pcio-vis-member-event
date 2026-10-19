<?php
/**
 * Safe cleanup of unlinked WordPress accounts.
 *
 * @package PCIO_VIS_Member_Event
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Keeps cleanup eligibility and deletion out of the admin view. */
class PCIO_VIS_User_Cleanup {

    /** Whether the current administrator may delete accounts on this site. */
    public static function can_delete_users(): bool {
        return ! is_multisite()
            && in_array( 'administrator', (array) wp_get_current_user()->roles, true )
            && current_user_can( 'manage_options' )
            && current_user_can( 'delete_users' );
    }

    /**
     * Get cleanup rows with a deletion status, excluding protected administrators.
     *
     * @return array|WP_Error
     */
    public static function get_candidates(): array|WP_Error {
        $rows = PCIO_VIS_Roles_DB::get_orphan_wp_users();
        if ( is_wp_error( $rows ) || [] === $rows ) {
            return $rows;
        }
        $users = [];
        foreach ( get_users( [ 'include' => array_column( $rows, 'ID' ) ] ) as $user ) {
            $users[ $user->ID ] = $user;
        }
        $candidates = [];
        foreach ( $rows as $row ) {
            $id   = (int) $row['ID'];
            $user = $users[ $id ] ?? null;
            if ( ! $user || in_array( 'administrator', (array) $user->roles, true )
                || user_can( $user, 'manage_options' ) || is_super_admin( $id ) ) {
                continue;
            }
            $status = '';
            if ( (int) $row['has_content'] ) {
                $status = __( 'Has WordPress content — not deleted.', 'pcio-vis-member-event' );
            } elseif ( is_multisite() ) {
                $status = __( 'Deletion is unavailable on WordPress Multisite.', 'pcio-vis-member-event' );
            } elseif ( $id === get_current_user_id() ) {
                $status = __( 'Current user — not deleted.', 'pcio-vis-member-event' );
            } elseif ( ! self::can_delete_users() || ! current_user_can( 'delete_user', $id ) ) {
                $status = __( 'Insufficient deletion permissions.', 'pcio-vis-member-event' );
            }
            $row['can_delete'] = '' === $status;
            $row['status']     = $row['can_delete'] ? __( 'Can be deleted.', 'pcio-vis-member-event' ) : $status;
            $candidates[]      = $row;
        }
        return $candidates;
    }

    /**
     * Recheck the current orphan/content list before using WordPress deletion.
     *
     * @param string $mode selected or all.
     * @param array  $ids Selected user IDs.
     * @return array|WP_Error Deleted IDs and explicit skip/failure messages.
     */
    public static function delete_users( string $mode, array $ids ): array|WP_Error {
        if ( ! self::can_delete_users() ) {
            return new WP_Error( 'pcio_vis_cleanup_forbidden', __( 'Only WordPress administrators with deletion permissions can delete these accounts. Multisite deletion is not supported.', 'pcio-vis-member-event' ), [ 'status' => 403 ] );
        }
        if ( ! in_array( $mode, [ 'selected', 'all' ], true ) ) {
            return new WP_Error( 'pcio_vis_cleanup_mode', __( 'Invalid cleanup action.', 'pcio-vis-member-event' ), [ 'status' => 400 ] );
        }
        $ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
        if ( 'selected' === $mode && [] === $ids ) {
            return new WP_Error( 'pcio_vis_cleanup_empty', __( 'Select at least one user to delete.', 'pcio-vis-member-event' ), [ 'status' => 400 ] );
        }
        $rows = self::get_candidates();
        if ( is_wp_error( $rows ) ) {
            return $rows;
        }
        $candidates = array_column( $rows, null, 'ID' );
        $ids        = 'all' === $mode ? array_keys( $candidates ) : $ids;
        $result     = [ 'deleted' => [], 'skipped' => [], 'failed' => [] ];
        if ( ! function_exists( 'wp_delete_user' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        foreach ( $ids as $id ) {
            $row = $candidates[ $id ] ?? null;
            if ( ! $row || ! $row['can_delete'] ) {
                $reason = $row ? $row['status'] : __( 'User is no longer a cleanup candidate.', 'pcio-vis-member-event' );
                $result['skipped'][] = sprintf(
                    /* translators: 1: WordPress user ID, 2: reason the user was not deleted. */
                    __( 'User #%1$d: %2$s', 'pcio-vis-member-event' ),
                    $id,
                    $reason
                );
                continue;
            }
            if ( ! current_user_can( 'delete_user', $id ) || ! wp_delete_user( $id ) ) {
                $result['failed'][] = sprintf(
                    /* translators: %d: WordPress user ID. */
                    __( 'Could not delete user #%d.', 'pcio-vis-member-event' ),
                    $id
                );
                continue;
            }
            $result['deleted'][] = $id;
        }
        return $result;
    }
}
