/* ================================================================
   PCIO VIS Member Event — event-recurrence.js
   Recurrence tab: series paging, rule editor, reconcile.
   Rendered by event.js via window.PCIO_ME_Recurrence.render().
   ================================================================ */

( function () {
    'use strict';

    // Frequency codes (must match PCIO_VIS_Recurrence_DB::FREQ_*).
    const FREQ = { DAY: 1, WEEK: 2, MONTH: 3, MONTHDAY: 4, YEARDAY: 5, YEAR: 6 };

    // Which rule fields each frequency enables (interval is always on).
    // Fields: weekdays(5), weekOrdinal(6), weekday(7), monthDay(8), month(9).
    const ENABLED = {
        [ FREQ.DAY ]:      { weekdays: false, weekOrdinal: false, weekday: false, monthDay: false, month: false },
        [ FREQ.WEEK ]:     { weekdays: true,  weekOrdinal: false, weekday: false, monthDay: false, month: false },
        [ FREQ.MONTH ]:    { weekdays: false, weekOrdinal: true,  weekday: true,  monthDay: false, month: false },
        [ FREQ.MONTHDAY ]: { weekdays: false, weekOrdinal: false, weekday: false, monthDay: true,  month: false },
        [ FREQ.YEARDAY ]:  { weekdays: false, weekOrdinal: false, weekday: false, monthDay: true,  month: true  },
        [ FREQ.YEAR ]:     { weekdays: false, weekOrdinal: true,  weekday: true,  monthDay: false, month: true  },
    };

    let cfg   = null;   // { eventId, restBase, nonce, i18n, calUrl, eventBaseUrl }
    let ctx   = null;   // server context
    let root  = null;   // container element
    let dirty = false;  // rule edited but not yet recalculated
    let busy  = false;

    // ── Public entry ──────────────────────────────────────────────
    async function render( container, config ) {
        cfg   = config;
        root  = container;
        dirty = false;
        await load();
    }

    async function load() {
        try {
            ctx = await api( 'GET', '' );
            draw();
        } catch ( err ) {
            root.innerHTML = `<div class="me-tab-card"><p class="mer-error">${ esc( T().recErr ) }</p></div>`;
        }
    }

    // ── Rendering ─────────────────────────────────────────────────
    function draw() {
        const t = T();
        const isRec    = !! ctx.isRecurring;
        const isAnchor = !! ctx.isAnchor;

        let html = '<div class="me-tab-card mer-root">';
        html += `<div class="mer-intro">${ esc( t.recIntro ) }</div>`;

        if ( isRec ) {
            html += pagerHtml();
            html += commandsHtml();
        }

        if ( isAnchor ) {
            html += ruleFormHtml();
        } else if ( isRec ) {
            html += `<p class="mer-note">${ esc( t.recNotAnchor ) }</p>`;
        }

        // Series / diff area
        html += '<div class="mer-series" id="mer-series">';
        if ( dirty ) {
            html += `<div class="mer-recalc">
                        <button type="button" class="me-btn me-btn-primary" id="mer-recalc-btn">${ esc( t.recRecalculate ) }</button>
                     </div>`;
        } else {
            html += seriesTableHtml();
            html += diffHtml();
        }
        html += '</div>';

        html += '<div class="mer-status" id="mer-status" role="status" aria-live="polite"></div>';
        html += '</div>';

        root.innerHTML = html;
        wire();
    }

    function pagerHtml() {
        const t   = T();
        const pos = ctx.position || 0;
        const tot = ctx.total || 0;
        const label = ( t.recPositionOf || 'Event %1$d of %2$d' )
            .replace( '%1$d', pos ).replace( '%2$d', tot );

        const link = ( id, text, enabled ) => enabled && id
            ? `<a class="me-btn me-btn-secondary mer-page" href="${ esc( eventUrl( id ) ) }">${ esc( text ) }</a>`
            : `<span class="me-btn me-btn-secondary mer-page is-disabled">${ esc( text ) }</span>`;

        return `<div class="mer-pager">
            ${ link( ctx.firstId, t.recFirst, pos > 1 ) }
            ${ link( ctx.prevId,  t.recPrev,  pos > 1 ) }
            <span class="mer-pos">${ esc( label ) }</span>
            ${ link( ctx.nextId,  t.recNext,  pos > 0 && pos < tot ) }
            ${ link( ctx.lastId,  t.recLast,  pos > 0 && pos < tot ) }
        </div>`;
    }

    function commandsHtml() {
        const t = T();
        const multi = ( ctx.total || 0 ) > 1;
        return `<div class="mer-commands">
            <button type="button" class="me-btn me-btn-secondary" id="mer-resolve">${ esc( t.recResolve ) }</button>
            <button type="button" class="me-btn me-btn-danger" id="mer-del-all">${ esc( t.recDeleteAll ) }</button>
            ${ multi ? `<button type="button" class="me-btn me-btn-danger" id="mer-del-others">${ esc( t.recDeleteOthers ) }</button>` : '' }
        </div>`;
    }

    function ruleFormHtml() {
        const t = T();
        const r = ctx.rule || defaultRule();
        const freq = r.freqType || FREQ.DAY;
        const en = ENABLED[ freq ] || ENABLED[ FREQ.DAY ];

        const freqOptions = [
            [ FREQ.DAY,      t.recFreqDay ],
            [ FREQ.WEEK,     t.recFreqWeek ],
            [ FREQ.MONTH,    t.recFreqMonth ],
            [ FREQ.MONTHDAY, t.recFreqMonthday ],
            [ FREQ.YEARDAY,  t.recFreqYearday ],
            [ FREQ.YEAR,     t.recFreqYear ],
        ].map( ( [ v, lbl ] ) =>
            `<option value="${ v }" ${ v === freq ? 'selected' : '' }>${ esc( lbl ) }</option>`
        ).join( '' );

        const ordOptions = optionList( [
            [ '', t.recNone ], [ 1, t.recOrdFirst ], [ 2, t.recOrdSecond ],
            [ 3, t.recOrdThird ], [ 4, t.recOrdFourth ], [ 5, t.recOrdLast ],
        ], r.weekOrdinal );

        const weekdayOptions = optionList( [
            [ '', t.recNone ], [ 1, t.recMon ], [ 2, t.recTue ], [ 3, t.recWed ],
            [ 4, t.recThu ], [ 5, t.recFri ], [ 6, t.recSat ], [ 7, t.recSun ],
        ], r.weekday );

        const monthOptions = optionList( [
            [ '', t.recNone ], [ 1, t.recMonthJan ], [ 2, t.recMonthFeb ], [ 3, t.recMonthMar ],
            [ 4, t.recMonthApr ], [ 5, t.recMonthMay ], [ 6, t.recMonthJun ], [ 7, t.recMonthJul ],
            [ 8, t.recMonthAug ], [ 9, t.recMonthSep ], [ 10, t.recMonthOct ],
            [ 11, t.recMonthNov ], [ 12, t.recMonthDec ],
        ], r.month );

        const dayNames = [ t.recMon, t.recTue, t.recWed, t.recThu, t.recFri, t.recSat, t.recSun ];
        const weekdayChecks = dayNames.map( ( name, i ) =>
            `<label class="mer-day"><input type="checkbox" class="mer-weekday" data-idx="${ i }"
                ${ ( r.weekdays && r.weekdays[ i ] ) ? 'checked' : '' }> ${ esc( name ) }</label>`
        ).join( '' );

        return `<div class="mer-rules" id="mer-rules">
            <h3 class="mer-rules-title">${ esc( t.recRules ) }</h3>

            <div class="mer-row">
                <div class="mer-field">
                    <label class="me-form-label" for="mer-occ">${ esc( t.recOccurrences ) }</label>
                    <input class="me-form-input mer-input" type="number" min="1" id="mer-occ"
                           value="${ r.occurrences != null ? esc( r.occurrences ) : '' }">
                </div>
                <div class="mer-field">
                    <label class="me-form-label" for="mer-until">${ esc( t.recUntil ) }</label>
                    <input class="me-form-input mer-input" type="date" id="mer-until"
                           value="${ r.untilDate ? esc( r.untilDate ) : '' }">
                </div>
            </div>

            <div class="mer-row">
                <div class="mer-field">
                    <label class="me-form-label" for="mer-freq">${ esc( t.recEvery ) }</label>
                    <select class="me-form-input mer-input" id="mer-freq">${ freqOptions }</select>
                </div>
                <div class="mer-field">
                    <label class="me-form-label" for="mer-interval">${ esc( t.recInterval ) }</label>
                    <input class="me-form-input mer-input" type="number" min="1" id="mer-interval"
                           value="${ esc( r.intervalN || 1 ) }">
                </div>
            </div>

            <div class="mer-row mer-cond" data-cond="weekdays" style="${ en.weekdays ? '' : 'display:none' }">
                <div class="mer-field mer-field-full">
                    <div class="mer-days">${ weekdayChecks }</div>
                </div>
            </div>

            <div class="mer-row">
                <div class="mer-field mer-cond" data-cond="weekOrdinal" style="${ en.weekOrdinal ? '' : 'display:none' }">
                    <label class="me-form-label" for="mer-ordinal">${ esc( t.recWeek ) }</label>
                    <select class="me-form-input mer-input" id="mer-ordinal">${ ordOptions }</select>
                </div>
                <div class="mer-field mer-cond" data-cond="weekday" style="${ en.weekday ? '' : 'display:none' }">
                    <label class="me-form-label" for="mer-weekday">${ esc( t.recWeekday ) }</label>
                    <select class="me-form-input mer-input" id="mer-weekday">${ weekdayOptions }</select>
                </div>
                <div class="mer-field mer-cond" data-cond="monthDay" style="${ en.monthDay ? '' : 'display:none' }">
                    <label class="me-form-label" for="mer-monthday">${ esc( t.recMonthDay ) }</label>
                    <input class="me-form-input mer-input" type="number" min="1" max="31" id="mer-monthday"
                           value="${ r.monthDay != null ? esc( r.monthDay ) : '' }">
                </div>
                <div class="mer-field mer-cond" data-cond="month" style="${ en.month ? '' : 'display:none' }">
                    <label class="me-form-label" for="mer-month">${ esc( t.recMonth ) }</label>
                    <select class="me-form-input mer-input" id="mer-month">${ monthOptions }</select>
                </div>
            </div>
        </div>`;
    }

    function seriesTableHtml() {
        const t   = T();
        const rows = Array.isArray( ctx.projection ) ? ctx.projection : [];
        if ( ! rows.length ) {
            return '';
        }
        const body = rows.map( row => {
            let created, idCell;
            if ( row.state === 'exists' ) {
                created = `<span class="mer-yes">${ esc( t.recYes ) }</span>`;
                idCell  = `<a href="${ esc( eventUrl( row.eventId ) ) }">${ esc( row.eventId ) }</a>`;
            } else if ( row.state === 'missing' ) {
                created = `<span class="mer-no">${ esc( t.recNo ) }</span>`;
                idCell  = '—';
            } else {
                created = `<span class="mer-del">${ esc( t.recToDelete ) }</span>`;
                idCell  = `<a href="${ esc( eventUrl( row.eventId ) ) }">${ esc( row.eventId ) }</a>`;
            }
            const cls = row.eventId === cfg.eventId ? ' class="mer-current"' : '';
            return `<tr${ cls }><td>${ idCell }</td><td>${ esc( fmtDate( row.start ) ) }</td><td>${ created }</td></tr>`;
        } ).join( '' );

        return `<table class="mer-table">
            <thead><tr>
                <th>${ esc( t.recColEventId ) }</th>
                <th>${ esc( t.recColStart ) }</th>
                <th>${ esc( t.recColCreated ) }</th>
            </tr></thead>
            <tbody>${ body }</tbody>
        </table>`;
    }

    function diffHtml() {
        const t = T();
        const c = ctx.counts || { missing: 0, extra: 0 };
        if ( ! ctx.isRecurring ) {
            return '';
        }
        if ( ( c.missing + c.extra ) === 0 ) {
            return `<p class="mer-ok">${ esc( t.recNoChanges ) }</p>`;
        }
        const text = ( t.recDiffText || '%1$d / %2$d' )
            .replace( '%1$d', c.missing ).replace( '%2$d', c.extra );
        return `<div class="mer-diff">
            <p class="mer-diff-text">${ esc( text ) }</p>
            <button type="button" class="me-btn me-btn-danger" id="mer-reconcile">${ esc( t.recReconcile ) }</button>
        </div>`;
    }

    // ── Wiring ────────────────────────────────────────────────────
    function wire() {
        // Rule field changes → mark dirty and show Recalculate.
        if ( ctx.isAnchor ) {
            const rules = document.getElementById( 'mer-rules' );
            if ( rules ) {
                rules.addEventListener( 'input', onRuleChanged );
                rules.addEventListener( 'change', onRuleChanged );
            }
            const freq = document.getElementById( 'mer-freq' );
            if ( freq ) {
                freq.addEventListener( 'change', onFreqChanged );
            }
        }

        bind( 'mer-recalc-btn', recalc );
        bind( 'mer-reconcile', reconcile );
        bind( 'mer-resolve', resolveSeries );
        bind( 'mer-del-all', deleteAll );
        bind( 'mer-del-others', deleteOthers );
    }

    function onFreqChanged() {
        const freq = parseInt( document.getElementById( 'mer-freq' ).value, 10 ) || FREQ.DAY;
        const en = ENABLED[ freq ] || ENABLED[ FREQ.DAY ];
        document.querySelectorAll( '.mer-cond' ).forEach( el => {
            const key = el.getAttribute( 'data-cond' );
            el.style.display = en[ key ] ? '' : 'none';
        } );
        onRuleChanged();
    }

    function onRuleChanged() {
        if ( dirty ) {
            return;
        }
        dirty = true;
        const series = document.getElementById( 'mer-series' );
        if ( series ) {
            series.innerHTML = `<div class="mer-recalc">
                <button type="button" class="me-btn me-btn-primary" id="mer-recalc-btn">${ esc( T().recRecalculate ) }</button>
            </div>`;
            bind( 'mer-recalc-btn', recalc );
        }
    }

    // ── Actions ───────────────────────────────────────────────────
    function collectRule() {
        const val = id => {
            const el = document.getElementById( id );
            return el ? el.value.trim() : '';
        };
        const weekdays = [];
        document.querySelectorAll( '.mer-weekday' ).forEach( cb => {
            weekdays[ parseInt( cb.getAttribute( 'data-idx' ), 10 ) ] = cb.checked;
        } );
        return {
            occurrences: val( 'mer-occ' ),
            until_date:  val( 'mer-until' ),
            freq_type:   parseInt( val( 'mer-freq' ), 10 ) || FREQ.DAY,
            interval_n:  parseInt( val( 'mer-interval' ), 10 ) || 1,
            weekdays:    weekdays,
            week_ordinal: val( 'mer-ordinal' ),
            weekday:      val( 'mer-weekday' ),
            month_day:    val( 'mer-monthday' ),
            month:        val( 'mer-month' ),
        };
    }

    async function recalc() {
        if ( busy ) {
            return;
        }
        setBusy( true, T().recSaving );
        try {
            ctx = await api( 'PUT', '', { rule: collectRule() } );
            dirty = false;
            draw();
        } catch ( err ) {
            status( err.message || T().recErr, true );
        } finally {
            busy = false;
        }
    }

    async function reconcile() {
        if ( busy ) {
            return;
        }
        setBusy( true, T().recWorking );
        try {
            ctx = await api( 'POST', '/apply', {} );
            dirty = false;
            draw();
        } catch ( err ) {
            status( err.message || T().recErr, true );
        } finally {
            busy = false;
        }
    }

    async function resolveSeries() {
        if ( busy || ! confirm( T().recConfirmResolve ) ) {
            return;
        }
        setBusy( true, T().recWorking );
        try {
            await api( 'POST', '/resolve', {} );
            await load();
        } catch ( err ) {
            status( err.message || T().recErr, true );
        } finally {
            busy = false;
        }
    }

    async function deleteAll() {
        if ( busy || ! confirm( T().recConfirmDelAll ) ) {
            return;
        }
        setBusy( true, T().recWorking );
        try {
            await api( 'DELETE', '?mode=all' );
            window.location.href = cfg.calUrl;
        } catch ( err ) {
            status( err.message || T().recErr, true );
            busy = false;
        }
    }

    async function deleteOthers() {
        if ( busy || ! confirm( T().recConfirmDelOthers ) ) {
            return;
        }
        setBusy( true, T().recWorking );
        try {
            await api( 'DELETE', '?mode=others' );
            await load();
        } catch ( err ) {
            status( err.message || T().recErr, true );
        } finally {
            busy = false;
        }
    }

    // ── REST helper ───────────────────────────────────────────────
    async function api( method, path, body ) {
        const url = `${ cfg.restBase }/${ cfg.eventId }/recurrence${ path }`;
        const res = await fetch( url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce,
            },
            body: body !== undefined ? JSON.stringify( body ) : undefined,
        } );
        if ( ! res.ok ) {
            let msg = `HTTP ${ res.status }`;
            try {
                const j = await res.json();
                if ( j && j.message ) {
                    msg = j.message;
                }
            } catch ( e ) { /* ignore */ }
            throw new Error( msg );
        }
        if ( res.status === 204 ) {
            return null;
        }
        return res.json();
    }

    // ── Small utilities ───────────────────────────────────────────
    function T() {
        return ( cfg && cfg.i18n ) || {};
    }

    function defaultRule() {
        return {
            occurrences: null, untilDate: null, freqType: FREQ.DAY, intervalN: 1,
            weekdays: [ false, false, false, false, false, false, false ],
            weekOrdinal: null, weekday: null, monthDay: null, month: null,
        };
    }

    function optionList( pairs, current ) {
        const cur = current == null ? '' : String( current );
        return pairs.map( ( [ v, lbl ] ) =>
            `<option value="${ esc( v ) }" ${ String( v ) === cur ? 'selected' : '' }>${ esc( lbl ) }</option>`
        ).join( '' );
    }

    function eventUrl( id ) {
        if ( cfg.eventBaseUrl ) {
            return cfg.eventBaseUrl.replace( /\/?$/, '/' ) + id;
        }
        return window.location.pathname.replace( /\/\d+\/?$/, '/' + id );
    }

    function fmtDate( raw ) {
        if ( ! raw ) {
            return '';
        }
        const d = new Date( String( raw ).replace( ' ', 'T' ) );
        if ( isNaN( d.getTime() ) ) {
            return raw;
        }
        return d.toLocaleString( undefined, {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit',
        } );
    }

    function bind( id, fn ) {
        const el = document.getElementById( id );
        if ( el ) {
            el.addEventListener( 'click', fn );
        }
    }

    function setBusy( on, msg ) {
        busy = on;
        if ( on && msg ) {
            status( msg, false );
        }
    }

    function status( msg, isError ) {
        const el = document.getElementById( 'mer-status' );
        if ( el ) {
            el.textContent = msg || '';
            el.classList.toggle( 'mer-status-error', !! isError );
        }
    }

    function esc( s ) {
        return String( s == null ? '' : s )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' )
            .replace( /'/g, '&#39;' );
    }

    window.PCIO_ME_Recurrence = { render };
} )();
