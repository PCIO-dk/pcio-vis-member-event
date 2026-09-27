<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Central schema installer / upgrader.
 *
 * Tables are created with dbDelta() on activation and whenever the stored
 * schema version (option `pcio_me_db_version`) differs from PCIO_VIS_DB_VERSION.
 * This avoids running CREATE TABLE IF NOT EXISTS on every request while still allowing a
 * controlled upgrade path when the plugin is updated in place.
 */
class PCIO_VIS_Installer {

    const VERSION_OPTION = 'pcio_me_db_version';

    /**
     * Run the installer only when the schema version has changed. Hooked on
     * admin_init so in-place plugin updates upgrade without reactivation.
     */
    public static function maybe_upgrade(): void {
        if ( get_option( self::VERSION_OPTION ) === PCIO_VIS_DB_VERSION ) {
            return;
        }
        self::install();
    }

    /**
     * Create/upgrade every table via dbDelta, then store the schema version.
     * Also runs on activation (see register_activation_hook in member-event.php).
     */
    public static function install(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        PCIO_VIS_DB::install();
        PCIO_VIS_Docs_DB::install();
        PCIO_VIS_Events_DB::install();
        PCIO_VIS_Journal_DB::install();
        PCIO_VIS_Statistics_DB::install();
        PCIO_VIS_Mails_DB::install();
        PCIO_VIS_Meta_DB::install();
        PCIO_VIS_Newsletters_DB::install();
        PCIO_VIS_Roles_DB::install();
        PCIO_VIS_Signups_DB::install();
        PCIO_VIS_Sync_DB::install();
        PCIO_VIS_Event_Types_DB::install();
        PCIO_VIS_Resources_DB::install();
        PCIO_VIS_Recurrence_DB::install();
        PCIO_VIS_Workgroups_DB::install();
        PCIO_VIS_Rolling_Text_DB::install();

        // One-time: seed the stored member↔WP-user link (me_member_roles.wp_user_id)
        // from matching emails, now that runtime email auto-detection has been removed.
        if ( ! get_option( 'pcio_me_link_backfilled' ) ) {
            PCIO_VIS_Roles_DB::backfill_links_from_email();
            update_option( 'pcio_me_link_backfilled', 1 );
        }

        // One-time: mark every WordPress account currently linked to a member with the
        // vis_member capability, so existing members keep access after switching to the
        // capability-based membership model.
        if ( ! get_option( 'pcio_me_member_cap_backfilled' ) ) {
            PCIO_VIS_Roles_DB::backfill_member_caps();
            update_option( 'pcio_me_member_cap_backfilled', 1 );
        }

        update_option( self::VERSION_OPTION, PCIO_VIS_DB_VERSION );
    }
}
