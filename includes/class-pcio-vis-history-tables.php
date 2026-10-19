<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Core table definitions for the change history (filter: pcio_me_history_tables).
 *
 * Extensions add their own tables through the same filter; see
 * PCIO_VIS_History::registry() for the definition keys.
 */
class PCIO_VIS_History_Tables {

    /**
     * Core tables.
     *
     * @return array<string,array>
     */
    public static function core(): array {
        $event_link = static function ( array $row ): array {
            return [ 'event_id' => (int) ( $row['event_id'] ?? 0 ) ];
        };

        return [
            'events'               => [
                'table'  => 'me_events',
                'label'  => __( 'Event', 'pcio-vis-member-event' ),
                'title'  => [ __CLASS__, 'event_title' ],
                'weight' => 10,
                'large'  => [ 'description', 'resume' ],
                'ignore' => [ 'created_at', 'updated_at', 'slug' ],
                'fields' => [
                    'title'               => __( 'Title', 'pcio-vis-member-event' ),
                    'description'         => __( 'Description', 'pcio-vis-member-event' ),
                    'resume'              => __( 'Resume', 'pcio-vis-member-event' ),
                    'start_datetime'      => __( 'Start', 'pcio-vis-member-event' ),
                    'end_datetime'        => __( 'End', 'pcio-vis-member-event' ),
                    'get_in_datetime'     => __( 'Get-in time', 'pcio-vis-member-event' ),
                    'door_opening_datetime' => __( 'Door opening', 'pcio-vis-member-event' ),
                    'all_day'             => __( 'All day', 'pcio-vis-member-event' ),
                    'color'               => __( 'Color', 'pcio-vis-member-event' ),
                    'location'            => __( 'Location', 'pcio-vis-member-event' ),
                    'image_id'            => __( 'Image', 'pcio-vis-member-event' ),
                    'thumb_image_id'      => __( 'Thumbnail', 'pcio-vis-member-event' ),
                    'event_type_id'       => __( 'Event type', 'pcio-vis-member-event' ),
                    'signup_mode'         => __( 'Signup mode', 'pcio-vis-member-event' ),
                    'external_url'        => __( 'External ticket sale URL', 'pcio-vis-member-event' ),
                    'publish_homepage'    => __( 'Publish on homepage', 'pcio-vis-member-event' ),
                    'publish_poster'      => __( 'Publish to poster', 'pcio-vis-member-event' ),
                    'publish_facebook'    => __( 'Publish to Facebook', 'pcio-vis-member-event' ),
                    'recurrence_group_id' => __( 'Series', 'pcio-vis-member-event' ),
                ],
                'links'  => static function ( array $row ): array {
                    return [ 'event_id' => (int) ( $row['id'] ?? 0 ) ];
                },
            ],
            'signups'              => [
                'table'  => 'me_event_signups',
                'label'  => __( 'Signup', 'pcio-vis-member-event' ),
                'title'  => [ __CLASS__, 'signup_title' ],
                'weight' => 5,
                'fields' => [
                    'status' => __( 'Status', 'pcio-vis-member-event' ),
                ],
                'links'  => static function ( array $row ): array {
                    return [
                        'event_id'  => (int) ( $row['event_id'] ?? 0 ),
                        'member_id' => (int) ( $row['member_id'] ?? 0 ),
                    ];
                },
            ],
            'event_resources'      => [
                'table'  => 'me_event_resources',
                'pk'     => [ 'event_id', 'resource_id' ],
                'label'  => __( 'Event resource', 'pcio-vis-member-event' ),
                'title'  => [ __CLASS__, 'resource_link_title' ],
                'weight' => 3,
                'links'  => $event_link,
            ],
            'recurrence'           => [
                'table'  => 'me_event_recurrence',
                'pk'     => [ 'group_id' ],
                'label'  => __( 'Recurrence', 'pcio-vis-member-event' ),
                'weight' => 4,
                'fields' => [
                    'occurrences'   => __( 'Occurrences', 'pcio-vis-member-event' ),
                    'until_date'    => __( 'Until', 'pcio-vis-member-event' ),
                    'freq_type'     => __( 'Frequency', 'pcio-vis-member-event' ),
                    'interval_n'    => __( 'Interval', 'pcio-vis-member-event' ),
                    'weekdays_mask' => __( 'Weekdays', 'pcio-vis-member-event' ),
                ],
            ],
            'resources'            => [
                'table'     => 'me_resources',
                'label'     => __( 'Resource', 'pcio-vis-member-event' ),
                'title_col' => 'name',
                'weight'    => 6,
                'large'     => [ 'description' ],
                'fields'    => [
                    'name'        => __( 'Name', 'pcio-vis-member-event' ),
                    'description' => __( 'Description', 'pcio-vis-member-event' ),
                ],
            ],
            'event_types'          => [
                'table'     => 'me_event_types',
                'label'     => __( 'Event type', 'pcio-vis-member-event' ),
                'title_col' => 'name',
                'weight'    => 6,
                'fields'    => [
                    'name'   => __( 'Name', 'pcio-vis-member-event' ),
                    'slug'   => __( 'Slug', 'pcio-vis-member-event' ),
                    'access' => __( 'Access', 'pcio-vis-member-event' ),
                    'color'  => __( 'Color', 'pcio-vis-member-event' ),
                    'in_cafe_time' => __( 'During cafe opening hours', 'pcio-vis-member-event' ),
                    'system_event_type' => __( 'System event type', 'pcio-vis-member-event' ),
                ],
            ],
            'event_type_resources' => [
                'table'  => 'me_event_type_resources',
                'pk'     => [ 'event_type_id', 'resource_id' ],
                'label'  => __( 'Event type resource', 'pcio-vis-member-event' ),
                'title'  => [ __CLASS__, 'resource_link_title' ],
                'weight' => 2,
            ],
            'event_roles'          => [
                'table'     => 'me_event_roles',
                'label'     => __( 'Event role', 'pcio-vis-member-event' ),
                'title_col' => 'name',
                'weight'    => 6,
                'large'     => [ 'description' ],
                'fields'    => [
                    'name'        => __( 'Name', 'pcio-vis-member-event' ),
                    'description' => __( 'Description', 'pcio-vis-member-event' ),
                ],
            ],
            'event_crew'           => [
                'table'  => 'me_event_crew',
                'label'  => __( 'Crew assignment', 'pcio-vis-member-event' ),
                'title'  => [ __CLASS__, 'crew_title' ],
                'weight' => 5,
                'fields' => [
                    'role_id'        => __( 'Role', 'pcio-vis-member-event' ),
                    'member_id'      => __( 'Volunteer', 'pcio-vis-member-event' ),
                    'start_datetime' => __( 'Start time', 'pcio-vis-member-event' ),
                    'end_datetime'   => __( 'End time', 'pcio-vis-member-event' ),
                ],
                'links'  => static function ( array $row ): array {
                    return [
                        'event_id'  => (int) ( $row['event_id'] ?? 0 ),
                        'member_id' => (int) ( $row['member_id'] ?? 0 ),
                    ];
                },
            ],
        ] + self::member_tables() + self::content_tables();
    }

