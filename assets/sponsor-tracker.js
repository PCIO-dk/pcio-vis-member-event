/* global PCIO_ME_TRACKER */
( function () {
    'use strict';

    var cfg = window.PCIO_ME_TRACKER;
    if ( ! cfg || ! cfg.rest ) return;

    document.addEventListener( 'click', function ( e ) {
        var url;

        // Case 1: plain <a class="pcio-sponsor-link"> — the class is on the anchor itself.
        var anchor = e.target.closest( 'a.pcio-sponsor-link' );
        if ( anchor ) {
            url = anchor.href;
        } else {
            // Case 2: Divi / section pattern — class is on a wrapper div/section,
            // the <a> is an inner child (e.g. a Divi Image module wrapping an image link).
            var wrapper = e.target.closest( '.pcio-sponsor-link' );
            if ( ! wrapper ) return;
            var inner = wrapper.querySelector( 'a[href]' );
            if ( ! inner ) return;
            url = inner.href;
        }

        if ( ! url ) return;

        // Fire-and-forget — do not block navigation.
        fetch( cfg.rest + '/statistics/sponsor-click', {
            method:    'POST',
            keepalive: true, // survives page unload
            headers:   { 'Content-Type': 'application/json' },
            body:      JSON.stringify( { url: url } ),
        } ).catch( function () { /* silently ignore */ } );
    } );
}() );
