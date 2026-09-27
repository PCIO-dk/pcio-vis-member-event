/* ================================================================
   PCIO VIS Member Event — event-signup.js
   Signup button interactivity for the [pcio_me_event] shortcode.
   Reads PCIO_VIS_EVENT global injected by shortcode_event().
   ================================================================ */

( function () {
    'use strict';

    const REST     = ( PCIO_VIS_EVENT.rest + '/events/' + PCIO_VIS_EVENT.eventId + '/signup' ).replace( /([^:]\/)\/+/g, '$1' );
    const NONCE    = PCIO_VIS_EVENT.nonce;
    const T        = PCIO_VIS_EVENT.i18n;

    let currentStatus = PCIO_VIS_EVENT.currentStatus || null;
    let busy          = false;

    document.addEventListener( 'DOMContentLoaded', () => {
        // Support multiple shortcode instances on the same page.
        const groups = document.querySelectorAll(
            '#me-sc-event-' + PCIO_VIS_EVENT.eventId + ' .me-sc-signup-group'
        );
        if ( ! groups.length ) return;

        groups.forEach( group => {
            group.querySelectorAll( '.me-sc-signup-btn' ).forEach( btn => {
                btn.addEventListener( 'click', () => handleClick( btn, group ) );
            } );
        } );
    } );

    async function handleClick( btn, group ) {
        if ( busy ) return;
        const newStatus = btn.dataset.status;

        // Toggle off (remove signup) if clicking the already-active status.
        if ( newStatus === currentStatus ) {
            await setStatus( null, group );
        } else {
            await setStatus( newStatus, group );
        }
    }

    async function setStatus( status, group ) {
        busy = true;
        setGroupLoading( group, true );
        try {
            let res;
            if ( status === null ) {
                res = await fetch( REST, {
                    method:  'DELETE',
                    headers: { 'X-WP-Nonce': NONCE },
                } );
            } else {
                res = await fetch( REST, {
                    method:  'PUT',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body:    JSON.stringify( { status } ),
                } );
            }

            if ( ! res.ok && res.status !== 204 ) {
                throw new Error( 'HTTP ' + res.status );
            }

            currentStatus = status;
            updateButtonStates( group );
        } catch ( _err ) {
            showError( group );
        } finally {
            busy = false;
            setGroupLoading( group, false );
        }
    }

    function updateButtonStates( group ) {
        group.querySelectorAll( '.me-sc-signup-btn' ).forEach( btn => {
            btn.classList.toggle( 'me-active', btn.dataset.status === currentStatus );
        } );
    }

    function setGroupLoading( group, loading ) {
        group.querySelectorAll( '.me-sc-signup-btn' ).forEach( btn => {
            btn.disabled = loading;
        } );
    }

    function showError( group ) {
        const existing = group.parentElement.querySelector( '.me-sc-signup-error' );
        if ( existing ) return;
        const el = document.createElement( 'p' );
        el.className   = 'me-sc-signup-error';
        el.textContent = T.errSave;
        group.insertAdjacentElement( 'afterend', el );
        setTimeout( () => el.remove(), 4000 );
    }

} )();
