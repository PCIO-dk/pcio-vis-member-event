<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP Admin → Vis → History: the full change log with undo.
 *
 * Viewing needs vis_manage_settings; the Undo button additionally needs
 * vis_undo_changes. Undo is a nonce-protected POST to admin-post.php.
 */
class PCIO_VIS_History_Admin {

    const PAGE   = 'pcio-me-history';
    const ACTION = 'pcio_vis_history_undo';

    private string $hook = '';

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ], 20 );
        add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_undo' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function add_menu(): void {
        $this->hook = (string) add_submenu_page(
            'pcio-me-settings',
            __( 'History', 'pcio-vis-member-event' ),
            __( 'History', 'pcio-vis-member-event' ),
            'vis_manage_settings',
            self::PAGE,
            [ $this, 'render' ]
        );
    }

    /**
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue( string $hook ): void {
        if ( '' === $this->hook || $hook !== $this->hook ) {
            return;
        }
        wp_enqueue_style( 'pcio-me-history-admin', plugins_url( 'assets/history-admin.css', PCIO_VIS_PLUGIN_FILE ), [], '1.0.0' );
        wp_enqueue_script( 'pcio-me-history-admin', plugins_url( 'assets/history-admin.js', PCIO_VIS_PLUGIN_FILE ), [], '1.0.0', true );
        wp_add_inline_script(
            'pcio-me-history-admin',
            'window.PCIO_ME_HISTORY_ADMIN = ' . wp_json_encode( [
                'confirm' => __( 'Undo this change?', 'pcio-vis-member-event' ),
            ] ) . ';',
            'before'
        );
    }

    // ── Undo handler ──────────────────────────────────────────────

    public function handle_undo(): void {
        $id = isset( $_POST['change_id'] ) ? absint( wp_unslash( $_POST['change_id'] ) ) : 0;
        check_admin_referer( self::ACTION . '_' . $id );

        if ( ! current_user_can( 'vis_manage_settings' ) || ! PCIO_VIS_History::current_user_can_undo() ) {
            wp_die( esc_html__( 'You are not allowed to undo changes.', 'pcio-vis-member-event' ), '', [ 'response' => 403 ] );
        }

        $result = PCIO_VIS_History::undo( $id );
        $notice = is_wp_error( $result )
            ? [ 'type' => 'error', 'message' => $result->get_error_message() ]
            : [
                'type'    => 'success',
                /* translators: %d: history entry id */
                'message' => sprintf( __( 'Change #%d was undone.', 'pcio-vis-member-event' ), $id ),
            ];
        set_transient( $this->notice_key(), $notice, MINUTE_IN_SECONDS );

        $back = wp_get_referer();
        wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }

    private function notice_key(): string {
        return 'pcio_vis_history_notice_' . get_current_user_id();
    }

    // ── Page ──────────────────────────────────────────────────────

    public function render(): void {
        if ( ! current_user_can( 'vis_manage_settings' ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'pcio-vis-member-event' ) );
        }

        $table = new PCIO_VIS_History_List_Table();
        $table->prepare_items();

        $notice = get_transient( $this->notice_key() );
        if ( $notice ) {
            delete_transient( $this->notice_key() );
        }
        ?>
        <div class="wrap pcio-me-history-admin">
            <h1><?php esc_html_e( 'Change history', 'pcio-vis-member-event' ); ?></h1>
            <?php if ( is_array( $notice ) ) : ?>
                <div class="notice notice-<?php echo esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ); ?> is-dismissible">
                    <p><?php echo esc_html( (string) $notice['message'] ); ?></p>
                </div>
            <?php endif; ?>
            <p class="description">
                <?php
                printf(
                    /* translators: %d: number of days undo data is kept */
                    esc_html__( 'All changes to Vis data, newest first. Undo restores the database rows only; e-mails, payments and WordPress accounts are not reverted. Undo data is kept for %d days.', 'pcio-vis-member-event' ),
                    (int) apply_filters( 'pcio_me_history_undo_retention_days', PCIO_VIS_History::RETENTION_DAYS )
                );
                ?>
            </p>
            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
                <?php
                $table->search_box( __( 'Search', 'pcio-vis-member-event' ), 'pcio-me-history' );
                $table->display();
                ?>
            </form>
            <?php $table->print_undo_forms(); ?>
        </div>
        <?php
    }
}

/**
 * List table for the change history admin page.
 */