    /**
     * Members, their meta, role links, Vis roles and workgroups.
     *
     * @return array<string,array>
     */
    private static function member_tables(): array {
        $member_link = static function ( array $row ): array {
            return [ 'member_id' => (int) ( $row['member_id'] ?? 0 ) ];
        };
        $member_title = static function ( array $row ): string {
            return self::member_name( (int) ( $row['member_id'] ?? 0 ) );
        };

        return [
            'members'           => [
                'table'     => PCIO_VIS_DB::TABLE,
                'label'     => __( 'Member', 'pcio-vis-member-event' ),
                'title_col' => 'name',
                'weight'    => 10,
                'fields'    => [
                    'member_number' => __( 'Member number', 'pcio-vis-member-event' ),
                    'name'          => __( 'Name', 'pcio-vis-member-event' ),
                    'email'         => __( 'Email', 'pcio-vis-member-event' ),
                    'phone'         => __( 'Phone', 'pcio-vis-member-event' ),
                    'address'       => __( 'Address', 'pcio-vis-member-event' ),
                    'join_date'     => __( 'Joined', 'pcio-vis-member-event' ),
                    'leave_date'    => __( 'Left', 'pcio-vis-member-event' ),
                    'is_volunteer'  => __( 'Volunteer', 'pcio-vis-member-event' ),
                ],
                'links'     => static function ( array $row ): array {
                    return [ 'member_id' => (int) ( $row['id'] ?? 0 ) ];
                },
            ],
            'member_meta'       => [
                'table'  => PCIO_VIS_Meta_DB::TABLE,
                'label'  => __( 'Member field', 'pcio-vis-member-event' ),
                'title'  => static function ( array $row ): string {
                    return self::member_name( (int) ( $row['member_id'] ?? 0 ) ) . ': ' . (string) ( $row['meta_key'] ?? '' );
                },
                'weight' => 3,
                'fields' => [
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Column label, not a query argument.
                    'meta_value' => __( 'Value', 'pcio-vis-member-event' ),
                ],
                'links'  => $member_link,
            ],
            'member_roles'      => [
                'table'          => 'me_member_roles',
                'pk'             => [ 'member_id' ],
                'auto_increment' => false,
                'label'          => __( 'Member role', 'pcio-vis-member-event' ),
                'title'          => $member_title,
                'weight'         => 4,
                'fields'         => [
                    'vis_role'   => __( 'Vis role', 'pcio-vis-member-event' ),
                    'wp_user_id' => __( 'WordPress account', 'pcio-vis-member-event' ),
                ],
                'links'          => $member_link,
            ],
            'vis_roles'         => [
                'table'     => 'me_vis_roles',
                'label'     => __( 'Vis role', 'pcio-vis-member-event' ),
                'title_col' => 'name',
                'weight'    => 6,
                'fields'    => [
                    'slug' => __( 'Slug', 'pcio-vis-member-event' ),
                    'name' => __( 'Name', 'pcio-vis-member-event' ),
                    'caps' => __( 'Capabilities', 'pcio-vis-member-event' ),
                ],
            ],
            'workgroups'        => [
                'table'     => PCIO_VIS_Workgroups_DB::TABLE,
                'label'     => __( 'Workgroup', 'pcio-vis-member-event' ),
                'title_col' => 'name',
                'weight'    => 6,
                'large'     => [ 'description' ],
                'fields'    => [
                    'name'        => __( 'Name', 'pcio-vis-member-event' ),
                    'description' => __( 'Description', 'pcio-vis-member-event' ),
                    'color'       => __( 'Color', 'pcio-vis-member-event' ),
                ],
            ],
            'workgroup_members' => [
                'table'  => PCIO_VIS_Workgroups_DB::TABLE_LINK,
                'pk'     => [ 'workgroup_id', 'member_id' ],
                'label'  => __( 'Workgroup member', 'pcio-vis-member-event' ),
                'title'  => [ __CLASS__, 'workgroup_member_title' ],
                'weight' => 3,
                'fields' => [
                    'is_chairman' => __( 'Chairman', 'pcio-vis-member-event' ),
                ],
                'links'  => $member_link,
            ],
        ];
    }

