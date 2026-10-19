<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Change logging must see live rows.

/**
 * Change history + undo service.
 *
 * All logged writes go through the wrappers below instead of $wpdb directly:
 *
 *     PCIO_VIS_History::insert( 'events', $data );
 *     PCIO_VIS_History::update( 'events', $data, [ 'id' => $id ] );
 *     PCIO_VIS_History::delete( 'events', [ 'id' => $id ] );
 *     PCIO_VIS_History::upsert( 'signups', $data, [ 'event_id', 'member_id' ] );
 *
 * Each wrapper reads the affected rows before and after the write and records
 * a structured change. Changes are grouped into an *operation* — one history
 * row with N undo steps. Without an explicit begin()/commit() every write in
 * the same request joins one ambient operation, flushed after the REST
 * callbacks run (or on shutdown), so a single user action becomes a single
 * history entry without touching the REST handlers.
 *
 * Tables are registered through the `pcio_me_history_tables` filter (see
 * PCIO_VIS_History_Tables for the core definitions). Undo only ever touches
 * registered tables and only through prepared statements.
 */
class PCIO_VIS_History {

    /** Same-user edits of the same row within this window merge into one entry. */
    const COALESCE_SECONDS = 900;

    /** Operations with more undo steps than this are logged but not undoable. */
    const MAX_UNDO_STEPS = 500;

    /** Values longer than this (bytes) are treated as large fields. */
    const LARGE_BYTES = 1024;

    /** Default retention of undo data. Filter: pcio_me_history_undo_retention_days. */
    const RETENTION_DAYS = 180;

    const CRON_HOOK = 'pcio_vis_history_purge';

    /** Hard cap for the stored description text. */
    const MAX_DESCRIPTION = 2000;

    /** @var array<string,array>|null Normalised table registry. */
    private static ?array $registry = null;

    /** @var array|null Operation currently being collected. */
    private static ?array $op = null;

    /** Nesting depth of explicit begin() calls (0 = ambient or none). */
    private static int $depth = 0;

    /** @var array<string,bool> Cached "table is InnoDB" checks. */
    private static array $innodb = [];

    // ── Bootstrapping ─────────────────────────────────────────────

    public static function init(): void {
        add_filter( 'rest_request_after_callbacks', [ __CLASS__, 'flush_after_rest' ], 999 );
        add_action( 'shutdown', [ __CLASS__, 'flush_ambient' ], 0 );
        add_action( self::CRON_HOOK, [ __CLASS__, 'purge' ] );
        add_action( 'init', [ __CLASS__, 'schedule_purge' ] );
    }

