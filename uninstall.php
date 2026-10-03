<?php
/**
 * Uninstall handler for PCIO VIS Member Event.
 *
 * Drops all custom tables and removes all plugin options when the plugin is
 * deleted from the WordPress admin. This file runs in a minimal WP context;
 * plugin classes are not loaded.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$pcio_me_tables = [
    'me_members',
    'me_member_meta',
    'me_vis_roles',
    'me_member_roles',
    'me_events',
    'me_event_signups',
    'me_event_recurrence',
    'me_event_types',
    'me_resources',
    'me_event_resources',
    'me_event_type_resources',
    'me_workgroups',
    'me_workgroup_members',
    'me_mails',
    'me_newsletters',
    'me_documents',
    'me_document_types',
    'me_rolling_texts',
    'me_finance_journal',
    'me_sync',
    'me_statistics',
    'me_sponsor_clicks',
];

foreach ( $pcio_me_tables as $pcio_me_table ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- intentional schema removal on uninstall; table name is a controlled literal.
    $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $pcio_me_table );
}

delete_option( 'pcio_me_db_version' );
delete_option( 'pcio_me_caps_version' );
delete_option( 'pcio_me_link_backfilled' );
delete_option( 'pcio_me_member_cap_backfilled' );
delete_option( 'pcio_vis_welcome_mail_seeded' );