class PCIO_VIS_History_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => 'change',
            'plural'   => 'changes',
            'ajax'     => false,
        ] );
    }

    public function get_columns(): array {
        return [
            'created_at'  => __( 'Date', 'pcio-vis-member-event' ),
            'user'        => __( 'User', 'pcio-vis-member-event' ),
            'table_key'   => __( 'Type', 'pcio-vis-member-event' ),
            'description' => __( 'Change', 'pcio-vis-member-event' ),
            'undo'        => __( 'Undo', 'pcio-vis-member-event' ),
        ];
    }

    /**
     * Read-only filter values from the query string (GET list, no state change).
     *
     * @return array{search:string,table_key:string,hide_undone:bool}
     */
    private function filters(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
        $out = [
            'search'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
            'table_key'   => isset( $_GET['table_key'] ) ? sanitize_key( wp_unslash( $_GET['table_key'] ) ) : '',
            'hide_undone' => ! empty( $_GET['hide_undone'] ),
        ];
        // phpcs:enable
        return $out;
    }

    public function prepare_items(): void {
        $per_page = 50;
        $result   = PCIO_VIS_History_DB::query( array_merge( $this->filters(), [
            'page'     => $this->get_pagenum(),
            'per_page' => $per_page,
        ] ) );

        $this->_column_headers = [ $this->get_columns(), [], [] ];
        $this->items           = array_map( [ 'PCIO_VIS_History', 'format_item' ], $result['items'] );
        $this->set_pagination_args( [
            'total_items' => $result['total'],
            'per_page'    => $per_page,
        ] );
    }

    /**
     * Table-type filter + "hide undone" toggle above the table.
     *
     * @param string $which top|bottom.
     */
    protected function extra_tablenav( $which ): void {
        if ( 'top' !== $which ) {
            return;
        }
        $f = $this->filters();
        ?>
        <div class="alignleft actions">
            <label class="screen-reader-text" for="pcio-me-history-type"><?php esc_html_e( 'Filter by type', 'pcio-vis-member-event' ); ?></label>
            <select name="table_key" id="pcio-me-history-type">
                <option value=""><?php esc_html_e( 'All types', 'pcio-vis-member-event' ); ?></option>
                <?php foreach ( PCIO_VIS_History_DB::distinct_table_keys() as $key ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $f['table_key'], $key ); ?>>
                        <?php echo esc_html( PCIO_VIS_History::table_label( $key ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label>
                <input type="checkbox" name="hide_undone" value="1" <?php checked( $f['hide_undone'] ); ?> />
                <?php esc_html_e( 'Hide undone', 'pcio-vis-member-event' ); ?>
            </label>
            <?php submit_button( __( 'Filter', 'pcio-vis-member-event' ), '', 'filter_action', false ); ?>
        </div>
        <?php
    }

    public function no_items(): void {
        esc_html_e( 'No changes logged yet.', 'pcio-vis-member-event' );
    }

    /**
     * @param array  $item        Formatted history item.
     * @param string $column_name Column key.
     */
    protected function column_default( $item, $column_name ): string {
        switch ( $column_name ) {
            case 'created_at':
                return esc_html( $item['createdHuman'] );
            case 'user':
                return esc_html( $item['userName'] );
            case 'table_key':
                return esc_html( $item['tableLabel'] );
            default:
                return '';
        }
    }

    /**
     * @param array $item Formatted history item.
     */
    protected function column_description( $item ): string {
        $html = esc_html( $item['description'] );
        $meta = [];
        if ( $item['eventId'] ) {
            $meta[] = '<a href="' . esc_url( home_url( 'vis/events/' . (int) $item['eventId'] . '/' ) ) . '">'
                /* translators: %d: event id */
                . esc_html( sprintf( __( 'Event #%d', 'pcio-vis-member-event' ), (int) $item['eventId'] ) ) . '</a>';
        }
        if ( $item['memberId'] ) {
            $meta[] = '<a href="' . esc_url( home_url( 'vis/members/' . (int) $item['memberId'] . '/' ) ) . '">'
                /* translators: %d: member id */
                . esc_html( sprintf( __( 'Member #%d', 'pcio-vis-member-event' ), (int) $item['memberId'] ) ) . '</a>';
        }
        if ( $item['undoneAt'] ) {
            $meta[] = '<span class="pcio-me-history-undone">' . esc_html(
                /* translators: 1: date, 2: user name */
                sprintf( __( 'Undone %1$s by %2$s', 'pcio-vis-member-event' ), $item['undoneAt'], (string) $item['undoneBy'] )
            ) . '</span>';
        }
        $meta[] = '<span class="pcio-me-history-id">#' . (int) $item['id'] . '</span>';
        return $html . '<div class="row-actions visible">' . implode( ' | ', $meta ) . '</div>';
    }

    /**
     * @param array $item Formatted history item.
     */
    protected function column_undo( $item ): string {
        if ( $item['undoneAt'] ) {
            return '<span class="pcio-me-history-state">' . esc_html__( 'Undone', 'pcio-vis-member-event' ) . '</span>';
        }
        if ( 'expired' === $item['undoState'] ) {
            return '<span class="pcio-me-history-state">' . esc_html__( 'Expired', 'pcio-vis-member-event' ) . '</span>';
        }
        if ( 'none' === $item['undoState'] ) {
            return '<span class="pcio-me-history-state">' . esc_html__( 'Not undoable', 'pcio-vis-member-event' ) . '</span>';
        }
        if ( ! $item['canUndo'] ) {
            return '';
        }
        $id = (int) $item['id'];
        // Nested forms are invalid inside the list form, so the button submits a
        // standalone form via the form attribute (see print_undo_forms()).
        return '<button type="submit" class="button button-small pcio-me-history-undo" form="pcio-me-undo-' . $id . '">'
            . esc_html__( 'Undo', 'pcio-vis-member-event' ) . '</button>';
    }

    /**
     * Render the row and, for undoable rows, a detached POST form the Undo button targets.
     *
     * @param array $item Formatted history item.
     */
    public function single_row( $item ): void {
        echo '<tr class="' . esc_attr( $item['undoneAt'] ? 'pcio-me-history-row-undone' : '' ) . '">';
        $this->single_row_columns( $item );
        echo '</tr>';
    }

    /**
     * Print the standalone undo forms after the table (outside the GET form).
     */
    public function print_undo_forms(): void {
        foreach ( $this->items as $item ) {
            if ( ! $item['canUndo'] ) {
                continue;
            }
            $id = (int) $item['id'];
            ?>
            <form id="pcio-me-undo-<?php echo esc_attr( (string) $id ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pcio-me-history-undo-form">
                <input type="hidden" name="action" value="<?php echo esc_attr( PCIO_VIS_History_Admin::ACTION ); ?>" />
                <input type="hidden" name="change_id" value="<?php echo esc_attr( (string) $id ); ?>" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( PCIO_VIS_History_Admin::ACTION . '_' . $id ) ); ?>" />
            </form>
            <?php
        }
    }
}
