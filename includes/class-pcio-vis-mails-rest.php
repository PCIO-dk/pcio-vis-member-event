<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PCIO_VIS_Mails_Rest {

    public function register(): void {
        $ns = PCIO_VIS_REST_NS;

        register_rest_route( $ns, '/mails', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_mails' ],
                'permission_callback' => [ $this, 'can_read' ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'create_mail' ],
                'permission_callback' => [ $this, 'can_write' ],
            ],
        ] );

        register_rest_route( $ns, '/mails/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_mail' ],
                'permission_callback' => [ $this, 'can_read' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'update_mail' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_mail' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ],
            ],
        ] );

        register_rest_route( $ns, '/mails/(?P<id>\d+)/send', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'send_mail' ],
                'permission_callback' => [ $this, 'can_write' ],
                'args'                => [ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ), 'sanitize_callback' => 'absint' ] ],
            ],
        ] );

        // Mail groups (read-only list; populated by extensions via filter)
        register_rest_route( $ns, '/mail-groups', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_mail_groups' ],
                'permission_callback' => [ $this, 'can_read' ],
            ],
        ] );

        add_filter( 'pcio_me_mail_groups', [ $this, 'add_core_mail_groups' ] );
    }

    // ── Permissions ───────────────────────────────────────────────

    public function can_read(): bool {
        return current_user_can( 'vis_manage_settings' );
    }

    public function can_write(): bool {
        return current_user_can( 'vis_manage_settings' );
    }

    // ── Handlers ──────────────────────────────────────────────────

    public function list_mails( WP_REST_Request $req ): WP_REST_Response {
        try {
            return new WP_REST_Response( PCIO_VIS_Mails_DB::get_all(), 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function get_mail( WP_REST_Request $req ): WP_REST_Response {
        try {
            $mail = PCIO_VIS_Mails_DB::get( (int) $req->get_param( 'id' ) );
            if ( ! $mail ) {
                return new WP_REST_Response( [ 'message' => __( 'Not found.', 'pcio-vis-member-event' ) ], 404 );
            }
            $mail['attachments']   = $this->decorate_attachment_ids( (array) $mail['attachments'] );
            /**
             * Filter: pcio_me_mail_tags
             * @param array  $tags     Array of ['tag'=>'{name}','label'=>'Customer name'] items.
             * @param string $mailtype The mailtype slug of the mail being fetched.
             */
            $mail['available_tags'] = (array) apply_filters( 'pcio_me_mail_tags', [], (string) ( $mail['mailtype'] ?? '' ) );
            return new WP_REST_Response( $mail, 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function create_mail( WP_REST_Request $req ): WP_REST_Response {
        try {
            $id = PCIO_VIS_Mails_DB::create( (array) $req->get_json_params() );
            if ( ! $id ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not create mail.', 'pcio-vis-member-event' ) ], 500 );
            }
            return new WP_REST_Response( PCIO_VIS_Mails_DB::get( (int) $id ), 201 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function update_mail( WP_REST_Request $req ): WP_REST_Response {
        try {
            $id     = (int) $req->get_param( 'id' );
            $params = (array) $req->get_json_params();
            // mailtype is system-managed and must not be changed via REST.
            unset( $params['mailtype'] );
            $ok = PCIO_VIS_Mails_DB::update( $id, $params );
            if ( ! $ok ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not update mail.', 'pcio-vis-member-event' ) ], 500 );
            }
            return new WP_REST_Response( PCIO_VIS_Mails_DB::get( $id ), 200 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    public function delete_mail( WP_REST_Request $req ): WP_REST_Response {
        try {
            $id = (int) $req->get_param( 'id' );
            if ( PCIO_VIS_Mails_DB::is_system_mail( $id ) ) {
                return new WP_REST_Response( [ 'message' => __( 'System mails cannot be deleted.', 'pcio-vis-member-event' ) ], 403 );
            }
            if ( ! PCIO_VIS_Mails_DB::delete( $id ) ) {
                return new WP_REST_Response( [ 'message' => __( 'Could not delete mail.', 'pcio-vis-member-event' ) ], 500 );
            }
            return new WP_REST_Response( null, 204 );
        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    // ── Send mail ────────────────────────────────────────────────
    // Mailer is resolved via the 'pcio_me_mailer_send' filter.
    // An extension (e.g. pcio-vis-mailings-with-postmark) hooks in to provide Postmark sending.
    // When no extension is active, or the extension returns null, core falls back
    // to wp_mail() so the Mails feature always works.

    public function send_mail( WP_REST_Request $req ): WP_REST_Response {
        try {
            $id   = (int) $req->get_param( 'id' );
            $mail = PCIO_VIS_Mails_DB::get( $id );
            if ( ! $mail ) {
                return new WP_REST_Response( [ 'message' => __( 'Mail not found.', 'pcio-vis-member-event' ) ], 404 );
            }

            $params     = (array) ( $req->get_json_params() ?? [] );
            $target     = sanitize_key( $params['target'] ?? 'all' );
            $recipients = $this->resolve_recipients( $target, $params );

            if ( is_wp_error( $recipients ) ) {
                return new WP_REST_Response( [ 'message' => $recipients->get_error_message() ], 400 );
            }
            if ( empty( $recipients ) ) {
                return new WP_REST_Response( [ 'message' => __( 'No recipients found for the selected target.', 'pcio-vis-member-event' ) ], 400 );
            }

            // If the client sent attachment IDs (e.g. unsaved changes from the compose modal),
            // persist them to the DB now so the record is always up to date.
            if ( array_key_exists( 'attachments', $params ) ) {
                PCIO_VIS_Mails_DB::update( $id, [ 'attachments' => $params['attachments'] ] );
                $mail = PCIO_VIS_Mails_DB::get( $id ); // reload with saved attachments
            }

            // Resolve attachment IDs → file metadata.
            $attachments = $this->resolve_attachments( (array) ( $mail['attachments'] ?? [] ) );

            /**
             * Filter: pcio_me_mailer_send
             *
             * Allows extension plugins to handle bulk mail sending.
             * The filter value starts as null. An extension should:
             *   – Return array ['sent'=>int,'failed'=>int] on success.
             *   – Return WP_Error when it is configured but the send fails.
             *   – Return null (or leave unchanged) to defer to the next handler
             *     or to the built-in wp_mail() fallback.
             *
             * @param array|null|\WP_Error $result      Previous value (null initially).
             * @param array                $mail        Mail row (subject, content, attachments …).
             * @param array                $recipients  Array of ['email'=>…] rows.
             * @param array                $attachments Array of ['name','path','mime'] resolved rows.
             */
            $result = apply_filters( 'pcio_me_mailer_send', null, $mail, $recipients, $attachments );

            // Extension returned an error — surface it to the caller.
            if ( is_wp_error( $result ) ) {
                return new WP_REST_Response( [ 'message' => $result->get_error_message() ], 502 );
            }

            // No extension handled it — fall back to wp_mail().
            if ( $result === null ) {
                $result = $this->send_via_wp_mail( $mail, $recipients, $attachments );
            }

            // Mark as sent.
            PCIO_VIS_Mails_DB::update( $id, [ 'sent_at' => current_time( 'mysql', true ) ] );
            $updated = PCIO_VIS_Mails_DB::get( $id );

            return new WP_REST_Response( [
                'mail'   => $updated,
                'sent'   => (int) ( $result['sent']   ?? 0 ),
                'failed' => (int) ( $result['failed'] ?? 0 ),
            ], 200 );

        } catch ( \Throwable $e ) {
            return new WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    // ── wp_mail() fallback ────────────────────────────────────────

    /**
     * Send the mail to all recipients using WordPress's built-in wp_mail().
     *
     * @param array $mail         Row from me_mails (subject, content).
     * @param array $recipients   Array of ['email' => …] rows.
     * @param array $attachments  Array of ['name','path','mime'] rows.
     * @return array{sent:int,failed:int}
     */
    private function send_via_wp_mail( array $mail, array $recipients, array $attachments = [] ): array {
        $sent    = 0;
        $failed  = 0;
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $files   = array_column( $attachments, 'path' );

        foreach ( $recipients as $r ) {
            if ( wp_mail( $r['email'], $mail['subject'], $mail['content'], $headers, $files ) ) {
                ++$sent;
            } else {
                ++$failed;
            }
        }

        return compact( 'sent', 'failed' );
    }

    // ── Attachment resolver ───────────────────────────────────────

    /**
     * Resolve an array of WP attachment IDs to file metadata.
     * Silently skips IDs whose file is missing on disk.
     *
     * @param  int[]  $ids  Array of wp_attachment post IDs.
     * @return array[]      Array of ['name'=>string,'path'=>string,'mime'=>string].
     */
    private function resolve_attachments( array $ids ): array {
        $out = [];
        foreach ( $ids as $id ) {
            $id   = absint( $id );
            $path = get_attached_file( $id );
            if ( ! $path || ! file_exists( $path ) ) {
                continue;
            }
            $out[] = [
                'name' => basename( $path ),
                'path' => $path,
                'mime' => (string) get_post_mime_type( $id ),
            ];
        }
        return $out;
    }

    /**
     * Decorate attachment IDs with human-readable names for the REST response.
     * Returns [{id, name}] — the JS modal uses this to render chips on edit-open.
     *
     * @param  int[]   $ids
     * @return array[]  Array of ['id'=>int,'name'=>string].
     */
    private function decorate_attachment_ids( array $ids ): array {
        $out = [];
        foreach ( $ids as $id ) {
            $id   = absint( $id );
            $path = get_attached_file( $id );
            $out[] = [
                'id'   => $id,
                'name' => $path ? basename( $path ) : (string) $id,
            ];
        }
        return $out;
    }

    /**
     * Add the built-in Volunteers group to the mail-groups list.
     * Filter: pcio_me_mail_groups
     */
    public function add_core_mail_groups( array $groups ): array {
        $groups['volunteers'] = [
            'label'  => __( 'Volunteers', 'pcio-vis-member-event' ),
            'emails' => PCIO_VIS_DB::get_volunteer_emails(),
        ];
        return $groups;
    }

    // ── Recipient resolver ────────────────────────────────────────

    /**
     * Resolve the recipients list based on the 'target' param.
     *
     * target='all'       → every member with an email address
     * target='addresses' → explicit semicolon-separated list from $params['addresses']
     * target='<group>'   → delegated to the 'pcio_me_mail_groups' filter (extensions)
     *
     * @return array|WP_Error  Array of ['email'=>…] rows, or WP_Error on bad input.
     */
    private function resolve_recipients( string $target, array $params ): array|WP_Error {
        if ( $target === 'all' ) {
            return PCIO_VIS_DB::get_emails();
        }

        if ( $target === 'addresses' ) {
            $raw = (array) ( $params['addresses'] ?? [] );
            $out = [];
            foreach ( $raw as $addr ) {
                $email = sanitize_email( wp_unslash( (string) $addr ) );
                if ( is_email( $email ) ) {
                    $out[] = [ 'email' => $email ];
                }
            }
            if ( empty( $out ) ) {
                return new WP_Error( 'no_valid_addresses', __( 'No valid email addresses provided.', 'pcio-vis-member-event' ) );
            }
            return $out;
        }

        // Group target — ask extensions
        $slug   = sanitize_key( $target );
        $groups = $this->get_mail_groups_map();
        if ( ! isset( $groups[ $slug ] ) ) {
            return new WP_Error( 'unknown_group', __( 'Unknown mail group.', 'pcio-vis-member-event' ) );
        }
        $emails = (array) ( $groups[ $slug ]['emails'] ?? [] );
        return array_map( static fn( $e ) => [ 'email' => $e ], $emails );
    }

    // ── Mail groups ───────────────────────────────────────────────

    /**
     * REST handler: GET /mail-groups
     * Returns the list of available mail groups (defined by extensions).
     */
    public function list_mail_groups( WP_REST_Request $req ): WP_REST_Response {
        $out = [];
        foreach ( $this->get_mail_groups_map() as $slug => $g ) {
            $out[] = [
                'slug'  => $slug,
                'label' => $g['label'] ?? $slug,
                'count' => isset( $g['emails'] ) ? count( (array) $g['emails'] ) : null,
            ];
        }
        return new WP_REST_Response( $out, 200 );
    }

    /**
     * Build the mail-groups map via the extensibility filter.
     *
     * Extensions add entries by hooking 'pcio_me_mail_groups':
     *
     *   add_filter( 'pcio_me_mail_groups', function ( array $groups ): array {
     *       $groups['volunteers'] = [
     *           'label'  => __( 'Volunteers', 'my-extension' ),
     *           'emails' => My_Extension::get_volunteer_emails(),
     *       ];
     *       return $groups;
     *   } );
     *
     * Each entry: slug (key) => ['label' => string, 'emails' => string[]]
     *
     * @return array<string, array{label:string, emails:string[]}>
     */
    private function get_mail_groups_map(): array {
        /**
         * Filter: pcio_me_mail_groups
         * Allows extensions to register named recipient groups for the Send Mail dialog.
         *
         * @param array $groups  Associative array of slug => ['label'=>…, 'emails'=>[…]].
         */
        return (array) apply_filters( 'pcio_me_mail_groups', [] );
    }
}