    /**
     * Documents, mails, newsletters, rolling texts and the finance journal.
     *
     * @return array<string,array>
     */
    private static function content_tables(): array {
        return [
            'document_types' => [
                'table'     => PCIO_VIS_Docs_DB::TABLE_TYPES,
                'label'     => __( 'Document type', 'pcio-vis-member-event' ),
                'title_col' => 'title',
                'weight'    => 6,
                'fields'    => [
                    'name'  => __( 'Name', 'pcio-vis-member-event' ),
                    'title' => __( 'Title', 'pcio-vis-member-event' ),
                ],
            ],
            'documents'      => [
                'table'     => PCIO_VIS_Docs_DB::TABLE_DOCS,
                'label'     => __( 'Document', 'pcio-vis-member-event' ),
                'title_col' => 'display_name',
                'weight'    => 6,
                'fields'    => [
                    'type_id'          => __( 'Type', 'pcio-vis-member-event' ),
                    'display_name'     => __( 'Name', 'pcio-vis-member-event' ),
                    'wp_attachment_id' => __( 'File', 'pcio-vis-member-event' ),
                    'document_date'    => __( 'Date', 'pcio-vis-member-event' ),
                ],
            ],
            'mails'          => [
                'table'     => PCIO_VIS_Mails_DB::TABLE,
                'label'     => __( 'Mail', 'pcio-vis-member-event' ),
                'title_col' => 'subject',
                'weight'    => 6,
                'large'     => [ 'content', 'footer' ],
                'ignore'    => [ 'created_at', 'sent_at' ],
                'fields'    => [
                    'subject'        => __( 'Subject', 'pcio-vis-member-event' ),
                    'content'        => __( 'Content', 'pcio-vis-member-event' ),
                    'attachments'    => __( 'Attachments', 'pcio-vis-member-event' ),
                    'footer'         => __( 'Footer', 'pcio-vis-member-event' ),
                    'event_max_date' => __( 'Events until', 'pcio-vis-member-event' ),
                    'mailtype'       => __( 'Mail type', 'pcio-vis-member-event' ),
                ],
            ],
            'newsletters'    => [
                'table'     => PCIO_VIS_Newsletters_DB::TABLE,
                'label'     => __( 'Newsletter', 'pcio-vis-member-event' ),
                'title_col' => 'title',
                'weight'    => 6,
                'ignore'    => [ 'generated_at' ],
            ],
            'rolling_texts'  => [
                'table'  => PCIO_VIS_Rolling_Text_DB::TABLE,
                'label'  => __( 'Rolling text', 'pcio-vis-member-event' ),
                'title'  => static function ( array $row ): string {
                    $text = wp_strip_all_tags( (string) ( $row['text'] ?? '' ) );
                    return mb_strlen( $text ) > 40 ? mb_substr( $text, 0, 40 ) . '…' : $text;
                },
                'weight' => 6,
                'fields' => [
                    'roller_id' => __( 'Roller', 'pcio-vis-member-event' ),
                    'start_at'  => __( 'Start', 'pcio-vis-member-event' ),
                    'stop_at'   => __( 'Stop', 'pcio-vis-member-event' ),
                    'text'      => __( 'Text', 'pcio-vis-member-event' ),
                ],
            ],
            'journal'        => [
                'table'     => PCIO_VIS_Journal_DB::TABLE,
                'label'     => __( 'Journal entry', 'pcio-vis-member-event' ),
                'title_col' => 'title',
                'weight'    => 6,
                'fields'    => [
                    'entry_date'    => __( 'Date', 'pcio-vis-member-event' ),
                    'amount_cents'  => __( 'Amount (cents)', 'pcio-vis-member-event' ),
                    'title'         => __( 'Title', 'pcio-vis-member-event' ),
                    'member_name'   => __( 'Member name', 'pcio-vis-member-event' ),
                    'member_number' => __( 'Member number', 'pcio-vis-member-event' ),
                    'media_id'      => __( 'Receipt', 'pcio-vis-member-event' ),
                    'verified'      => __( 'Verified', 'pcio-vis-member-event' ),
                ],
                'links'     => static function ( array $row ): array {
                    $ids = PCIO_VIS_DB::get_member_ids_by_member_number( (int) ( $row['member_number'] ?? 0 ) );
                    return $ids ? [ 'member_id' => min( $ids ) ] : [];
                },
            ],
        ];
    }