    public static function schedule_purge(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    public static function unschedule_purge(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Commit the ambient operation once a REST callback has finished.
     *
     * @param mixed $response REST response (passed through untouched).
     * @return mixed
     */
    public static function flush_after_rest( $response ) {
        self::flush_ambient();
        return $response;
    }

    /** Commit an ambient (implicit) operation, if one is open. */
    public static function flush_ambient(): void {
        if ( null !== self::$op && 0 === self::$depth ) {
            self::write_operation();
        }
    }

    /** Whether the current user may undo changes. */
    public static function current_user_can_undo(): bool {
        return current_user_can( PCIO_VIS_Caps::UNDO_CAP );
    }

    // ── Registry ──────────────────────────────────────────────────

    /**
     * All registered tables, keyed by table key.
     *
     * @return array<string,array>
     */
    public static function registry(): array {
        if ( null !== self::$registry ) {
            return self::$registry;
        }
        /**
         * Filter: pcio_me_history_tables
         *
         * Register tables whose writes are logged and can be undone. Keys are
         * stable identifiers stored in the log; values describe the table:
         *
         *  table     (string)   Table name without $wpdb->prefix. Required.
         *  pk        (string[]) Primary-key columns. Default [ 'id' ].
         *  label     (string)   Human label, e.g. "Event".
         *  title     (callable) fn( array $row ): string — row label in descriptions.
         *  title_col (string)   Shortcut for title: use this column.
         *  fields    (array)    column => label for descriptions.
         *  ignore    (string[]) Columns not diffed (default created_at, updated_at).
         *  large     (string[]) Columns treated as large text (summary only).
         *  links     (callable) fn( array $row ): array{event_id?:int, member_id?:int}.
         *  undoable  (bool)     False for tables with external side effects.
         *  weight    (int)      Higher = preferred as the operation's headline.
         *
         * @param array<string,array> $tables
         */
        $raw = apply_filters( 'pcio_me_history_tables', PCIO_VIS_History_Tables::core() );

        $out = [];
        foreach ( (array) $raw as $key => $def ) {
            if ( ! is_array( $def ) || empty( $def['table'] ) ) {
                continue;
            }
            $out[ sanitize_key( (string) $key ) ] = self::normalise_def( (string) $key, $def );
        }
        self::$registry = $out;
        return $out;
    }

    /** Reset the cached registry (tests, late registrations). */
    public static function reset_registry(): void {
        self::$registry = null;
    }

    public static function table_def( string $key ): ?array {
        return self::registry()[ $key ] ?? null;
    }

    /** Human label for a table key (falls back to the key). */
    public static function table_label( string $key ): string {
        $def = self::table_def( $key );
        return $def ? $def['label'] : $key;
    }

    private static function normalise_def( string $key, array $def ): array {
        global $wpdb;
        $pk = array_values( array_map( 'strval', (array) ( $def['pk'] ?? [ 'id' ] ) ) );
        return [
            'key'        => $key,
            'table_name' => $wpdb->prefix . (string) $def['table'],
            'pk'         => $pk,
            'auto_inc'   => (bool) ( $def['auto_increment'] ?? ( 1 === count( $pk ) ) ),
            'label'      => (string) ( $def['label'] ?? $key ),
            'title'      => is_callable( $def['title'] ?? null ) ? $def['title'] : null,
            'title_col'  => (string) ( $def['title_col'] ?? '' ),
            'fields'     => (array) ( $def['fields'] ?? [] ),
            'ignore'     => array_values( (array) ( $def['ignore'] ?? [ 'created_at', 'updated_at' ] ) ),
            'large'      => array_values( (array) ( $def['large'] ?? [] ) ),
            'links'      => is_callable( $def['links'] ?? null ) ? $def['links'] : null,
            'undoable'   => (bool) ( $def['undoable'] ?? true ),
            'weight'     => (int) ( $def['weight'] ?? 1 ),
        ];
    }

    private static function require_def( string $key ): ?array {
        $def = self::table_def( $key );
        if ( ! $def ) {
            _doing_it_wrong(
                __METHOD__,
                esc_html( sprintf( 'History table "%s" is not registered via pcio_me_history_tables.', $key ) ),
                '1.6.0'
            );
        }
        return $def;
    }

    // ── Write wrappers ────────────────────────────────────────────

    /**
     * Logged $wpdb->insert(). $wpdb->insert_id is preserved for the caller.
     *
     * @param string            $key    Registered table key.
     * @param array             $data   Column => value.
     * @param array|string|null $format Optional $wpdb formats.
     * @return int|false Rows inserted, false on error.
     */
    public static function insert( string $key, array $data, $format = null ) {
        global $wpdb;
        $def = self::require_def( $key );
        if ( ! $def ) {
            return false;
        }
        $result = $wpdb->insert( $def['table_name'], $data, $format );
        if ( ! $result ) {
            return $result;
        }
        $insert_id = $wpdb->insert_id;

        $pk = [];
        foreach ( $def['pk'] as $col ) {
            $pk[ $col ] = array_key_exists( $col, $data ) ? $data[ $col ] : null;
        }
        if ( $def['auto_inc'] && null === $pk[ $def['pk'][0] ] ) {
            $pk[ $def['pk'][0] ] = $insert_id;
        }
        $after = self::fetch_row( $def, $pk );
        if ( $after ) {
            self::record( $def, 'insert', self::pk_of( $def, $after ), null, $after );
        }

        $wpdb->insert_id = $insert_id;
        return $result;
    }

    /**
     * Logged $wpdb->update(). Every matched row is diffed individually.
     *
     * @param string            $key          Registered table key.
     * @param array             $data         Column => new value.
     * @param array             $where        Column => value (AND).
     * @param array|string|null $format       Optional $wpdb formats for $data.
     * @param array|string|null $where_format Optional $wpdb formats for $where.
     * @return int|false Rows affected, false on error.
     */
    public static function update( string $key, array $data, array $where, $format = null, $where_format = null ) {
        global $wpdb;
        $def = self::require_def( $key );
        if ( ! $def ) {
            return false;
        }
        $before_rows = self::fetch_where( $def, $where );

        $result = $wpdb->update( $def['table_name'], $data, $where, $format, $where_format );
        if ( false === $result ) {
            return false;
        }

        foreach ( $before_rows as $before ) {
            // A PK column may itself be part of the update.
            $pk    = array_merge( self::pk_of( $def, $before ), array_intersect_key( $data, array_flip( $def['pk'] ) ) );
            $after = self::fetch_row( $def, $pk );
            if ( $after ) {
                self::record( $def, 'update', $pk, $before, $after );
            }
        }
        return $result;
    }

    /**
     * Logged $wpdb->delete(). The full rows are kept for undo.
     *
     * @param string            $key          Registered table key.
     * @param array             $where        Column => value (AND).
     * @param array|string|null $where_format Optional $wpdb formats.
     * @return int|false Rows deleted, false on error.
     */
    public static function delete( string $key, array $where, $where_format = null ) {
        global $wpdb;
        $def = self::require_def( $key );
        if ( ! $def ) {
            return false;
        }
        $before_rows = self::fetch_where( $def, $where );

        $result = $wpdb->delete( $def['table_name'], $where, $where_format );
        if ( false === $result || 0 === $result ) {
            return $result;
        }
        foreach ( $before_rows as $before ) {
            self::record( $def, 'delete', self::pk_of( $def, $before ), $before, null );
        }
        return $result;
    }

    /**
     * Logged insert-or-update keyed on a unique column set.
     *
     * @param string   $key   Registered table key.
     * @param array    $data  Full column => value set (must include $match columns).
     * @param string[] $match Columns identifying the row.
     * @return bool Success.
     */
    public static function upsert( string $key, array $data, array $match ): bool {
        $def = self::require_def( $key );
        if ( ! $def ) {
            return false;
        }
        $where = array_intersect_key( $data, array_flip( $match ) );
        if ( count( $where ) !== count( $match ) ) {
            return false;
        }
        if ( self::fetch_where( $def, $where ) ) {
            return false !== self::update( $key, array_diff_key( $data, $where ), $where );
        }
        return (bool) self::insert( $key, $data );
    }

    // ── Operations ────────────────────────────────────────────────

    /**
     * Start an explicit operation. Nested calls fold into the outer one.
     *
     * @param string $description Optional headline; generated when empty.
     * @param array  $links       Optional event_id / member_id overrides.
     */
    public static function begin( string $description = '', array $links = [] ): void {
        if ( 0 === self::$depth ) {
            self::flush_ambient();
            self::$op = self::new_op();
        }
        self::$depth++;
        if ( '' !== $description && '' === self::$op['description'] ) {
            self::$op['description'] = $description;
        }
        foreach ( [ 'event_id', 'member_id' ] as $link ) {
            if ( ! empty( $links[ $link ] ) && empty( self::$op['links'][ $link ] ) ) {
                self::$op['links'][ $link ] = (int) $links[ $link ];
            }
        }
    }

    /**
     * Finish an explicit operation; the outermost commit writes it.
     *
     * @return int History id written (0 when nothing changed or still nested).
     */
    public static function commit(): int {
        if ( self::$depth > 0 ) {
            self::$depth--;
        }
        if ( self::$depth > 0 ) {
            return 0;
        }
        return self::write_operation();
    }

    /** Discard the operation being collected (the DB writes are not reverted). */
    public static function rollback(): void {
        self::$op    = null;
        self::$depth = 0;
    }

    /** Set the headline of the current operation (opens an ambient one if needed). */
    public static function describe( string $description ): void {
        self::ensure_op();
        self::$op['description'] = $description;
    }

    /** Mark the current operation as not undoable (external side effects). */
    public static function mark_not_undoable(): void {
        self::ensure_op();
        self::$op['no_undo'] = true;
    }

    private static function new_op(): array {
        $user = wp_get_current_user();
        return [
            'user_id'     => (int) $user->ID,
            'user_name'   => $user->exists() ? (string) $user->display_name : '',
            'description' => '',
            'links'       => [],
            'changes'     => [],
            'overflow'    => 0,
            'no_undo'     => false,
            'undo_of'     => 0,
        ];
    }

    private static function ensure_op(): void {
        if ( null === self::$op ) {
            self::$op = self::new_op();
        }
    }

    /**
     * Buffer one row change into the current operation.
     */
    private static function record( array $def, string $type, array $pk, ?array $before, ?array $after ): void {
        $changed = [];
        if ( 'update' === $type ) {
            $changed = self::diff( $def, (array) $before, (array) $after );
            if ( empty( $changed ) ) {
                return;
            }
        }
        self::ensure_op();
        if ( count( self::$op['changes'] ) >= self::MAX_UNDO_STEPS ) {
            self::$op['overflow']++;
            return;
        }
        self::$op['changes'][] = [
            'key'     => $def['key'],
            'type'    => $type,
            'pk'      => $pk,
            'before'  => $before,
            'after'   => $after,
            'changed' => $changed,
        ];
    }

    /**
     * Persist the collected operation as a history row + undo steps.
     *
     * @return int History id (0 when nothing to log).
     */
    private static function write_operation(): int {
        $op       = self::$op;
        self::$op = null;
        if ( null === $op || empty( $op['changes'] ) ) {
            return 0;
        }

        $primary = self::primary_change( $op['changes'] );
        $def     = self::table_def( $primary['key'] );
        if ( ! $def ) {
            return 0;
        }

        $undoable = ! $op['no_undo'] && 0 === $op['overflow'];
        foreach ( $op['changes'] as $c ) {
            $cdef = self::table_def( $c['key'] );
            if ( ! $cdef || ! $cdef['undoable'] ) {
                $undoable = false;
                break;
            }
        }

        $total = count( $op['changes'] ) + $op['overflow'];
        $links = self::collect_links( $op, $primary );
        $row_id = self::pk_string( $primary['pk'] );

        if ( $undoable && 1 === $total && 'update' === $primary['type'] && 0 === $op['undo_of']
            && '' === $op['description'] && $op['user_id'] > 0 ) {
            $merged = self::try_coalesce( $op, $primary, $def, $row_id );
            if ( null !== $merged ) {
                return $merged;
            }
        }

        $description = '' !== $op['description'] ? $op['description'] : self::describe_change( $def, $primary, $primary['changed'] );
        if ( '' === $op['description'] && $total > 1 ) {
            $description .= ' ' . sprintf(
                /* translators: %d: number of additional row changes in the same operation */
                _n( '(+%d related change)', '(+%d related changes)', $total - 1, 'pcio-vis-member-event' ),
                $total - 1
            );
        }

        $row = [
            'created_at'   => current_time( 'mysql', true ),
            'user_id'      => $op['user_id'],
            'user_name'    => mb_substr( $op['user_name'], 0, 120 ),
            'action'       => $op['undo_of'] ? 'undo' : self::op_action( $op['changes'] ),
            'table_key'    => $primary['key'],
            'row_id'       => mb_substr( $row_id, 0, 64 ),
            'event_id'     => $links['event_id'] ?? null,
            'member_id'    => $links['member_id'] ?? null,
            'description'  => mb_substr( $description, 0, self::MAX_DESCRIPTION ),
            'fields'       => mb_substr( implode( ',', $primary['changed'] ), 0, 500 ),
            'change_count' => $total,
            'undoable'     => $undoable ? PCIO_VIS_History_DB::UNDO_AVAILABLE : PCIO_VIS_History_DB::UNDO_NONE,
        ];

        $steps = $undoable ? array_map( [ __CLASS__, 'build_step' ], $op['changes'] ) : [];
        return PCIO_VIS_History_DB::insert( $row, $steps );
    }

    /**
     * Merge a single-field-set update into the same user's recent entry for
     * the same row. Returns the history id used, or null when not merged.
     */
    private static function try_coalesce( array $op, array $change, array $def, string $row_id ): ?int {
        $prev = PCIO_VIS_History_DB::latest_for_row( $change['key'], $row_id );
        if ( ! $prev
            || (int) $prev['user_id'] !== $op['user_id']
            || 'update' !== $prev['action']
            || null !== $prev['undone_at']
            || null !== $prev['undo_of']
            || PCIO_VIS_History_DB::UNDO_AVAILABLE !== (int) $prev['undoable']
            || 1 !== (int) $prev['change_count']
            || ( time() - (int) strtotime( $prev['created_at'] . ' UTC' ) ) > self::COALESCE_SECONDS ) {
            return null;
        }
        $steps = PCIO_VIS_History_DB::get_steps( (int) $prev['id'] );
        if ( 1 !== count( $steps ) || 'update' !== $steps[0]['op'] ) {
            return null;
        }

        $step   = self::build_step( $change );
        $before = (array) $steps[0]['before'] + (array) $step['before']; // Earliest value wins.
        $after  = array_merge( (array) $steps[0]['after'], (array) $step['after'] );

        // Columns that are back at their original value are no longer a change.
        foreach ( array_keys( $before ) as $col ) {
            if ( self::matches( $after[ $col ] ?? null, $before[ $col ] ) ) {
                unset( $before[ $col ], $after[ $col ] );
            }
        }
        $prev_id = (int) $prev['id'];
        if ( empty( $before ) ) {
            PCIO_VIS_History_DB::delete( $prev_id );
            return $prev_id;
        }

        $current = (array) $change['after'];
        $virtual = [
            'key'     => $change['key'],
            'type'    => 'update',
            'pk'      => $change['pk'],
            'before'  => array_merge( $current, $before ),
            'after'   => $current,
            'changed' => array_keys( $before ),
        ];
        PCIO_VIS_History_DB::replace_steps(
            $prev_id,
            [ [ 'op' => 'update', 'table_key' => $change['key'], 'pk' => $change['pk'], 'before' => $before, 'after' => $after ] ]
        );
        PCIO_VIS_History_DB::update(
            $prev_id,
            [
                'created_at'  => current_time( 'mysql', true ),
                'description' => mb_substr( self::describe_change( $def, $virtual, $virtual['changed'] ), 0, self::MAX_DESCRIPTION ),
                'fields'      => mb_substr( implode( ',', $virtual['changed'] ), 0, 500 ),
            ]
        );
        return $prev_id;
    }

    /**
     * Build the inverse (undo) step of a recorded change.
     */
    private static function build_step( array $c ): array {
        $def = (array) self::table_def( $c['key'] );
        switch ( $c['type'] ) {
            case 'insert':
                return [
                    'op'        => 'delete',
                    'table_key' => $c['key'],
                    'pk'        => $c['pk'],
                    'before'    => null,
                    'after'     => self::expected_values( $def, (array) $c['after'], null ),
                ];
            case 'delete':
                return [
                    'op'        => 'insert',
                    'table_key' => $c['key'],
                    'pk'        => $c['pk'],
                    'before'    => (array) $c['before'],
                    'after'     => null,
                ];
            default:
                $before = array_intersect_key( (array) $c['before'], array_flip( $c['changed'] ) );
                return [
                    'op'        => 'update',
                    'table_key' => $c['key'],
                    'pk'        => $c['pk'],
                    'before'    => $before,
                    'after'     => self::expected_values( $def, (array) $c['after'], $c['changed'] ),
                ];
        }
    }

    /**
     * Values the row must still hold for undo to be safe. Large values are
     * stored as a hash only — enough to detect later edits, cheap to keep.
     *
     * @param array         $def  Table definition.
     * @param array         $row  Row after the change.
     * @param string[]|null $cols Columns to include (null = all but ignored).
     */
    private static function expected_values( array $def, array $row, ?array $cols ): array {
        $out = [];
        foreach ( $row as $col => $value ) {
            if ( null !== $cols && ! in_array( $col, $cols, true ) ) {
                continue;
            }
            if ( null === $cols && in_array( $col, $def['ignore'], true ) ) {
                continue;
            }
            $out[ $col ] = self::is_large( $def, $col, $value ) ? [ '__sha1' => sha1( (string) $value ) ] : $value;
        }
        return $out;
    }

    // ── Undo ──────────────────────────────────────────────────────

    /**
     * Undo a logged operation.
     *
     * Runs every inverse step in reverse order inside a transaction. If any
     * row was changed since, nothing is applied and a 409 WP_Error lists the
     * conflicting fields. The undo itself is logged as a new entry.
     *
     * @param int $id History id.
     * @return int|WP_Error History id of the undo entry.
     */
    public static function undo( int $id ) {
        global $wpdb;

        if ( ! self::current_user_can_undo() ) {
            return new WP_Error( 'pcio_vis_history_forbidden', __( 'You are not allowed to undo changes.', 'pcio-vis-member-event' ), [ 'status' => 403 ] );
        }
        $row = PCIO_VIS_History_DB::get( $id );
        if ( ! $row ) {
            return new WP_Error( 'pcio_vis_history_not_found', __( 'History entry not found.', 'pcio-vis-member-event' ), [ 'status' => 404 ] );
        }
        if ( null !== $row['undone_at'] ) {
            return new WP_Error( 'pcio_vis_history_undone', __( 'This change has already been undone.', 'pcio-vis-member-event' ), [ 'status' => 409 ] );
        }
        if ( PCIO_VIS_History_DB::UNDO_AVAILABLE !== (int) $row['undoable'] ) {
            $msg = PCIO_VIS_History_DB::UNDO_PURGED === (int) $row['undoable']
                ? __( 'The undo data for this change has expired.', 'pcio-vis-member-event' )
                : __( 'This change cannot be undone.', 'pcio-vis-member-event' );
            return new WP_Error( 'pcio_vis_history_not_undoable', $msg, [ 'status' => 409 ] );
        }

        $steps = PCIO_VIS_History_DB::get_steps( $id );
        if ( empty( $steps ) ) {
            return new WP_Error( 'pcio_vis_history_not_undoable', __( 'This change cannot be undone.', 'pcio-vis-member-event' ), [ 'status' => 409 ] );
        }
        foreach ( $steps as $step ) {
            if ( 'events' === $step['table_key'] && in_array( $step['op'], [ 'insert', 'update' ], true ) && ! current_user_can( 'vis_publish_events' ) ) {
                foreach ( PCIO_VIS_Events_DB::PUBLISH_FIELDS as $field ) {
                    if ( (int) ( $step['before'][ $field ] ?? 0 ) !== (int) ( $step['after'][ $field ] ?? 0 ) ) {
                        return new WP_Error( 'event_publish_forbidden', __( 'Only publishers may change event publication settings.', 'pcio-vis-member-event' ), [ 'status' => 403 ] );
                    }
                }
            }
            $def = self::table_def( $step['table_key'] );
            if ( ! $def || ! $def['undoable'] || ! in_array( $step['op'], [ 'insert', 'update', 'delete' ], true ) ) {
                return new WP_Error( 'pcio_vis_history_not_undoable', __( 'This change cannot be undone.', 'pcio-vis-member-event' ), [ 'status' => 409 ] );
            }
            if ( ! self::is_innodb( $def['table_name'] ) ) {
                return new WP_Error(
                    'pcio_vis_history_engine',
                    /* translators: %s: database table name */
                    sprintf( __( 'Undo needs transactional (InnoDB) tables; %s is not.', 'pcio-vis-member-event' ), $def['table_name'] ),
                    [ 'status' => 500 ]
                );
            }
        }

        self::flush_ambient();
        $wpdb->query( 'START TRANSACTION' );

        self::begin(
            /* translators: 1: history entry id, 2: description of the undone change */
            sprintf( __( 'Undo of #%1$d: %2$s', 'pcio-vis-member-event' ), $id, (string) $row['description'] ),
            [ 'event_id' => (int) $row['event_id'], 'member_id' => (int) $row['member_id'] ]
        );
        self::$op['undo_of'] = $id;

        foreach ( array_reverse( $steps ) as $step ) {
            $result = self::apply_step( (array) self::table_def( $step['table_key'] ), $step );
            if ( is_wp_error( $result ) ) {
                self::rollback();
                $wpdb->query( 'ROLLBACK' );
                return $result;
            }
        }

        $undo_entry = self::commit();
        if ( ! PCIO_VIS_History_DB::mark_undone( $id, get_current_user_id(), $undo_entry ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'pcio_vis_history_db', __( 'Could not record the undo.', 'pcio-vis-member-event' ), [ 'status' => 500 ] );
        }
        $wpdb->query( 'COMMIT' );

        /**
         * Action: pcio_me_history_undone
         * Fires after an operation was undone. Use it to re-apply side effects
         * that live outside the logged tables (caches, capabilities, …).
         *
         * @param int     $id    Undone history id.
         * @param array   $row   Undone history row.
         * @param array[] $steps Undo steps that were applied.
         */
        do_action( 'pcio_me_history_undone', $id, $row, $steps );

        return $undo_entry;
    }

    /**
     * Apply one inverse step after verifying the row is still as logged.
     *
     * @return true|WP_Error
     */
    private static function apply_step( array $def, array $step ) {
        $pk      = (array) $step['pk'];
        $current = self::fetch_row( $def, $pk );
        $label   = self::row_title( $def, $current ?? (array) ( $step['before'] ?? [] ) );

        // A formerly user-created type may have been promoted by a schema upgrade.
        if ( 'event_types' === $def['key'] && ! empty( $current['system_event_type'] ) ) {
            $before = (array) ( $step['before'] ?? [] );
            if ( 'delete' === $step['op']
                || ( 'update' === $step['op'] && (
                    ( array_key_exists( 'slug', $before ) && $before['slug'] !== $current['slug'] )
                    || ( array_key_exists( 'system_event_type', $before ) && ! (int) $before['system_event_type'] )
                ) ) ) {
                return new WP_Error( 'pcio_vis_system_event_type', __( 'System event types cannot be deleted or lose their system identity.', 'pcio-vis-member-event' ), [ 'status' => 403 ] );
            }
        }

        if ( 'signups' === $def['key'] && in_array( $step['op'], [ 'insert', 'update' ], true ) ) {
            $restored = array_merge( $current ?? [], (array) $step['before'] );
            if ( in_array( $restored['status'] ?? '', [ 'joining', 'interested', 'arrived' ], true ) ) {
                $event = PCIO_VIS_Events_DB::get( (int) ( $restored['event_id'] ?? 0 ) );
                if ( ! $event || ! PCIO_VIS_Events_DB::is_published( $event ) ) {
                    return new WP_Error( 'event_unpublished', __( 'This event has not been published yet.', 'pcio-vis-member-event' ), [ 'status' => 403 ] );
                }
            }
        }

        if ( 'insert' === $step['op'] ) {
            if ( $current ) {
                return self::conflict(
                    /* translators: 1: table label, 2: row title */
                    sprintf( __( '%1$s “%2$s” already exists again.', 'pcio-vis-member-event' ), $def['label'], $label ),
                    []
                );
            }
            $ok = self::insert( $def['key'], (array) $step['before'] );
        } else {
            if ( ! $current ) {
                return self::conflict(
                    /* translators: 1: table label, 2: row title */
                    sprintf( __( '%1$s “%2$s” no longer exists.', 'pcio-vis-member-event' ), $def['label'], $label ),
                    []
                );
            }
            $changed = [];
            foreach ( (array) $step['after'] as $col => $expected ) {
                if ( ! self::matches( $expected, $current[ $col ] ?? null ) ) {
                    $changed[] = self::field_label( $def, (string) $col );
                }
            }
            if ( $changed ) {
                return self::conflict(
                    sprintf(
                        /* translators: 1: table label, 2: row title, 3: comma-separated field names */
                        __( '%1$s “%2$s” was changed again afterwards (%3$s). Undo the newer change first.', 'pcio-vis-member-event' ),
                        $def['label'],
                        $label,
                        implode( ', ', $changed )
                    ),
                    $changed
                );
            }
            $ok = 'delete' === $step['op']
                ? self::delete( $def['key'], $pk )
                : self::update( $def['key'], (array) $step['before'], $pk );
        }

        if ( false === $ok ) {
            global $wpdb;
            $msg = __( 'A database error stopped the undo.', 'pcio-vis-member-event' );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $wpdb->last_error ) ) {
                $msg .= ' [' . $wpdb->last_error . ']';
            }
            return new WP_Error( 'pcio_vis_history_db', $msg, [ 'status' => 500 ] );
        }
        return true;
    }

