<?php
/**
 * Template Name: Event detail (full width)
 *
 * Page template used by the single Event page that serves /events/{slug}/.
 *
 * It renders the page content (the [pcio_me_event] shortcode) full width inside
 * the active theme's header and footer, without the theme's blog sidebar or
 * comments. The template is provided by the plugin and assigned to the Event
 * page automatically; see PCIO_VIS_Event_Page.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();
?>
<main id="pcio-me-event-page" class="pcio-me-event-page" role="main">
    <?php
    while ( have_posts() ) {
        the_post();
        the_content();
    }
    ?>
</main>
<?php
get_footer();
