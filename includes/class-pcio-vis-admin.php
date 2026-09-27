<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin-side concerns for PCIO VIS Member Event.
 *
 * The main application lives at front-end URLs like /vis/members/
 * (served by PCIO_VIS_Plugin::serve_templates).  This class only handles:
 *   – the WP admin Settings page (API keys, defaults, etc.)
 *   – the plugin-list action links
 *
 * Do NOT add full CRUD pages here.  Use templates/ + rewrite rules instead.
 */
class PCIO_VIS_Admin {

    /**
     * Admin page hook suffixes, captured when the menu is registered so
     * assets can be enqueued only on the pages that need them.
     *
     * @var array<string,string>
     */
    private array $hooks = [];

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ], 10 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_filter(
            'plugin_action_links_' . plugin_basename( PCIO_VIS_PLUGIN_FILE ),
            [ $this, 'action_links' ]
        );
    }

    // ── Plugin list action links ──────────────────────────────────

    public function action_links( array $links ): array {
        $extra = [
            '<a href="' . esc_url( home_url( 'vis/members' ) ) . '">'
                . esc_html__( 'Members', 'pcio-vis-member-event' ) . '</a>',
            '<a href="' . esc_url( admin_url( 'admin.php?page=pcio-me-settings' ) ) . '">'
                . esc_html__( 'Settings', 'pcio-vis-member-event' ) . '</a>',
        ];
        return array_merge( $extra, $links );
    }

    // ── Admin menu ────────────────────────────────────────────────

    public function add_menu(): void {
        // Top-level "Vis" menu — first submenu (Document Types) is the landing page.
        // Document Types is gated by vis_manage_settings (consistent with the
        // REST layer) so a Vis admin role does not need the core manage_options cap.
        add_menu_page(
            __( 'Vis', 'pcio-vis-member-event' ),
            __( 'Vis', 'pcio-vis-member-event' ),
            'vis_manage_settings',
            'pcio-me-settings',
            [ $this, 'render_doc_types_page' ],
            'dashicons-groups',
            30
        );

        // First submenu: Document Types (replaces the auto-generated parent link)
        $this->hooks['doc_types'] = add_submenu_page(
            'pcio-me-settings',
            __( 'Document Types', 'pcio-vis-member-event' ),
            __( 'Document Types', 'pcio-vis-member-event' ),
            'vis_manage_settings',
            'pcio-me-settings',
            [ $this, 'render_doc_types_page' ]
        );

        // Second submenu: Roles & Capabilities
        add_submenu_page(
            'pcio-me-settings',
            __( 'Roles', 'pcio-vis-member-event' ),
            __( 'Roles', 'pcio-vis-member-event' ),
            'manage_options',
            'pcio-me-roles',
            [ $this, 'render_roles_page' ]
        );

        // Third submenu: Sync Jobs
        add_submenu_page(
            'pcio-me-settings',
            __( 'Sync Jobs', 'pcio-vis-member-event' ),
            __( 'Sync Jobs', 'pcio-vis-member-event' ),
            'manage_options',
            'pcio-me-sync',
            [ $this, 'render_sync_page' ]
        );

        // WP User Cleanup: read-only report of unlinked WordPress accounts.
        add_submenu_page(
            'pcio-me-settings',
            __( 'WP User Cleanup', 'pcio-vis-member-event' ),
            __( 'WP User Cleanup', 'pcio-vis-member-event' ),
            'manage_options',
            'pcio-me-cleanup',
            [ $this, 'render_cleanup_page' ]
        );

        // Fourth submenu: Shortcode reference
        $this->hooks['shortcodes'] = add_submenu_page(
            'pcio-me-settings',
            __( 'Shortcodes', 'pcio-vis-member-event' ),
            __( 'Shortcodes', 'pcio-vis-member-event' ),
            'manage_options',
            'pcio-me-shortcodes',
            [ $this, 'render_shortcodes_page' ]
        );
    }

    /**
     * Enqueue inline admin scripts/styles only on the pages that need them.
     *
     * @param string $hook Current admin page hook suffix.
     */
    public function enqueue_admin_assets( string $hook ): void {
        if ( isset( $this->hooks['doc_types'] ) && $hook === $this->hooks['doc_types'] ) {
            wp_register_script( 'pcio-me-doc-types', false, [], PCIO_VIS_DB_VERSION, true );
            wp_enqueue_script( 'pcio-me-doc-types' );
            wp_add_inline_script( 'pcio-me-doc-types', $this->doc_types_inline_js() );
        }

        if ( isset( $this->hooks['shortcodes'] ) && $hook === $this->hooks['shortcodes'] ) {
            wp_register_style( 'pcio-me-shortcodes', false, [], PCIO_VIS_DB_VERSION );
            wp_enqueue_style( 'pcio-me-shortcodes' );
            wp_add_inline_style( 'pcio-me-shortcodes', $this->shortcodes_inline_css() );

            wp_register_script( 'pcio-me-shortcodes', false, [], PCIO_VIS_DB_VERSION, true );
            wp_enqueue_script( 'pcio-me-shortcodes' );
            wp_add_inline_script( 'pcio-me-shortcodes', $this->shortcodes_inline_js() );
        }
    }

    /**
     * Inline JavaScript for the Document Types page shortcode preview.
     */
    private function doc_types_inline_js(): string {
        return <<<'JS'
( function() {
    var nameInput = document.getElementById( 'dt_name' );
    var preview   = document.getElementById( 'pcio-sc-preview' );
    if ( ! nameInput || ! preview ) { return; }
    nameInput.addEventListener( 'input', function() {
        var slug = nameInput.value || 'type-slug';
        preview.textContent = '[pcio_me_documents type="' + slug + '"]';
    } );
} )();
JS;
    }

    /**
     * Inline CSS for the Shortcode reference page.
     */
    private function shortcodes_inline_css(): string {
        return <<<'CSS'
/* ── Shared token styles ──────────────────────────────────────────────── */
.pcio-sc-table { border-collapse: collapse; width: 100%; margin-bottom: 24px; }
.pcio-sc-table th,
.pcio-sc-table td { padding: 10px 14px; border: 1px solid #e2e8f0; vertical-align: top; }
.pcio-sc-table thead th { background: #f8fafc; font-weight: 600; }
.pcio-sc-code { font-family: monospace; font-size: 13px; background: #f1f5f9;
                border: 1px solid #e2e8f0; border-radius: 4px; padding: 3px 7px;
                white-space: nowrap; display: inline-block; }
.pcio-sc-block { font-family: monospace; font-size: 13px; background: #f1f5f9;
                 border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px;
                 display: block; white-space: pre-wrap; word-break: break-all; margin: 4px 0 0; }
.pcio-sc-note  { font-size: 12px; color: #64748b; margin: 6px 0 0; }
.pcio-sc-cap   { font-size: 12px; font-family: monospace; background: #ede9fe;
                 border-radius: 4px; padding: 1px 6px; color: #5b21b6; }

/* ── Two-column layout ────────────────────────────────────────────────── */
.pcio-sc-wrap { max-width: 1280px; }
.pcio-sc-layout { display: flex; align-items: flex-start; margin-top: 20px; gap: 28px; }

/* ── Sticky sidebar ───────────────────────────────────────────────────── */
.pcio-sc-sidebar { width: 226px; flex-shrink: 0; position: sticky; top: 28px;
                   max-height: calc(100vh - 80px); overflow-y: auto;
                   background: #fff; border: 1px solid #e2e8f0; border-radius: 8px;
                   padding: 8px 0 12px; }
.pcio-sc-nav-group { margin-bottom: 2px; }
.pcio-sc-nav-heading { font-size: 10px; font-weight: 700; letter-spacing: .08em;
                       text-transform: uppercase; color: #94a3b8; padding: 12px 14px 4px; }
.pcio-sc-nav-group:first-child .pcio-sc-nav-heading { padding-top: 4px; }
.pcio-sc-nav-item { display: flex; align-items: center; padding: 4px 14px;
                    font-size: 12px; color: #475569; text-decoration: none;
                    border-left: 2px solid transparent; font-family: monospace;
                    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
                    transition: background .1s, color .1s, border-color .1s; line-height: 1.6; }
.pcio-sc-nav-item:hover { color: #2271b1; background: #f1f5f9; }
.pcio-sc-nav-item.pcio-sc-nav-plain { font-family: sans-serif; font-style: italic;
                                      font-size: 11px; color: #94a3b8; }
.pcio-sc-nav-item.pcio-sc-nav-plain:hover { color: #64748b; background: #f8fafc; }
.pcio-sc-nav-item.is-active { color: #2271b1; border-left-color: #2271b1;
                              background: #eff6ff; font-weight: 600; }

/* ── Content area ─────────────────────────────────────────────────────── */
.pcio-sc-content { flex: 1; min-width: 0; }
.pcio-sc-content h2 { font-size: 1.05rem; margin: 0 0 14px;
                      padding: 0 0 10px; border-bottom: 2px solid #e2e8f0;
                      font-family: monospace; font-weight: 700; color: #1d2327; }
.pcio-sc-content hr { border: none; border-top: 1px solid #f1f5f9; margin: 36px 0; }
[id^="sc-"] { scroll-margin-top: 36px; }
CSS;
    }

    /**
     * Inline JS for the Shortcode reference page (scroll-spy).
     */
    private function shortcodes_inline_js(): string {
        return <<<'JS'
( function () {
    'use strict';
    var nav = document.getElementById( 'pcio-sc-nav' );
    if ( ! nav || ! window.IntersectionObserver ) { return; }

    var links    = Array.from( nav.querySelectorAll( '.pcio-sc-nav-item[href^="#"]' ) );
    var sections = links.map( function ( a ) {
        return document.getElementById( a.getAttribute( 'href' ).slice( 1 ) );
    } ).filter( Boolean );

    function activate( id ) {
        links.forEach( function ( a ) {
            a.classList.toggle( 'is-active', a.getAttribute( 'href' ) === '#' + id );
        } );
    }

    var obs = new IntersectionObserver( function ( entries ) {
        entries.forEach( function ( e ) {
            if ( e.isIntersecting ) { activate( e.target.id ); }
        } );
    }, { rootMargin: '-10% 0px -75% 0px' } );

    sections.forEach( function ( s ) { obs.observe( s ); } );

    if ( sections.length ) { activate( sections[0].id ); }
} () );
JS;
    }

    // ── Document Types admin page ─────────────────────────────────

    public function render_doc_types_page(): void {
        $can_manage = current_user_can( 'vis_manage_settings' );

       

        if ( ! $can_manage ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pcio-vis-member-event' ) );
        }

        $notice  = '';
        $action  = sanitize_key( $_POST['pcio_me_dt_action'] ?? '' );

        if ( $action && isset( $_POST['pcio_me_dt_nonce'] ) &&
             wp_verify_nonce( sanitize_key( $_POST['pcio_me_dt_nonce'] ), 'pcio_me_doc_types' ) ) {

            if ( $action === 'add' ) {
                $name  = sanitize_key(        wp_unslash( $_POST['dt_name']  ?? '' ) );
                $title = sanitize_text_field( wp_unslash( $_POST['dt_title'] ?? '' ) );
                if ( $name && $title ) {
                    $ok = PCIO_VIS_Docs_DB::create_type( [ 'name' => $name, 'title' => $title ] );
                    $notice = $ok
                        ? [ 'type' => 'success', 'msg' => __( 'Document type added.', 'pcio-vis-member-event' ) ]
                        : [ 'type' => 'error',   'msg' => __( 'Could not add document type (name may already exist).', 'pcio-vis-member-event' ) ];
                } else {
                    $notice = [ 'type' => 'error', 'msg' => __( 'Name and title are required.', 'pcio-vis-member-event' ) ];
                }

            } elseif ( $action === 'edit' ) {
                $id    = absint(              wp_unslash( $_POST['dt_id']    ?? 0  ) );
                $name  = sanitize_key(        wp_unslash( $_POST['dt_name']  ?? '' ) );
                $title = sanitize_text_field( wp_unslash( $_POST['dt_title'] ?? '' ) );
                if ( $id && $name && $title ) {
                    $ok = PCIO_VIS_Docs_DB::update_type( $id, [ 'name' => $name, 'title' => $title ] );
                    $notice = $ok
                        ? [ 'type' => 'success', 'msg' => __( 'Document type updated.', 'pcio-vis-member-event' ) ]
                        : [ 'type' => 'error',   'msg' => __( 'Could not update document type.', 'pcio-vis-member-event' ) ];
                } else {
                    $notice = [ 'type' => 'error', 'msg' => __( 'Name and title are required.', 'pcio-vis-member-event' ) ];
                }

            } elseif ( $action === 'delete' ) {
                $id  = absint( wp_unslash( $_POST['dt_id'] ?? 0 ) );
                $ok  = $id ? PCIO_VIS_Docs_DB::delete_type( $id ) : false;
                $notice = ( $ok === true )
                    ? [ 'type' => 'success', 'msg' => __( 'Document type deleted.', 'pcio-vis-member-event' ) ]
                    : [ 'type' => 'error',   'msg' => __( 'Cannot delete: documents of this type still exist, or type not found.', 'pcio-vis-member-event' ) ];
            }
        }

        $types      = PCIO_VIS_Docs_DB::get_all_types();
        $edit_id    = absint( $_GET['edit'] ?? 0 );
        $edit_type  = $edit_id ? PCIO_VIS_Docs_DB::get_type( $edit_id ) : null;
        $page_url   = admin_url( 'admin.php?page=pcio-me-settings' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Document Types', 'pcio-vis-member-event' ); ?></h1>

            <?php if ( $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice['type'] === 'success' ? 'success' : 'error' ); ?> is-dismissible">
                <p><?php echo esc_html( $notice['msg'] ); ?></p>
            </div>
            <?php endif; ?>

            <!-- Add / Edit form -->
            <h2><?php echo $edit_type
                ? esc_html__( 'Edit Document Type', 'pcio-vis-member-event' )
                : esc_html__( 'Add Document Type', 'pcio-vis-member-event' ); ?></h2>
            <form method="post" action="<?php echo esc_url( $page_url ); ?>">
                <?php wp_nonce_field( 'pcio_me_doc_types', 'pcio_me_dt_nonce' ); ?>
                <input type="hidden" name="pcio_me_dt_action" value="<?php echo $edit_type ? 'edit' : 'add'; ?>">
                <?php if ( $edit_type ) : ?>
                    <input type="hidden" name="dt_id" value="<?php echo (int) $edit_type['id']; ?>">
                <?php endif; ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="dt_name"><?php esc_html_e( 'Name (slug)', 'pcio-vis-member-event' ); ?></label>
                        </th>
                        <td>
                            <input name="dt_name" id="dt_name" type="text"
                                   class="regular-text" required
                                   pattern="[a-z0-9\-]+"
                                   value="<?php echo esc_attr( $edit_type['name'] ?? '' ); ?>">
                            <p class="description"><?php esc_html_e( 'Lowercase letters, numbers and hyphens only (e.g. "annual-report", "minutes").', 'pcio-vis-member-event' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="dt_title"><?php esc_html_e( 'Title', 'pcio-vis-member-event' ); ?></label>
                        </th>
                        <td>
                            <input name="dt_title" id="dt_title" type="text"
                                   class="regular-text" required
                                   value="<?php echo esc_attr( $edit_type['title'] ?? '' ); ?>">
                            <p class="description"><?php esc_html_e( 'Human-readable display name shown in the document list.', 'pcio-vis-member-event' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Shortcode', 'pcio-vis-member-event' ); ?></th>
                        <td>
                            <p style="margin:0 0 4px">
                                <?php esc_html_e( 'Paste one of these into any WordPress page or widget:', 'pcio-vis-member-event' ); ?>
                            </p>
                            <code id="pcio-sc-preview" style="display:block;padding:6px 10px;background:#f0f0f1;border-radius:3px;font-size:13px"><?php
                                $slug = $edit_type['name'] ?? 'type-slug';
                                echo '[pcio_me_documents type="' . esc_html( $slug ) . '"]';
                            ?></code>
                            <code style="display:block;margin-top:4px;padding:6px 10px;background:#f0f0f1;border-radius:3px;font-size:13px"><?php
                                echo '[pcio_me_documents type="' . esc_html( $edit_type['name'] ?? 'type-slug' ) . '" limit="10"]';
                            ?></code>
                            <p class="description" style="margin-top:4px"><?php esc_html_e( 'The optional "limit" attribute caps the number of documents shown (omit for all).', 'pcio-vis-member-event' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php
                submit_button(
                    $edit_type
                        ? __( 'Update type', 'pcio-vis-member-event' )
                        : __( 'Add type', 'pcio-vis-member-event' )
                );
                if ( $edit_type ) :
                    echo '<a href="' . esc_url( $page_url ) . '" class="button" style="margin-left:8px">'
                        . esc_html__( 'Cancel', 'pcio-vis-member-event' ) . '</a>';
                endif;
                ?>
            </form>

            <hr>

            <!-- Type list -->
            <h2><?php esc_html_e( 'Existing types', 'pcio-vis-member-event' ); ?></h2>
            <?php if ( empty( $types ) ) : ?>
                <p><?php esc_html_e( 'No document types yet.', 'pcio-vis-member-event' ); ?></p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name (slug)', 'pcio-vis-member-event' ); ?></th>
                        <th><?php esc_html_e( 'Title', 'pcio-vis-member-event' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'pcio-vis-member-event' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $types as $t ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $t['name'] ); ?></code></td>
                        <td><?php echo esc_html( $t['title'] ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( add_query_arg( 'edit', $t['id'], $page_url ) ); ?>">
                                <?php esc_html_e( 'Edit', 'pcio-vis-member-event' ); ?>
                            </a>
                            &nbsp;|
                            <form method="post" action="<?php echo esc_url( $page_url ); ?>"
                                  style="display:inline"
                                  onsubmit="return confirm('<?php echo esc_js( __( 'Delete this document type?', 'pcio-vis-member-event' ) ); ?>')">
                                <?php wp_nonce_field( 'pcio_me_doc_types', 'pcio_me_dt_nonce' ); ?>
                                <input type="hidden" name="pcio_me_dt_action" value="delete">
                                <input type="hidden" name="dt_id" value="<?php echo (int) $t['id']; ?>">
                                <button type="submit" class="button-link" style="color:#b32d2e">
                                    <?php esc_html_e( 'Delete', 'pcio-vis-member-event' ); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── WP User Cleanup report ────────────────────────────────────

    /**
     * Read-only report of WordPress accounts not linked to any active member.
     *
     * This plugin never deletes WordPress users. Any deletion is performed on the
     * native Users screen, which enforces the delete_users capability and any
     * security-plugin protections.
     */
    public function render_cleanup_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pcio-vis-member-event' ) );
        }

        // Exclude administrators — they are never cleanup candidates.
        $orphans = array_filter(
            PCIO_VIS_Roles_DB::get_orphan_wp_users(),
            static function ( array $u ): bool {
                return ! user_can( (int) $u['ID'], 'manage_options' );
            }
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP User Cleanup', 'pcio-vis-member-event' ); ?></h1>

            <p>
                <?php
                printf(
                    /* translators: %d: number of unlinked WordPress users */
                    esc_html( _n(
                        '%d WordPress user is not linked to an active member.',
                        '%d WordPress users are not linked to an active member.',
                        count( $orphans ),
                        'pcio-vis-member-event'
                    ) ),
                    (int) count( $orphans )
                );
                ?>
            </p>
            <p class="description">
                <?php esc_html_e( 'This plugin never deletes WordPress accounts. If you want to remove any of these users, use the WordPress Users screen, which applies the normal capability and security checks.', 'pcio-vis-member-event' ); ?>
            </p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">
                    <?php esc_html_e( 'Open WordPress → Users', 'pcio-vis-member-event' ); ?>
                </a>
            </p>

            <?php if ( empty( $orphans ) ) : ?>
                <p><?php esc_html_e( 'Every WordPress user is linked to an active member. Nothing to clean up.', 'pcio-vis-member-event' ); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Username', 'pcio-vis-member-event' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'pcio-vis-member-event' ); ?></th>
                            <th><?php esc_html_e( 'Registered', 'pcio-vis-member-event' ); ?></th>
                            <th><?php esc_html_e( 'Action', 'pcio-vis-member-event' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $orphans as $u ) : ?>
                            <tr>
                                <td><?php echo esc_html( $u['user_login'] ); ?></td>
                                <td><?php echo esc_html( $u['user_email'] ); ?></td>
                                <td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $u['user_registered'] ) ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( get_edit_user_link( (int) $u['ID'] ) ); ?>">
                                        <?php esc_html_e( 'Edit user', 'pcio-vis-member-event' ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Roles & Capabilities admin page ──────────────────────────

    public function render_roles_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pcio-vis-member-event' ) );
        }

        $notice   = '';
        $action   = sanitize_key( $_POST['pcio_me_vr_action'] ?? '' );
        $page_url = admin_url( 'admin.php?page=pcio-me-roles' );

        // ── Handle vis-role CRUD ───────────────────────────────────
        if ( $action && isset( $_POST['pcio_me_vr_nonce'] ) &&
             wp_verify_nonce( sanitize_key( $_POST['pcio_me_vr_nonce'] ), 'pcio_me_vis_roles' ) ) {

            if ( $action === 'add-vis-role' ) {
                $slug = sanitize_key(        wp_unslash( $_POST['vr_slug'] ?? '' ) );
                $name = sanitize_text_field( wp_unslash( $_POST['vr_name'] ?? '' ) );
                $caps = array_map( 'sanitize_key', (array) ( $_POST['vr_caps'] ?? [] ) );
                if ( $slug && $name ) {
                    $ok = PCIO_VIS_Roles_DB::create_role( compact( 'slug', 'name', 'caps' ) );
                    $notice = $ok
                        ? [ 'type' => 'success', 'msg' => __( 'Vis role added.', 'pcio-vis-member-event' ) ]
                        : [ 'type' => 'error',   'msg' => __( 'Could not add vis role (slug may already exist).', 'pcio-vis-member-event' ) ];
                } else {
                    $notice = [ 'type' => 'error', 'msg' => __( 'Slug and name are required.', 'pcio-vis-member-event' ) ];
                }

            } elseif ( $action === 'edit-vis-role' ) {
                $id   = absint(              wp_unslash( $_POST['vr_id']   ?? 0  ) );
                $slug = sanitize_key(        wp_unslash( $_POST['vr_slug'] ?? '' ) );
                $name = sanitize_text_field( wp_unslash( $_POST['vr_name'] ?? '' ) );
                $caps = array_map( 'sanitize_key', (array) ( $_POST['vr_caps'] ?? [] ) );
                if ( $id && $slug && $name ) {
                    $ok = PCIO_VIS_Roles_DB::update_role( $id, compact( 'slug', 'name', 'caps' ) );
                    $notice = $ok
                        ? [ 'type' => 'success', 'msg' => __( 'Vis role updated.', 'pcio-vis-member-event' ) ]
                        : [ 'type' => 'error',   'msg' => __( 'Could not update vis role.', 'pcio-vis-member-event' ) ];
                } else {
                    $notice = [ 'type' => 'error', 'msg' => __( 'Slug and name are required.', 'pcio-vis-member-event' ) ];
                }

            } elseif ( $action === 'delete-vis-role' ) {
                $id = absint( wp_unslash( $_POST['vr_id'] ?? 0 ) );
                $ok = $id ? PCIO_VIS_Roles_DB::delete_role( $id ) : false;
                $notice = $ok
                    ? [ 'type' => 'success', 'msg' => __( 'Vis role deleted.', 'pcio-vis-member-event' ) ]
                    : [ 'type' => 'error',   'msg' => __( 'Could not delete vis role.', 'pcio-vis-member-event' ) ];

            } elseif ( $action === 'sync-default-caps' ) {
                // Re-apply native WP-role grants (so administrators pick up any
                // newly introduced caps such as vis_manage_finance) …
                PCIO_VIS_Caps::activate();
                // … let add-ons re-grant their own native role caps …
                do_action( 'pcio_me_sync_caps' );
                // … and additively merge new defaults into the Vis roles.
                $n = PCIO_VIS_Roles_DB::sync_default_caps();
                $notice = [
                    'type' => 'success',
                    'msg'  => sprintf(
                        /* translators: %d: number of Vis roles updated */
                        _n(
                            'Capabilities synced. %d event-system role updated.',
                            'Capabilities synced. %d event-system roles updated.',
                            $n,
                            'pcio-vis-member-event'
                        ),
                        $n
                    ),
                ];
            }
        }

        $vis_roles = PCIO_VIS_Roles_DB::get_all_roles();
        $edit_id   = absint( $_GET['edit_vr'] ?? 0 );
        $edit_role = $edit_id ? PCIO_VIS_Roles_DB::get_role( $edit_id ) : null;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Vis — Roles &amp; Capabilities', 'pcio-vis-member-event' ); ?></h1>

            <?php if ( $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
                <p><?php echo esc_html( $notice['msg'] ); ?></p>
            </div>
            <?php endif; ?>

            <!-- Sync capabilities: grants newly introduced caps (e.g. after a
                 plugin update) to administrators and the default event-system
                 roles without a full plugin reactivation. Purely additive. -->
            <form method="post" action="<?php echo esc_url( $page_url ); ?>" style="margin:8px 0 16px">
                <?php wp_nonce_field( 'pcio_me_vis_roles', 'pcio_me_vr_nonce' ); ?>
                <input type="hidden" name="pcio_me_vr_action" value="sync-default-caps">
                <button type="submit" class="button"><?php esc_html_e( 'Sync capabilities', 'pcio-vis-member-event' ); ?></button>
                <span class="description" style="margin-left:8px"><?php
                    esc_html_e( 'Grant newly added capabilities to administrators and the matching default roles. Existing custom selections are kept.', 'pcio-vis-member-event' );
                ?></span>
            </form>

            <!-- ════════════════════════════════════════════════════════
                 SECTION 1 — Vis role definitions (stored in DB)
                 ════════════════════════════════════════════════════════ -->
            <h2><?php esc_html_e( 'Event-system roles', 'pcio-vis-member-event' ); ?></h2>
            <p class="description" style="margin-bottom:12px"><?php
                esc_html_e( 'These are the event-system roles you can assign to members. Each role grants a set of vis_* capabilities.', 'pcio-vis-member-event' );
            ?></p>

            <!-- Add / Edit vis role form -->
            <form method="post" action="<?php echo esc_url( $page_url ); ?>" style="max-width:560px">
                <?php wp_nonce_field( 'pcio_me_vis_roles', 'pcio_me_vr_nonce' ); ?>
                <input type="hidden" name="pcio_me_vr_action"
                       value="<?php echo $edit_role ? 'edit-vis-role' : 'add-vis-role'; ?>">
                <?php if ( $edit_role ) : ?>
                    <input type="hidden" name="vr_id" value="<?php echo (int) $edit_role['id']; ?>">
                <?php endif; ?>
                <h3><?php echo $edit_role
                    ? esc_html__( 'Edit event-system role', 'pcio-vis-member-event' )
                    : esc_html__( 'Add event-system role', 'pcio-vis-member-event' ); ?></h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="vr_slug"><?php esc_html_e( 'Slug', 'pcio-vis-member-event' ); ?></label></th>
                        <td>
                            <input name="vr_slug" id="vr_slug" type="text" class="regular-text" required
                                   pattern="[a-z0-9\-]+"
                                   value="<?php echo esc_attr( $edit_role['slug'] ?? '' ); ?>">
                            <p class="description"><?php esc_html_e( 'Lowercase letters, numbers and hyphens.', 'pcio-vis-member-event' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vr_name"><?php esc_html_e( 'Name', 'pcio-vis-member-event' ); ?></label></th>
                        <td>
                            <input name="vr_name" id="vr_name" type="text" class="regular-text" required
                                   value="<?php echo esc_attr( $edit_role['name'] ?? '' ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Capabilities', 'pcio-vis-member-event' ); ?></th>
                        <td>
                            <?php foreach ( PCIO_VIS_Caps::all_caps() as $cap ) :
                                $checked = $edit_role && in_array( $cap, $edit_role['caps'], true );
                            ?>
                            <label style="display:block;margin-bottom:4px">
                                <input type="checkbox" name="vr_caps[]" value="<?php echo esc_attr( $cap ); ?>"
                                       <?php checked( $checked ); ?>>
                                <?php echo esc_html( PCIO_VIS_Caps::cap_labels()[ $cap ] ?? $cap ); ?>
                                <code style="font-size:11px;color:#888;margin-left:6px"><?php echo esc_html( $cap ); ?></code>
                            </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button( $edit_role
                    ? __( 'Update role', 'pcio-vis-member-event' )
                    : __( 'Add role', 'pcio-vis-member-event' ),
                    'primary', 'submit', false
                ); ?>
                <?php if ( $edit_role ) : ?>
                    <a href="<?php echo esc_url( $page_url ); ?>" class="button" style="margin-left:8px">
                        <?php esc_html_e( 'Cancel', 'pcio-vis-member-event' ); ?>
                    </a>
                <?php endif; ?>
            </form>

            <hr>

            <!-- Vis roles table -->
            <?php if ( empty( $vis_roles ) ) : ?>
                <p><?php esc_html_e( 'No event-system roles yet.', 'pcio-vis-member-event' ); ?></p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped" style="max-width:760px">
                <thead>
                    <tr>
                        <th style="width:120px"><?php esc_html_e( 'Slug', 'pcio-vis-member-event' ); ?></th>
                        <th style="width:140px"><?php esc_html_e( 'Name', 'pcio-vis-member-event' ); ?></th>
                        <th><?php esc_html_e( 'Capabilities', 'pcio-vis-member-event' ); ?></th>
                        <th style="width:120px"><?php esc_html_e( 'Actions', 'pcio-vis-member-event' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $vis_roles as $vr ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $vr['slug'] ); ?></code></td>
                        <td><?php echo esc_html( $vr['name'] ); ?></td>
                        <td>
                            <?php foreach ( $vr['caps'] as $cap ) : ?>
                                <code style="font-size:11px;margin-right:4px"><?php echo esc_html( $cap ); ?></code>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( add_query_arg( 'edit_vr', $vr['id'], $page_url ) ); ?>">
                                <?php esc_html_e( 'Edit', 'pcio-vis-member-event' ); ?>
                            </a>
                            &nbsp;|
                            <form method="post" action="<?php echo esc_url( $page_url ); ?>"
                                  style="display:inline"
                                  onsubmit="return confirm('<?php echo esc_js( __( 'Delete this event-system role?', 'pcio-vis-member-event' ) ); ?>')">
                                <?php wp_nonce_field( 'pcio_me_vis_roles', 'pcio_me_vr_nonce' ); ?>
                                <input type="hidden" name="pcio_me_vr_action" value="delete-vis-role">
                                <input type="hidden" name="vr_id" value="<?php echo (int) $vr['id']; ?>">
                                <button type="submit" class="button-link" style="color:#b32d2e">
                                    <?php esc_html_e( 'Delete', 'pcio-vis-member-event' ); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

        </div>
        <?php
    }

    // ── Sync Jobs admin page ──────────────────────────────────────

    public function render_sync_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pcio-vis-member-event' ) );
        }

        $notice   = '';
        $action   = sanitize_key( $_POST['pcio_me_sync_action'] ?? '' );
        $page_url = admin_url( 'admin.php?page=pcio-me-sync' );

        // ── Handle CRUD ───────────────────────────────────────────
        if ( $action && isset( $_POST['pcio_me_sync_nonce'] ) &&
             wp_verify_nonce( sanitize_key( $_POST['pcio_me_sync_nonce'] ), 'pcio_me_sync_jobs' ) ) {

            $fields = [
                'name'         => sanitize_text_field(     wp_unslash( $_POST['sj_name']         ?? '' ) ),
                'description'  => sanitize_textarea_field( wp_unslash( $_POST['sj_description']  ?? '' ) ),
                'cron_name'    => sanitize_key(             wp_unslash( $_POST['sj_cron_name']    ?? '' ) ),
                'php_function' => sanitize_text_field(      wp_unslash( $_POST['sj_php_function'] ?? '' ) ),
                'sync_url'     => esc_url_raw(              wp_unslash( $_POST['sj_sync_url']     ?? '' ) ),
                'frequence'    => max( 1, absint(           wp_unslash( $_POST['sj_frequence']    ?? 24 ) ) ),
                'enabled'      => isset( $_POST['sj_enabled'] ) ? 1 : 0,
            ];

            // Security: the callable must be on the registered allow-list.
            // This blocks forged POSTs that try to run an arbitrary function.
            if ( ( $action === 'add' || $action === 'edit' )
                && ! PCIO_VIS_Sync::is_callable_allowed( $fields['php_function'] ) ) {
                $action = '';
                $notice = [
                    'type' => 'error',
                    'msg'  => __( 'Invalid PHP function: it is not a registered sync callable.', 'pcio-vis-member-event' ),
                ];
            }

            if ( $action === 'add' ) {
                if ( $fields['name'] && $fields['cron_name'] && $fields['php_function'] ) {
                    $new_id = PCIO_VIS_Sync_DB::create( $fields );
                    $notice = $new_id
                        ? [ 'type' => 'success', 'msg' => __( 'Sync job added.', 'pcio-vis-member-event' ) ]
                        : [ 'type' => 'error',   'msg' => __( 'Could not add sync job (cron name may already exist).', 'pcio-vis-member-event' ) ];
                } else {
                    $notice = [ 'type' => 'error', 'msg' => __( 'Name, Cron Name and PHP Function are required.', 'pcio-vis-member-event' ) ];
                }

            } elseif ( $action === 'edit' ) {
                $id = absint( wp_unslash( $_POST['sj_id'] ?? 0 ) );
                if ( $id && $fields['name'] && $fields['cron_name'] && $fields['php_function'] ) {
                    // Unschedule the old cron hook if cron_name is changing.
                    $old = PCIO_VIS_Sync_DB::get( $id );
                    if ( $old && $old['cron_name'] !== $fields['cron_name'] ) {
                        PCIO_VIS_Sync::unschedule( $old['cron_name'] );
                    }
                    $ok = PCIO_VIS_Sync_DB::update( $id, $fields );
                    $notice = $ok
                        ? [ 'type' => 'success', 'msg' => __( 'Sync job updated.', 'pcio-vis-member-event' ) ]
                        : [ 'type' => 'error',   'msg' => __( 'Could not update sync job.', 'pcio-vis-member-event' ) ];
                } else {
                    $notice = [ 'type' => 'error', 'msg' => __( 'Name, Cron Name and PHP Function are required.', 'pcio-vis-member-event' ) ];
                }

            } elseif ( $action === 'delete' ) {
                $id = absint( wp_unslash( $_POST['sj_id'] ?? 0 ) );
                if ( $id ) {
                    $old = PCIO_VIS_Sync_DB::get( $id );
                    if ( $old ) {
                        PCIO_VIS_Sync::unschedule( $old['cron_name'] );
                    }
                    $ok = PCIO_VIS_Sync_DB::delete( $id );
                    $notice = $ok
                        ? [ 'type' => 'success', 'msg' => __( 'Sync job deleted.', 'pcio-vis-member-event' ) ]
                        : [ 'type' => 'error',   'msg' => __( 'Could not delete sync job.', 'pcio-vis-member-event' ) ];
                }

            } elseif ( $action === 'toggle' ) {
                $id = absint( wp_unslash( $_POST['sj_id'] ?? 0 ) );
                if ( $id ) {
                    $old = PCIO_VIS_Sync_DB::get( $id );
                    if ( $old ) {
                        $new_enabled = (int) ! (int) $old['enabled'];
                        PCIO_VIS_Sync_DB::update( $id, [ 'enabled' => $new_enabled ] );
                        if ( ! $new_enabled ) {
                            PCIO_VIS_Sync::unschedule( $old['cron_name'] );
                        }
                        $notice = [ 'type' => 'success', 'msg' => $new_enabled
                            ? __( 'Job enabled.', 'pcio-vis-member-event' )
                            : __( 'Job disabled.', 'pcio-vis-member-event' ) ];
                    }
                }
            }
        }

        // ── Load current state ────────────────────────────────────
        $jobs    = PCIO_VIS_Sync_DB::get_all();
        $edit_id = absint( $_GET['edit_id'] ?? 0 );
        $edit_job = $edit_id ? PCIO_VIS_Sync_DB::get( $edit_id ) : null;

        // ── Health check ──────────────────────────────────────────
        $all_ok     = true;
        $now_ts     = time();
        $overdue_names = [];
        foreach ( $jobs as $job ) {
            // Disabled jobs are not expected to run automatically.
            if ( ! (int) $job['enabled'] ) {
                continue;
            }
            $freq_secs = max( 1, (int) $job['frequence'] ) * HOUR_IN_SECONDS;
            if ( ! $job['last_run'] ) {
                $all_ok          = false;
                $overdue_names[] = $job['name'];
            } elseif ( ( $now_ts - (int) strtotime( $job['last_run'] ) ) > $freq_secs * 1.1 ) {
                $all_ok          = false;
                $overdue_names[] = $job['name'];
            }
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Vis — Sync Jobs', 'pcio-vis-member-event' ); ?></h1>

            <?php if ( isset( $_GET['pcio_me_ran'] ) && absint( $_GET['pcio_me_ran'] ) ) : ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Job executed. Refresh to see the updated result.', 'pcio-vis-member-event' ); ?></p>
            </div>
            <?php endif; ?>

            <?php if ( $notice ) : ?>
            <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
                <p><?php echo esc_html( $notice['msg'] ); ?></p>
            </div>
            <?php endif; ?>

            <!-- ── Job list ──────────────────────────────────────── -->
            <h2><?php esc_html_e( 'Scheduled Jobs', 'pcio-vis-member-event' ); ?></h2>

            <?php if ( empty( $jobs ) ) : ?>
                <p><?php esc_html_e( 'No sync jobs registered yet.', 'pcio-vis-member-event' ); ?></p>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:15%"><?php esc_html_e( 'Name', 'pcio-vis-member-event' ); ?></th>
                        <th style="width:14%"><?php esc_html_e( 'Cron Name', 'pcio-vis-member-event' ); ?></th>
                        <th style="width:7%;text-align:center"><?php esc_html_e( 'Freq. (h)', 'pcio-vis-member-event' ); ?></th>
                        <th style="width:10%;text-align:center"><?php esc_html_e( 'Status', 'pcio-vis-member-event' ); ?></th>
                        <th style="width:14%"><?php esc_html_e( 'Last Run', 'pcio-vis-member-event' ); ?></th>
                        <th><?php esc_html_e( 'Result', 'pcio-vis-member-event' ); ?></th>
                        <th style="width:18%"><?php esc_html_e( 'Actions', 'pcio-vis-member-event' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $jobs as $job ) :
                    $freq_secs   = max( 1, (int) $job['frequence'] ) * HOUR_IN_SECONDS;
                    $is_overdue  = (int) $job['enabled'] && (
                        ! $job['last_run']
                        || ( $now_ts - (int) strtotime( $job['last_run'] ) ) > $freq_secs * 1.1
                    );
                    $row_style   = $is_overdue ? 'background:#fff3cd' : '';
                    ?>
                    <tr style="<?php echo esc_attr( $row_style ); ?>">
                        <td>
                            <strong><?php echo esc_html( $job['name'] ); ?></strong>
                            <?php if ( $job['description'] ) : ?>
                                <br><small style="color:#666"><?php echo esc_html( wp_trim_words( $job['description'], 12 ) ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><code><?php echo esc_html( $job['cron_name'] ); ?></code></td>
                        <td style="text-align:center"><?php echo (int) $job['frequence']; ?></td>
                        <td style="text-align:center">
                            <form method="post" action="<?php echo esc_url( $page_url ); ?>" style="display:inline">
                                <?php wp_nonce_field( 'pcio_me_sync_jobs', 'pcio_me_sync_nonce' ); ?>
                                <input type="hidden" name="pcio_me_sync_action" value="toggle">
                                <input type="hidden" name="sj_id" value="<?php echo (int) $job['id']; ?>">
                                <button type="submit" class="button button-small" style="<?php echo (int) $job['enabled']
                                    ? 'color:#155724;background:#d4edda;border-color:#c3e6cb'
                                    : 'color:#856404;background:#fff3cd;border-color:#ffc107'; ?>">
                                    <?php echo (int) $job['enabled']
                                        ? esc_html__( '✓ Enabled', 'pcio-vis-member-event' )
                                        : esc_html__( 'Disabled', 'pcio-vis-member-event' ); ?>
                                </button>
                            </form>
                        </td>
                        <td>
                            <?php if ( $job['last_run'] ) : ?>
                                <?php echo esc_html( get_date_from_gmt( $job['last_run'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?>
                            <?php else : ?>
                                <em><?php esc_html_e( 'Never', 'pcio-vis-member-event' ); ?></em>
                            <?php endif; ?>
                        </td>
                        <td style="word-break:break-word">
                            <?php
                            if ( $job['result'] ) {
                                echo '<span title="' . esc_attr( $job['result'] ) . '">'
                                    . esc_html( wp_trim_words( $job['result'], 20, '…' ) )
                                    . '</span>';
                            } else {
                                echo '<em>' . esc_html__( '—', 'pcio-vis-member-event' ) . '</em>';
                            }
                            ?>
                        </td>
                        <td>
                            <!-- Run Now -->
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                                  style="display:inline"
                                  onsubmit="return confirm('<?php echo esc_js( __( 'Run this job now?', 'pcio-vis-member-event' ) ); ?>')">
                                <?php wp_nonce_field( 'pcio_me_run_sync', 'pcio_me_run_nonce' ); ?>
                                <input type="hidden" name="action"      value="pcio_me_run_sync">
                                <input type="hidden" name="sync_job_id" value="<?php echo (int) $job['id']; ?>">
                                <button type="submit" class="button button-small">
                                    <?php esc_html_e( 'Run now', 'pcio-vis-member-event' ); ?>
                                </button>
                            </form>
                            &nbsp;
                            <!-- Edit -->
                            <a href="<?php echo esc_url( add_query_arg( 'edit_id', $job['id'], $page_url ) ); ?>"
                               class="button button-small">
                                <?php esc_html_e( 'Edit', 'pcio-vis-member-event' ); ?>
                            </a>
                            &nbsp;
                            <!-- Delete -->
                            <form method="post" action="<?php echo esc_url( $page_url ); ?>"
                                  style="display:inline"
                                  onsubmit="return confirm('<?php echo esc_js( __( 'Delete this sync job?', 'pcio-vis-member-event' ) ); ?>')">
                                <?php wp_nonce_field( 'pcio_me_sync_jobs', 'pcio_me_sync_nonce' ); ?>
                                <input type="hidden" name="pcio_me_sync_action" value="delete">
                                <input type="hidden" name="sj_id" value="<?php echo (int) $job['id']; ?>">
                                <button type="submit" class="button button-small" style="color:#b32d2e">
                                    <?php esc_html_e( 'Delete', 'pcio-vis-member-event' ); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <!-- ── Schedule health indicator ─────────────────────── -->
            <div style="margin:16px 0;padding:12px 18px;border-radius:6px;display:inline-flex;align-items:center;gap:10px;
                        <?php echo $all_ok
                            ? 'background:#d4edda;border:1px solid #c3e6cb;color:#155724'
                            : 'background:#f8d7da;border:1px solid #f5c6cb;color:#721c24'; ?>">
                <span style="font-size:22px;line-height:1"><?php echo $all_ok ? '🟢' : '🔴'; ?></span>
                <?php if ( $all_ok ) : ?>
                    <strong><?php esc_html_e( 'All jobs are on schedule.', 'pcio-vis-member-event' ); ?></strong>
                <?php else : ?>
                    <div>
                        <strong><?php esc_html_e( 'One or more jobs are overdue:', 'pcio-vis-member-event' ); ?></strong>
                        <?php echo ' ' . esc_html( implode( ', ', $overdue_names ) ); ?>
                    </div>
                <?php endif; ?>
            </div>

            <hr>

            <!-- ── Add / Edit form ───────────────────────────────── -->
            <h2><?php echo $edit_job
                ? esc_html__( 'Edit Sync Job', 'pcio-vis-member-event' )
                : esc_html__( 'Add Sync Job', 'pcio-vis-member-event' ); ?></h2>

            <form method="post" action="<?php echo esc_url( $page_url ); ?>">
                <?php wp_nonce_field( 'pcio_me_sync_jobs', 'pcio_me_sync_nonce' ); ?>
                <input type="hidden" name="pcio_me_sync_action" value="<?php echo $edit_job ? 'edit' : 'add'; ?>">
                <?php if ( $edit_job ) : ?>
                    <input type="hidden" name="sj_id" value="<?php echo (int) $edit_job['id']; ?>">
                <?php endif; ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="sj_name"><?php esc_html_e( 'Name', 'pcio-vis-member-event' ); ?></label>
                        </th>
                        <td>
                            <input name="sj_name" id="sj_name" type="text"
                                   class="regular-text" required
                                   value="<?php echo esc_attr( $edit_job['name'] ?? '' ); ?>">
                            <p class="description"><?php esc_html_e( 'Short human-readable label shown in the list.', 'pcio-vis-member-event' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sj_description"><?php esc_html_e( 'Description', 'pcio-vis-member-event' ); ?></label>
                        </th>
                        <td>
                            <textarea name="sj_description" id="sj_description"
                                      class="large-text" rows="3"><?php echo esc_textarea( $edit_job['description'] ?? '' ); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sj_cron_name"><?php esc_html_e( 'Cron Name', 'pcio-vis-member-event' ); ?></label>
                        </th>
                        <td>
                            <input name="sj_cron_name" id="sj_cron_name" type="text"
                                   class="regular-text" required
                                   pattern="[a-z0-9_]+"
                                   value="<?php echo esc_attr( $edit_job['cron_name'] ?? '' ); ?>">
                            <p class="description"><?php esc_html_e( 'Unique slug (lowercase, numbers, underscores). Used as the WP-cron hook name suffix.', 'pcio-vis-member-event' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sj_php_function"><?php esc_html_e( 'PHP Function', 'pcio-vis-member-event' ); ?></label>
                        </th>
                        <td>
                            <?php $current_callable = $edit_job['php_function'] ?? ''; ?>
                            <select name="sj_php_function" id="sj_php_function" class="regular-text" required>
                                <option value=""><?php esc_html_e( '— Select a job —', 'pcio-vis-member-event' ); ?></option>
                                <?php foreach ( PCIO_VIS_Sync::allowed_callables() as $callable => $label ) : ?>
                                    <option value="<?php echo esc_attr( $callable ); ?>"
                                        <?php selected( $current_callable, $callable ); ?>>
                                        <?php echo esc_html( $label ); ?> (<?php echo esc_html( $callable ); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Select a registered sync callable. Extensions add their own jobs to this list.', 'pcio-vis-member-event' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sj_sync_url"><?php esc_html_e( 'Sync URL', 'pcio-vis-member-event' ); ?></label>
                        </th>
                        <td>
                            <input name="sj_sync_url" id="sj_sync_url" type="url"
                                   class="regular-text"
                                   placeholder="https://…"
                                   value="<?php echo esc_attr( $edit_job['sync_url'] ?? '' ); ?>">
                            <p class="description"><?php esc_html_e( 'Optional external URL the job synchronises with (informational; your PHP function reads this from the $job array).', 'pcio-vis-member-event' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sj_frequence"><?php esc_html_e( 'Frequency (hours)', 'pcio-vis-member-event' ); ?></label>
                        </th>
                        <td>
                            <input name="sj_frequence" id="sj_frequence" type="number"
                                   class="small-text" min="1" max="8760" required
                                   value="<?php echo (int) ( $edit_job['frequence'] ?? 24 ); ?>">
                            <p class="description"><?php esc_html_e( 'How often (in whole hours) this job should run. Minimum 1.', 'pcio-vis-member-event' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="sj_enabled"><?php esc_html_e( 'Enabled', 'pcio-vis-member-event' ); ?></label>
                        </th>
                        <td>
                            <label>
                                <input name="sj_enabled" id="sj_enabled" type="checkbox" value="1"
                                       <?php checked( (int) ( $edit_job['enabled'] ?? 0 ), 1 ); ?>>
                                <?php esc_html_e( 'Run automatically on the set schedule', 'pcio-vis-member-event' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'When unchecked the job can still be triggered manually with “Run now”.', 'pcio-vis-member-event' ); ?></p>
                        </td>
                    </tr>
                </table>

                <?php
                submit_button(
                    $edit_job
                        ? __( 'Update job', 'pcio-vis-member-event' )
                        : __( 'Add job', 'pcio-vis-member-event' )
                );
                if ( $edit_job ) :
                    echo '<a href="' . esc_url( $page_url ) . '" class="button" style="margin-left:8px">'
                        . esc_html__( 'Cancel', 'pcio-vis-member-event' ) . '</a>';
                endif;
                ?>
            </form>

            <?php
            /** Action: pcio_me_sync_page_after — extensions inject extra settings here. */
            do_action( 'pcio_me_sync_page_after' );
            ?>
        </div>
        <?php
    }

    // ── Shortcode reference page ──────────────────────────────────

    public function render_shortcodes_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pcio-vis-member-event' ) );
        }

        // Collect known document type slugs for the live examples.
        $doc_types  = PCIO_VIS_Docs_DB::get_all_types();
        $first_type = ! empty( $doc_types ) ? esc_attr( $doc_types[0]['name'] ) : 'annual-report';

        ?>
        <div class="wrap pcio-sc-wrap">
            <h1><?php esc_html_e( 'Vis — Shortcode Reference', 'pcio-vis-member-event' ); ?></h1>

            <div class="pcio-sc-layout">

            <!-- ── Sidebar nav ──────────────────────────────────── -->
            <nav class="pcio-sc-sidebar" id="pcio-sc-nav" aria-label="<?php esc_attr_e( 'Shortcode navigation', 'pcio-vis-member-event' ); ?>">
                <div class="pcio-sc-nav-group">
                    <div class="pcio-sc-nav-heading"><?php esc_html_e( 'PCIO VIS Member Event', 'pcio-vis-member-event' ); ?></div>
                    <a href="#sc-members"      class="pcio-sc-nav-item">[pcio_me_members]</a>
                    <a href="#sc-edit-profile" class="pcio-sc-nav-item">[pcio_me_edit_profile]</a>
                    <a href="#sc-documents"    class="pcio-sc-nav-item">[pcio_me_documents]</a>
                    <a href="#sc-event"        class="pcio-sc-nav-item">[pcio_me_event]</a>
                    <a href="#sc-events"       class="pcio-sc-nav-item">[pcio_me_events]</a>
                    <a href="#sc-upcoming-events" class="pcio-sc-nav-item">[pcio_me_upcoming_events]</a>
                    <a href="#sc-event-roller" class="pcio-sc-nav-item">[pcio_me_event_roller]</a>
                    <a href="#sc-newsletters"  class="pcio-sc-nav-item">[pcio_me_newsletters]</a>
                    <a href="#sc-member-count" class="pcio-sc-nav-item">[pcio_me_member_count]</a>
                    <a href="#sc-member-signup" class="pcio-sc-nav-item">[pcio_me_member_signup]</a>
                    <a href="#sc-sponsor"      class="pcio-sc-nav-item pcio-sc-nav-plain">pcio-sponsor-link</a>
                    <a href="#sc-rolling-text" class="pcio-sc-nav-item">[pcio_me_rolling_text]</a>
                </div>
                <?php do_action( 'pcio_me_shortcodes_nav_items' ); ?>
            </nav>

            <!-- ── Content ─────────────────────────────────────── -->
            <div class="pcio-sc-content">

            <!-- ─────────────────────────────────────────────────── -->
            <h2 id="sc-members"><?php esc_html_e( '[pcio_me_members]', 'pcio-vis-member-event' ); ?></h2>
            <p><?php esc_html_e( 'Renders a read-only table of all members. No login required.', 'pcio-vis-member-event' ); ?></p>

            <table class="pcio-sc-table wp-list-table widefat">
                <thead><tr>
                    <th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-member-event' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'pcio-vis-member-event' ); ?></th>
                </tr></thead>
                <tbody>
                    <tr><td colspan="4"><em><?php esc_html_e( 'No attributes.', 'pcio-vis-member-event' ); ?></em></td></tr>
                </tbody>
            </table>

            <strong><?php esc_html_e( 'Example', 'pcio-vis-member-event' ); ?></strong>
            <code class="pcio-sc-block">[pcio_me_members]</code>

            <hr style="margin:32px 0">

            <!-- ─────────────────────────────────────────────────── -->
            <h2 id="sc-edit-profile"><?php esc_html_e( '[pcio_me_edit_profile]', 'pcio-vis-member-event' ); ?></h2>
            <p><?php esc_html_e( 'Renders an inline edit form for the currently logged-in member, allowing them to update their own contact details. Requires the vis_member capability; shows a message if no member record is linked to the account.', 'pcio-vis-member-event' ); ?></p>

            <table class="pcio-sc-table wp-list-table widefat">
                <thead><tr>
                    <th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-member-event' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'pcio-vis-member-event' ); ?></th>
                </tr></thead>
                <tbody>
                    <tr><td colspan="4"><em><?php esc_html_e( 'No attributes.', 'pcio-vis-member-event' ); ?></em></td></tr>
                </tbody>
            </table>

            <p style="margin-top:8px">
                <strong><?php esc_html_e( 'Required capability:', 'pcio-vis-member-event' ); ?></strong>
                <code class="pcio-sc-cap">vis_member</code>
            </p>

            <strong style="display:block;margin-top:12px"><?php esc_html_e( 'Example', 'pcio-vis-member-event' ); ?></strong>
            <code class="pcio-sc-block">[pcio_me_edit_profile]</code>

            <hr style="margin:32px 0">

            <!-- ─────────────────────────────────────────────────── -->
            <h2 id="sc-documents"><?php esc_html_e( '[pcio_me_documents]', 'pcio-vis-member-event' ); ?></h2>
            <p><?php esc_html_e( 'Renders a list of documents filtered by document type. No login required.', 'pcio-vis-member-event' ); ?></p>

            <table class="pcio-sc-table wp-list-table widefat">
                <thead><tr>
                    <th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-member-event' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'pcio-vis-member-event' ); ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><code class="pcio-sc-code">type</code> <span style="color:#ef4444">*</span></td>
                        <td><?php esc_html_e( 'string', 'pcio-vis-member-event' ); ?></td>
                        <td>—</td>
                        <td>
                            <?php esc_html_e( 'Document type slug (required). Must match the Name column in Document Types.', 'pcio-vis-member-event' ); ?>
                            <?php if ( ! empty( $doc_types ) ) : ?>
                            <p class="pcio-sc-note">
                                <?php esc_html_e( 'Available types:', 'pcio-vis-member-event' ); ?>
                                <?php foreach ( $doc_types as $dt ) :
                                    echo '<code class="pcio-sc-code">' . esc_html( $dt['name'] ) . '</code> ';
                                endforeach; ?>
                            </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><code class="pcio-sc-code">limit</code></td>
                        <td><?php esc_html_e( 'integer', 'pcio-vis-member-event' ); ?></td>
                        <td><code class="pcio-sc-code">0</code></td>
                        <td><?php esc_html_e( 'Maximum number of documents to show. 0 = all.', 'pcio-vis-member-event' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <strong><?php esc_html_e( 'Examples', 'pcio-vis-member-event' ); ?></strong>
            <code class="pcio-sc-block">[pcio_me_documents type="<?php echo esc_html( $first_type ); ?>"]
[pcio_me_documents type="<?php echo esc_html( $first_type ); ?>" limit="5"]</code>

            <hr style="margin:32px 0">

            <!-- ─────────────────────────────────────────────────── -->
            <h2 id="sc-event"><?php esc_html_e( '[pcio_me_event]', 'pcio-vis-member-event' ); ?></h2>
            <p><?php esc_html_e( 'Renders an event card with cover image, title, date, location, description, and sign-up buttons for logged-in members.', 'pcio-vis-member-event' ); ?></p>

            <table class="pcio-sc-table wp-list-table widefat">
                <thead><tr>
                    <th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-member-event' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'pcio-vis-member-event' ); ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><code class="pcio-sc-code">id</code> <span style="color:#ef4444">*</span></td>
                        <td><?php esc_html_e( 'integer', 'pcio-vis-member-event' ); ?></td>
                        <td>—</td>
                        <td><?php esc_html_e( 'The numeric ID of the event (required). Find it in the URL when viewing an event at /vis/events/{id}/.', 'pcio-vis-member-event' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <strong><?php esc_html_e( 'What is rendered', 'pcio-vis-member-event' ); ?></strong>
            <ul style="list-style:disc;margin-left:24px;margin-top:8px">
                <li><?php esc_html_e( 'Cover image (if one has been uploaded via the Photos tab on the event detail page)', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'Event title, date / time range, and location', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'Event description (rich text)', 'pcio-vis-member-event' ); ?></li>
                <li>
                    <?php esc_html_e( 'Sign-up buttons (Joining / Interested / Not joining) — shown only to logged-in users with the', 'pcio-vis-member-event' ); ?>
                    <code class="pcio-sc-cap">vis_member</code>
                    <?php esc_html_e( 'capability who are also linked to a member record.', 'pcio-vis-member-event' ); ?>
                </li>
            </ul>

            <strong style="display:block;margin-top:16px"><?php esc_html_e( 'Example', 'pcio-vis-member-event' ); ?></strong>
            <code class="pcio-sc-block">[pcio_me_event id="42"]</code>

            <hr style="margin:32px 0">

            <!-- ─────────────────────────────────────────────────── -->
            <h2 id="sc-events"><?php esc_html_e( '[pcio_me_events]', 'pcio-vis-member-event' ); ?></h2>
            <p><?php esc_html_e( 'Shows all upcoming events as a responsive grid of tiles. The number of tiles per row adapts automatically to the screen width. Each tile shows the square thumbnail (falling back to the banner image), a date badge, the title, date / time and location, and links to the public event page at /events/{slug}/. No login required.', 'pcio-vis-member-event' ); ?></p>

            <table class="pcio-sc-table wp-list-table widefat">
                <thead><tr>
                    <th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-member-event' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'pcio-vis-member-event' ); ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><code class="pcio-sc-code">limit</code></td>
                        <td><?php esc_html_e( 'integer', 'pcio-vis-member-event' ); ?></td>
                        <td><code class="pcio-sc-code">100</code></td>
                        <td><?php esc_html_e( 'Maximum number of upcoming events to display.', 'pcio-vis-member-event' ); ?></td>
                    </tr>
                    <tr>
                        <td><code class="pcio-sc-code">search</code></td>
                        <td><?php esc_html_e( 'yes / no', 'pcio-vis-member-event' ); ?></td>
                        <td><code class="pcio-sc-code">yes</code></td>
                        <td><?php esc_html_e( 'Whether to show the live search box above the tiles for filtering by title and location.', 'pcio-vis-member-event' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <strong><?php esc_html_e( 'What is rendered', 'pcio-vis-member-event' ); ?></strong>
            <ul style="list-style:disc;margin-left:24px;margin-top:8px">
                <li><?php esc_html_e( 'A responsive grid of tiles — the number of columns adjusts to the available width.', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'Only upcoming events are listed, ordered by start date. Past days are excluded.', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'Each tile uses the square Thumb image, falling back to the Banner image, then a coloured placeholder using the event colour.', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'An optional search box filters the tiles instantly as the visitor types.', 'pcio-vis-member-event' ); ?></li>
            </ul>

            <strong style="display:block;margin-top:16px"><?php esc_html_e( 'Examples', 'pcio-vis-member-event' ); ?></strong>
            <code class="pcio-sc-block">[pcio_me_events]
[pcio_me_events limit="12" search="no"]</code>

            <hr style="margin:32px 0">

            <!-- ─────────────────────────────────────────────────── -->
            <h2 id="sc-event-roller"><?php esc_html_e( '[pcio_me_event_roller]', 'pcio-vis-member-event' ); ?></h2>
            <p><?php esc_html_e( 'Rotating front-page slider that automatically shows the next upcoming events from the calendar. Each slide shows the cover image, an overlaid title and date, and a Read More button linking to the public event page. No login required.', 'pcio-vis-member-event' ); ?></p>

            <table class="pcio-sc-table wp-list-table widefat">
                <thead><tr>
                    <th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-member-event' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'pcio-vis-member-event' ); ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><code class="pcio-sc-code">limit</code></td>
                        <td><?php esc_html_e( 'integer', 'pcio-vis-member-event' ); ?></td>
                        <td><code class="pcio-sc-code">3</code></td>
                        <td><?php esc_html_e( 'How many upcoming events to include in the slider.', 'pcio-vis-member-event' ); ?></td>
                    </tr>
                    <tr>
                        <td><code class="pcio-sc-code">interval</code></td>
                        <td><?php esc_html_e( 'integer', 'pcio-vis-member-event' ); ?></td>
                        <td><code class="pcio-sc-code">10</code></td>
                        <td><?php esc_html_e( 'Seconds between automatic slide changes. The timer resets when a visitor uses the arrows.', 'pcio-vis-member-event' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <strong><?php esc_html_e( 'How events are chosen', 'pcio-vis-member-event' ); ?></strong>
            <ul style="list-style:disc;margin-left:24px;margin-top:8px">
                <li><?php esc_html_e( 'Events are selected automatically — the next events ordered by start date.', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'Only the date part of the start time is compared, so events happening today are still shown. Past days are excluded.', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'Events without a cover image show a coloured placeholder using the event colour.', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'Visitors can move between slides with the left / right arrows; otherwise it advances on its own.', 'pcio-vis-member-event' ); ?></li>
            </ul>

            <strong style="display:block;margin-top:16px"><?php esc_html_e( 'Examples', 'pcio-vis-member-event' ); ?></strong>
            <code class="pcio-sc-block">[pcio_me_event_roller]
[pcio_me_event_roller limit="5" interval="8"]</code>

            <hr style="margin:28px 0">

            <h2 id="sc-upcoming-events"><?php esc_html_e( '[pcio_me_upcoming_events]', 'pcio-vis-member-event' ); ?></h2>
            <p><?php esc_html_e( 'A compact “agenda” list of upcoming events grouped by month. Each month is shown as a heading, followed by its events as “day. title”, with the title linking to the public event page. Month names follow the site language (e.g. September / Oktober / November in Danish). No login required.', 'pcio-vis-member-event' ); ?></p>

            <table class="pcio-sc-table wp-list-table widefat">
                <thead><tr>
                    <th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-member-event' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'pcio-vis-member-event' ); ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><code class="pcio-sc-code">months</code></td>
                        <td><?php esc_html_e( 'integer', 'pcio-vis-member-event' ); ?></td>
                        <td><code class="pcio-sc-code">3</code></td>
                        <td><?php esc_html_e( 'How many months ahead to include, counting the current month (3 = this month plus the next two).', 'pcio-vis-member-event' ); ?></td>
                    </tr>
                    <tr>
                        <td><code class="pcio-sc-code">limit</code></td>
                        <td><?php esc_html_e( 'integer', 'pcio-vis-member-event' ); ?></td>
                        <td><code class="pcio-sc-code">0</code></td>
                        <td><?php esc_html_e( 'Optional hard cap on the number of events shown. 0 means no cap — every event in the window is listed.', 'pcio-vis-member-event' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <strong><?php esc_html_e( 'How events are chosen', 'pcio-vis-member-event' ); ?></strong>
            <ul style="list-style:disc;margin-left:24px;margin-top:8px">
                <li><?php esc_html_e( 'Events from the start of today up to the end of the month window are listed, ordered by start date.', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'Events are grouped under their start month; the year is added to the heading only when the window crosses into another year.', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'Only the date part of the start time is compared, so events happening today are still shown.', 'pcio-vis-member-event' ); ?></li>
            </ul>

            <strong style="display:block;margin-top:16px"><?php esc_html_e( 'Examples', 'pcio-vis-member-event' ); ?></strong>
            <code class="pcio-sc-block">[pcio_me_upcoming_events]
[pcio_me_upcoming_events months="2" limit="10"]</code>

            <hr style="margin:28px 0">

            <h2 id="sc-newsletters"><?php esc_html_e( '[pcio_me_newsletters]', 'pcio-vis-member-event' ); ?></h2>
            <p><?php esc_html_e( 'Shows a list of the most recently generated newsletter PDFs, each linking to the public PDF document. Newsletters are produced from the Mails screen by ticking “This mail is a newsletter” and clicking “Generate newsletter PDF”.', 'pcio-vis-member-event' ); ?></p>

            <table class="pcio-sc-table wp-list-table widefat">
                <thead><tr>
                    <th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-member-event' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'pcio-vis-member-event' ); ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><code class="pcio-sc-code">limit</code></td>
                        <td><?php esc_html_e( 'integer', 'pcio-vis-member-event' ); ?></td>
                        <td><code class="pcio-sc-code">12</code></td>
                        <td><?php esc_html_e( 'How many recent newsletters to list.', 'pcio-vis-member-event' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <strong style="display:block;margin-top:16px"><?php esc_html_e( 'Examples', 'pcio-vis-member-event' ); ?></strong>
            <code class="pcio-sc-block">[pcio_me_newsletters]
[pcio_me_newsletters limit="6"]</code>

            <hr style="margin:32px 0">

            <!-- ─────────────────────────────────────────────────── -->
            <h2 id="sc-member-count"><?php esc_html_e( '[pcio_me_member_count]', 'pcio-vis-member-event' ); ?></h2>
            <p><?php esc_html_e( 'Displays the current total number of members as an animated count-up number. Each page render is recorded as a front-page view in the Statistics dashboard. Works in any page builder text or code module (e.g. Divi Text Module).', 'pcio-vis-member-event' ); ?></p>

            <table class="pcio-sc-table wp-list-table widefat">
                <thead><tr>
                    <th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-member-event' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'pcio-vis-member-event' ); ?></th>
                </tr></thead>
                <tbody>
                    <tr><td colspan="4"><em><?php esc_html_e( 'No attributes.', 'pcio-vis-member-event' ); ?></em></td></tr>
                </tbody>
            </table>

            <strong style="display:block;margin-top:12px"><?php esc_html_e( 'Note', 'pcio-vis-member-event' ); ?></strong>
            <p class="pcio-sc-note">
                <?php esc_html_e( 'To track sponsor link clicks from this page, add the CSS class ', 'pcio-vis-member-event' ); ?>
                <code class="pcio-sc-code">pcio-sponsor-link</code>
                <?php esc_html_e( 'to any anchor tag in your content. Clicks are recorded automatically and shown under Vis → Statistics.', 'pcio-vis-member-event' ); ?>
            </p>

            <strong style="display:block;margin-top:12px"><?php esc_html_e( 'Example', 'pcio-vis-member-event' ); ?></strong>
            <code class="pcio-sc-block">[pcio_me_member_count]</code>

            <hr style="margin:32px 0">

            <!-- ─────────────────────────────────────────────────── -->
            <h2 id="sc-sponsor" style="color:inherit"><?php esc_html_e( 'Sponsor link tracking', 'pcio-vis-member-event' ); ?></h2>
            <p>
                <?php esc_html_e( 'Every click on a front-end link that carries the CSS class ', 'pcio-vis-member-event' ); ?>
                <code class="pcio-sc-code">pcio-sponsor-link</code>
                <?php esc_html_e( 'is captured in the browser and sent to the server. Totals appear on the Statistics dashboard under', 'pcio-vis-member-event' ); ?>
                <strong><?php esc_html_e( 'Vis → Statistics → Sponsor link clicks', 'pcio-vis-member-event' ); ?></strong>.
                <?php esc_html_e( 'No plugin configuration is needed — just add the class to the link in your page editor.', 'pcio-vis-member-event' ); ?>
            </p>

            <table class="pcio-sc-table wp-list-table widefat">
                <thead><tr>
                    <th style="width:22%"><?php esc_html_e( 'What to add', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:12%"><?php esc_html_e( 'Where', 'pcio-vis-member-event' ); ?></th>
                    <th><?php esc_html_e( 'Effect', 'pcio-vis-member-event' ); ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><code class="pcio-sc-code">class="pcio-sponsor-link"</code></td>
                        <td><?php esc_html_e( 'Any <a> tag, or any wrapper element (div, section…) that contains an <a>', 'pcio-vis-member-event' ); ?></td>
                        <td><?php esc_html_e( 'Records a click entry (URL + timestamp) whenever a visitor clicks the element. The URL is taken from the <a href> — either the element itself or the first anchor found inside it. Navigation is not interrupted.', 'pcio-vis-member-event' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <strong style="display:block;margin-top:16px"><?php esc_html_e( 'How to add the class in WordPress', 'pcio-vis-member-event' ); ?></strong>
            <ol style="margin:.5rem 0 1rem;padding-left:1.25rem;font-size:.9rem">
                <li><?php esc_html_e( 'In the block editor: select the link text, open the link toolbar, click the three-dot menu → "Edit link" → expand "Advanced" → add the class in the "CSS class" field.', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'In the classic editor or a page builder code/HTML module: switch to HTML view and add the class directly on the <a> element.', 'pcio-vis-member-event' ); ?></li>
                <li>
                    <?php esc_html_e( 'In Divi — plain text link: select the link → link settings → CSS class field.', 'pcio-vis-member-event' ); ?>
                </li>
                <li>
                    <?php esc_html_e( 'In Divi — Image or other module with a link: open the module settings → Advanced tab → CSS ID & Classes → CSS Class field. The class goes on the module wrapper div, which contains the <a> Divi generates automatically.', 'pcio-vis-member-event' ); ?>
                </li>
            </ol>

            <strong><?php esc_html_e( 'Examples (HTML source)', 'pcio-vis-member-event' ); ?></strong>
            <code class="pcio-sc-block">&lt;!-- Plain anchor (block editor / classic editor / code module) --&gt;
&lt;a href="https://www.sponsor-example.com" class="pcio-sponsor-link"&gt;Our main sponsor&lt;/a&gt;

&lt;!-- Divi Image module: class is on the wrapper div, &lt;a&gt; is generated inside by Divi --&gt;
&lt;div class="et_pb_image pcio-sponsor-link"&gt;
  &lt;a href="https://www.sponsor-example.com" target="_blank"&gt;
    &lt;img src="sponsor-logo.png" alt="Sponsor"&gt;
  &lt;/a&gt;
&lt;/div&gt;</code>

            <p style="margin-top:1rem">
                <strong><?php esc_html_e( 'Multiple sponsors:', 'pcio-vis-member-event' ); ?></strong>
                <?php esc_html_e( 'Each link\'s full URL is stored with every click, so the Statistics page can group and rank sponsors by click count automatically — no extra configuration needed.', 'pcio-vis-member-event' ); ?>
            </p>

            <hr style="margin:32px 0">

            <!-- ─────────────────────────────────────────────────── -->
            <h2 id="sc-member-signup"><?php esc_html_e( '[pcio_me_member_signup]', 'pcio-vis-member-event' ); ?></h2>
            <p>
                <?php esc_html_e( 'Renders a public registration form for new members. With no payment extension active the member is created immediately and a welcome email is sent. Active extensions can inject extra fields and add a payment (checkout) step on submission.', 'pcio-vis-member-event' ); ?>
            </p>
            <p>
                <?php esc_html_e( 'The welcome email is sent from the system mail template labelled "member_welcome" under Vis → Mails. Edit the subject and body there, using the tag chips to insert dynamic values.', 'pcio-vis-member-event' ); ?>
            </p>

            <table class="pcio-sc-table wp-list-table widefat">
                <thead><tr>
                    <th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-member-event' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'pcio-vis-member-event' ); ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><code class="pcio-sc-code">cancel_url</code></td>
                        <td><?php esc_html_e( 'string (URL)', 'pcio-vis-member-event' ); ?></td>
                        <td><code class="pcio-sc-code">""</code></td>
                        <td><?php esc_html_e( 'URL the visitor is sent to if they abandon the Stripe payment step. Relative paths are converted to absolute HTTPS automatically. Ignored when no payment extension is active.', 'pcio-vis-member-event' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <strong><?php esc_html_e( 'Core fields (always shown)', 'pcio-vis-member-event' ); ?></strong>
            <ul style="list-style:disc;margin-left:24px;margin-top:8px">
                <li><?php esc_html_e( 'Full name (required)', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'Email address (required, must be unique)', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'Phone number (optional)', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'Address (optional)', 'pcio-vis-member-event' ); ?></li>
            </ul>

            <?php
            /**
             * Extensions contribute descriptions of the extra signup fields they inject.
             * Each item is a plain-text field description.
             *
             * @param string[] $fields
             */
            $pcio_me_signup_help_fields = (array) apply_filters( 'pcio_me_signup_help_fields', array() );
            if ( ! empty( $pcio_me_signup_help_fields ) ) :
                ?>
                <strong style="display:block;margin-top:12px"><?php esc_html_e( 'Additional fields (added by active extensions)', 'pcio-vis-member-event' ); ?></strong>
                <ul style="list-style:disc;margin-left:24px;margin-top:8px">
                    <?php foreach ( $pcio_me_signup_help_fields as $pcio_me_field_desc ) : ?>
                        <li><?php echo esc_html( $pcio_me_field_desc ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <strong style="display:block;margin-top:12px"><?php esc_html_e( 'Welcome email tags', 'pcio-vis-member-event' ); ?></strong>
            <table class="pcio-sc-table wp-list-table widefat" style="margin-top:8px">
                <thead><tr>
                    <th style="width:26%"><?php esc_html_e( 'Tag', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:20%"><?php esc_html_e( 'Provided by', 'pcio-vis-member-event' ); ?></th>
                    <th><?php esc_html_e( 'Value', 'pcio-vis-member-event' ); ?></th>
                </tr></thead>
                <tbody>
                    <tr><td><code class="pcio-sc-code">{name}</code></td><td><?php esc_html_e( 'Core', 'pcio-vis-member-event' ); ?></td><td><?php esc_html_e( "Member's full name", 'pcio-vis-member-event' ); ?></td></tr>
                    <tr><td><code class="pcio-sc-code">{member_number}</code></td><td><?php esc_html_e( 'Core', 'pcio-vis-member-event' ); ?></td><td><?php esc_html_e( 'Assigned member number', 'pcio-vis-member-event' ); ?></td></tr>
                    <tr><td><code class="pcio-sc-code">{site_name}</code></td><td><?php esc_html_e( 'Core', 'pcio-vis-member-event' ); ?></td><td><?php esc_html_e( 'WordPress site title', 'pcio-vis-member-event' ); ?></td></tr>
                    <tr><td><code class="pcio-sc-code">{login_url}</code></td><td><?php esc_html_e( 'Core', 'pcio-vis-member-event' ); ?></td><td><?php esc_html_e( 'One-time set-password URL for new accounts; login URL for existing accounts', 'pcio-vis-member-event' ); ?></td></tr>
                    <?php
                    /**
                     * Extensions contribute welcome-email tag rows they provide.
                     * Each row: array( 'tag' => '{slug}', 'source' => 'plugin-slug', 'desc' => 'text' ).
                     *
                     * @param array<int,array{tag:string,source:string,desc:string}> $rows
                     */
                    $pcio_me_signup_help_tags = (array) apply_filters( 'pcio_me_signup_help_tags', array() );
                    foreach ( $pcio_me_signup_help_tags as $pcio_me_tag_row ) :
                        ?>
                        <tr>
                            <td><code class="pcio-sc-code"><?php echo esc_html( $pcio_me_tag_row['tag'] ?? '' ); ?></code></td>
                            <td><?php echo esc_html( $pcio_me_tag_row['source'] ?? '' ); ?></td>
                            <td><?php echo esc_html( $pcio_me_tag_row['desc'] ?? '' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="pcio-sc-note"><?php esc_html_e( 'Extension tags resolve to an empty string when the corresponding plugin is not active.', 'pcio-vis-member-event' ); ?></p>

            <?php
            /**
             * Extensions contribute extra explanatory notes for the signup shortcode
             * (e.g. backward-compat aliases). Each note may contain limited inline HTML.
             *
             * @param string[] $notes
             */
            $pcio_me_signup_help_notes = (array) apply_filters( 'pcio_me_signup_help_notes', array() );
            if ( ! empty( $pcio_me_signup_help_notes ) ) {
                $pcio_me_note_allowed = array(
                    'strong' => array(),
                    'em'     => array(),
                    'br'     => array(),
                    'a'      => array( 'href' => true, 'target' => true, 'rel' => true ),
                    'code'   => array( 'class' => true ),
                );
                foreach ( $pcio_me_signup_help_notes as $pcio_me_note_html ) {
                    echo '<p style="margin-top:12px">' . wp_kses( $pcio_me_note_html, $pcio_me_note_allowed ) . '</p>';
                }
            }
            ?>

            <strong style="display:block;margin-top:16px"><?php esc_html_e( 'Examples', 'pcio-vis-member-event' ); ?></strong>
            <code class="pcio-sc-block">[pcio_me_member_signup]
[pcio_me_member_signup cancel_url="/join/"]</code>

            <hr style="margin:32px 0">

            <!-- ─────────────────────────────────────────────────── -->
            <h2 id="sc-rolling-text"><?php esc_html_e( '[pcio_me_rolling_text]', 'pcio-vis-member-event' ); ?></h2>
            <p>
                <?php esc_html_e( 'Renders a scrolling marquee banner using texts that are scheduled in the Rolling Texts table. Only entries whose start/stop window includes the current server time are shown. Multiple simultaneous entries are joined by a long dash (—).', 'pcio-vis-member-event' ); ?>
            </p>
            <p>
                <?php esc_html_e( 'Manage entries at Vis → Calendar → Rolling Texts tab. You can have as many independent banners as you like by giving each a unique Roller ID and using a matching id attribute in the shortcode.', 'pcio-vis-member-event' ); ?>
            </p>

            <table class="pcio-sc-table wp-list-table widefat">
                <thead><tr>
                    <th style="width:22%"><?php esc_html_e( 'Attribute', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:12%"><?php esc_html_e( 'Type', 'pcio-vis-member-event' ); ?></th>
                    <th style="width:14%"><?php esc_html_e( 'Default', 'pcio-vis-member-event' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'pcio-vis-member-event' ); ?></th>
                </tr></thead>
                <tbody>
                    <tr>
                        <td><code class="pcio-sc-code">id</code> <span style="color:#ef4444">*</span></td>
                        <td><?php esc_html_e( 'string', 'pcio-vis-member-event' ); ?></td>
                        <td>—</td>
                        <td><?php esc_html_e( 'Roller ID (required). Must match the Roller ID of at least one entry in the Rolling Texts table. Use lowercase letters, numbers and hyphens — e.g. "homepage", "events-sidebar".', 'pcio-vis-member-event' ); ?></td>
                    </tr>
                </tbody>
            </table>

            <strong><?php esc_html_e( 'Behaviour', 'pcio-vis-member-event' ); ?></strong>
            <ul style="list-style:disc;margin-left:24px;margin-top:8px">
                <li><?php esc_html_e( 'When no entry is active the shortcode outputs nothing — the banner simply disappears.', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'Multiple overlapping entries are concatenated with  —  between them.', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'Schedule entries under Vis → Calendar → Rolling Texts. The Roller ID in that form must match the id attribute used in the shortcode.', 'pcio-vis-member-event' ); ?></li>
                <li><?php esc_html_e( 'Different pages can show different banners by using different id values.', 'pcio-vis-member-event' ); ?></li>
            </ul>

            <strong style="display:block;margin-top:16px"><?php esc_html_e( 'Examples', 'pcio-vis-member-event' ); ?></strong>
            <code class="pcio-sc-block">[pcio_me_rolling_text id="homepage"]
[pcio_me_rolling_text id="events-sidebar"]</code>

            <hr style="margin:32px 0">

            <?php do_action( 'pcio_me_shortcodes_after' ); ?>

            </div><!-- .pcio-sc-content -->
            </div><!-- .pcio-sc-layout -->
        </div><!-- .wrap -->
        <?php
    }
}
