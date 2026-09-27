/**
 * [pcio_me_events] — client-side live search across event tiles.
 */
( function () {
    'use strict';

    var input     = document.getElementById( 'me-events-search' );
    var grid      = document.getElementById( 'me-events-grid' );
    var noResults = document.getElementById( 'me-events-noresults' );

    if ( ! input || ! grid ) {
        return;
    }

    var DELAY = 250;
    var timer = null;

    input.addEventListener( 'input', function () {
        clearTimeout( timer );
        timer = setTimeout( applyFilter, DELAY );
    } );

    function applyFilter() {
        var query   = input.value.toLowerCase().trim();
        var decks   = grid.querySelectorAll( '.me-events-deck' );
        var visible = 0;

        decks.forEach( function ( deck ) {
            var haystack = deck.getAttribute( 'data-search' ) || '';
            var match    = ! query || haystack.indexOf( query ) !== -1;
            deck.classList.toggle( 'me-hidden', ! match );
            if ( match ) {
                visible++;
            }
        } );

        if ( noResults ) {
            noResults.classList.toggle( 'me-hidden', ! ( visible === 0 && query !== '' ) );
        }
    }
}() );
