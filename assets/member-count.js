( function () {
    'use strict';

    var DURATION = 1500; // ms

    function easeOutCubic( t ) {
        return 1 - Math.pow( 1 - t, 3 );
    }

    function animateCounter( el ) {
        var target = parseInt( el.getAttribute( 'data-target' ), 10 );
        if ( isNaN( target ) || target < 1 ) {
            el.textContent = String( target || 0 );
            return;
        }

        var start = null;

        function step( ts ) {
            if ( ! start ) start = ts;
            var progress = Math.min( ( ts - start ) / DURATION, 1 );
            var value    = Math.round( easeOutCubic( progress ) * target );
            el.textContent = value.toLocaleString();
            if ( progress < 1 ) {
                requestAnimationFrame( step );
            }
        }

        requestAnimationFrame( step );
    }

    document.addEventListener( 'DOMContentLoaded', function () {
        var elements = document.querySelectorAll( '.pcio-me-member-count' );
        elements.forEach( function ( el ) {
            animateCounter( el );
        } );
    } );
}() );
