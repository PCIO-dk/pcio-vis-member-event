<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cron-job manager for PCIO VIS Member Event (and its extensions).
 *
 * Responsibilities
 * ────────────────
 *  • Registers a custom WP-cron interval for every unique frequence stored
 *    in the sync DB  (e.g. frequence=24 → interval key "pcio_me_24h").
 *  • Schedules (or re-schedules) every DB job via wp_schedule_event().
 *  • Executes a job by calling the stored php_function callable.
 *  • Provides the built-in "Sync WP Users" job.
 *  • Handles the admin-post "Run Now" action from the admin UI.
 *
 * Extension hook
 * ──────────────
 * Extensions can register their own seed jobs by hooking into the action
 * that fires before scheduling takes place:
 *
 *   add_action( 'pcio_me_sync_register_jobs', function () {
 *       if ( ! PCIO_VIS_Sync_DB::get_by_cron_name( 'my_extension_job' ) ) {
 *           PCIO_VIS_Sync_DB::create( [
 *               'name'         => 'My Extension Job',
 *               'description'  => 'Does something useful.',
 *               'cron_name'    => 'my_extension_job',
 *               'php_function' => 'My_Extension_Class::run',
 *               'sync_url'     => '',
 *               'frequence'    => 12,
 *           ] );
 *       }
 *   } );
 *
 * Once the row exists in the DB the job appears in the admin list and is
 * managed like any other job (edit, delete, run-now).
 */
class PCIO_VIS_Sync {

    public static function init(): void {
        add_filter( 'cron_schedules',              [ __CLASS__, 'add_cron_schedules' ] );
        add_action( 'init',                        [ __CLASS__, 'schedule_jobs'      ] );
        add_action( 'admin_post_pcio_me_run_sync', [ __CLASS__, 'handle_run_now'     ] );
    }

    // ── WP-cron interval registration ─────────────────────────────

    /**
     * Add a custom WP-cron schedule for every unique frequence in the DB.
     * Called via the 'cron_schedules' filter.
     *
     * @param array $schedules Existing WP cron schedules.
     * @return array
     */
    public static function add_cron_schedules( array $schedules ): array {
        $jobs = PCIO_VIS_Sync_DB::get_all();
        $seen = [];
        foreach ( $jobs as $job ) {
            // Disabled jobs have no cron schedule.
            if ( ! (int) $job['enabled'] ) {
                continue;
            }
            $h   = max( 1, (int) $job['frequence'] );
            $key = 'pcio_me_' . $h . 'h';
            if ( ! isset( $seen[ $key ] ) ) {
                $schedules[ $key ] = [
                    'interval' => $h * HOUR_IN_SECONDS,
                    /* translators: %d: number of hours */
                    'display'  => sprintf( __( 'Every %d hour(s) (Vis sync)', 'pcio-vis-member-event' ), $h ),
                ];
                $seen[ $key ] = true;
            }
        }
        return $schedules;
    }

    // ── Schedule all DB-registered jobs ───────────────────────────

    /**
     * Fired on 'init'. Lets extensions seed their rows, then schedules
     * every job found in the DB if it isn't already scheduled.
     */
    public static function schedule_jobs(): void {
        // Only run scheduling logic where it is meaningful.
        if ( ! is_admin() && ! wp_doing_cron() ) {
            return;
        }

        /**
         * Action: pcio_me_sync_register_jobs
         *
         * Fires before job scheduling. Extension plugins should insert any
         * seed rows (if not already present) here:
         *
         *   add_action( 'pcio_me_sync_register_jobs', function () {
         *       if ( ! PCIO_VIS_Sync_DB::get_by_cron_name( 'ext_job' ) ) {
         *           PCIO_VIS_Sync_DB::create( [ ... ] );
         *       }
         *   } );
         */
        do_action( 'pcio_me_sync_register_jobs' );

        $jobs = PCIO_VIS_Sync_DB::get_all();
        foreach ( $jobs as $job ) {
            $hook     = 'pcio_me_cron_' . $job['cron_name'];
            $job_id   = (int) $job['id'];

            // Disabled job: make sure it is not scheduled and skip binding.
            if ( ! (int) $job['enabled'] ) {
                wp_clear_scheduled_hook( $hook );
                continue;
            }

            $interval = 'pcio_me_' . max( 1, (int) $job['frequence'] ) . 'h';

            // Schedule if not already queued.
            if ( ! wp_next_scheduled( $hook ) ) {
                wp_schedule_event(
                    time() + max( 1, (int) $job['frequence'] ) * HOUR_IN_SECONDS,
                    $interval,
                    $hook
                );
            }

            // Bind the cron event to the executor.
            add_action( $hook, static function () use ( $job_id ): void {
                PCIO_VIS_Sync::execute_job( $job_id );
            } );
        }
    }

