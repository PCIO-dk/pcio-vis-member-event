/* global PCIO_VIS_STATS, Chart */
( function () {
    'use strict';

    const cfg = window.PCIO_VIS_STATS;

    // ── Helpers ───────────────────────────────────────────────────

    function escHtml( s ) { const d = document.createElement( 'div' ); d.textContent = String( s ); return d.innerHTML; }
    function escAttr( s ) { return String( s ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ); }

    function el( tag, cls, text ) {
        const e = document.createElement( tag );
        if ( cls )  e.className   = cls;
        if ( text !== undefined ) e.textContent = text;
        return e;
    }

    function showError( app, msg ) {
        const p = el( 'p', 'me-stats-error', msg );
        app.appendChild( p );
    }

    // ── Bootstrap ─────────────────────────────────────────────────

    document.addEventListener( 'DOMContentLoaded', function () {
        const app = document.getElementById( 'pcio-me-app' );
        if ( ! app ) return;

        // Loading state
        const loader = el( 'p', 'me-stats-loading', cfg.i18n.loading );
        app.appendChild( loader );

        fetch( cfg.rest + '/statistics', {
            headers: {
                'X-WP-Nonce': cfg.nonce,
            },
        } )
            .then( function ( res ) {
                if ( ! res.ok ) throw new Error( cfg.i18n.errorLoad + res.status );
                return res.json();
            } )
            .then( function ( data ) {
                loader.remove();
                render( app, data );
            } )
            .catch( function ( err ) {
                loader.remove();
                showError( app, err.message );
            } );
    } );

    // ── Render ────────────────────────────────────────────────────

    function render( app, data ) {
        // Dark page header — matching the Members page header style.
        const header = document.createElement( 'div' );
        header.className = 'me-page-header';
        header.innerHTML = '<h1 class="me-page-title">' + escHtml( cfg.i18n.pageTitle || 'Statistics' ) + '</h1>';
        app.appendChild( header );

        // Sub-navigation tabs (Member list / Statistics / future Volunteers…).
        if ( Array.isArray( cfg.subnav ) && cfg.subnav.length > 1 ) {
            const nav = document.createElement( 'div' );
            nav.className = 'me-fin-tabs';
            nav.innerHTML = cfg.subnav.map( function ( item ) {
                return '<a class="me-fin-tab' + ( item.active ? ' me-fin-tab-active' : '' ) +
                    '" href="' + escAttr( item.url ) + '">' + escHtml( item.label ) + '</a>';
            } ).join( '' );
            app.appendChild( nav );
        }

        app.appendChild( buildMemberChart( data.members_by_year ) );
        app.appendChild( buildCounterSection( data ) );
        app.appendChild( buildSponsorTable( data.sponsor_clicks ) );
    }

    // ── Bar chart: members joining and leaving by year ────────────

    function buildMemberChart( rows ) {
        const section = el( 'section', 'me-stats-section' );
        section.appendChild( el( 'h2', 'me-stats-heading', cfg.i18n.chartTitle ) );

        if ( ! rows || rows.length === 0 ) {
            section.appendChild( el( 'p', 'me-stats-empty', cfg.i18n.noData ) );
            return section;
        }

        const wrap = el( 'div', 'me-stats-chart-wrap' );
        const canvas = document.createElement( 'canvas' );
        canvas.id = 'pcio-me-member-chart';
        wrap.appendChild( canvas );
        section.appendChild( wrap );

        const years   = rows.map( function ( r ) { return String( r.year ); } );
        const joined  = rows.map( function ( r ) { return r.joined; } );
        const left    = rows.map( function ( r ) { return r.left; } );

        new Chart( canvas, {
            type: 'bar',
            data: {
                labels: years,
                datasets: [
                    {
                        label:           cfg.i18n.labelJoined,
                        data:            joined,
                        backgroundColor: 'rgba(34, 197, 94, 0.8)',
                        borderColor:     'rgba(34, 197, 94, 1)',
                        borderWidth:     1,
                    },
                    {
                        label:           cfg.i18n.labelLeft,
                        data:            left,
                        backgroundColor: 'rgba(239, 68, 68, 0.8)',
                        borderColor:     'rgba(239, 68, 68, 1)',
                        borderWidth:     1,
                    },
                ],
            },
            options: {
                responsive:          true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    title:  { display: false },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, precision: 0 },
                    },
                },
            },
        } );

        return section;
    }

    // ── Counter section: page views / logins / total members ──────

    function buildCounterSection( data ) {
        const section = el( 'section', 'me-stats-section' );
        section.appendChild( el( 'h2', 'me-stats-heading', cfg.i18n.countersTitle ) );

        const grid = el( 'div', 'me-stats-counters' );

        const counters = [
            { label: cfg.i18n.totalMembers, value: data.total_members },
            { label: cfg.i18n.activeSubs,   value: data.active_subscriptions },
            { label: cfg.i18n.pageViews,    value: data.page_views },
            { label: cfg.i18n.logins,       value: data.login_count },
        ];

        counters.forEach( function ( c ) {
            const card = el( 'div', 'me-stats-counter-card' );
            card.appendChild( el( 'span', 'me-stats-counter-value', Number( c.value ).toLocaleString() ) );
            card.appendChild( el( 'span', 'me-stats-counter-label', c.label ) );
            grid.appendChild( card );
        } );

        section.appendChild( grid );
        return section;
    }

    // ── Sponsor clicks table ──────────────────────────────────────

    function buildSponsorTable( clicks ) {
        const section = el( 'section', 'me-stats-section' );
        section.appendChild( el( 'h2', 'me-stats-heading', cfg.i18n.sponsorTitle ) );

        if ( ! clicks || clicks.length === 0 ) {
            section.appendChild( el( 'p', 'me-stats-empty', cfg.i18n.noSponsorClicks ) );
            return section;
        }

        const table  = el( 'table', 'me-stats-table' );
        const thead  = document.createElement( 'thead' );
        const hrow   = document.createElement( 'tr' );
        [ cfg.i18n.sponsorUrl, cfg.i18n.sponsorClicks ].forEach( function ( h ) {
            hrow.appendChild( el( 'th', '', h ) );
        } );
        thead.appendChild( hrow );
        table.appendChild( thead );

        const tbody = document.createElement( 'tbody' );
        clicks.forEach( function ( row ) {
            const tr = document.createElement( 'tr' );
            const td1 = el( 'td' );
            const a   = document.createElement( 'a' );
            a.href        = row.sponsor_url;
            a.textContent = row.sponsor_url;
            a.target      = '_blank';
            a.rel         = 'noopener noreferrer';
            td1.appendChild( a );
            tr.appendChild( td1 );
            tr.appendChild( el( 'td', 'me-stats-clicks-num', String( row.click_count ) ) );
            tbody.appendChild( tr );
        } );
        table.appendChild( tbody );
        section.appendChild( table );

        return section;
    }

}() );
