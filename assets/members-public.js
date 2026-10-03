/**
 * [pcio_me_members] — client-side live search across the member table.
 *
 * Each row carries a lowercased `data-search` haystack built server-side from
 * every value in the member row (core columns, meta and extension fields).
 * Rows are hidden with an inline `display` so no stylesheet is required on the
 * host theme.
 */
( function () {
    'use strict';

    var DELAY = 250;

    document.querySelectorAll( '.pcio-me-members-wrap' ).forEach( function ( wrap ) {
        var input     = wrap.querySelector( '[data-members-search]' );
        var table     = wrap.querySelector( '.pcio-me-members-table' );
        var noResults = wrap.querySelector( '[data-members-noresults]' );

        if ( ! input || ! table ) {
            return;
        }

        var rows  = Array.prototype.slice.call( table.querySelectorAll( 'tbody tr' ) );
        var timer = null;

        input.addEventListener( 'input', function () {
            clearTimeout( timer );
            timer = setTimeout( applyFilter, DELAY );
        } );

        function applyFilter() {
            var query   = input.value.toLowerCase().trim();
            var visible = 0;

            rows.forEach( function ( row ) {
                var haystack = row.getAttribute( 'data-search' ) || '';
                var match    = ! query || haystack.indexOf( query ) !== -1;

                row.style.display = match ? '' : 'none';
                if ( match ) {
                    visible++;
                }
            } );

            if ( noResults ) {
                noResults.style.display = ( visible === 0 && query !== '' ) ? '' : 'none';
            }
        }
    } );
}() );
