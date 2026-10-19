<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The single "Event" detail page that hosts the [pcio_me_event] shortcode.
 *
 * Public event permalinks (/events/{slug}/) are rewritten onto this real
 * WordPress page instead of being rendered by a raw template during
 * template_redirect. Rendering through the normal page template hierarchy is
 * what makes the active theme output its real header and footer (classic
 * themes, block/FSE themes and builders such as Divi's Theme Builder all key
 * off the queried object), returns a normal 200 response instead of a 404, and
 * keeps the page indexable.
 *
 * The page content is only the shortcode; the shortcode resolves the event
 * from the URL (or from an explicit id/slug attribute) so one page serves every
 * event detail.
 */
class PCIO_VIS_Event_Page {

    /** Option holding the detail page ID. */
    const OPTION_ID = 'pcio_me_event_page_id';

    /** Slug WordPress resolves /events/{slug}/ onto (pagename=<slug>). */
    const SLUG = 'event';

    /**
     * Page template assigned to the page. It is provided by the plugin
     * (templates/event-page.php) and registered with theme_page_templates, so
     * the page renders the event full width inside the theme header/footer
     * without the theme's blog sidebar.
     */
    const TEMPLATE = 'pcio-me-event-page.php';

    /** Content stored on the page — the shortcode renders the event. */
    const SHORTCODE = '[pcio_me_event]';

    /**
     * Return the stored page ID, creating the page when it is missing.
     */
    public static function get_id(): int {
        $id = (int) get_option( self::OPTION_ID );
        if ( $id > 0 && self::is_valid( $id ) ) {
            return $id;
        }
        return self::create();
    }

    /**
     * Whether the plugin's full-width page template applies.
     *
     * Block themes render their own header/footer template parts and have no
     * blog sidebar, so the classic PHP template is only used on classic themes
     * (including builders such as Divi). On block themes the page renders
     * through the theme's own page template instead.
     */
    public static function uses_full_width_template(): bool {
        return ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme();
    }

    /**
     * Ensure the page exists and carries the full-width page template.
     * Cheap no-op once it is in place; safe to call on every admin request so
     * a deleted page or a changed template heals itself.
     */
    public static function maybe_create(): void {
        $id = self::get_id();
        if ( $id > 0 && self::uses_full_width_template()
            && get_post_meta( $id, '_wp_page_template', true ) !== self::TEMPLATE ) {
            update_post_meta( $id, '_wp_page_template', self::TEMPLATE );
        }
    }

    /**
     * Whether the current request is the event detail page.
     */
    public static function is_current(): bool {
        $id = (int) get_option( self::OPTION_ID );
        if ( $id < 1 ) {
            return false;
        }
        if ( function_exists( 'is_page' ) && is_page( $id ) ) {
            return true;
        }
        return (int) get_query_var( 'page_id' ) === $id;
    }

    /**
     * A page that exists, is a page, and is not in the trash.
     */
    private static function is_valid( int $id ): bool {
        if ( ! function_exists( 'get_post' ) ) {
            return false;
        }
        $page = get_post( $id );
        return $page instanceof WP_Post
            && 'page' === $page->post_type
            && 'trash' !== $page->post_status;
    }

    /**
     * Create (or adopt) the detail page and remember its ID.
     *
     * @return int Page ID, or 0 when it could not be created.
     */
    public static function create(): int {
        $existing = get_page_by_path( self::SLUG );
        if ( $existing instanceof WP_Post && 'trash' !== $existing->post_status ) {
            $id = (int) $existing->ID;
        } else {
            $id = wp_insert_post(
                [
                    'post_type'      => 'page',
                    'post_status'    => 'publish',
                    'post_title'     => __( 'Event', 'pcio-vis-member-event' ),
                    'post_name'      => self::SLUG,
                    'post_content'   => self::SHORTCODE,
                    'comment_status' => 'closed',
                    'ping_status'    => 'closed',
                ],
                true
            );

            if ( is_wp_error( $id ) || ! $id ) {
                return 0;
            }
            $id = (int) $id;
        }

        update_option( self::OPTION_ID, $id );
        if ( self::uses_full_width_template() ) {
            update_post_meta( $id, '_wp_page_template', self::TEMPLATE );
        }
        return $id;
    }
}
