<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Vis → Google admin page.
 *
 * Stores the service-account key (GOOGLE_KEY), sync options and the list of
 * Google calendars used by PCIO_VIS_Google_Sync. The sync itself is scheduled
 * and run from Vis → Sync Jobs ("Sync Google Calendar").
 */
class PCIO_VIS_Google_Admin {

    const PAGE       = 'pcio-me-google';
    const CAPABILITY = 'manage_options';
    const NOTICE     = 'pcio_me_google_notice_';

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ], 11 );
        add_action( 'admin_post_pcio_me_google_settings', [ $this, 'handle_settings' ] );
        add_action( 'admin_post_pcio_me_google_calendar', [ $this, 'handle_calendar' ] );
        add_action( 'admin_post_pcio_me_google_test', [ $this, 'handle_test' ] );
    }

    public function add_menu(): void {
        add_submenu_page(
            'pcio-me-settings',
            __( 'Google', 'pcio-vis-member-event' ),
            __( 'Google', 'pcio-vis-member-event' ),
            self::CAPABILITY,
            self::PAGE,
            [ $this, 'render_page' ]
        );
    }

    // ── Request handlers ──────────────────────────────────────────

    /** Save the Google key, default location and AI resume instruction. */
    public function handle_settings(): void {
        $this->guard();
        check_admin_referer( 'pcio_me_google_settings' );

        $messages = [];
        $type     = 'success';

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is validated by parse_credentials() and re-encoded below.
        $raw_key = trim( (string) wp_unslash( $_POST['google_key'] ?? '' ) );
        if ( ! empty( $_POST['google_key_remove'] ) ) {
            PCIO_VIS_Google_DB::set_key( '' );
            $messages[] = __( 'Google key removed.', 'pcio-vis-member-event' );
        } elseif ( '' !== $raw_key ) {
            $credentials = PCIO_VIS_Google_Client::parse_credentials( $raw_key );
            if ( is_wp_error( $credentials ) ) {
                $type       = 'error';
                $messages[] = $credentials->get_error_message();
            } else {
                PCIO_VIS_Google_DB::set_key( (string) wp_json_encode( $credentials ) );
                $messages[] = __( 'Google key saved.', 'pcio-vis-member-event' );
            }
        }

        PCIO_VIS_Google_DB::set_location( sanitize_text_field( wp_unslash( $_POST['google_location'] ?? '' ) ) );
        PCIO_VIS_Google_DB::set_ai_prompt( sanitize_textarea_field( wp_unslash( $_POST['ai_resume_prompt'] ?? '' ) ) );
        $messages[] = __( 'Settings saved.', 'pcio-vis-member-event' );

        $this->redirect( $type, $messages );
    }

    /** Add, edit or delete a calendar. */
    public function handle_calendar(): void {
        $this->guard();
        check_admin_referer( 'pcio_me_google_calendar' );

        $action = sanitize_key( wp_unslash( $_POST['calendar_action'] ?? '' ) );
        $id     = absint( wp_unslash( $_POST['calendar_ref'] ?? 0 ) );
        $data   = [
            'name'        => sanitize_text_field( wp_unslash( $_POST['calendar_name'] ?? '' ) ),
            'calendar_id' => sanitize_text_field( wp_unslash( $_POST['calendar_google_id'] ?? '' ) ),
            'scope'       => sanitize_key( wp_unslash( $_POST['calendar_scope'] ?? '' ) ),
        ];

        if ( 'delete' === $action && $id ) {
            $ok = PCIO_VIS_Google_DB::delete_calendar( $id );
            $this->redirect(
                $ok ? 'success' : 'error',
                [ $ok ? __( 'Calendar removed. Events already in Google are left untouched.', 'pcio-vis-member-event' ) : __( 'Could not remove the calendar.', 'pcio-vis-member-event' ) ]
            );
        }

        if ( '' === $data['name'] || '' === $data['calendar_id'] ) {
            $this->redirect( 'error', [ __( 'Name and Google calendar ID are required.', 'pcio-vis-member-event' ) ] );
        }

        if ( 'edit' === $action && $id ) {
            $ok = PCIO_VIS_Google_DB::update_calendar( $id, $data );
            $this->redirect( $ok ? 'success' : 'error', [ $ok ? __( 'Calendar updated.', 'pcio-vis-member-event' ) : __( 'Could not update the calendar.', 'pcio-vis-member-event' ) ] );
        }

        $ok = PCIO_VIS_Google_DB::create_calendar( $data );
        $this->redirect( $ok ? 'success' : 'error', [ $ok ? __( 'Calendar added.', 'pcio-vis-member-event' ) : __( 'Could not add the calendar.', 'pcio-vis-member-event' ) ] );
    }

    /** Verify the key and access to every configured calendar. */
    public function handle_test(): void {
        $this->guard();
        check_admin_referer( 'pcio_me_google_test' );

        $key = PCIO_VIS_Google_DB::get_key();
        if ( '' === $key ) {
            $this->redirect( 'error', [ __( 'No Google key configured.', 'pcio-vis-member-event' ) ] );
        }
        $client = PCIO_VIS_Google_Client::from_json( $key );
        if ( is_wp_error( $client ) ) {
            $this->redirect( 'error', [ $client->get_error_message() ] );
        }
        $token = $client->get_access_token();
        if ( is_wp_error( $token ) ) {
            $this->redirect( 'error', [ $token->get_error_message() ] );
        }

        $type     = 'success';
        $messages = [ __( 'Authentication with Google succeeded.', 'pcio-vis-member-event' ) ];
        foreach ( PCIO_VIS_Google_DB::get_calendars() as $calendar ) {
            $result = $client->get_calendar( (string) $calendar['calendar_id'] );
            if ( is_wp_error( $result ) ) {
                $type       = 'error';
                $messages[] = $calendar['name'] . ': ' . $result->get_error_message();
            } else {
                $messages[] = $calendar['name'] . ': ' . __( 'OK', 'pcio-vis-member-event' );
            }
        }
        $this->redirect( $type, $messages );
    }

    /** Capability check for every handler; each handler verifies its own nonce. */
    private function guard(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pcio-vis-member-event' ) );
        }
    }

    /**
     * Store a one-shot notice for the current user and return to the page.
     *
     * @param string   $type     'success' | 'error'.
     * @param string[] $messages Notice lines.
     */
    private function redirect( string $type, array $messages ): void {
        set_transient( self::NOTICE . get_current_user_id(), [ 'type' => $type, 'messages' => $messages ], MINUTE_IN_SECONDS );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
        exit;
    }

    // ── Page ──────────────────────────────────────────────────────

    public function render_page(): void {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'pcio-vis-member-event' ) );
        }

        $notice = get_transient( self::NOTICE . get_current_user_id() );
        delete_transient( self::NOTICE . get_current_user_id() );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Vis — Google Calendar', 'pcio-vis-member-event' ); ?></h1>

            <?php if ( is_array( $notice ) && ! empty( $notice['messages'] ) ) : ?>
            <div class="notice notice-<?php echo esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ); ?> is-dismissible">
                <?php foreach ( (array) $notice['messages'] as $line ) : ?>
                    <p><?php echo esc_html( (string) $line ); ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <p>
                <?php esc_html_e( 'Vis events are pushed to the Google calendars below by the "Sync Google Calendar" job.', 'pcio-vis-member-event' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=pcio-me-sync' ) ); ?>"><?php esc_html_e( 'Schedule or run it under Sync Jobs.', 'pcio-vis-member-event' ); ?></a>
            </p>

            <?php
            $this->render_settings_form();
            $this->render_calendars();
            $this->render_test_form();
            ?>
        </div>
        <?php
    }

    private function render_settings_form(): void {
        $client     = '' !== PCIO_VIS_Google_DB::get_key() ? PCIO_VIS_Google_Client::from_json( PCIO_VIS_Google_DB::get_key() ) : null;
        $ai_available = PCIO_VIS_AI::is_available();
        ?>
        <h2><?php esc_html_e( 'Settings', 'pcio-vis-member-event' ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="pcio_me_google_settings">
            <?php wp_nonce_field( 'pcio_me_google_settings' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="google_key"><?php esc_html_e( 'Google key (GOOGLE_KEY)', 'pcio-vis-member-event' ); ?></label></th>
                    <td>
                        <p>
                            <?php if ( $client instanceof PCIO_VIS_Google_Client ) : ?>
                                <span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span>
                                <?php
                                printf(
                                    /* translators: %s: service account e-mail address */
                                    esc_html__( 'A key is stored for service account %s.', 'pcio-vis-member-event' ),
                                    '<code>' . esc_html( $client->get_client_email() ) . '</code>'
                                );
                                ?>
                            <?php elseif ( is_wp_error( $client ) ) : ?>
                                <span class="dashicons dashicons-warning" style="color:#d63638"></span>
                                <?php echo esc_html( $client->get_error_message() ); ?>
                            <?php else : ?>
                                <?php esc_html_e( 'No key stored.', 'pcio-vis-member-event' ); ?>
                            <?php endif; ?>
                        </p>
                        <textarea name="google_key" id="google_key" rows="6" class="large-text code" autocomplete="off" spellcheck="false"
                            placeholder="<?php esc_attr_e( 'Paste the service-account JSON key here to set or replace it', 'pcio-vis-member-event' ); ?>"></textarea>
                        <p class="description">
                            <?php esc_html_e( 'Service-account key (JSON) from Google Cloud Console with the Calendar API enabled. The stored key is never shown again. Share each Google calendar with the service account e-mail and give it "Make changes to events" permission.', 'pcio-vis-member-event' ); ?>
                        </p>
                        <?php if ( null !== $client ) : ?>
                        <label><input type="checkbox" name="google_key_remove" value="1"> <?php esc_html_e( 'Remove the stored key', 'pcio-vis-member-event' ); ?></label>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="google_location"><?php esc_html_e( 'Default location', 'pcio-vis-member-event' ); ?></label></th>
                    <td>
                        <input type="text" name="google_location" id="google_location" class="regular-text" value="<?php echo esc_attr( PCIO_VIS_Google_DB::get_location() ); ?>">
                        <p class="description"><?php esc_html_e( 'Used as the Google event location when a Vis event has no location.', 'pcio-vis-member-event' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'AI text generation', 'pcio-vis-member-event' ); ?></th>
                    <td>
                        <p>
                            <?php if ( $ai_available ) : ?>
                                <span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span>
                                <?php esc_html_e( 'An AI provider is connected. Event editors can generate a short version of the event description.', 'pcio-vis-member-event' ); ?>
                            <?php else : ?>
                                <span class="dashicons dashicons-info-outline"></span>
                                <?php esc_html_e( 'No AI provider supporting text generation is connected.', 'pcio-vis-member-event' ); ?>
                            <?php endif; ?>
                        </p>
                        <p class="description">
                            <?php esc_html_e( 'Uses the WordPress AI Client. Install a provider plugin (e.g. AI Provider for Google) and add its API key under Settings → Connectors. The event description is sent to the provider only when the button is used.', 'pcio-vis-member-event' ); ?>
                            <?php if ( function_exists( 'wp_ai_client_prompt' ) ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php esc_html_e( 'Open Connectors', 'pcio-vis-member-event' ); ?></a>
                            <?php else : ?>
                                <?php esc_html_e( 'Requires WordPress 7.0 or later.', 'pcio-vis-member-event' ); ?>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ai_resume_prompt"><?php esc_html_e( 'AI resume instruction', 'pcio-vis-member-event' ); ?></label></th>
                    <td>
                        <textarea name="ai_resume_prompt" id="ai_resume_prompt" rows="5" class="large-text"><?php echo esc_textarea( PCIO_VIS_Google_DB::get_ai_prompt() ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'Sent to the AI before the event description when "Generate with AI" is used. Use it to control length, tone and content of the resume. Leave empty to restore the default.', 'pcio-vis-member-event' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save settings', 'pcio-vis-member-event' ) ); ?>
        </form>
        <?php
    }

    private function render_calendars(): void {
        $calendars = PCIO_VIS_Google_DB::get_calendars();
        $edit_id   = absint( wp_unslash( $_GET['edit_calendar'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection of the row to edit.
        $editing   = $edit_id ? PCIO_VIS_Google_DB::get_calendar( $edit_id ) : null;
        $scopes    = [
            PCIO_VIS_Google_DB::SCOPE_ALL    => __( 'All events (internal)', 'pcio-vis-member-event' ),
            PCIO_VIS_Google_DB::SCOPE_PUBLIC => __( 'Public events only', 'pcio-vis-member-event' ),
        ];
        ?>
        <hr>
        <h2><?php esc_html_e( 'Calendars', 'pcio-vis-member-event' ); ?></h2>
        <?php if ( ! $calendars ) : ?>
            <p><?php esc_html_e( 'No calendars configured yet.', 'pcio-vis-member-event' ); ?></p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th><?php esc_html_e( 'Name', 'pcio-vis-member-event' ); ?></th>
                <th><?php esc_html_e( 'Google calendar ID', 'pcio-vis-member-event' ); ?></th>
                <th><?php esc_html_e( 'Events', 'pcio-vis-member-event' ); ?></th>
                <th style="width:160px"><?php esc_html_e( 'Actions', 'pcio-vis-member-event' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $calendars as $calendar ) : ?>
                <tr>
                    <td><?php echo esc_html( $calendar['name'] ); ?></td>
                    <td><code><?php echo esc_html( $calendar['calendar_id'] ); ?></code></td>
                    <td><?php echo esc_html( $scopes[ $calendar['scope'] ] ?? $calendar['scope'] ); ?></td>
                    <td>
                        <a class="button button-small" href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE, 'edit_calendar' => (int) $calendar['id'] ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'pcio-vis-member-event' ); ?></a>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
                            onsubmit="return confirm('<?php echo esc_js( __( 'Remove this calendar from the sync?', 'pcio-vis-member-event' ) ); ?>');">
                            <input type="hidden" name="action" value="pcio_me_google_calendar">
                            <input type="hidden" name="calendar_action" value="delete">
                            <input type="hidden" name="calendar_ref" value="<?php echo esc_attr( (string) $calendar['id'] ); ?>">
                            <?php wp_nonce_field( 'pcio_me_google_calendar' ); ?>
                            <button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'pcio-vis-member-event' ); ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <h3><?php echo esc_html( $editing ? __( 'Edit calendar', 'pcio-vis-member-event' ) : __( 'Add calendar', 'pcio-vis-member-event' ) ); ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="pcio_me_google_calendar">
            <input type="hidden" name="calendar_action" value="<?php echo esc_attr( $editing ? 'edit' : 'add' ); ?>">
            <input type="hidden" name="calendar_ref" value="<?php echo esc_attr( (string) ( $editing['id'] ?? 0 ) ); ?>">
            <?php wp_nonce_field( 'pcio_me_google_calendar' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="calendar_name"><?php esc_html_e( 'Name', 'pcio-vis-member-event' ); ?></label></th>
                    <td><input type="text" name="calendar_name" id="calendar_name" class="regular-text" required value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="calendar_google_id"><?php esc_html_e( 'Google calendar ID', 'pcio-vis-member-event' ); ?></label></th>
                    <td>
                        <input type="text" name="calendar_google_id" id="calendar_google_id" class="large-text code" required value="<?php echo esc_attr( $editing['calendar_id'] ?? '' ); ?>">
                        <p class="description"><?php esc_html_e( 'Found in Google Calendar under Settings → Integrate calendar, e.g. abc123@group.calendar.google.com. Changing it drops the stored event links, so events are recreated in the new calendar.', 'pcio-vis-member-event' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="calendar_scope"><?php esc_html_e( 'Events', 'pcio-vis-member-event' ); ?></label></th>
                    <td>
                        <select name="calendar_scope" id="calendar_scope">
                            <?php foreach ( $scopes as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $editing['scope'] ?? PCIO_VIS_Google_DB::SCOPE_ALL, $value ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( '"Public events only" syncs events whose event type has public access. Events from one year back to one year ahead are synced; other events in the Google calendar within that window are removed.', 'pcio-vis-member-event' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( $editing ? __( 'Update calendar', 'pcio-vis-member-event' ) : __( 'Add calendar', 'pcio-vis-member-event' ), 'secondary' ); ?>
            <?php if ( $editing ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Cancel', 'pcio-vis-member-event' ); ?></a>
            <?php endif; ?>
        </form>
        <?php
    }

    private function render_test_form(): void {
        ?>
        <hr>
        <h2><?php esc_html_e( 'Test connection', 'pcio-vis-member-event' ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="pcio_me_google_test">
            <?php wp_nonce_field( 'pcio_me_google_test' ); ?>
            <p class="description"><?php esc_html_e( 'Authenticates with the stored key and checks access to every configured calendar. Nothing is changed.', 'pcio-vis-member-event' ); ?></p>
            <?php submit_button( __( 'Test connection', 'pcio-vis-member-event' ), 'secondary' ); ?>
        </form>
        <?php
    }
}
