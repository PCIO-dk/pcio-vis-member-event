<?php
/**
 * Front-end template: Public event detail page (legacy standalone fallback).
 *
 * Normally /events/{slug}/ is rewritten onto the real Event page
 * (PCIO_VIS_Event_Page), which renders the [pcio_me_event] shortcode through the
 * theme's page template so the site header and footer are used. This raw
 * template is only used when that page is missing; it uses the active theme's
 * header and footer (get_header/get_footer) and lays the event out full-width.
 *
 * Provided by PCIO_VIS_Plugin::serve_public_event():
 *   $pcio_me_event  array  Raw event row.
 *   $pcio_me_view   array  Prepared view data (urls, dates, signup state…).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$pcio_me_v = isset( $pcio_me_view ) && is_array( $pcio_me_view ) ? $pcio_me_view : [];
if ( empty( $pcio_me_v ) ) {
    return;
}

get_header();

require __DIR__ . '/event-public-body.php';

get_footer();
