( function () {
    'use strict';

    const dialogs = new Map();
    const bound = new WeakSet();
    const selector = 'input, select, textarea, [contenteditable="true"]';

    function fields( overlay ) {
        return Array.from( overlay.querySelectorAll( selector ) ).filter( field =>
            ! [ 'button', 'submit', 'reset' ].includes( field.type ) &&
            ! field.closest( '.ql-toolbar, .ql-tooltip, [data-pcio-modal-ignore]' )
        );
    }

    function value( field ) {
        if ( field.matches( '[contenteditable="true"]' ) ) return field.innerHTML;
        if ( field.type === 'checkbox' || field.type === 'radio' ) return field.checked;
        if ( field.multiple ) return JSON.stringify( Array.from( field.selectedOptions, option => option.value ) );
        return field.value;
    }

    function visible( overlay ) {
        return overlay.isConnected && ! overlay.hidden && ! overlay.classList.contains( 'me-hidden' );
    }

    function dirty( overlay, state ) {
        if ( ! visible( overlay ) ) return false;
        const current = fields( overlay );
        return current.length !== state.baseline.size ||
            current.some( field => ! state.baseline.has( field ) || state.baseline.get( field ) !== value( field ) ) ||
            JSON.stringify( state.extra() ) !== state.extraBaseline;
    }

    window.PCIOVisModal = {
        open( target, onClose, extra = () => null ) {
            const overlay = typeof target === 'string' ? document.getElementById( target ) : target;
            const state = {
                baseline: new Map( fields( overlay ).map( field => [ field, value( field ) ] ) ),
                extra,
                extraBaseline: JSON.stringify( extra() ),
                onClose,
            };
            dialogs.set( overlay, state );
            if ( ! bound.has( overlay ) ) {
                overlay.addEventListener( 'keydown', event => {
                    if ( event.key !== 'Escape' || event.defaultPrevented ) return;
                    const active = dialogs.get( overlay );
                    if ( ! active || ! visible( overlay ) ) return;
                    event.preventDefault();
                    event.stopPropagation();
                    active.onClose();
                } );
                bound.add( overlay );
            }
        },

        // Add asynchronously loaded controls without resetting edits to existing fields.
        refresh( target ) {
            const overlay = typeof target === 'string' ? document.getElementById( target ) : target;
            const state = dialogs.get( overlay );
            if ( ! state ) return;
            fields( overlay ).forEach( field => {
                if ( ! state.baseline.has( field ) ) state.baseline.set( field, value( field ) );
            } );
        },

        close( target, saved = false ) {
            const overlay = typeof target === 'string' ? document.getElementById( target ) : target;
            const state = dialogs.get( overlay );
            if ( saved !== true && state && dirty( overlay, state ) &&
                ! window.confirm( window.PCIO_VIS_MODAL.i18n.confirmDiscard ) ) return false;
            dialogs.delete( overlay );
            return true;
        },
    };

    window.addEventListener( 'beforeunload', event => {
        if ( ! Array.from( dialogs ).some( ( [ overlay, state ] ) => dirty( overlay, state ) ) ) return;
        event.preventDefault();
        event.returnValue = '';
    } );
} )();
