( function () {
    'use strict';

    document.addEventListener( 'click', event => {
        const link = event.target.closest( '.me-nav-submenu a' );
        if ( link ) link.closest( '.me-nav-dropdown' ).open = false;
        document.querySelectorAll( '.me-nav-dropdown[open]' ).forEach( menu => {
            if ( ! menu.contains( event.target ) ) menu.open = false;
        } );
    } );
    document.addEventListener( 'keydown', event => {
        if ( event.key !== 'Escape' ) return;
        const menu = event.target.closest( '.me-nav-dropdown[open]' );
        if ( menu ) {
            menu.open = false;
            menu.querySelector( 'summary' ).focus();
            event.preventDefault();
        }
    } );
    document.addEventListener( 'focusin', event => {
        document.querySelectorAll( '.me-nav-dropdown[open]' ).forEach( menu => {
            if ( ! menu.contains( event.target ) ) menu.open = false;
        } );
    } );
} )();