    private static function conflict( string $message, array $fields ): WP_Error {
        return new WP_Error( 'pcio_vis_history_conflict', $message, [ 'status' => 409, 'fields' => $fields ] );
    }

    // ── Presentation ──────────────────────────────────────────────

    /**
     * Shape a history row for REST / admin output.
     */
    public static function format_item( array $row ): array {
        $undoable  = (int) $row['undoable'];
        $undone_by = null !== $row['undone_by'] ? get_userdata( (int) $row['undone_by'] ) : false;
        $created   = get_date_from_gmt( (string) $row['created_at'], 'Y-m-d H:i:s' );
        return [
            'id'          => (int) $row['id'],
            'createdAt'   => $created,
            'createdHuman'=> mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created ),
            'userId'      => (int) $row['user_id'],
            'userName'    => (int) $row['user_id'] > 0
                ? ( '' !== (string) $row['user_name'] ? (string) $row['user_name'] : '#' . (int) $row['user_id'] )
                : __( 'System', 'pcio-vis-member-event' ),
            'action'      => (string) $row['action'],
            'tableKey'    => (string) $row['table_key'],
            'tableLabel'  => self::table_label( (string) $row['table_key'] ),
            'rowId'       => (string) $row['row_id'],
            'eventId'     => null !== $row['event_id'] ? (int) $row['event_id'] : null,
            'memberId'    => null !== $row['member_id'] ? (int) $row['member_id'] : null,
            'description' => (string) $row['description'],
            'changeCount' => (int) $row['change_count'],
            'undoState'   => PCIO_VIS_History_DB::UNDO_AVAILABLE === $undoable ? 'available'
                : ( PCIO_VIS_History_DB::UNDO_PURGED === $undoable ? 'expired' : 'none' ),
            'undoneAt'    => null !== $row['undone_at'] ? get_date_from_gmt( (string) $row['undone_at'], 'Y-m-d H:i:s' ) : null,
            'undoneBy'    => $undone_by ? (string) $undone_by->display_name : null,
            'undoOf'      => null !== $row['undo_of'] ? (int) $row['undo_of'] : null,
            'canUndo'     => PCIO_VIS_History_DB::UNDO_AVAILABLE === $undoable && null === $row['undone_at'] && self::current_user_can_undo(),
        ];
    }

    /**
     * Human description of one row change.
     *
     * @param array    $def     Table definition.
     * @param array    $c       Recorded change.
     * @param string[] $changed Changed columns (updates).
     */
    private static function describe_change( array $def, array $c, array $changed ): string {
        $row   = (array) ( $c['after'] ?? $c['before'] );
        $title = self::row_title( $def, $row );

        if ( 'insert' === $c['type'] ) {
            /* translators: 1: table label (e.g. Event), 2: row title */
            return sprintf( __( '%1$s “%2$s” created', 'pcio-vis-member-event' ), $def['label'], $title );
        }
        if ( 'delete' === $c['type'] ) {
            /* translators: 1: table label (e.g. Event), 2: row title */
            return sprintf( __( '%1$s “%2$s” deleted', 'pcio-vis-member-event' ), $def['label'], $title );
        }

        $parts = [];
        foreach ( $changed as $col ) {
            $old = $c['before'][ $col ] ?? null;
            $new = $c['after'][ $col ] ?? null;
            if ( self::is_large( $def, $col, $old ) || self::is_large( $def, $col, $new ) ) {
                $parts[] = sprintf(
                    /* translators: 1: field label, 2: old length, 3: new length */
                    __( '%1$s (%2$s → %3$s chars)', 'pcio-vis-member-event' ),
                    self::field_label( $def, $col ),
                    number_format_i18n( mb_strlen( (string) $old ) ),
                    number_format_i18n( mb_strlen( (string) $new ) )
                );
                continue;
            }
            $parts[] = sprintf( '%1$s: %2$s → %3$s', self::field_label( $def, $col ), self::short_value( $old ), self::short_value( $new ) );
        }
        /* translators: 1: table label (e.g. Event), 2: row title, 3: list of field changes */
        return sprintf( __( '%1$s “%2$s” changed: %3$s', 'pcio-vis-member-event' ), $def['label'], $title, implode( '; ', $parts ) );
    }

    private static function row_title( array $def, array $row ): string {
        $title = '';
        if ( $def['title'] && $row ) {
            $title = (string) call_user_func( $def['title'], $row );
        } elseif ( '' !== $def['title_col'] ) {
            $title = (string) ( $row[ $def['title_col'] ] ?? '' );
        }
        if ( '' === trim( $title ) ) {
            $title = '#' . self::pk_string( array_intersect_key( $row, array_flip( $def['pk'] ) ) );
        }
        return mb_substr( wp_strip_all_tags( $title ), 0, 80 );
    }

    private static function field_label( array $def, string $col ): string {
        return (string) ( $def['fields'][ $col ] ?? ucfirst( str_replace( '_', ' ', $col ) ) );
    }

    private static function short_value( $value ): string {
        if ( null === $value || '' === $value ) {
            return '—';
        }
        $value = wp_strip_all_tags( (string) $value );
        return mb_strlen( $value ) > 60 ? mb_substr( $value, 0, 57 ) . '…' : $value;
    }

    // ── Internals ─────────────────────────────────────────────────

    private static function primary_change( array $changes ): array {
        $best = $changes[0];
        $best_weight = (int) ( self::table_def( $best['key'] )['weight'] ?? 0 );
        foreach ( $changes as $c ) {
            $w = (int) ( self::table_def( $c['key'] )['weight'] ?? 0 );
            if ( $w > $best_weight ) {
                $best        = $c;
                $best_weight = $w;
            }
        }
        return $best;
    }

    private static function collect_links( array $op, array $primary ): array {
        $links   = $op['links'];
        $ordered = array_merge( [ $primary ], $op['changes'] );
        foreach ( $ordered as $c ) {
            if ( ! empty( $links['event_id'] ) && ! empty( $links['member_id'] ) ) {
                break;
            }
            $def = self::table_def( $c['key'] );
            if ( ! $def || ! $def['links'] ) {
                continue;
            }
            $found = (array) call_user_func( $def['links'], (array) ( $c['after'] ?? $c['before'] ) );
            foreach ( [ 'event_id', 'member_id' ] as $k ) {
                if ( empty( $links[ $k ] ) && ! empty( $found[ $k ] ) ) {
                    $links[ $k ] = (int) $found[ $k ];
                }
            }
        }
        return array_filter( $links );
    }

    private static function op_action( array $changes ): string {
        $types = array_unique( array_column( $changes, 'type' ) );
        if ( 1 !== count( $types ) ) {
            return 'mixed';
        }
        return [ 'insert' => 'create', 'update' => 'update', 'delete' => 'delete' ][ $types[0] ] ?? 'mixed';
    }

    /**
     * Columns whose value differs (strict, NULL ≠ '').
     *
     * @return string[]
     */
    private static function diff( array $def, array $before, array $after ): array {
        $changed = [];
        foreach ( $after as $col => $value ) {
            if ( in_array( $col, $def['ignore'], true ) ) {
                continue;
            }
            $old = $before[ $col ] ?? null;
            if ( self::norm( $old ) !== self::norm( $value ) ) {
                $changed[] = (string) $col;
            }
        }
        return $changed;
    }

    private static function norm( $value ): ?string {
        return null === $value ? null : (string) $value;
    }

    /**
     * Compare a stored expected value (possibly a hash marker) with a live value.
     */
    private static function matches( $expected, $actual ): bool {
        if ( is_array( $expected ) && isset( $expected['__sha1'] ) ) {
            if ( is_array( $actual ) && isset( $actual['__sha1'] ) ) {
                return $expected['__sha1'] === $actual['__sha1'];
            }
            return null !== $actual && hash_equals( (string) $expected['__sha1'], sha1( (string) $actual ) );
        }
        if ( is_array( $actual ) && isset( $actual['__sha1'] ) ) {
            return null !== $expected && hash_equals( (string) $actual['__sha1'], sha1( (string) $expected ) );
        }
        return self::norm( $expected ) === self::norm( $actual );
    }

    private static function is_large( array $def, string $col, $value ): bool {
        return in_array( $col, $def['large'], true )
            || ( is_string( $value ) && strlen( $value ) > self::LARGE_BYTES );
    }

    private static function pk_of( array $def, array $row ): array {
        $pk = [];
        foreach ( $def['pk'] as $col ) {
            $pk[ $col ] = $row[ $col ] ?? null;
        }
        return $pk;
    }

    private static function pk_string( array $pk ): string {
        return implode( '-', array_map( 'strval', $pk ) );
    }

    private static function fetch_row( array $def, array $pk ): ?array {
        return self::fetch_where( $def, $pk, 1 )[0] ?? null;
    }

    /**
     * SELECT * FROM table WHERE col = value AND … (NULL → IS NULL).
     *
     * @return array[]
     */
    private static function fetch_where( array $def, array $where, int $limit = 0 ): array {
        global $wpdb;
        if ( empty( $where ) ) {
            return [];
        }
        $conds  = [];
        $params = [ $def['table_name'] ];
        foreach ( $where as $col => $value ) {
            if ( null === $value ) {
                $conds[]  = '%i IS NULL';
                $params[] = (string) $col;
            } else {
                $conds[]  = '%i = %s';
                $params[] = (string) $col;
                $params[] = (string) $value;
            }
        }
        $sql = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $conds );
        if ( $limit > 0 ) {
            $sql     .= ' LIMIT %d';
            $params[] = $limit;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built only from fixed fragments with %i/%s/%d placeholders.
        return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];
    }

    private static function is_innodb( string $table ): bool {
        global $wpdb;
        if ( ! isset( self::$innodb[ $table ] ) ) {
            $engine = (string) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                    $table
                )
            );
            self::$innodb[ $table ] = 'innodb' === strtolower( $engine );
        }
        return self::$innodb[ $table ];
    }

    // ── Retention ─────────────────────────────────────────────────

    /** Daily cron: drop undo data past the retention window. */
    public static function purge(): void {
        /**
         * Filter: pcio_me_history_undo_retention_days
         *
         * @param int $days Days to keep undo data (history text is kept forever).
         */
        $days = (int) apply_filters( 'pcio_me_history_undo_retention_days', self::RETENTION_DAYS );
        if ( $days < 1 ) {
            return;
        }
        PCIO_VIS_History_DB::purge_undo_before( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) );
    }
}
