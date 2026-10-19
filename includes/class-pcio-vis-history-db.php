<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Change log tables; caching would hide real-time history.

/**
 * Repository for the change history tables.
 *
 *  me_change_history — one row per logged operation (what the user sees).
 *  me_change_undo    — ordered, structured inverse steps for that operation.
 *
 * Undo steps never contain SQL. They hold a registered table key, the primary
 * key and before/after values as JSON; PCIO_VIS_History builds prepared
 * statements from them at undo time.
 */
class PCIO_VIS_History_DB {

    const TABLE      = 'me_change_history';
    const TABLE_UNDO = 'me_change_undo';

    /** Undo state of a history row (column `undoable`). */
    const UNDO_NONE      = 0; // Never undoable (side effects, payment data, too large).
    const UNDO_AVAILABLE = 1;
    const UNDO_PURGED    = 2; // Undo data removed by the retention job.

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function table_undo(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_UNDO;
    }

    // ── Schema ────────────────────────────────────────────────────

    /**
     * Create both tables. InnoDB is required so undo can run in a transaction.
     */
    public static function install(): void {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table creation runs only from the install routine.
        $wpdb->query(
            $wpdb->prepare(
                'CREATE TABLE IF NOT EXISTS %i (
                    id bigint unsigned NOT NULL AUTO_INCREMENT,
                    created_at datetime NOT NULL,
                    user_id bigint unsigned NOT NULL DEFAULT 0,
                    user_name varchar(120) NOT NULL DEFAULT \'\',
                    action varchar(20) NOT NULL DEFAULT \'\',
                    table_key varchar(64) NOT NULL DEFAULT \'\',
                    row_id varchar(64) NOT NULL DEFAULT \'\',
                    event_id int NULL DEFAULT NULL,
                    member_id int NULL DEFAULT NULL,
                    description text NOT NULL,
                    fields varchar(500) NOT NULL DEFAULT \'\',
                    change_count int unsigned NOT NULL DEFAULT 0,
                    undoable tinyint NOT NULL DEFAULT 1,
                    undone_at datetime NULL DEFAULT NULL,
                    undone_by bigint unsigned NULL DEFAULT NULL,
                    undo_of bigint unsigned NULL DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY created_at (created_at),
                    KEY event_id (event_id, created_at),
                    KEY member_id (member_id, created_at),
                    KEY table_row (table_key, row_id),
                    KEY user_id (user_id)
                ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                self::table()
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                'CREATE TABLE IF NOT EXISTS %i (
                    id bigint unsigned NOT NULL AUTO_INCREMENT,
                    change_id bigint unsigned NOT NULL,
                    seq int unsigned NOT NULL DEFAULT 0,
                    op varchar(10) NOT NULL,
                    table_key varchar(64) NOT NULL,
                    pk longtext NOT NULL,
                    before_data longtext NULL,
                    after_data longtext NULL,
                    PRIMARY KEY (id),
                    KEY change_id (change_id, seq)
                ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                self::table_undo()
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
    }

    // ── Writes ────────────────────────────────────────────────────

    /**
     * Store an operation with its undo steps.
     *
     * @param array   $row   History columns (see install()).
     * @param array[] $steps Each: op, table_key, pk, before, after (arrays → JSON).
     * @return int History id, 0 on failure.
     */
    public static function insert( array $row, array $steps ): int {
        global $wpdb;
        if ( ! $wpdb->insert( self::table(), $row ) ) {
            return 0;
        }
        $change_id = (int) $wpdb->insert_id;
        self::insert_steps( $change_id, $steps );
        return $change_id;
    }

    /**
     * Store undo steps for a history row.
     *
     * @param int     $change_id History id.
     * @param array[] $steps     Undo steps.
     */
    public static function insert_steps( int $change_id, array $steps ): void {
        global $wpdb;
        foreach ( array_values( $steps ) as $seq => $step ) {
            $wpdb->insert(
                self::table_undo(),
                [
                    'change_id'   => $change_id,
                    'seq'         => $seq,
                    'op'          => (string) $step['op'],
                    'table_key'   => (string) $step['table_key'],
                    'pk'          => (string) wp_json_encode( $step['pk'] ),
                    'before_data' => null === $step['before'] ? null : (string) wp_json_encode( $step['before'] ),
                    'after_data'  => null === $step['after'] ? null : (string) wp_json_encode( $step['after'] ),
                ],
                [ '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
            );
        }
    }

    /**
     * Replace the undo steps of a history row (used when coalescing edits).
     *
     * @param int     $change_id History id.
     * @param array[] $steps     New undo steps.
     */
    public static function replace_steps( int $change_id, array $steps ): void {
        self::delete_steps( $change_id );
        self::insert_steps( $change_id, $steps );
    }

    public static function delete_steps( int $change_id ): void {
        global $wpdb;
        $wpdb->delete( self::table_undo(), [ 'change_id' => $change_id ], [ '%d' ] );
    }

    /**
     * Update columns on a history row.
     *
     * @param int   $id   History id.
     * @param array $data Columns to set.
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;
        return false !== $wpdb->update( self::table(), $data, [ 'id' => $id ] );
    }

    /** Remove a history row and its undo steps (net no-op after coalescing). */
    public static function delete( int $id ): void {
        global $wpdb;
        self::delete_steps( $id );
        $wpdb->delete( self::table(), [ 'id' => $id ], [ '%d' ] );
    }

    /**
     * Mark a history row as undone.
     *
     * @param int $id         History id.
     * @param int $user_id    User performing the undo.
     * @param int $undo_entry History id of the entry logged for the undo itself.
     */
    public static function mark_undone( int $id, int $user_id, int $undo_entry ): bool {
        global $wpdb;
        return false !== $wpdb->update(
            self::table(),
            [
                'undone_at' => current_time( 'mysql', true ),
                'undone_by' => $user_id,
            ],
            [ 'id' => $id ],
            [ '%s', '%d' ],
            [ '%d' ]
        ) && ( $undo_entry <= 0 || false !== $wpdb->update(
            self::table(),
            [ 'undo_of' => $id ],
            [ 'id' => $undo_entry ],
            [ '%d' ],
            [ '%d' ]
        ) );
    }

    // ── Reads ─────────────────────────────────────────────────────

    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Undo steps of a history row, in execution order.
     *
     * @return array[] Decoded steps.
     */
    public static function get_steps( int $change_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT * FROM %i WHERE change_id = %d ORDER BY seq ASC', self::table_undo(), $change_id ),
            ARRAY_A
        ) ?: [];
        return array_map(
            static function ( array $r ): array {
                return [
                    'op'        => (string) $r['op'],
                    'table_key' => (string) $r['table_key'],
                    'pk'        => (array) json_decode( (string) $r['pk'], true ),
                    'before'    => null === $r['before_data'] ? null : (array) json_decode( (string) $r['before_data'], true ),
                    'after'     => null === $r['after_data'] ? null : (array) json_decode( (string) $r['after_data'], true ),
                ];
            },
            $rows
        );
    }

    /**
     * Newest history row that touched a given table row.
     */
    public static function latest_for_row( string $table_key, string $row_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE table_key = %s AND row_id = %s ORDER BY id DESC LIMIT 1',
                self::table(),
                $table_key,
                $row_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Paginated, filtered list (newest first).
     *
     * @param array $args event_id, member_id, user_id, table_key, search, hide_undone, page, per_page.
     * @return array{items: array[], total: int}
     */
    public static function query( array $args ): array {
        global $wpdb;

        $where  = [ '1=1' ];
        $params = [ self::table() ];

        if ( ! empty( $args['event_id'] ) ) {
            $where[]  = 'event_id = %d';
            $params[] = (int) $args['event_id'];
        }
        if ( ! empty( $args['member_id'] ) ) {
            $where[]  = 'member_id = %d';
            $params[] = (int) $args['member_id'];
        }
        if ( ! empty( $args['user_id'] ) ) {
            $where[]  = 'user_id = %d';
            $params[] = (int) $args['user_id'];
        }
        if ( ! empty( $args['table_key'] ) ) {
            $where[]  = 'table_key = %s';
            $params[] = (string) $args['table_key'];
        }
        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
            $where[]  = '( description LIKE %s OR user_name LIKE %s )';
            $params[] = $like;
            $params[] = $like;
        }
        if ( ! empty( $args['hide_undone'] ) ) {
            $where[] = 'undone_at IS NULL AND undo_of IS NULL';
        }

        $per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $sql_cond = implode( ' AND ', $where );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql_cond only holds fixed fragments with placeholders built above.
        $total = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$sql_cond}", ...$params )
        );
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE {$sql_cond} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
                ...array_merge( $params, [ $per_page, ( $page - 1 ) * $per_page ] )
            ),
            ARRAY_A
        ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return [ 'items' => $items, 'total' => $total ];
    }

    /**
     * Distinct table keys present in the log (for the admin filter).
     *
     * @return string[]
     */
    public static function distinct_table_keys(): array {
        global $wpdb;
        return $wpdb->get_col(
            $wpdb->prepare( 'SELECT DISTINCT table_key FROM %i ORDER BY table_key ASC', self::table() )
        ) ?: [];
    }

    // ── Retention ─────────────────────────────────────────────────

    /**
     * Drop undo data older than the cutoff; the history text is kept.
     *
     * @param string $cutoff_gmt 'Y-m-d H:i:s' GMT.
     * @return int Number of history rows whose undo data was purged.
     */
    public static function purge_undo_before( string $cutoff_gmt ): int {
        global $wpdb;
        $ids = array_map(
            'intval',
            $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT id FROM %i WHERE created_at < %s AND undoable = %d',
                    self::table(),
                    $cutoff_gmt,
                    self::UNDO_AVAILABLE
                )
            ) ?: []
        );
        foreach ( array_chunk( $ids, 200 ) as $chunk ) {
            $in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is a list of %d placeholders.
            $wpdb->query(
                $wpdb->prepare( "DELETE FROM %i WHERE change_id IN ({$in})", self::table_undo(), ...$chunk )
            );
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE %i SET undoable = %d WHERE id IN ({$in})",
                    self::table(),
                    self::UNDO_PURGED,
                    ...$chunk
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        }
        return count( $ids );
    }
}