    /**
     * Unschedule the WP-cron event for a given cron_name.
     * Call this whenever a job row is deleted or its cron_name changes.
     *
     * @param string $cron_name  Value of the cron_name column.
     */
    public static function unschedule( string $cron_name ): void {
        $hook = 'pcio_me_cron_' . $cron_name;
        $next = wp_next_scheduled( $hook );
        if ( $next ) {
            wp_unschedule_event( $next, $hook );
        }
        // Also clear any remaining instances.
        wp_clear_scheduled_hook( $hook );
    }

    // ── Job execution ─────────────────────────────────────────────

    /**
     * Allow-list of callables that sync jobs are permitted to execute.
     *
     * Only callables registered here (by the core plugin and its extensions)
     * may be run by execute_job(). This prevents an admin-stored value from
     * invoking arbitrary existing PHP functions or methods.
     *
     * Extensions register their own callable via the 'pcio_me_sync_callables'
     * filter, e.g.:
     *
     *   add_filter( 'pcio_me_sync_callables', function ( array $callables ) {
     *       $callables['My_Extension_Class::run'] = __( 'My Extension Job', 'my-textdomain' );
     *       return $callables;
     *   } );
     *
     * @return array<string,string> Map of callable => human-readable label.
     */
    public static function allowed_callables(): array {
        $callables = [
            'PCIO_VIS_Sync::run_sync_wp_users'   => __( 'Sync WP Users', 'pcio-vis-member-event' ),
            'PCIO_VIS_Sync::run_import_wp_users' => __( 'Import WP Users', 'pcio-vis-member-event' ),
        ];

        /**
         * Filter: pcio_me_sync_callables
         * Register additional callables that sync jobs are allowed to execute.
         *
         * @param array<string,string> $callables Map of callable => label.
         */
        $callables = apply_filters( 'pcio_me_sync_callables', $callables );

        // Keep only well-formed string callables mapped to string labels.
        $clean = [];
        foreach ( $callables as $callable => $label ) {
            if ( is_string( $callable ) && $callable !== '' ) {
                $clean[ $callable ] = (string) $label;
            }
        }
        return $clean;
    }

    /**
     * Whether a given callable string is permitted to be executed.
     *
     * @param string $callable Callable in "ClassName::method" or "function" form.
     * @return bool
     */
    public static function is_callable_allowed( string $callable ): bool {
        return array_key_exists( $callable, self::allowed_callables() );
    }

    /**
     * Execute a stored sync job by its database ID and persist the result.
     *
     * @param int $id  Primary key in the me_sync table.
     */
    public static function execute_job( int $id ): void {
        $job = PCIO_VIS_Sync_DB::get( $id );
        if ( ! $job ) {
            return;
        }

        $callable = $job['php_function'];

        // Security: only run callables that core/extensions have registered.
        if ( ! self::is_callable_allowed( $callable ) ) {
            PCIO_VIS_Sync_DB::update_run(
                $id,
                sprintf( 'Error: callable "%s" is not on the allow-list.', $callable )
            );
            return;
        }

        $result = '';

        try {
            if ( str_contains( $callable, '::' ) ) {
                [ $class, $method ] = explode( '::', $callable, 2 );
                if ( class_exists( $class ) && method_exists( $class, $method ) ) {
                    $result = (string) call_user_func( [ $class, $method ], $job );
                } else {
                    $result = sprintf( 'Error: callable "%s" not found.', $callable );
                }
            } elseif ( function_exists( $callable ) ) {
                $result = (string) call_user_func( $callable, $job );
            } else {
                $result = sprintf( 'Error: function "%s" not found.', $callable );
            }
        } catch ( \Throwable $e ) {
            $result = 'Exception: ' . $e->getMessage();
        }

        PCIO_VIS_Sync_DB::update_run( $id, $result );
    }

    // ── Admin-post: Run Now ────────────────────────────────────────

