<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom tables; the migration must read the live schema and cannot be served from cache.

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

        // Must run before PCIO_VIS_DB::install(): dbDelta would otherwise narrow
        // the free-text member_number column to an integer on its own, dropping
        // the values the migration still needs.
        self::migrate_member_numbers();

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

    // ── Data migrations ───────────────────────────────────────────

    /**
     * One-time data migration for the 1.5.0 membership-number change.
     *
     * Up to 1.4.0 the members table carried two number columns: a free-text
     * `member_number` shown as "#", and a shared `member_subscription_number`
     * shown as "Membership #" that grouped a boat or household. 1.5.0 keeps a
     * single integer `member_number` and drops the second column, so the shared
     * number has to be moved across first.
     *
     * Nothing is thrown away:
     *  - the shared number wins wherever one is set;
     *  - otherwise the leading digits of the old "#" become the membership
     *    number, matching the manual upgrade SQL;
     *  - a "#" that was not purely numeric (e.g. "42B") is written to member
     *    meta as `legacy_member_number` before the column is narrowed.
     *
     * Detects the legacy layout from the table itself, so it is safe to run on
     * activation, on an in-place upgrade, and on a fresh install (where it
     * returns immediately).
     */
    public static function migrate_member_numbers(): void {
        self::migrate_members_table();
        self::migrate_journal_table();
        self::migrate_subscriptions_table();
    }

    /**
     * Move the members table onto the single integer membership number.
     */
    private static function migrate_members_table(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'me_members';
        $meta    = $wpdb->prefix . 'me_member_meta';
        $has_msn = self::column_type( $table, 'member_subscription_number' );
        $has_num = self::column_type( $table, 'member_number' );

        if ( null === $has_num ) {
            if ( null === $has_msn || null === self::column_type( $table, 'member_number_legacy_140' ) ) {
                return; // No members table yet, or already on the single-number layout.
            }
            // A previous run was interrupted between the two renames below; the
            // old numbers are still parked in member_number_legacy_140.
            self::finish_members_rename( $table );
            return;
        }

        $meta_ready = null !== self::column_type( $meta, 'meta_value' );

        // Keep every number that cannot survive the int column, before any ALTER.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, member_number FROM %i WHERE member_number <> '' AND member_number NOT REGEXP '^[0-9]+'",
                $table
            ),
            ARRAY_A
        );
        if ( $meta_ready ) {
            foreach ( (array) $rows as $row ) {
                self::stash_legacy_member_number( (int) $row['id'], (string) $row['member_number'] );
            }
        }

        if ( null !== $has_msn ) {
            // Up to 1.4.0: a shared number wins; fall back to the leading digits
            // of the "#" for members that were never given one.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, member_number FROM %i WHERE member_subscription_number = 0 AND member_number <> %s',
                    $table,
                    ''
                ),
                ARRAY_A
            );
            foreach ( (array) $rows as $row ) {
                $number = self::leading_digits( (string) $row['member_number'] );
                if ( $number > 0 ) {
                    $wpdb->update(
                        $table,
                        [ 'member_subscription_number' => $number ],
                        [ 'id' => (int) $row['id'] ],
                        [ '%d' ],
                        [ '%d' ]
                    );
                }
            }

            // Rename the shared column into place rather than narrowing the old
            // one, so the values survive untouched. The free-text column is parked
            // under a scratch name instead of being dropped outright: if a later
            // step fails, the original numbers are still on the table and the
            // next run rolls the rename forward. Its declared type is carried over
            // as-is, so a site with a wider column does not get truncated.
            self::run_schema(
                'ALTER TABLE %i CHANGE COLUMN `member_number` `member_number_legacy_140` %s NOT NULL DEFAULT %s',
                $table,
                $has_num,
                ''
            );
            self::run_schema( "ALTER TABLE %i CHANGE COLUMN `member_subscription_number` `member_number` int NOT NULL DEFAULT 0", $table );
            self::finish_members_rename( $table );
        } elseif ( 0 === strpos( strtolower( $has_num ), 'varchar' ) ) {
            // Straight from 1.3.0, where only the free-text "#" existed.
            $rows = $wpdb->get_results(
                $wpdb->prepare( 'SELECT id, member_number FROM %i WHERE member_number <> %s', $table, '' ),
                ARRAY_A
            );
            foreach ( (array) $rows as $row ) {
                $wpdb->update(
                    $table,
                    [ 'member_number' => self::leading_digits( (string) $row['member_number'] ) ],
                    [ 'id' => (int) $row['id'] ],
                    [ '%d' ],
                    [ '%d' ]
                );
            }
            self::run_schema( "ALTER TABLE %i CHANGE COLUMN `member_number` `member_number` int NOT NULL DEFAULT 0", $table );
        }

        if ( ! self::index_exists( $table, 'member_number' ) ) {
            self::run_schema( "ALTER TABLE %i ADD KEY `member_number` (`member_number`)", $table );
        }
    }

    /**
     * Post-rename clean-up: drop the index that followed the renamed column
     * under its old name, name the new index, and remove the scratch column.
     */
    private static function finish_members_rename( string $table ): void {
        self::drop_index( $table, 'member_subscription_number' );

        if ( ! self::index_exists( $table, 'member_number' ) ) {
            self::run_schema( "ALTER TABLE %i ADD KEY `member_number` (`member_number`)", $table );
        }

        if ( null !== self::column_type( $table, 'member_number_legacy_140' ) ) {
            self::run_schema( "ALTER TABLE %i DROP COLUMN `member_number_legacy_140`", $table );
        }
    }

    /**
     * Narrow the finance journal's member number, which was free text until
     * 1.5.0, using the same leading-digits rule as the members table.
     */
    private static function migrate_journal_table(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'me_finance_journal';
        $type  = self::column_type( $table, 'member_number' );

        if ( null === $type || 0 !== strpos( strtolower( $type ), 'int' ) ) {
            return; // Missing, or already an integer.
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT id, member_number FROM %i WHERE member_number <> '0'", $table ),
            ARRAY_A
        );
        foreach ( (array) $rows as $row ) {
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET member_number = %d WHERE id = %d',
                    $table,
                    self::leading_digits( (string) $row['member_number'] ),
                    (int) $row['id']
                )
            );
        }

        self::run_schema( "ALTER TABLE %i CHANGE COLUMN `member_number` `member_number` int NOT NULL DEFAULT 0", $table );
    }

    /**
     * Follow the core rename on the Products extension's subscription table.
     *
     * That table is owned by pcio-vis-products, which reads `member_number`
     * from 1.5.0 on. Up to 1.4.0 the same shared membership number was stored
     * there as `member_subscription_number`, so a site that updates the core
     * first would otherwise find no membership numbers at all. The values are
     * unchanged by the rename, so this only moves the column.
     */
    private static function migrate_subscriptions_table(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'me_p_subscriptions';

        if ( null === self::column_type( $table, 'member_subscription_number' )
            || null !== self::column_type( $table, 'member_number' ) ) {
            return; // Table absent, or the extension already renamed it.
        }

        self::run_schema( "ALTER TABLE %i CHANGE COLUMN `member_subscription_number` `member_number` int NOT NULL DEFAULT 0", $table );
    }

    // ── Migration helpers ─────────────────────────────────────────

    /**
     * Store a member's pre-1.5.0 "#" so the original text is recoverable from
     * the member record after the column is narrowed.
     */
    private static function stash_legacy_member_number( int $member_id, string $value ): void {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (member_id, meta_key, meta_value)
                 VALUES (%d, %s, %s)
                 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)',
                $wpdb->prefix . 'me_member_meta',
                $member_id,
                'legacy_member_number',
                $value
            )
        );
    }

    /**
     * The leading run of digits in $value as an int; 0 when there is none.
     */
    private static function leading_digits( string $value ): int {
        return preg_match( '/^\s*(\d+)/', $value, $m ) ? (int) $m[1] : 0;
    }

    /** Whether $table exists in the current database. */
    private static function table_exists( string $table ): bool {
        global $wpdb;

        return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    /**
     * The declared type of $column on $table, or null when it is not there.
     *
     * Returns null for a table that does not exist either, without letting the
     * database error surface. That case is normal, not a fault: the migration
     * runs before the core tables are created, and it also inspects the Products
     * extension's me_p_subscriptions table, which is absent on any site that does
     * not have that extension installed.
     */
    private static function column_type( string $table, string $column ): ?string {
        global $wpdb;

        if ( ! self::table_exists( $table ) ) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ),
            ARRAY_A
        );

        return is_array( $row ) && isset( $row['Type'] ) ? (string) $row['Type'] : null;
    }

    /** Whether $table carries an index named $index. */
    private static function index_exists( string $table, string $index ): bool {
        global $wpdb;

        if ( ! self::table_exists( $table ) ) {
            return false;
        }

        return null !== $wpdb->get_var(
            $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, $index )
        );
    }

    /** Drop an index only when it is actually there. */
    private static function drop_index( string $table, string $index ): void {
        if ( self::index_exists( $table, $index ) ) {
            self::run_schema( "ALTER TABLE %i DROP KEY `%s`", $table, $index );
        }
    }

    /**
     * Run a schema change, ignoring the failure and moving on.
     *
     * @param string $sql    Schema change with %i/%s placeholders.
     * @param mixed  ...$args Values for the placeholders.
     */
    private static function run_schema( string $sql, ...$args ): void {
        global $wpdb;

        $suppress = $wpdb->suppress_errors( true );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is always a hard-coded SQL template from plugin schema definitions.
        $wpdb->query( $wpdb->prepare( $sql, ...$args ) );

        $wpdb->suppress_errors( $suppress );
    }
}