    // ── Row titles ────────────────────────────────────────────────

    public static function event_title( array $row ): string {
        $title = (string) ( $row['title'] ?? '' );
        $start = (string) ( $row['start_datetime'] ?? '' );
        return '' !== $start ? $title . ' (' . substr( $start, 0, 10 ) . ')' : $title;
    }

    public static function signup_title( array $row ): string {
        return self::member_name( (int) ( $row['member_id'] ?? 0 ) ) . ' → ' . self::event_name( (int) ( $row['event_id'] ?? 0 ) );
    }

    public static function resource_link_title( array $row ): string {
        global $wpdb;
        $name = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single lookup for a history label.
            $wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', PCIO_VIS_Resources_DB::table(), (int) ( $row['resource_id'] ?? 0 ) )
        );
        $owner = isset( $row['event_id'] ) ? self::event_name( (int) $row['event_id'] ) : '';
        return '' !== $owner ? $name . ' → ' . $owner : $name;
    }

    public static function workgroup_member_title( array $row ): string {
        global $wpdb;
        $group = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single lookup for a history label.
            $wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', $wpdb->prefix . PCIO_VIS_Workgroups_DB::TABLE, (int) ( $row['workgroup_id'] ?? 0 ) )
        );
        return self::member_name( (int) ( $row['member_id'] ?? 0 ) ) . ' → ' . $group;
    }

    public static function member_name( int $member_id ): string {
        global $wpdb;
        if ( $member_id <= 0 ) {
            return '';
        }
        $name = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single lookup for a history label.
            $wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', $wpdb->prefix . PCIO_VIS_DB::TABLE, $member_id )
        );
        return '' !== $name ? $name : '#' . $member_id;
    }

    public static function crew_title( array $row ): string {
        $member_id = (int) ( $row['member_id'] ?? 0 );
        $member    = $member_id > 0 ? self::member_name( $member_id ) : __( 'Unassigned', 'pcio-vis-member-event' );
        $event     = self::event_name( (int) ( $row['event_id'] ?? 0 ) );
        return $member . ' → ' . $event;
    }

    public static function event_name( int $event_id ): string {
        if ( $event_id <= 0 ) {
            return '';
        }
        $event = PCIO_VIS_Events_DB::get( $event_id );
        return $event ? self::event_title( $event ) : '#' . $event_id;
    }
}
