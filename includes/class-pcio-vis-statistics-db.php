<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom admin table; caching would hide real-time changes.

/**
 * Database layer for the statistics feature:
 *   wp_me_statistics    — named counters (page_views, login_count).
 *   wp_me_sponsor_clicks — one row per sponsor link click.
 */
class PCIO_VIS_Statistics_DB {

    const TABLE_STATS  = 'me_statistics';
    const TABLE_CLICKS = 'me_sponsor_clicks';

    public static function table_stats(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_STATS;
    }

    public static function table_clicks(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_CLICKS;
    }

    // ── Schema ────────────────────────────────────────────────────

    public static function install(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $stats  = self::table_stats();
        $clicks = self::table_clicks();

        $sql_stats = "CREATE TABLE IF NOT EXISTS {$stats} (
            stat_key varchar(80) NOT NULL,
            stat_value bigint NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (stat_key)
        ) {$charset};";

        $sql_clicks = "CREATE TABLE IF NOT EXISTS {$clicks} (
            id int NOT NULL AUTO_INCREMENT,
            sponsor_url varchar(255) NOT NULL DEFAULT '',
            clicked_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY sponsor_url (sponsor_url(191))
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_stats );
        dbDelta( $sql_clicks );
    }

    // ── Counter helpers ───────────────────────────────────────────

    /**
     * Atomically increment a named counter, creating it if it does not exist.
     */
    public static function increment( string $key ): void {
        global $wpdb;
        $table = self::table_stats();
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO %i (stat_key, stat_value)
                 VALUES (%s, 1)
                 ON DUPLICATE KEY UPDATE stat_value = stat_value + 1",
                $table,
                $key
            )
        );
    }

    /**
     * Return the current value of a named counter (0 if it has never been set).
     */
    public static function get( string $key ): int {
        global $wpdb;
        $table = self::table_stats();
        $value = $wpdb->get_var(
            $wpdb->prepare( 'SELECT stat_value FROM %i WHERE stat_key = %s', $table, $key )
        );
        return (int) $value;
    }

    // ── Sponsor clicks ────────────────────────────────────────────

    /**
     * Record a single sponsor link click.
     */
    public static function record_sponsor_click( string $url ): void {
        global $wpdb;
        $wpdb->insert(
            self::table_clicks(),
            [
                'sponsor_url' => $url,
                'clicked_at'  => current_time( 'mysql', true ),
            ],
            [ '%s', '%s' ]
        );
    }

    /**
     * Return total click counts grouped by URL, highest first.
     *
     * @return array<int, array{sponsor_url: string, click_count: int}>
     */
    public static function get_sponsor_totals(): array {
        global $wpdb;
        $table = self::table_clicks();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT sponsor_url, COUNT(*) AS click_count FROM %i GROUP BY sponsor_url ORDER BY click_count DESC',
                $table
            ),
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) {
            return [];
        }
        return array_map(
            static function ( array $row ): array {
                return [
                    'sponsor_url' => (string) $row['sponsor_url'],
                    'click_count' => (int)    $row['click_count'],
                ];
            },
            $rows
        );
    }

    // ── Member join / leave by year ───────────────────────────────

    /**
     * Count of active (non-expired) membership subscriptions.
     *
     * The subscription data is owned by pcio-vis-products, so the value is
     * supplied via the pcio_me_active_subscription_count filter. Returns 0 when
     * no extension provides it (e.g. pcio-vis-products is not active).
     *
     * @return int
     */
    public static function active_subscription_count(): int {
        return (int) apply_filters( 'pcio_me_active_subscription_count', 0 );
    }

    /**
     * Return joined and left member counts grouped by year, covering all years
     * present in wp_me_members for either date column.
     *
     * @return array<int, array{year: int, joined: int, left: int}>
     */
    public static function get_members_by_year(): array {
        global $wpdb;
        $members = $wpdb->prefix . 'me_members';

        $joined = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT YEAR(join_date) AS yr, COUNT(*) AS cnt FROM %i WHERE join_date IS NOT NULL GROUP BY yr',
                $members
            ),
            ARRAY_A
        ) ?: [];

        $left = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT YEAR(leave_date) AS yr, COUNT(*) AS cnt FROM %i WHERE leave_date IS NOT NULL GROUP BY yr',
                $members
            ),
            ARRAY_A
        ) ?: [];

        // Merge into a single year-keyed map.
        $map = [];
        foreach ( $joined as $row ) {
            $yr        = (int) $row['yr'];
            $map[ $yr ] = [ 'year' => $yr, 'joined' => (int) $row['cnt'], 'left' => 0 ];
        }
        foreach ( $left as $row ) {
            $yr = (int) $row['yr'];
            if ( ! isset( $map[ $yr ] ) ) {
                $map[ $yr ] = [ 'year' => $yr, 'joined' => 0, 'left' => 0 ];
            }
            $map[ $yr ]['left'] = (int) $row['cnt'];
        }

        ksort( $map );
        return array_values( $map );
    }
}