    /**
     * Handles POST from the "Run now" button in the admin UI.
     * Action: admin_post_pcio_me_run_sync
     */
    public static function handle_run_now(): void {
        if (
            ! isset( $_POST['pcio_me_run_nonce'] ) ||
            ! wp_verify_nonce( sanitize_key( $_POST['pcio_me_run_nonce'] ), 'pcio_me_run_sync' )
        ) {
            wp_die( esc_html__( 'Security check failed.', 'pcio-vis-member-event' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pcio-vis-member-event' ) );
        }

        $id = absint( $_POST['sync_job_id'] ?? 0 );
        if ( $id ) {
            self::execute_job( $id );
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'        => 'pcio-me-sync',
                    'pcio_me_ran' => $id,
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    // ── Built-in job: Sync WP Users ───────────────────────────────

    /**
     * Synchronise Vis membership against WordPress users.
     *
     * Three rules (administrators are never touched; WP accounts are never deleted):
     *  1. Member in list, no matching WP user     → create subscriber + grant vis_member.
     *  2. Member in list, WP user exists, no cap  → grant vis_member + persist link.
     *  3. WP user has vis_member, email not in list → revoke vis_member.
     *
     * @param array $job  The DB row for this job, passed by execute_job().
     * @return string     Human-readable result summary.
     */
    public static function run_sync_wp_users( array $job ): string {
        // Build lookup: lower-cased email → member row (includes vis_role, wp_user_id).
        $members_by_email = [];
        foreach ( PCIO_VIS_DB::get_all() as $m ) {
            $email = strtolower( trim( $m['email'] ) );
            if ( $email ) {
                $members_by_email[ $email ] = $m;
            }
        }

        $revoked = 0;
        $created = 0;
        $linked  = 0;
        $skipped = 0; // WP administrators

        // ── Pass 1: revoke vis_member from WP users no longer in member list ──
        foreach ( get_users( [ 'number' => -1 ] ) as $wp_user ) {
            if ( in_array( 'administrator', (array) $wp_user->roles, true ) ) {
                ++$skipped;
                continue;
            }
            if ( ! PCIO_VIS_Caps::is_member( (int) $wp_user->ID ) ) {
                continue;
            }
            $email = strtolower( trim( $wp_user->user_email ) );
            if ( ! isset( $members_by_email[ $email ] ) ) {
                PCIO_VIS_Caps::revoke_membership( (int) $wp_user->ID );
                ++$revoked;
            }
        }

        // ── Pass 2: create or link WP users for every member ──
        foreach ( $members_by_email as $email => $member ) {
            $member_id    = (int) $member['id'];
            $vis_role     = (string) ( $member['vis_role'] ?? '' );
            $existing_uid = (int) email_exists( $email );

            if ( 0 === $existing_uid ) {
                // No WP user: create subscriber account and grant vis_member.
                $result = PCIO_VIS_Caps::provision_member_wp_account(
                    $member_id,
                    (string) $member['email'],
                    (string) ( $member['name'] ?? '' ),
                    $vis_role
                );
                if ( $result['wp_user_id'] > 0 ) {
                    ++$created;
                }
            } elseif ( ! PCIO_VIS_Caps::is_member( $existing_uid ) ) {
                // WP user exists but lacks vis_member: grant it and persist the link.
                PCIO_VIS_Caps::grant_membership( $existing_uid );
                PCIO_VIS_Roles_DB::set_member_role( $member_id, $vis_role, $existing_uid );
                ++$linked;
            }
        }

        return sprintf(
            /* translators: 1: revoked count, 2: created count, 3: linked count, 4: skipped admins */
            __( 'Revoked: %1$d  |  Created: %2$d  |  Linked: %3$d  |  Admins skipped: %4$d', 'pcio-vis-member-event' ),
            $revoked,
            $created,
            $linked,
            $skipped
        );
    }

    // ── Built-in job: Import WP Users ──────────────────────────────

    /**
     * Import WordPress users into the member list.
     *
     * Creates a new member row for every WP user whose e-mail address
     * does not already exist in the member list. Existing members are
     * never modified. All WP users are considered (including admins).
     *
     * @param array $job  The DB row for this job, passed by execute_job().
     * @return string     Human-readable result summary.
     */
    public static function run_import_wp_users( array $job ): string {
        // Build lookup of existing member e-mails (lower-cased).
        $existing = [];
        foreach ( PCIO_VIS_DB::get_emails() as $row ) {
            $email = strtolower( trim( $row['email'] ) );
            if ( $email ) {
                $existing[ $email ] = true;
            }
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ( get_users( [ 'number' => -1 ] ) as $wp_user ) {
            $email = strtolower( trim( $wp_user->user_email ) );

            // Skip users with no e-mail or already in the member list.
            if ( ! $email || isset( $existing[ $email ] ) ) {
                ++$skipped;
                continue;
            }

            $id = PCIO_VIS_DB::create( [
                'name'          => trim( $wp_user->display_name ?: $wp_user->user_login ),
                'email'         => $wp_user->user_email,
                'member_number' => '',
                'phone'         => '',
                'address'       => '',
            ] );

            if ( $id ) {
                ++$imported;
                // Prevent a duplicate if two WP users share the same e-mail.
                $existing[ $email ] = true;
            } else {
                $errors[] = "Failed to insert user #{$wp_user->ID} <{$wp_user->user_email}>";
            }
        }

        $summary = sprintf(
            /* translators: 1: imported count, 2: skipped count */
            __( 'Imported: %1$d  |  Skipped (already exist): %2$d', 'pcio-vis-member-event' ),
            $imported,
            $skipped
        );
        if ( $errors ) {
            $summary .= '  |  Errors: ' . implode( '; ', array_map( 'esc_html', $errors ) );
        }
        return $summary;
    }

}
