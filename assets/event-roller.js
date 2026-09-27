/* ================================================================
   PCIO VIS Member Event — event-roller.js
   Front-page [pcio_me_event_roller] slider: auto-advance + prev/next arrows.
   Supports multiple independent rollers on the same page.
   ================================================================ */

( function () {
    'use strict';

    function initRoller( root ) {
        const slides = Array.prototype.slice.call(
            root.querySelectorAll( '.me-roller-slide' )
        );
        if ( slides.length <= 1 ) {
            return; // Nothing to rotate.
        }

        const prevBtn = root.querySelector( '.me-roller-prev' );
        const nextBtn = root.querySelector( '.me-roller-next' );

        // Interval in ms (data-interval is in seconds, default 10s).
        let seconds = parseInt( root.getAttribute( 'data-interval' ), 10 );
        if ( isNaN( seconds ) || seconds < 2 ) {
            seconds = 10;
        }
        const intervalMs = seconds * 1000;

        let current = 0;
        let timer   = null;

        function show( index ) {
            current = ( index + slides.length ) % slides.length;
            slides.forEach( ( slide, i ) => {
                const active = i === current;
                slide.classList.toggle( 'is-active', active );
                slide.setAttribute( 'aria-hidden', active ? 'false' : 'true' );
            } );
        }

        function next() { show( current + 1 ); }
        function prev() { show( current - 1 ); }

        function startTimer() {
            stopTimer();
            timer = setInterval( next, intervalMs );
        }
        function stopTimer() {
            if ( timer ) {
                clearInterval( timer );
                timer = null;
            }
        }
        // Reset the countdown after manual navigation.
        function restartTimer() {
            startTimer();
        }

        if ( nextBtn ) {
            nextBtn.addEventListener( 'click', () => { next(); restartTimer(); } );
        }
        if ( prevBtn ) {
            prevBtn.addEventListener( 'click', () => { prev(); restartTimer(); } );
        }

        // Pause while hovering or focusing within the roller.
        root.addEventListener( 'mouseenter', stopTimer );
        root.addEventListener( 'mouseleave', startTimer );
        root.addEventListener( 'focusin',  stopTimer );
        root.addEventListener( 'focusout', startTimer );

        // Pause when the tab is hidden to avoid jumping while away.
        document.addEventListener( 'visibilitychange', () => {
            if ( document.hidden ) {
                stopTimer();
            } else {
                startTimer();
            }
        } );

        show( 0 );
        startTimer();
    }

    document.addEventListener( 'DOMContentLoaded', () => {
        document.querySelectorAll( '.me-roller' ).forEach( initRoller );
    } );
} )();
