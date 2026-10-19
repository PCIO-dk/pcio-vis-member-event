/* WP Admin → Vis → History: confirm before undoing. */
( function () {
    'use strict';
    var cfg = window.PCIO_ME_HISTORY_ADMIN || {};
    document.addEventListener( 'click', function ( e ) {
        var btn = e.target.closest( '.pcio-me-history-undo' );
        if ( btn && ! window.confirm( cfg.confirm || 'Undo?' ) ) {
            e.preventDefault();
        }
    } );
} )();
