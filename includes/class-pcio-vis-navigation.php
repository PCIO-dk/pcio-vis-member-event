<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared, capability-aware sections for Vis navigation and page tabs.
 */
class PCIO_VIS_Navigation {

    /** Return the navigation sections, including extension-provided items. */
    public static function items( string $active = '' ): array {
        $items = [
            'members'   => [ 'label' => __( 'Members', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/members' ) ],
            'calendar'  => [ 'label' => __( 'Calendar', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/calendar' ) ],
        ];
        $items['members']['children']['members'] = $items['members'];
        if ( current_user_can( 'vis_manage_users' ) ) {
            $items['members']['children']['workgroups'] = [ 'label' => __( 'Workgroups', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/workgroups' ) ];
        }
        $items['calendar']['children'] = [
            'calendar'    => [ 'label' => __( 'Calendar', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/calendar#calendar' ) ],
            'event-types' => [ 'label' => __( 'Event Types', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/calendar#event-types' ) ],
            'event-roles' => [ 'label' => __( 'Event Roles', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/calendar#event-roles' ) ],
            'resources'   => [ 'label' => __( 'Resources', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/calendar#resources' ) ],
            'rolling-text'=> [ 'label' => __( 'Rolling Texts', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/calendar#rolling-text' ) ],
        ];
        if ( current_user_can( 'vis_manage_settings' ) ) {
            $items['mails'] = [
                'label' => __( 'Marketing', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/mails' ),
                'children' => [
                    'mails' => [ 'label' => __( 'Mails', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/mails' ) ],
                    'newsletters' => [ 'label' => __( 'Newsletters', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/newsletters' ) ],
                ],
            ];
        }
        $items['documents'] = [ 'label' => __( 'Documents', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/documents' ) ];
        if ( current_user_can( 'vis_manage_finance' ) ) {
            $items['finance'] = [ 'label' => __( 'Finance', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/finance' ) ];
        }
        /**
         * Filter section links. Optional children use the same label/url shape.
         *
         * @param array  $items  Keyed section descriptors.
         * @param string $active Active page key.
         */
        $items = (array) apply_filters( 'pcio_me_nav_items', $items, $active );
        $finance = self::finance_tabs();
        if ( $finance ) {
            $items['finance']['label'] = __( 'Finance', 'pcio-vis-member-event' );
            $items['finance']['url'] = $items['finance']['url'] ?? reset( $finance )['url'];
            $items['finance']['children'] = $finance;
        }
        if ( current_user_can( 'vis_manage_users' ) ) {
            $items['statistics'] = [ 'label' => __( 'Statistics', 'pcio-vis-member-event' ), 'url' => home_url( 'vis/members/statistics' ) ];
        }

        $ordered = [];
        foreach ( [ 'members', 'calendar', 'mails', 'documents', 'finance', 'galleries', 'shop', 'statistics' ] as $key ) {
            if ( isset( $items[ $key ] ) ) {
                $ordered[ $key ] = $items[ $key ];
                unset( $items[ $key ] );
            }
        }
        return $ordered + $items;
    }

    /** Return the Finance list links plus extension-owned pages such as Deliveries. */
    public static function finance_tabs(): array {
        $tabs = [];
        if ( current_user_can( 'vis_manage_finance' ) ) {
            $lists = apply_filters( 'pcio_me_finance_lists', [
                [ 'id' => 'journal', 'label' => __( 'Journal', 'pcio-vis-member-event' ) ],
            ] );
            $by_id = array_column( $lists, null, 'id' );
            foreach ( [ 'journal', 'shop-orders', 'orders' ] as $id ) {
                if ( isset( $by_id[ $id ] ) ) {
                    $tabs[ $id ] = [ 'label' => $by_id[ $id ]['label'], 'url' => home_url( 'vis/finance#' . $id ) ];
                    unset( $by_id[ $id ] );
                }
            }
            foreach ( $by_id as $id => $list ) {
                $tabs[ $id ] = [ 'label' => $list['label'], 'url' => home_url( 'vis/finance#' . $id ) ];
            }
        }
        /**
         * Add capability-filtered links to extension-owned Finance pages.
         *
         * @param array $tabs Keyed label/url descriptors.
         */
        return (array) apply_filters( 'pcio_me_finance_tabs', $tabs );
    }

    /** Return page-link tabs with their current-page state. */
    public static function tabs( string $section, string $active ): array {
        $items = self::items( $active );
        $tabs = $items[ $section ]['children'] ?? [];
        foreach ( $tabs as $key => &$tab ) {
            $tab['active'] = $key === $active;
        }
        unset( $tab );
        return array_values( $tabs );
    }
}
