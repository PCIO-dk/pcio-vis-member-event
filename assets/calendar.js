/* ================================================================
   PCIO VIS Member Event — calendar.js
   FullCalendar v6 integration with inline CRUD modal
   ================================================================ */

( function () {
    'use strict';

    const EVENTS_REST  = ( PCIO_ME.rest + '/events'        ).replace( /([^:]\/)\/+/g, '$1' );
    const TYPES_REST   = ( PCIO_ME.rest + '/event-types'   ).replace( /([^:]\/)\/+/g, '$1' );
    const RSC_REST     = ( PCIO_ME.rest + '/resources'     ).replace( /([^:]\/)\/+/g, '$1' );
    const ROLLING_REST = ( PCIO_ME.rest + '/rolling-texts' ).replace( /([^:]\/)\/+/g, '$1' );
    const NONCE       = PCIO_ME.nonce;
    const CAN_EDIT    = PCIO_ME.canEdit;
    const FC_LOCALE   = PCIO_ME.fcLocale || 'en';
    const T           = PCIO_ME.i18n || {};
    const EVENT_URL   = ( id ) => PCIO_ME.eventBaseUrl + id + '/';
    const PRESET_COLORS = [
        { hex: '#3b82f6', name: T.colorBlue   },
        { hex: '#10b981', name: T.colorGreen  },
        { hex: '#ef4444', name: T.colorRed    },
        { hex: '#8b5cf6', name: T.colorPurple },
        { hex: '#f59e0b', name: T.colorAmber  },
        { hex: '#06b6d4', name: T.colorTeal   },
        { hex: '#ec4899', name: T.colorPink   },
        { hex: '#64748b', name: T.colorSlate  },
    ];

    let calendar      = null;
    let editingId     = null;
    let pendingDelId  = null;
    let toastTimer    = null;
    let activeCalTab  = 'calendar';
    let calEventTypes = [];   // cached for the create modal dropdown
    let rollerFilter  = '';   // current filter value in the Rolling Texts tab

    // ── Bootstrap ─────────────────────────────────────────────────
    document.addEventListener( 'DOMContentLoaded', () => {
        buildShell();
        initCalendar();
    } );

    // ── Tab bar ───────────────────────────────────────────────────
    function buildCalTabBar() {
        const tabs = [
            { id: 'calendar',      label: T.tabCalendar    || 'Calendar'      },
            { id: 'event-types',   label: T.tabEventTypes  || 'Event Types'   },
            { id: 'resources',     label: T.tabResources   || 'Resources'     },
            { id: 'rolling-text',  label: T.tabRollingText || 'Rolling Texts' },
        ];
        return '<div class="me-fin-tabs me-cal-tabs" id="me-cal-tab-bar">' +
            tabs.map( t =>
                '<button class="me-fin-tab' + ( activeCalTab === t.id ? ' me-fin-tab-active' : '' ) +
                '" data-tab="' + t.id + '">' + escHtml( t.label ) + '</button>'
            ).join( '' ) +
            '</div>';
    }

    function switchCalTab( id ) {
        activeCalTab = id;
        document.querySelectorAll( '.me-cal-tabs .me-fin-tab' ).forEach( b =>
            b.classList.toggle( 'me-fin-tab-active', b.dataset.tab === id )
        );
        document.getElementById( 'me-cal-panel-calendar'     )?.classList.toggle( 'me-hidden', id !== 'calendar'     );
        document.getElementById( 'me-cal-panel-event-types'  )?.classList.toggle( 'me-hidden', id !== 'event-types'  );
        document.getElementById( 'me-cal-panel-resources'    )?.classList.toggle( 'me-hidden', id !== 'resources'    );
        document.getElementById( 'me-cal-panel-rolling-text' )?.classList.toggle( 'me-hidden', id !== 'rolling-text' );
        if ( id === 'event-types'  ) loadEventTypes();
        if ( id === 'resources'    ) loadResources();
        if ( id === 'rolling-text' ) loadRollingTexts();
    }

    // ── Page shell ────────────────────────────────────────────────
    function buildShell() {
        const app = document.getElementById( 'pcio-me-app' );
        if ( ! app ) return;

        const swatches = PRESET_COLORS.map( c =>
            `<button type="button" class="me-color-swatch" data-color="${ c.hex }"
                      title="${ escHtml( c.name ) }" style="background:${ c.hex }"
                      aria-label="${ escHtml( ( T.colorAria || 'Color: %s' ).replace( '%s', c.name ) ) }"></button>`
        ).join( '' );

        const newBtn = CAN_EDIT
            ? `<button class="me-btn me-btn-primary" id="me-btn-new-event">${ escHtml( T.btnNew ) }</button>`
            : '';

        app.innerHTML = `
        <div class="me-page-header">
            <h1 class="me-page-title">${ escHtml( T.pageTitle ) }</h1>
            <div class="me-header-right">${ newBtn }</div>
        </div>

        ${ buildCalTabBar() }

        <div id="me-cal-panel-calendar" class="me-table-card me-calendar-card">
            <div id="me-calendar"></div>
        </div>

        <div id="me-cal-panel-event-types" class="me-hidden me-table-card" style="padding:20px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                <strong>${ escHtml( T.tabEventTypes || 'Event Types' ) }</strong>
                ${ PCIO_ME.canManageTypes ? `<button class="me-btn me-btn-primary me-btn-sm" id="me-btn-new-type">${ escHtml( T.btnNewType || '+ New type' ) }</button>` : '' }
            </div>
            <table class="widefat striped" id="me-types-table"><tbody id="me-types-tbody"></tbody></table>
        </div>

        <div id="me-cal-panel-resources" class="me-hidden me-table-card" style="padding:20px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                <strong>${ escHtml( T.tabResources || 'Resources' ) }</strong>
                ${ PCIO_ME.canManageTypes ? `<button class="me-btn me-btn-primary me-btn-sm" id="me-btn-new-resource">${ escHtml( T.btnNewResource || '+ New resource' ) }</button>` : '' }
            </div>
            <table class="widefat striped" id="me-rsc-table"><tbody id="me-rsc-tbody"></tbody></table>
        </div>

        <div id="me-cal-panel-rolling-text" class="me-hidden me-table-card" style="padding:20px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                <div style="display:flex;align-items:center;gap:10px">
                    <strong>${ escHtml( T.tabRollingText || 'Rolling Texts' ) }</strong>
                    <input type="text" id="me-rt-filter" class="me-form-input" style="width:180px"
                           placeholder="${ escAttr( T.filterRollerId || 'Filter by roller ID' ) }">
                </div>
                ${ PCIO_ME.canManageTypes ? `<button class="me-btn me-btn-primary me-btn-sm" id="me-btn-new-rt">${ escHtml( T.btnNewRollingText || '+ New entry' ) }</button>` : '' }
            </div>
            <table class="widefat striped" id="me-rt-table">
                <thead><tr>
                    <th style="width:130px">${ escHtml( T.fieldRollerId || 'Roller ID' ) }</th>
                    <th style="width:150px">${ escHtml( T.fieldStartAt || 'Start' ) }</th>
                    <th style="width:150px">${ escHtml( T.fieldStopAt || 'Stop' ) }</th>
                    <th>${ escHtml( T.fieldRollingText || 'Text' ) }</th>
                    <th style="width:130px"></th>
                </tr></thead>
                <tbody id="me-rt-tbody"></tbody>
            </table>
        </div>

        <!-- ── Simple edit modal (shared for event-types & resources) ── -->
        <div class="me-overlay me-hidden" id="me-cal-item-overlay" role="dialog" aria-modal="true">
            <div class="me-modal">
                <div class="me-modal-header">
                    <h2 class="me-modal-title" id="me-cal-item-title">—</h2>
                    <button class="me-modal-close" id="me-cal-item-close">&times;</button>
                </div>
                <div class="me-modal-body" id="me-cal-item-body"></div>
                <div class="me-modal-footer">
                    <button class="me-btn me-btn-secondary" id="me-cal-item-cancel">${ escHtml( T.btnCancel ) }</button>
                    <button class="me-btn me-btn-primary"   id="me-cal-item-save">${ escHtml( T.btnSave ) }</button>
                </div>
            </div>
        </div>

        <!-- ── Create / Edit event modal ──────────────────────── -->
        <div class="me-overlay me-hidden" id="me-evt-overlay"
             role="dialog" aria-modal="true" aria-labelledby="me-evt-title-text">
            <div class="me-modal me-modal-lg">
                <div class="me-modal-header">
                    <h2 class="me-modal-title" id="me-evt-title-text">${ escHtml( T.titleCreate ) }</h2>
                    <button class="me-modal-close" id="me-evt-close"
                            aria-label="${ escHtml( T.closeLabel ) }">&times;</button>
                </div>
                <div class="me-modal-body">
                    <form id="me-evt-form" novalidate>
                        <div class="me-form-row full">
                            <div class="me-form-group">
                                <label class="me-form-label" for="f-evt-type">${ escHtml( T.fieldEventType || 'Event type' ) }</label>
                                <select class="me-form-input" id="f-evt-type" name="event_type_id">
                                    <option value="">${ escHtml( T.noEventType || '— None —' ) }</option>
                                </select>
                            </div>
                        </div>

                        <div class="me-form-row full">
                            <div class="me-form-group">
                                <label class="me-form-label" for="f-evt-title">
                                    ${ escHtml( T.fieldTitle ) } <span class="req">*</span>
                                </label>
                                <input class="me-form-input" type="text"
                                       id="f-evt-title" name="title"
                                       placeholder="${ escHtml( T.phTitle ) }" maxlength="200" required>
                                <span class="me-field-error" id="err-evt-title"></span>
                            </div>
                        </div>

                        <div class="me-form-row full">
                            <div class="me-form-group">
                                <label class="me-form-label">
                                    <input type="checkbox" id="f-evt-all-day" name="all_day"
                                           style="margin-right:6px;vertical-align:-1px">
                                    ${ escHtml( T.allDayEvt ) }
                                </label>
                            </div>
                        </div>

                        <div class="me-form-row">
                            <div class="me-form-group">
                                <label class="me-form-label" for="f-evt-start-date">
                                    ${ escHtml( T.fieldStartDate ) } <span class="req">*</span>
                                </label>
                                <input class="me-form-input" type="date"
                                       id="f-evt-start-date" name="start_date">
                                <span class="me-field-error" id="err-evt-start"></span>
                            </div>
                            <div class="me-form-group" id="g-evt-start-time">
                                <label class="me-form-label" for="f-evt-start-time">${ escHtml( T.fieldStartTime ) }</label>
                                <input class="me-form-input" type="time"
                                       id="f-evt-start-time" name="start_time">
                            </div>
                        </div>

                        <div class="me-form-row">
                            <div class="me-form-group">
                                <label class="me-form-label" for="f-evt-end-date">${ escHtml( T.fieldEndDate ) }</label>
                                <input class="me-form-input" type="date"
                                       id="f-evt-end-date" name="end_date">
                            </div>
                            <div class="me-form-group" id="g-evt-end-time">
                                <label class="me-form-label" for="f-evt-end-time">${ escHtml( T.fieldEndTime ) }</label>
                                <input class="me-form-input" type="time"
                                       id="f-evt-end-time" name="end_time">
                            </div>
                        </div>

                        <div class="me-form-row">
                            <div class="me-form-group">
                                <label class="me-form-label" for="f-evt-location">${ escHtml( T.fieldLocation ) }</label>
                                <input class="me-form-input" type="text"
                                       id="f-evt-location" name="location"
                                       placeholder="${ escHtml( T.phLocation ) }" maxlength="200">
                            </div>
                        </div>
                        <input type="hidden" id="f-evt-color" value="${ PRESET_COLORS[0].hex }">
                    </form>
                </div>
                <div class="me-modal-footer">
                    <div style="flex:1">
                        <button class="me-btn me-btn-danger me-hidden"
                                id="me-evt-delete">${ escHtml( T.btnDeleteEvt ) }</button>
                    </div>
                    <button class="me-btn me-btn-secondary" id="me-evt-cancel">${ escHtml( T.btnCancel ) }</button>
                    <button class="me-btn me-btn-primary"   id="me-evt-save">${ escHtml( T.btnSave ) }</button>
                </div>
            </div>
        </div>

        <!-- ── Delete confirm modal ───────────────────────────── -->
        <div class="me-overlay me-hidden" id="me-evt-del-overlay"
             role="alertdialog" aria-modal="true">
            <div class="me-modal me-modal-sm">
                <div class="me-modal-header">
                    <h2 class="me-modal-title">${ escHtml( T.titleDelete ) }</h2>
                    <button class="me-modal-close" id="me-evt-del-close"
                            aria-label="${ escHtml( T.closeLabel ) }">&times;</button>
                </div>
                <div class="me-delete-body">
                    <div class="me-delete-icon">
                        <svg viewBox="0 0 24 24">
                            <polyline points="3 6 5 6 21 6"/>
                            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                            <path d="M10 11v6"/><path d="M14 11v6"/>
                            <path d="M9 6V4h6v2"/>
                        </svg>
                    </div>
                    <p class="me-delete-title">${ escHtml( T.confirmDel ) }</p>
                    <p class="me-delete-name" id="me-evt-del-name">&mdash;</p>
                    <p class="me-delete-warning">${ escHtml( T.cannotUndo ) }</p>
                </div>
                <div class="me-delete-footer">
                    <button class="me-btn me-btn-secondary" id="me-evt-del-cancel">${ escHtml( T.btnCancel ) }</button>
                    <button class="me-btn me-btn-danger"    id="me-evt-del-confirm">${ escHtml( T.btnDelete ) }</button>
                </div>
            </div>
        </div>

        <div class="me-toast me-hidden" id="me-toast" role="status" aria-live="polite"></div>
        `;

        // ── Static bindings ──────────────────────────────────────
        // Calendar tab bar
        document.getElementById( 'me-cal-tab-bar' )?.addEventListener( 'click', ( e ) => {
            const btn = e.target.closest( '.me-fin-tab' );
            if ( btn && btn.dataset.tab ) switchCalTab( btn.dataset.tab );
        } );

        // Item modal (event types / resources)
        document.getElementById( 'me-cal-item-close'  )?.addEventListener( 'click', closeItemModal );
        document.getElementById( 'me-cal-item-cancel' )?.addEventListener( 'click', closeItemModal );
        document.getElementById( 'me-cal-item-save'   )?.addEventListener( 'click', saveCalItem );
        document.getElementById( 'me-cal-item-overlay' )?.addEventListener( 'click', ( e ) => {
            if ( e.target === e.currentTarget ) closeItemModal();
        } );

        if ( CAN_EDIT ) {
            document.getElementById( 'me-btn-new-event' )
                .addEventListener( 'click', () => openModal( null, null ) );
            document.getElementById( 'me-evt-close'   ).addEventListener( 'click', closeModal );
            document.getElementById( 'me-evt-cancel'  ).addEventListener( 'click', closeModal );
            document.getElementById( 'me-evt-save'    ).addEventListener( 'click', submitForm );
            // New type / resource buttons (only present when canManageTypes)
            document.getElementById( 'me-btn-new-type'     )?.addEventListener( 'click', () => openItemModal( 'type',     null ) );
            document.getElementById( 'me-btn-new-resource' )?.addEventListener( 'click', () => openItemModal( 'resource', null ) );
        }

        document.getElementById( 'me-evt-overlay' ).addEventListener( 'click', ( e ) => {
            if ( e.target === e.currentTarget ) closeModal();
        } );
        document.getElementById( 'me-evt-del-overlay' ).addEventListener( 'click', ( e ) => {
            if ( e.target === e.currentTarget ) closeDeleteModal();
        } );
        document.addEventListener( 'keydown', ( e ) => {
            if ( e.key !== 'Escape' ) return;
            if ( ! document.getElementById( 'me-evt-overlay' ).classList.contains( 'me-hidden' ) ) {
                closeModal();
            } else {
                closeDeleteModal();
            }
        } );

        document.getElementById( 'f-evt-all-day' )
            .addEventListener( 'change', syncTimeVisibility );
    }

    // ── ISO-8601 week number (Monday start, week 1 has ≥4 days in the new
    //    year). Matches the Danish week numbering. ─────────────────
    function isoWeekNumber( date ) {
        const d = new Date( Date.UTC( date.getFullYear(), date.getMonth(), date.getDate() ) );
        const dayNum = d.getUTCDay() || 7; // Mon=1 … Sun=7
        d.setUTCDate( d.getUTCDate() + 4 - dayNum ); // shift to the week's Thursday
        const yearStart = new Date( Date.UTC( d.getUTCFullYear(), 0, 1 ) );
        return Math.ceil( ( ( d - yearStart ) / 86400000 + 1 ) / 7 );
    }

    // ── FullCalendar init ─────────────────────────────────────────
    function initCalendar() {
        const el = document.getElementById( 'me-calendar' );
        if ( ! el || typeof FullCalendar === 'undefined' ) {
            if ( el ) el.textContent = T.errFcLoad || 'FullCalendar could not be loaded.';
            return;
        }

        calendar = new FullCalendar.Calendar( el, {
            headerToolbar: {
                left:   'prev,next today',
                center: 'title',
                right:  'dayGridMonth,timeGridWeek,timeGridDay,listMonth',
            },
            buttonText: {
                today:     T.btnToday,
                month:     T.btnMonth,
                week:      T.btnWeek,
                day:       T.btnDay,
                list:      T.btnList,
            },
            initialView:  'dayGridMonth',
            locale:       FC_LOCALE,
            weekNumberCalculation: 'ISO',
            height:       'auto',
            editable:     CAN_EDIT,
            selectable:   CAN_EDIT,
            selectMirror: true,
            dayMaxEvents: true,
            nowIndicator: true,
            timeZone:     'local',
            events:       fetchEvents,
            select:       onSelect,
            eventClick:   onEventClick,
            eventDrop:    onEventDrop,
            eventResize:  onEventResize,
            datesSet( info ) {
                // Show the ISO week number in the week-view title via a CSS ::before
                // that reads this data attribute. We must NOT rewrite the title's text
                // node — FullCalendar owns it (Preact) and would duplicate it on re-render.
                const titleEl = el.querySelector( '.fc-toolbar-title' );
                if ( ! titleEl ) {
                    return;
                }
                if ( info.view.type === 'timeGridWeek' ) {
                    titleEl.setAttribute( 'data-week', ( T.weekLabel || 'Week' ) + ' ' + isoWeekNumber( info.start ) + ' · ' );
                } else {
                    titleEl.removeAttribute( 'data-week' );
                }
            },
            eventDidMount( info ) {
                if ( info.event.extendedProps.location ) {
                    info.el.title = info.event.extendedProps.location;
                }
            },
        } );

        calendar.render();
    }

    // ── FullCalendar event source ─────────────────────────────────
    async function fetchEvents( info, successCallback, failureCallback ) {
        try {
            const url = EVENTS_REST
                + '?start=' + encodeURIComponent( info.startStr )
                + '&end='   + encodeURIComponent( info.endStr );
            const res = await fetch( url, { headers: { 'X-WP-Nonce': NONCE } } );
            if ( ! res.ok ) throw new Error( 'HTTP ' + res.status );
            const data = await res.json();
            successCallback( data.map( toFCEvent ) );
        } catch ( err ) {
            failureCallback( err );
            showToast( T.errLoadEvents + err.message, 'error' );
        }
    }

    /** Convert DB row → FullCalendar event object */
    function toFCEvent( e ) {
        const color  = e.color || PRESET_COLORS[0].hex;
        const allDay = Boolean( parseInt( e.all_day, 10 ) );
        // For all-day: stored end is inclusive (same day at 23:59:59).
        // FC needs the exclusive next-day date-only string for correct multi-day display.
        function fcAllDayEnd( dtStr ) {
            if ( ! dtStr ) return null;
            const p = dtStr.substring( 0, 10 ).split( '-' ).map( Number );
            const d = new Date( p[0], p[1] - 1, p[2] + 1 );
            return d.getFullYear() + '-'
                + String( d.getMonth() + 1 ).padStart( 2, '0' ) + '-'
                + String( d.getDate() ).padStart( 2, '0' );
        }
        return {
            id:              String( e.id ),
            title:           e.title,
            start:           allDay ? e.start_datetime.substring( 0, 10 ) : toISO( e.start_datetime ),
            end:             e.end_datetime
                                ? ( allDay ? fcAllDayEnd( e.end_datetime ) : toISO( e.end_datetime ) )
                                : null,
            allDay,
            backgroundColor: color,
            borderColor:     color,
            textColor:       '#fff',
            extendedProps: {
                description: e.description || '',
                location:    e.location    || '',
                color,
            },
        };
    }

    // ── Calendar interaction handlers ─────────────────────────────
    function onSelect( info ) {
        const startDate = info.startStr.substring( 0, 10 );
        const startTime = info.allDay ? '' : ( info.startStr.substring( 11, 16 ) || '' );

        let endDate = '';
        let endTime = '';
        if ( info.endStr ) {
            if ( info.allDay ) {
                // FC exclusive end → adjust to inclusive for display.
                // Use local date parts (not toISOString) to avoid UTC offset
                // rolling the date back by a day in UTC+ timezones.
                const parts = info.endStr.substring( 0, 10 ).split( '-' ).map( Number );
                const d     = new Date( parts[0], parts[1] - 1, parts[2] - 1 );
                const adj   = d.getFullYear() + '-'
                    + String( d.getMonth() + 1 ).padStart( 2, '0' ) + '-'
                    + String( d.getDate() ).padStart( 2, '0' );
                endDate = adj;
            } else {
                endDate = info.endStr.substring( 0, 10 );
                endTime = info.endStr.substring( 11, 16 ) || '';
            }
        }

        openModal( null, { startDate, startTime, endDate, endTime, allDay: info.allDay } );
        calendar.unselect();
    }

    function onEventClick( info ) {
        // Always navigate to the detail page on click
        window.location.href = EVENT_URL( info.event.id );
    }

    async function onEventDrop( info ) {
        const ev = info.event;
        try {
            await patchEvent( ev.id, {
                start_datetime: toDBStart( ev.startStr, ev.allDay ),
                end_datetime:   ev.endStr ? toDBEnd( ev.endStr, ev.allDay ) : null,
                all_day:        ev.allDay ? 1 : 0,
            } );
            showToast( T.toastMoved, 'success' );
        } catch ( err ) {
            info.revert();
            showToast( T.errMove + err.message, 'error' );
        }
    }

    async function onEventResize( info ) {
        const ev = info.event;
        try {
            await patchEvent( ev.id, {
                end_datetime: ev.endStr ? toDBEnd( ev.endStr, ev.allDay ) : null,
            } );
            showToast( T.toastResized, 'success' );
        } catch ( err ) {
            info.revert();
            showToast( T.errResize + err.message, 'error' );
        }
    }

    async function patchEvent( id, data ) {
        const res = await fetch( EVENTS_REST + '/' + id, {
            method:  'PUT',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
            body:    JSON.stringify( data ),
        } );
        if ( ! res.ok ) throw new Error( 'HTTP ' + res.status );
        return res.json();
    }

    // ── Create / Edit modal ───────────────────────────────────────
    function openModal( id, defaults ) {
        editingId = id;
        clearErrors();

        const today = new Date().toISOString().substring( 0, 10 );
        document.getElementById( 'f-evt-start-date' ).value = defaults?.startDate || today;
        document.getElementById( 'f-evt-start-time' ).value = defaults?.startTime || '';
        document.getElementById( 'f-evt-end-date'   ).value = defaults?.endDate   || '';
        document.getElementById( 'f-evt-end-time'   ).value = defaults?.endTime   || '';
        document.getElementById( 'f-evt-all-day'    ).checked =
            defaults ? Boolean( defaults.allDay ) : true;
        document.getElementById( 'f-evt-title' ).value = '';
        document.getElementById( 'f-evt-color' ).value = PRESET_COLORS[0].hex;
        clearErrors();

        // Populate event type select (load once, then reuse cache).
        populateTypeSelect();

        syncTimeVisibility();
        document.getElementById( 'me-evt-overlay' ).classList.remove( 'me-hidden' );
        setTimeout( () => document.getElementById( 'f-evt-title' )?.focus(), 40 );
    }

    function populateTypeSelect() {
        const sel = document.getElementById( 'f-evt-type' );
        if ( ! sel ) return;
        if ( calEventTypes.length ) {
            fillTypeSelect( sel );
            return;
        }
        fetch( TYPES_REST, { headers: { 'X-WP-Nonce': NONCE } } )
            .then( r => r.json() )
            .then( types => {
                calEventTypes = types || [];
                fillTypeSelect( sel );
            } )
            .catch( () => {} );
    }

    function fillTypeSelect( sel ) {
        const current = sel.value;
        const placeholder = T.noEventType || '\u2014 None \u2014';
        sel.innerHTML = `<option value="">${ escHtml( placeholder ) }</option>` +
            calEventTypes.map( t => `<option value="${ t.id }"${ String( t.id ) === current ? ' selected' : '' }>${ escHtml( t.name ) }</option>` ).join( '' );
        if ( ! sel._typeListenerAdded ) {
            sel._typeListenerAdded = true;
            sel.addEventListener( 'change', () => {
                const t = calEventTypes.find( x => String( x.id ) === sel.value );
                if ( t && t.color ) {
                    document.getElementById( 'f-evt-color' ).value = t.color;
                }
            } );
        }
    }

    function closeModal() {
        document.getElementById( 'me-evt-overlay' )?.classList.add( 'me-hidden' );
        clearErrors();
        editingId = null;
    }

    async function submitForm() {
        clearErrors();

        const title     = document.getElementById( 'f-evt-title'      ).value.trim();
        const allDay    = document.getElementById( 'f-evt-all-day'    ).checked;
        const startDate = document.getElementById( 'f-evt-start-date' ).value;
        const startTime = document.getElementById( 'f-evt-start-time' ).value || '00:00';
        const endDate   = document.getElementById( 'f-evt-end-date'   ).value;
        const endTime   = document.getElementById( 'f-evt-end-time'   ).value || '00:00';

        if ( ! title )     { setError( 'evt-title', T.errTitleReq );  return; }
        if ( ! startDate ) { setError( 'evt-start', T.errStartReq );  return; }

        const startDatetime = allDay ? ( startDate + ' 00:00:00' ) : ( startDate + ' ' + startTime + ':00' );

        // All-day end: default to startDate if blank; store inclusive end at 23:59:59.
        let endDatetime = null;
        if ( allDay ) {
            endDatetime = ( endDate || startDate ) + ' 23:59:59';
        } else if ( endDate ) {
            endDatetime = endDate + ' ' + endTime + ':00';
        }

        const data = {
            title:          title,
            start_datetime: startDatetime,
            end_datetime:   endDatetime,
            all_day:        allDay ? 1 : 0,
            location:       document.getElementById( 'f-evt-location' ).value.trim(),
            color:          document.getElementById( 'f-evt-color' ).value || PRESET_COLORS[0].hex,
            event_type_id:  parseInt( document.getElementById( 'f-evt-type' )?.value || 0, 10 ) || null,
        };

        const saveBtn = document.getElementById( 'me-evt-save' );
        saveBtn.disabled    = true;
        saveBtn.textContent = T.stateSaving;

        try {
            let res;
            if ( editingId === null ) {
                res = await fetch( EVENTS_REST, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body:    JSON.stringify( data ),
                } );
            } else {
                res = await fetch( EVENTS_REST + '/' + editingId, {
                    method:  'PUT',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body:    JSON.stringify( data ),
                } );
            }

            if ( ! res.ok ) {
                const err = await res.json().catch( () => ( {} ) );
                throw new Error( err.message || 'HTTP ' + res.status );
            }

            const saved = await res.json();
            // Redirect to event detail page to fill in remaining details
            window.location.href = EVENT_URL( saved.id );
        } catch ( err ) {
            showToast( T.errSave + err.message, 'error' );
        } finally {
            saveBtn.disabled    = false;
            saveBtn.textContent = T.btnSave;
        }
    }

    // ── Delete modal ──────────────────────────────────────────────
    function openDeleteModal( id ) {
        pendingDelId = id;
        const fcEvent = calendar?.getEventById( String( id ) );
        const nameEl  = document.getElementById( 'me-evt-del-name' );
        if ( nameEl ) {
            nameEl.innerHTML = ( T.deletePrompt || 'Delete %s?' ).replace(
                '%s',
                '<strong>' + escHtml( fcEvent?.title || T.thisEvent ) + '</strong>'
            );
        }
        document.getElementById( 'me-evt-del-overlay' ).classList.remove( 'me-hidden' );
        document.getElementById( 'me-evt-del-confirm' )?.focus();
    }

    function closeDeleteModal() {
        document.getElementById( 'me-evt-del-overlay' )?.classList.add( 'me-hidden' );
        pendingDelId = null;
    }

    async function confirmDelete() {
        if ( pendingDelId === null ) return;
        const btn = document.getElementById( 'me-evt-del-confirm' );
        btn.disabled    = true;
        btn.textContent = T.stateDeleting;

        try {
            const res = await fetch( EVENTS_REST + '/' + pendingDelId, {
                method:  'DELETE',
                headers: { 'X-WP-Nonce': NONCE },
            } );
            if ( ! res.ok && res.status !== 204 ) throw new Error( 'HTTP ' + res.status );

            calendar?.getEventById( String( pendingDelId ) )?.remove();
            closeDeleteModal();
            showToast( T.toastDeleted, 'info' );
        } catch ( err ) {
            showToast( T.errDelete + err.message, 'error' );
        } finally {
            btn.disabled    = false;
            btn.textContent = T.btnDelete;
        }
    }

    // ── UI helpers ────────────────────────────────────────────────
    function syncTimeVisibility() {
        const allDay = document.getElementById( 'f-evt-all-day' )?.checked;
        const vis    = allDay ? 'hidden' : 'visible';
        const sg     = document.getElementById( 'g-evt-start-time' );
        if ( sg ) sg.style.visibility = vis;
        const eg     = document.getElementById( 'g-evt-end-time' );
        if ( eg ) eg.style.visibility = vis;
    }

    function selectColor( hex ) {
        document.getElementById( 'f-evt-color' ).value = hex;
        document.querySelectorAll( '.me-color-swatch' ).forEach( s => {
            s.classList.toggle( 'selected', s.dataset.color === hex );
        } );
    }

    function showToast( msg, type ) {
        const toast = document.getElementById( 'me-toast' );
        if ( ! toast ) return;
        toast.textContent = msg;
        toast.className   = 'me-toast ' + type;
        if ( toastTimer ) clearTimeout( toastTimer );
        toastTimer = setTimeout( () => toast.classList.add( 'me-hidden' ), 3500 );
    }

    // ── Form / error helpers ──────────────────────────────────────
    function setVal( name, value ) {
        const el = document.querySelector( `[name="${ name }"]` );
        if ( el ) el.value = value;
    }

    function clearErrors() {
        document.querySelectorAll( '.me-field-error' ).forEach( el => { el.textContent = ''; } );
        document.querySelectorAll( '.me-form-input.is-invalid' )
            .forEach( el => el.classList.remove( 'is-invalid' ) );
    }

    function setError( fieldId, msg ) {
        const errEl = document.getElementById( 'err-' + fieldId );
        const input = document.getElementById( 'f-'   + fieldId );
        if ( errEl ) errEl.textContent = msg;
        if ( input ) { input.classList.add( 'is-invalid' ); input.focus(); }
    }

    // ── Date/time utilities ───────────────────────────────────────
    // MySQL "2026-06-15 10:00:00"  →  ISO "2026-06-15T10:00:00"
    function toISO( dt ) {
        return dt ? dt.replace( ' ', 'T' ) : '';
    }

    // FC startStr → MySQL DATETIME start (local time).
    function toDBStart( str, allDay ) {
        if ( ! str ) return null;
        if ( allDay ) return str.substring( 0, 10 ) + ' 00:00:00';
        return str.substring( 0, 19 ).replace( 'T', ' ' );
    }

    // FC endStr (exclusive) → MySQL DATETIME end. All-day: subtract 1 day and use 23:59:59.
    function toDBEnd( str, allDay ) {
        if ( ! str ) return null;
        if ( allDay ) {
            const p = str.substring( 0, 10 ).split( '-' ).map( Number );
            const d = new Date( p[0], p[1] - 1, p[2] - 1 );
            return d.getFullYear() + '-'
                + String( d.getMonth() + 1 ).padStart( 2, '0' ) + '-'
                + String( d.getDate() ).padStart( 2, '0' )
                + ' 23:59:59';
        }
        return str.substring( 0, 19 ).replace( 'T', ' ' );
    }

    // ── Security ──────────────────────────────────────────────────
    function escHtml( str ) {
        return String( str ?? '' )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }
    function escAttr( str ) { return escHtml( str ); }

    // ── Event Types tab ───────────────────────────────────────────
    let eventTypes = [];

    async function loadEventTypes() {
        try {
            const res = await fetch( TYPES_REST, { headers: { 'X-WP-Nonce': NONCE } } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            eventTypes = await res.json();
            renderEventTypes();
        } catch ( err ) {
            showToast( ( T.errLoadEvents || 'Could not load: ' ) + err.message, 'error' );
        }
    }

    const ACCESS_LABELS = {
        'public':     () => T.accessPublic     || 'Public',
        'members':    () => T.accessMembers    || 'Members only',
        'volunteers': () => T.accessVolunteers || 'Volunteers only',
    };

    function renderEventTypes() {
        const tbody = document.getElementById( 'me-types-tbody' );
        if ( ! tbody ) return;
        if ( ! eventTypes.length ) {
            tbody.innerHTML = `<tr><td colspan="4" style="color:#94a3b8;text-align:center;padding:20px">—</td></tr>`;
            return;
        }
        tbody.innerHTML = eventTypes.map( t => `
            <tr>
                <td><span class="wg-color-dot" style="background:${ escAttr( t.color || '#94a3b8' ) };display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;vertical-align:middle"></span>${ escHtml( t.name ) }</td>
                <td>${ escHtml( ( ACCESS_LABELS[ t.access ] || ( () => t.access ) )() ) }</td>
                <td>${ PCIO_ME.canManageTypes ? `
                    <button class="me-btn me-btn-secondary me-btn-sm" data-action="edit-type"   data-id="${ t.id }">${ escHtml( T.btnEdit || 'Edit' ) }</button>
                    <button class="me-btn me-btn-danger me-btn-sm"    data-action="delete-type" data-id="${ t.id }">${ escHtml( T.btnDelete || 'Delete' ) }</button>
                ` : '' }</td>
            </tr>` ).join( '' );

        if ( PCIO_ME.canManageTypes ) {
            tbody.querySelectorAll( '[data-action="edit-type"]'   ).forEach( b => b.addEventListener( 'click', () => openItemModal( 'type', eventTypes.find( t => t.id == b.dataset.id ) ) ) );
            tbody.querySelectorAll( '[data-action="delete-type"]' ).forEach( b => b.addEventListener( 'click', () => deleteCalItem( 'type', parseInt( b.dataset.id ) ) ) );
        }
    }

    // ── Resources tab ─────────────────────────────────────────────
    let resources = [];

    async function loadResources() {
        try {
            const res = await fetch( RSC_REST, { headers: { 'X-WP-Nonce': NONCE } } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            resources = await res.json();
            renderResources();
        } catch ( err ) {
            showToast( ( T.errLoadEvents || 'Could not load: ' ) + err.message, 'error' );
        }
    }

    function renderResources() {
        const tbody = document.getElementById( 'me-rsc-tbody' );
        if ( ! tbody ) return;
        if ( ! resources.length ) {
            tbody.innerHTML = `<tr><td colspan="3" style="color:#94a3b8;text-align:center;padding:20px">—</td></tr>`;
            return;
        }
        tbody.innerHTML = resources.map( r => `
            <tr>
                <td><strong>${ escHtml( r.name ) }</strong></td>
                <td style="color:#64748b">${ escHtml( r.description || '' ) }</td>
                <td>${ PCIO_ME.canManageTypes ? `
                    <button class="me-btn me-btn-secondary me-btn-sm" data-action="edit-rsc"   data-id="${ r.id }">${ escHtml( T.btnEdit || 'Edit' ) }</button>
                    <button class="me-btn me-btn-danger me-btn-sm"    data-action="delete-rsc" data-id="${ r.id }">${ escHtml( T.btnDelete || 'Delete' ) }</button>
                ` : '' }</td>
            </tr>` ).join( '' );

        if ( PCIO_ME.canManageTypes ) {
            tbody.querySelectorAll( '[data-action="edit-rsc"]'   ).forEach( b => b.addEventListener( 'click', () => openItemModal( 'resource', resources.find( r => r.id == b.dataset.id ) ) ) );
            tbody.querySelectorAll( '[data-action="delete-rsc"]' ).forEach( b => b.addEventListener( 'click', () => deleteCalItem( 'resource', parseInt( b.dataset.id ) ) ) );
        }
    }

    // ── Rolling Texts tab ──────────────────────────────────────────
    let rollingTexts = [];

    async function loadRollingTexts() {
        // Wire the filter input and new-entry button on first visit.
        const filterEl = document.getElementById( 'me-rt-filter' );
        if ( filterEl && ! filterEl.dataset.wired ) {
            filterEl.dataset.wired = '1';
            filterEl.addEventListener( 'input', () => {
                rollerFilter = filterEl.value.trim().toLowerCase();
                renderRollingTexts();
            } );
        }
        const newBtn = document.getElementById( 'me-btn-new-rt' );
        if ( newBtn && ! newBtn.dataset.wired ) {
            newBtn.dataset.wired = '1';
            newBtn.addEventListener( 'click', () => openItemModal( 'rolling-text', null ) );
        }
        try {
            const res = await fetch( ROLLING_REST, { headers: { 'X-WP-Nonce': NONCE } } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            rollingTexts = await res.json();
            renderRollingTexts();
        } catch ( err ) {
            showToast( ( T.errLoadEvents || 'Could not load: ' ) + err.message, 'error' );
        }
    }

    function renderRollingTexts() {
        const tbody = document.getElementById( 'me-rt-tbody' );
        if ( ! tbody ) return;
        const filtered = rollerFilter
            ? rollingTexts.filter( r => r.roller_id.toLowerCase().includes( rollerFilter ) )
            : rollingTexts;
        if ( ! filtered.length ) {
            tbody.innerHTML = `<tr><td colspan="5" style="color:#94a3b8;text-align:center;padding:20px">—</td></tr>`;
            return;
        }
        tbody.innerHTML = filtered.map( r => `
            <tr>
                <td><code>${ escHtml( r.roller_id ) }</code></td>
                <td style="white-space:nowrap">${ escHtml( ( r.start_at || '' ).substring( 0, 16 ).replace( 'T', ' ' ) ) }</td>
                <td style="white-space:nowrap">${ escHtml( ( r.stop_at  || '' ).substring( 0, 16 ).replace( 'T', ' ' ) ) }</td>
                <td style="color:#374151">${ escHtml( r.text || '' ) }</td>
                <td>${ PCIO_ME.canManageTypes ? `
                    <button class="me-btn me-btn-secondary me-btn-sm" data-action="edit-rt"   data-id="${ r.id }">Edit</button>
                    <button class="me-btn me-btn-danger   me-btn-sm" data-action="delete-rt" data-id="${ r.id }">Delete</button>
                ` : '' }</td>
            </tr>` ).join( '' );

        if ( PCIO_ME.canManageTypes ) {
            tbody.querySelectorAll( '[data-action="edit-rt"]'   ).forEach( b => b.addEventListener( 'click', () => openItemModal( 'rolling-text', rollingTexts.find( r => r.id == b.dataset.id ) ) ) );
            tbody.querySelectorAll( '[data-action="delete-rt"]' ).forEach( b => b.addEventListener( 'click', () => deleteCalItem( 'rolling-text', parseInt( b.dataset.id ) ) ) );
        }
    }

    // ── Shared item modal (event types, resources, rolling texts) ─────────
    // kind: 'type' | 'resource' | 'rolling-text'
    let itemModalKind = null;
    let itemModalId   = null;

    function openItemModal( kind, item ) {
        itemModalKind = kind;
        itemModalId   = item?.id ?? null;
        const title   = document.getElementById( 'me-cal-item-title' );
        const body    = document.getElementById( 'me-cal-item-body' );
        if ( kind === 'type' ) {
            title.textContent = item ? item.name : ( T.titleNewType || 'New Event Type' );
            body.innerHTML = `
                <div class="me-form-group">
                    <label class="me-form-label" for="ci-name">${ escHtml( T.fieldTypeName || 'Name' ) } *</label>
                    <input class="me-form-input" type="text" id="ci-name" value="${ escAttr( item?.name || '' ) }" maxlength="100">
                </div>
                <div class="me-form-group">
                    <label class="me-form-label" for="ci-access">${ escHtml( T.fieldTypeAccess || 'Access' ) }</label>
                    <select class="me-form-input" id="ci-access">
                        <option value="public"     ${ item?.access === 'public'     ? 'selected' : '' }>${ escHtml( T.accessPublic     || 'Public'         ) }</option>
                        <option value="members"    ${ ( ! item || item.access === 'members' ) ? 'selected' : '' }>${ escHtml( T.accessMembers    || 'Members only'   ) }</option>
                        <option value="volunteers" ${ item?.access === 'volunteers' ? 'selected' : '' }>${ escHtml( T.accessVolunteers || 'Volunteers only' ) }</option>
                    </select>
                </div>
                <div class="me-form-group">
                    <label class="me-form-label" for="ci-color">${ escHtml( T.fieldTypeColor || 'Colour' ) }</label>
                    <input type="color" id="ci-color" value="${ escAttr( item?.color || '#3b82f6' ) }">
                </div>
                <div class="me-form-group" id="ci-default-resources-wrap" style="margin-top:8px">
                    <label class="me-form-label">${ escHtml( T.fieldDefaultResources || 'Default resources' ) }</label>
                    <span style="color:#94a3b8;font-size:0.85em">${ escHtml( T.loadingResources || 'Loading…' ) }</span>
                </div>`;
            renderDefaultResourceCheckboxes( item?.default_resource_ids || [] );
        } else if ( kind === 'resource' ) {
            title.textContent = item ? item.name : ( T.titleNewResource || 'New Resource' );
            body.innerHTML = `
                <div class="me-form-group">
                    <label class="me-form-label" for="ci-name">${ escHtml( T.fieldResourceName || 'Name' ) } *</label>
                    <input class="me-form-input" type="text" id="ci-name" value="${ escAttr( item?.name || '' ) }" maxlength="150">
                </div>
                <div class="me-form-group">
                    <label class="me-form-label" for="ci-desc">${ escHtml( T.fieldResourceDesc || 'Description' ) }</label>
                    <textarea class="me-form-textarea" id="ci-desc" rows="3">${ escHtml( item?.description || '' ) }</textarea>
                </div>`;
        } else {
            // kind === 'rolling-text'
            title.textContent = item
                ? ( T.titleEditRollingText || 'Edit Rolling Text' )
                : ( T.titleNewRollingText  || 'New Rolling Text'  );
            const startVal = item?.start_at ? item.start_at.substring( 0, 16 ).replace( ' ', 'T' ) : '';
            const stopVal  = item?.stop_at  ? item.stop_at.substring(  0, 16 ).replace( ' ', 'T' ) : '';
            body.innerHTML = `
                <div class="me-form-group">
                    <label class="me-form-label" for="rt-roller-id">${ escHtml( T.fieldRollerId || 'Roller ID' ) } *</label>
                    <input class="me-form-input" type="text" id="rt-roller-id"
                           value="${ escAttr( item?.roller_id || rollerFilter ) }"
                           maxlength="100" placeholder="e.g. homepage">
                </div>
                <div class="me-form-group">
                    <label class="me-form-label" for="rt-start-at">${ escHtml( T.fieldStartAt || 'Start' ) } *</label>
                    <input class="me-form-input" type="datetime-local" id="rt-start-at" value="${ escAttr( startVal ) }">
                </div>
                <div class="me-form-group">
                    <label class="me-form-label" for="rt-stop-at">${ escHtml( T.fieldStopAt || 'Stop' ) } *</label>
                    <input class="me-form-input" type="datetime-local" id="rt-stop-at" value="${ escAttr( stopVal ) }">
                </div>
                <div class="me-form-group">
                    <label class="me-form-label" for="rt-text">${ escHtml( T.fieldRollingText || 'Text' ) } *</label>
                    <textarea class="me-form-textarea" id="rt-text" rows="3">${ escHtml( item?.text || '' ) }</textarea>
                </div>`;
        }
        document.getElementById( 'me-cal-item-overlay' ).classList.remove( 'me-hidden' );
        document.getElementById( kind === 'rolling-text' ? 'rt-roller-id' : 'ci-name' )?.focus();
    }

    async function renderDefaultResourceCheckboxes( checkedIds ) {
        const wrap = document.getElementById( 'ci-default-resources-wrap' );
        if ( ! wrap ) return;
        if ( ! resources.length ) {
            try {
                const res = await fetch( RSC_REST, { headers: { 'X-WP-Nonce': NONCE } } );
                if ( res.ok ) resources = await res.json();
            } catch ( _e ) {}
        }
        if ( ! resources.length ) {
            wrap.innerHTML = `<label class="me-form-label">${ escHtml( T.fieldDefaultResources || 'Default resources' ) }</label>
                <span style="color:#94a3b8;font-size:0.85em">${ escHtml( T.noResources || 'No resources defined.' ) }</span>`;
            return;
        }
        const checkedSet = new Set( checkedIds.map( Number ) );
        wrap.innerHTML = `<label class="me-form-label">${ escHtml( T.fieldDefaultResources || 'Default resources' ) }</label>` +
            resources.map( r =>
                `<label style="display:flex;align-items:center;gap:8px;margin:6px 0;font-weight:normal;padding:4px 0">
                    <input type="checkbox" name="ci-rsc" value="${ escAttr( r.id ) }"${ checkedSet.has( Number( r.id ) ) ? ' checked' : '' }>
                    ${ escHtml( r.name ) }
                </label>`
            ).join( '' );
    }

    function closeItemModal() {
        document.getElementById( 'me-cal-item-overlay' ).classList.add( 'me-hidden' );
        itemModalKind = null;
        itemModalId   = null;
    }

    async function saveCalItem() {
        if ( itemModalKind === 'rolling-text' ) {
            const rollerId = document.getElementById( 'rt-roller-id' )?.value.trim();
            const startAt  = document.getElementById( 'rt-start-at' )?.value;
            const stopAt   = document.getElementById( 'rt-stop-at'  )?.value;
            const text     = document.getElementById( 'rt-text'     )?.value.trim();
            if ( ! rollerId || ! startAt || ! stopAt || ! text ) return;
            const rtUrl    = itemModalId ? `${ ROLLING_REST }/${ itemModalId }` : ROLLING_REST;
            const rtMethod = itemModalId ? 'PUT' : 'POST';
            try {
                const res = await fetch( rtUrl, {
                    method:  rtMethod,
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                    body:    JSON.stringify( { roller_id: rollerId, start_at: startAt, stop_at: stopAt, text } ),
                } );
                if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
                closeItemModal();
                loadRollingTexts();
                showToast( ( itemModalId ? T.toastRtUpdated : T.toastRtCreated ) || 'Saved.', 'success' );
            } catch ( err ) {
                showToast( ( T.errSave || 'Save failed: ' ) + err.message, 'error' );
            }
            return;
        }
        const name = document.getElementById( 'ci-name' )?.value.trim();
        if ( ! name ) return;
        const isType = itemModalKind === 'type';
        const url    = itemModalId
            ? `${ isType ? TYPES_REST : RSC_REST }/${ itemModalId }`
            : ( isType ? TYPES_REST : RSC_REST );
        const method = itemModalId ? 'PUT' : 'POST';
        const body   = isType
            ? {
                name,
                access: document.getElementById( 'ci-access' )?.value,
                color:  document.getElementById( 'ci-color' )?.value,
                default_resource_ids: Array.from(
                    document.querySelectorAll( 'input[name="ci-rsc"]:checked' ),
                    el => parseInt( el.value, 10 )
                ),
            }
            : { name, description: document.getElementById( 'ci-desc' )?.value };
        try {
            const res = await fetch( url, {
                method,
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify( body ),
            } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            closeItemModal();
            if ( isType ) { calEventTypes = []; loadEventTypes(); } else { loadResources(); }
            showToast( itemModalId
                ? ( isType ? T.toastTypeUpdated : T.toastRscUpdated ) || 'Updated.'
                : ( isType ? T.toastTypeCreated : T.toastRscCreated ) || 'Created.',
                'success'
            );
        } catch ( err ) {
            showToast( ( T.errSave || 'Save failed: ' ) + err.message, 'error' );
        }
    }

    async function deleteCalItem( kind, id ) {
        if ( kind === 'rolling-text' ) {
            try {
                const res = await fetch( `${ ROLLING_REST }/${ id }`, {
                    method:  'DELETE',
                    headers: { 'X-WP-Nonce': NONCE },
                } );
                if ( ! res.ok && res.status !== 204 ) throw new Error( `HTTP ${ res.status }` );
                loadRollingTexts();
                showToast( T.toastRtDeleted || 'Deleted.', 'success' );
            } catch ( err ) {
                showToast( ( T.errDelete || 'Delete failed: ' ) + err.message, 'error' );
            }
            return;
        }
        const isType = kind === 'type';
        try {
            const res = await fetch( `${ isType ? TYPES_REST : RSC_REST }/${ id }`, {
                method:  'DELETE',
                headers: { 'X-WP-Nonce': NONCE },
            } );
            if ( ! res.ok && res.status !== 204 ) throw new Error( `HTTP ${ res.status }` );
            if ( isType ) { calEventTypes = []; loadEventTypes(); } else { loadResources(); }
            showToast( ( isType ? T.toastTypeDeleted : T.toastRscDeleted ) || 'Deleted.', 'success' );
        } catch ( err ) {
            showToast( ( T.errDelete || 'Delete failed: ' ) + err.message, 'error' );
        }
    }

} )();
