/* ================================================================
   PCIO VIS Member Event — calendar.js
   FullCalendar v6 integration with inline CRUD modal
   ================================================================ */

( function () {
    'use strict';

    const EVENTS_REST  = ( PCIO_ME.rest + '/events'        ).replace( /([^:]\/)\/+/g, '$1' );
    const TYPES_REST   = ( PCIO_ME.rest + '/event-types'   ).replace( /([^:]\/)\/+/g, '$1' );
    const ROLES_REST   = ( PCIO_ME.rest + '/event-roles'   ).replace( /([^:]\/)\/+/g, '$1' );
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
    let filterActive  = Boolean( PCIO_ME.filterActive ); // personal calendar filter saved?
    let filterDateRange = PCIO_ME.filterRange
        ? { from: String( PCIO_ME.filterRange.from || '' ), to: String( PCIO_ME.filterRange.to || '' ) }
        : null; // the active date rule: { from, to } inclusive, either bound may be ''

    // ── Bootstrap ─────────────────────────────────────────────────
    document.addEventListener( 'DOMContentLoaded', () => {
        buildShell();
        initCalendar();
        const selectHashTab = () => {
            const id = window.location.hash.slice( 1 );
            if ( [ 'calendar', 'event-types', 'event-roles', 'resources', 'rolling-text' ].includes( id ) ) switchCalTab( id );
        };
        selectHashTab();
        window.addEventListener( 'hashchange', selectHashTab );
    } );

    // ── Tab bar ───────────────────────────────────────────────────
    function buildCalTabBar() {
        const tabs = [
            { id: 'calendar',      label: T.tabCalendar    || 'Calendar'      },
            { id: 'event-types',   label: T.tabEventTypes  || 'Event Types'   },
            { id: 'event-roles',   label: T.tabEventRoles  || 'Event Roles'   },
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
        if ( window.location.hash !== '#' + id ) window.history.replaceState( null, '', '#' + id );
        document.querySelectorAll( '.me-cal-tabs .me-fin-tab' ).forEach( b =>
            b.classList.toggle( 'me-fin-tab-active', b.dataset.tab === id )
        );
        document.getElementById( 'me-cal-panel-calendar'     )?.classList.toggle( 'me-hidden', id !== 'calendar'     );
        document.getElementById( 'me-cal-panel-event-types'  )?.classList.toggle( 'me-hidden', id !== 'event-types'  );
        document.getElementById( 'me-cal-panel-event-roles'  )?.classList.toggle( 'me-hidden', id !== 'event-roles'  );
        document.getElementById( 'me-cal-panel-resources'    )?.classList.toggle( 'me-hidden', id !== 'resources'    );
        document.getElementById( 'me-cal-panel-rolling-text' )?.classList.toggle( 'me-hidden', id !== 'rolling-text' );
        // "Filter" and "+ New event" only apply to the calendar itself.
        document.getElementById( 'me-cal-header-actions' )?.classList.toggle( 'me-hidden', id !== 'calendar' );
        if ( id === 'event-types'  ) loadEventTypes();
        if ( id === 'event-roles'  ) loadEventRoles();
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
        const filterBtn = `<button type="button" class="me-btn me-cal-filter-btn${ filterActive ? ' is-active' : '' }" id="me-btn-cal-filter"
                aria-pressed="${ filterActive ? 'true' : 'false' }">${ escHtml( T.btnFilter || 'Filter' ) }</button>`;

        app.innerHTML = `
        <div class="me-page-header">
            <h1 class="me-page-title">${ escHtml( T.pageTitle ) }</h1>
            <div class="me-header-right" id="me-cal-header-actions">${ filterBtn }${ newBtn }</div>
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
            <table class="widefat striped" id="me-types-table">
                <thead><tr>
                    <th>${ escHtml( T.fieldTypeName || 'Name' ) }</th>
                    <th style="width:150px">${ escHtml( T.colScope || 'Scope' ) }</th>
                    <th style="width:120px;text-align:center">${ escHtml( T.fieldInCafeTime || 'During cafe opening hours' ) }</th>
                    <th>${ escHtml( T.tabResources || 'Resources' ) }</th>
                    <th style="width:70px;text-align:center">${ escHtml( T.fieldTypeColor || 'Colour' ) }</th>
                    <th style="width:1%"></th>
                </tr></thead>
                <tbody id="me-types-tbody"></tbody>
            </table>
        </div>

        <div id="me-cal-panel-event-roles" class="me-hidden me-table-card" style="padding:20px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                <strong>${ escHtml( T.tabEventRoles || 'Event Roles' ) }</strong>
                ${ PCIO_ME.canManageTypes ? `<button class="me-btn me-btn-primary me-btn-sm" id="me-btn-new-role">${ escHtml( T.btnNewRole || '+ New role' ) }</button>` : '' }
            </div>
            <table class="widefat striped" id="me-roles-table">
                <thead><tr>
                    <th style="width:220px">${ escHtml( T.fieldRoleName || 'Name' ) }</th>
                    <th>${ escHtml( T.fieldRoleDesc || 'Description' ) }</th>
                    <th style="width:130px"></th>
                </tr></thead>
                <tbody id="me-roles-tbody"></tbody>
            </table>
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
                                <select class="me-form-input" id="f-evt-type" name="event_type_id" required aria-required="true">
                                    <option value="">${ escHtml( T.chooseEventType || '\u2014 Choose event type \u2014' ) }</option>
                                </select>
                                <span class="me-field-error" id="err-evt-type"></span>
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

        document.getElementById( 'me-btn-cal-filter' )?.addEventListener( 'click', openFilterModal );

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
                right:  'dayGridMonth,timeGridWeek,timeGridDay,list',
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
            // The generic "list" view has no fixed duration, so it honours the
            // visibleRange below (the built-in listMonth would ignore it). Its
            // title shows the personal filter's date interval when one is active.
            views: {
                list: {
                    dateIncrement:     { month: 1 },
                    listDayFormat:     { month: 'long', day: 'numeric', year: 'numeric' },
                    listDaySideFormat: { weekday: 'long' },
                    titleFormat:       listTitleFormat,
                },
            },
            visibleRange: makeVisibleRange(),
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
                + '&end='   + encodeURIComponent( info.endStr )
                + '&filtered=1';
            const res = await fetch( url, { headers: { 'X-WP-Nonce': NONCE } } );
            if ( ! res.ok ) throw new Error( 'HTTP ' + res.status );
            const data = await res.json();
            successCallback( data.map( toFCEvent ) );
        } catch ( err ) {
            failureCallback( err );
            showToast( T.errLoadEvents + err.message, 'error' );
        }
    }

    // ── Personal calendar filter ──────────────────────────────────
    const FILTER_REST = ( PCIO_ME.rest + '/calendar-filter' ).replace( /([^:]\/)\/+/g, '$1' );
    let filterOptions = null;

    /**
     * Active date interval from a saved filter, or null when its date rule is
     * off/excluding. Either bound may be an empty string (open-ended): an empty
     * "from" means all past events, an empty "to" means all future events.
     */
    function dateRangeFromFilter( filter, active ) {
        const d = filter && filter.date;
        if ( ! active || ! d || ! d.on || d.exclude ) return null;
        return { from: String( d.from || '' ), to: String( d.to || '' ) };
    }

    /** "YYYY-MM-DD" shifted by N days, using local date parts (no UTC rollover). */
    function addDaysStr( dateStr, days ) {
        const p = dateStr.split( '-' ).map( Number );
        const d = new Date( p[0], p[1] - 1, p[2] + days );
        return d.getFullYear() + '-'
            + String( d.getMonth() + 1 ).padStart( 2, '0' ) + '-'
            + String( d.getDate() ).padStart( 2, '0' );
    }

    /** "YYYY-MM-DD" (local) → Date at local midnight. */
    function parseYmd( ymd ) {
        const p = ymd.split( '-' ).map( Number );
        return new Date( p[0], p[1] - 1, p[2] );
    }

    // Bounds used for open-ended filter limits (FullCalendar needs both ends).
    const LIST_MIN_DATE = new Date( 1970, 0, 1 );
    const LIST_MAX_DATE = new Date( 2999, 11, 31 );

    /**
     * Range the list view should display. With a date filter this is the filter's
     * interval (inclusive bounds, open ends extended to the sentinels above);
     * without one it is the calendar month of the current date.
     */
    function currentListRange( currentDate ) {
        if ( ! filterDateRange ) {
            const d = currentDate instanceof Date ? currentDate : new Date();
            return {
                start: new Date( d.getFullYear(), d.getMonth(), 1 ),
                end:   new Date( d.getFullYear(), d.getMonth() + 1, 1 ),
            };
        }
        const start = filterDateRange.from ? parseYmd( filterDateRange.from ) : LIST_MIN_DATE;
        const end   = filterDateRange.to
            ? parseYmd( addDaysStr( filterDateRange.to, 1 ) ) // inclusive → exclusive
            : LIST_MAX_DATE;
        return { start, end };
    }

    /** Localised "13 Oct 2026". Falls back to the raw value if Intl is unhappy. */
    function formatFilterDate( ymd ) {
        try {
            return new Intl.DateTimeFormat( FC_LOCALE, { year: 'numeric', month: 'short', day: 'numeric' } )
                .format( parseYmd( ymd ) );
        } catch ( e ) {
            return ymd;
        }
    }

    /** List title for the active filter: an interval, "From …", "Until …" or "All events". */
    function listFilterTitle() {
        if ( ! filterDateRange ) return '';
        const from = filterDateRange.from ? formatFilterDate( filterDateRange.from ) : '';
        const to   = filterDateRange.to   ? formatFilterDate( filterDateRange.to )   : '';
        if ( from && to ) return from + ' \u2013 ' + to;
        if ( from ) return ( T.listFrom  || 'From %s'  ).replace( '%s', from );
        if ( to )   return ( T.listUntil || 'Until %s' ).replace( '%s', to );
        return T.listAll || 'All events';
    }

    /**
     * titleFormat for the list view. FullCalendar calls it as a formatter with
     * { start, end } (the view's range). With a date filter we show the filter's
     * interval; otherwise the built-in month + year for the current date.
     */
    function listTitleFormat() {
        if ( filterDateRange ) return listFilterTitle();
        const d = calendar && typeof calendar.getDate === 'function' ? calendar.getDate() : new Date();
        try {
            return new Intl.DateTimeFormat( FC_LOCALE, { year: 'numeric', month: 'long' } ).format( d );
        } catch ( e ) {
            return '';
        }
    }

    /**
     * visibleRange for the whole calendar. Only the generic "list" view has no
     * duration, so this is ignored by month/week/day (their duration wins) and
     * only the list view uses it. A fresh function reference is created on every
     * call so FullCalendar rebuilds the range instead of reusing its memoised one.
     */
    function makeVisibleRange() {
        return function ( currentDate ) { return currentListRange( currentDate ); };
    }

    function applyListView() {
        if ( ! calendar ) return;
        // Changing the visible range rebuilds the date profile and refetches; the
        // explicit refetch also covers non-date filter changes.
        calendar.setOption( 'visibleRange', makeVisibleRange() );
        calendar.refetchEvents();
    }

    function setFilterActive( active ) {
        filterActive = Boolean( active );
        const btn = document.getElementById( 'me-btn-cal-filter' );
        if ( ! btn ) return;
        btn.classList.toggle( 'is-active', filterActive );
        btn.setAttribute( 'aria-pressed', filterActive ? 'true' : 'false' );
        btn.title = filterActive ? ( T.filterActiveTitle || 'Personal filter in use' ) : '';
    }

    function filterRow( rule, label, control ) {
        return `<tr data-rule="${ rule }">
            <td class="me-cal-filter-check"><input type="checkbox" data-part="on" aria-label="${ escAttr( ( T.filterUseRule || 'Use filter: %s' ).replace( '%s', label ) ) }"></td>
            <td class="me-cal-filter-check"><input type="checkbox" data-part="exclude" aria-label="${ escAttr( ( T.filterExcludeRule || 'Hide matches: %s' ).replace( '%s', label ) ) }"></td>
            <th scope="row">${ escHtml( label ) }</th>
            <td>${ control }</td>
        </tr>`;
    }

    function checkList( name, items ) {
        if ( ! items.length ) return `<em>${ escHtml( T.filterNone || 'None' ) }</em>`;
        return '<div class="me-cal-filter-list">' + items.map( i =>
            `<label><input type="checkbox" name="${ name }" value="${ i.id }"> ${ escHtml( i.name ) }</label>`
        ).join( '' ) + '</div>';
    }

    function buildFilterModal( opts ) {
        const members = opts.members.map( m =>
            `<option value="${ m.id }">${ escHtml( m.name ) }</option>`
        ).join( '' );
        const quick = [
            [ 'internal', T.filterQuickInternal || 'Internal' ],
            [ 'public',   T.filterQuickPublic   || 'Public' ],
            [ 'cafe',     T.filterQuickCafe     || 'Cafe' ],
            [ 'none',     T.filterQuickNone     || 'Clear' ],
        ].map( ( [ k, l ] ) => `<button type="button" class="me-btn me-btn-secondary me-btn-sm" data-quick="${ k }">${ escHtml( l ) }</button>` ).join( '' );

        const overlay = document.createElement( 'div' );
        overlay.className = 'me-overlay me-hidden';
        overlay.id = 'me-cal-filter-overlay';
        overlay.setAttribute( 'role', 'dialog' );
        overlay.setAttribute( 'aria-modal', 'true' );
        overlay.setAttribute( 'aria-labelledby', 'me-cal-filter-title' );
        overlay.innerHTML = `
            <div class="me-modal me-modal-lg">
                <div class="me-modal-header">
                    <h2 class="me-modal-title" id="me-cal-filter-title">${ escHtml( T.filterTitle || 'Calendar filter' ) }</h2>
                    <button class="me-modal-close" id="me-cal-filter-close" aria-label="${ escAttr( T.closeLabel ) }">&times;</button>
                </div>
                <div class="me-modal-body">
                    <table class="me-cal-filter-table">
                        <thead><tr>
                            <th>${ escHtml( T.filterColUse || 'Filter' ) }</th>
                            <th>${ escHtml( T.filterColExclude || 'Exclude' ) }</th>
                            <th>${ escHtml( T.filterColCriterion || 'Criterion' ) }</th>
                            <th></th>
                        </tr></thead>
                        <tbody>
                            ${ filterRow( 'date', T.filterDate || 'Date', `
                                <label>${ escHtml( T.filterFrom || 'From' ) } <input type="date" id="me-cf-from"></label>
                                <label>${ escHtml( T.filterTo || 'To' ) } <input type="date" id="me-cf-to"></label>` ) }
                            ${ filterRow( 'text', T.filterText || 'Text', `
                                <input type="search" id="me-cf-text" maxlength="100" class="me-cf-wide"
                                       placeholder="${ escAttr( T.filterTextPh || 'Search title, resume and description' ) }">` ) }
                            ${ filterRow( 'person', T.filterPerson || 'Crew member', `
                                <select id="me-cf-person" aria-label="${ escAttr( T.filterPerson || 'Crew member' ) }">
                                    <option value="0">${ escHtml( T.filterMissingVolunteer || 'Missing volunteer (open shift)' ) }</option>
                                    ${ members }
                                </select>
                                ${ opts.me ? `<button type="button" class="me-btn me-btn-secondary me-btn-sm" id="me-cf-me">${ escHtml( T.filterMe || 'Me' ) }</button>` : '' }` ) }
                            ${ filterRow( 'published', T.filterPublished || 'Published on homepage', '' ) }
                            ${ filterRow( 'types', T.fieldEventType || 'Event type', `
                                <div class="me-cf-quick">${ quick }</div>
                                ${ checkList( 'me-cf-type', opts.types ) }` ) }
                            ${ filterRow( 'roles', T.filterRoles || 'Crew role', checkList( 'me-cf-role', opts.roles ) ) }
                        </tbody>
                    </table>
                    <p class="me-cal-filter-help">${ escHtml( T.filterHelp || '' ) }</p>
                </div>
                <div class="me-modal-footer">
                    <button class="me-btn me-btn-danger me-cf-clear" id="me-cal-filter-clear">${ escHtml( T.filterClear || 'Clear' ) }</button>
                    <button class="me-btn me-btn-secondary" id="me-cal-filter-cancel">${ escHtml( T.btnCancel ) }</button>
                    <button class="me-btn me-btn-primary" id="me-cal-filter-ok">${ escHtml( T.filterOk || 'OK' ) }</button>
                </div>
            </div>`;
        document.getElementById( 'pcio-me-app' ).appendChild( overlay );

        // Changing a criterion's value switches that criterion on.
        overlay.querySelector( 'tbody' ).addEventListener( 'input', ( e ) => {
            if ( e.target.dataset.part ) return;
            const row = e.target.closest( 'tr[data-rule]' );
            if ( row ) row.querySelector( '[data-part="on"]' ).checked = true;
        } );
        overlay.querySelectorAll( '[data-quick]' ).forEach( btn => btn.addEventListener( 'click', () => {
            const match = {
                internal: t => t.access !== 'public',
                public:   t => t.access === 'public',
                cafe:     t => t.in_cafe_time,
                none:     () => false,
            }[ btn.dataset.quick ];
            const ids = new Set( opts.types.filter( match ).map( t => String( t.id ) ) );
            overlay.querySelectorAll( 'input[name="me-cf-type"]' ).forEach( cb => { cb.checked = ids.has( cb.value ); } );
            overlay.querySelector( 'tr[data-rule="types"] [data-part="on"]' ).checked = ids.size > 0;
        } ) );
        overlay.querySelector( '#me-cf-me' )?.addEventListener( 'click', () => {
            overlay.querySelector( '#me-cf-person' ).value = String( opts.me );
            overlay.querySelector( 'tr[data-rule="person"] [data-part="on"]' ).checked = true;
        } );
        overlay.querySelector( '#me-cal-filter-close'  ).addEventListener( 'click', () => closeFilterModal() );
        overlay.querySelector( '#me-cal-filter-cancel' ).addEventListener( 'click', () => closeFilterModal() );
        overlay.querySelector( '#me-cal-filter-ok'     ).addEventListener( 'click', saveFilter );
        overlay.querySelector( '#me-cal-filter-clear'  ).addEventListener( 'click', clearFilter );
        return overlay;
    }

    function fillFilterForm( overlay, f ) {
        Object.keys( f ).forEach( rule => {
            const row = overlay.querySelector( `tr[data-rule="${ rule }"]` );
            if ( ! row ) return;
            row.querySelector( '[data-part="on"]' ).checked      = Boolean( f[ rule ].on );
            row.querySelector( '[data-part="exclude"]' ).checked = Boolean( f[ rule ].exclude );
        } );
        overlay.querySelector( '#me-cf-from' ).value   = f.date.from || '';
        overlay.querySelector( '#me-cf-to' ).value     = f.date.to || '';
        overlay.querySelector( '#me-cf-text' ).value   = f.text.value || '';
        const person = overlay.querySelector( '#me-cf-person' );
        person.value = String( f.person.member_id || 0 );
        if ( person.selectedIndex < 0 ) person.value = '0';
        const setIds = ( name, ids ) => {
            const set = new Set( ( ids || [] ).map( String ) );
            overlay.querySelectorAll( `input[name="${ name }"]` ).forEach( cb => { cb.checked = set.has( cb.value ); } );
        };
        setIds( 'me-cf-type', f.types.ids );
        setIds( 'me-cf-role', f.roles.ids );
    }

    function readFilterForm( overlay ) {
        const rule = ( name, extra ) => {
            const row = overlay.querySelector( `tr[data-rule="${ name }"]` );
            return Object.assign( {
                on:      row.querySelector( '[data-part="on"]' ).checked,
                exclude: row.querySelector( '[data-part="exclude"]' ).checked,
            }, extra );
        };
        const ids = name => Array.from( overlay.querySelectorAll( `input[name="${ name }"]:checked` ), cb => parseInt( cb.value, 10 ) );
        return {
            date:      rule( 'date',   { from: overlay.querySelector( '#me-cf-from' ).value, to: overlay.querySelector( '#me-cf-to' ).value } ),
            text:      rule( 'text',   { value: overlay.querySelector( '#me-cf-text' ).value.trim() } ),
            person:    rule( 'person', { member_id: parseInt( overlay.querySelector( '#me-cf-person' ).value, 10 ) || 0 } ),
            published: rule( 'published' ),
            types:     rule( 'types',  { ids: ids( 'me-cf-type' ) } ),
            roles:     rule( 'roles',  { ids: ids( 'me-cf-role' ) } ),
        };
    }

    async function openFilterModal() {
        try {
            const res = await fetch( FILTER_REST, { headers: { 'X-WP-Nonce': NONCE } } );
            if ( ! res.ok ) throw new Error( 'HTTP ' + res.status );
            const data = await res.json();
            if ( ! filterOptions ) {
                filterOptions = data.options;
                buildFilterModal( filterOptions );
            }
            const overlay = document.getElementById( 'me-cal-filter-overlay' );
            fillFilterForm( overlay, data.filter );
            setFilterActive( data.active );
            overlay.classList.remove( 'me-hidden' );
            PCIOVisModal.open( overlay, closeFilterModal );
            setTimeout( () => overlay.querySelector( '#me-cf-from' )?.focus(), 40 );
        } catch ( err ) {
            showToast( ( T.filterErrLoad || 'Could not load the filter: ' ) + err.message, 'error' );
        }
    }

    function closeFilterModal( saved = false ) {
        if ( ! PCIOVisModal.close( 'me-cal-filter-overlay', saved ) ) return;
        document.getElementById( 'me-cal-filter-overlay' )?.classList.add( 'me-hidden' );
        document.getElementById( 'me-btn-cal-filter' )?.focus();
    }

    async function sendFilter( method, body ) {
        const res = await fetch( FILTER_REST, {
            method,
            headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
            body: body ? JSON.stringify( body ) : undefined,
        } );
        const data = await res.json().catch( () => ( {} ) );
        if ( ! res.ok ) throw new Error( data.message || 'HTTP ' + res.status );
        setFilterActive( data.active );
        filterDateRange = dateRangeFromFilter( data.filter, data.active );
        closeFilterModal( true );
        // Re-apply the list view range; FullCalendar rebuilds the date profile and
        // refetches the events for the new interval.
        applyListView();
    }

    async function saveFilter() {
        const overlay = document.getElementById( 'me-cal-filter-overlay' );
        const filter  = readFilterForm( overlay );
        if ( filter.date.from && filter.date.to && filter.date.to < filter.date.from ) {
            showToast( T.filterErrDates || 'The end date cannot be before the start date.', 'error' );
            return;
        }
        try {
            await sendFilter( 'PUT', filter );
        } catch ( err ) {
            showToast( ( T.filterErrSave || 'Could not save the filter: ' ) + err.message, 'error' );
        }
    }

    async function clearFilter() {
        try {
            await sendFilter( 'DELETE' );
        } catch ( err ) {
            showToast( ( T.filterErrSave || 'Could not save the filter: ' ) + err.message, 'error' );
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
        PCIOVisModal.open( 'me-evt-overlay', closeModal );
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
        const placeholder = T.chooseEventType || '\u2014 Choose event type \u2014';
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

    function closeModal( saved = false ) {
        if ( ! PCIOVisModal.close( 'me-evt-overlay', saved ) ) return;
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
        const typeId = parseInt( document.getElementById( 'f-evt-type' )?.value || 0, 10 );
        if ( ! typeId )    { setError( 'evt-type', T.errEventTypeReq || 'Choose an event type.' ); return; }

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
            event_type_id:  typeId,
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
            PCIOVisModal.close( 'me-evt-overlay', true );
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
            const [ res, rscRes ] = await Promise.all( [
                fetch( TYPES_REST, { headers: { 'X-WP-Nonce': NONCE } } ),
                fetch( RSC_REST,   { headers: { 'X-WP-Nonce': NONCE } } ),
            ] );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            eventTypes = await res.json();
            if ( rscRes.ok ) resources = await rscRes.json();
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
            tbody.innerHTML = `<tr><td colspan="6" style="color:#94a3b8;text-align:center;padding:20px">—</td></tr>`;
            return;
        }
        const resourceNames = new Map( resources.map( r => [ Number( r.id ), r.name ] ) );
        tbody.innerHTML = eventTypes.map( t => {
            const rscNames = ( t.default_resource_ids || [] )
                .map( id => resourceNames.get( Number( id ) ) )
                .filter( Boolean );
            const color = /^#[0-9a-f]{3,8}$/i.test( t.color || '' ) ? t.color : '#94a3b8';
            return `
            <tr>
                <td><strong>${ escHtml( t.name ) }</strong>
                    ${ t.system_event_type ? `<br><small style="color:#64748b">${ escHtml( T.systemEventType || 'System event type' ) }</small>` : '' }</td>
                <td>${ escHtml( ( ACCESS_LABELS[ t.access ] || ( () => t.access ) )() ) }</td>
                <td style="text-align:center"><input type="checkbox" disabled ${ t.in_cafe_time ? 'checked' : '' } aria-label="${ escAttr( T.fieldInCafeTime || 'During cafe opening hours' ) }"></td>
                <td>${ rscNames.length ? escHtml( rscNames.join( ', ' ) ) : '<span style="color:#94a3b8">—</span>' }</td>
                <td style="text-align:center"><span title="${ escAttr( color ) }" style="display:inline-block;width:18px;height:18px;border-radius:3px;border:1px solid rgba(0,0,0,.15);vertical-align:middle;background:${ escAttr( color ) }"></span></td>
                <td style="white-space:nowrap;text-align:right">${ PCIO_ME.canManageTypes ? `
                    <button class="me-btn me-btn-secondary me-btn-sm" data-action="edit-type"   data-id="${ t.id }">${ escHtml( T.btnEdit || 'Edit' ) }</button>
                    ${ ! t.system_event_type ? `<button class="me-btn me-btn-danger me-btn-sm" data-action="delete-type" data-id="${ t.id }">${ escHtml( T.btnDelete || 'Delete' ) }</button>` : '' }
                ` : '' }</td>
            </tr>`;
        } ).join( '' );

        if ( PCIO_ME.canManageTypes ) {
            tbody.querySelectorAll( '[data-action="edit-type"]'   ).forEach( b => b.addEventListener( 'click', () => openItemModal( 'type', eventTypes.find( t => t.id == b.dataset.id ) ) ) );
            tbody.querySelectorAll( '[data-action="delete-type"]' ).forEach( b => b.addEventListener( 'click', () => deleteCalItem( 'type', parseInt( b.dataset.id ) ) ) );
        }
    }

    // ── Event Roles tab ───────────────────────────────────────────
    let eventRoles = [];

    async function loadEventRoles() {
        const newBtn = document.getElementById( 'me-btn-new-role' );
        if ( newBtn && ! newBtn.dataset.wired ) {
            newBtn.dataset.wired = '1';
            newBtn.addEventListener( 'click', () => openItemModal( 'role', null ) );
        }
        try {
            const res = await fetch( ROLES_REST, { headers: { 'X-WP-Nonce': NONCE } } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            eventRoles = await res.json();
            renderEventRoles();
        } catch ( err ) {
            showToast( ( T.errLoadEvents || 'Could not load: ' ) + err.message, 'error' );
        }
    }

    function renderEventRoles() {
        const tbody = document.getElementById( 'me-roles-tbody' );
        if ( ! tbody ) return;
        if ( ! eventRoles.length ) {
            tbody.innerHTML = `<tr><td colspan="3" style="color:#94a3b8;text-align:center;padding:20px">—</td></tr>`;
            return;
        }
        tbody.innerHTML = eventRoles.map( r => `
            <tr>
                <td><strong>${ escHtml( r.name ) }</strong></td>
                <td style="color:#64748b">${ escHtml( r.description || '' ) }</td>
                <td>${ PCIO_ME.canManageTypes ? `
                    <button class="me-btn me-btn-secondary me-btn-sm" data-action="edit-role"   data-id="${ r.id }">${ escHtml( T.btnEdit || 'Edit' ) }</button>
                    <button class="me-btn me-btn-danger me-btn-sm"    data-action="delete-role" data-id="${ r.id }">${ escHtml( T.btnDelete || 'Delete' ) }</button>
                ` : '' }</td>
            </tr>` ).join( '' );

        if ( PCIO_ME.canManageTypes ) {
            tbody.querySelectorAll( '[data-action="edit-role"]'   ).forEach( b => b.addEventListener( 'click', () => openItemModal( 'role', eventRoles.find( r => r.id == b.dataset.id ) ) ) );
            tbody.querySelectorAll( '[data-action="delete-role"]' ).forEach( b => b.addEventListener( 'click', () => {
                const role = eventRoles.find( r => r.id == b.dataset.id );
                const prompt = ( T.confirmDelRole || 'Are you sure you want to delete %s?' ).replace( '%s', role ? role.name : '' );
                if ( ! confirm( prompt ) ) return;
                deleteCalItem( 'role', parseInt( b.dataset.id ) );
            } ) );
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
                <div class="me-form-group">
                    <label for="ci-in-cafe-time"><input type="checkbox" id="ci-in-cafe-time" ${ item?.in_cafe_time ? 'checked' : '' }> ${ escHtml( T.fieldInCafeTime ) }</label>
                    ${ item?.system_event_type ? `<p>${ escHtml( T.systemTypeHelp ) }</p>` : '' }
                </div>
                <div class="me-form-group" id="ci-default-resources-wrap" style="margin-top:8px">
                    <label class="me-form-label">${ escHtml( T.fieldDefaultResources || 'Default resources' ) }</label>
                    <span style="color:#94a3b8;font-size:0.85em">${ escHtml( T.loadingResources || 'Loading…' ) }</span>
                </div>`;
            renderDefaultResourceCheckboxes( item?.default_resource_ids || [] );
        } else if ( kind === 'role' ) {
            title.textContent = item ? ( T.titleEditRole || 'Edit Event Role' ) : ( T.titleNewRole || 'New Event Role' );
            body.innerHTML = `
                <div class="me-form-group">
                    <label class="me-form-label" for="ci-name">${ escHtml( T.fieldRoleName || 'Name' ) } *</label>
                    <input class="me-form-input" type="text" id="ci-name" value="${ escAttr( item?.name || '' ) }" maxlength="100">
                </div>
                <div class="me-form-group">
                    <label class="me-form-label" for="ci-desc">${ escHtml( T.fieldRoleDesc || 'Description' ) }</label>
                    <textarea class="me-form-textarea" id="ci-desc" rows="3">${ escHtml( item?.description || '' ) }</textarea>
                </div>`;
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
        PCIOVisModal.open( 'me-cal-item-overlay', closeItemModal );
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
        PCIOVisModal.refresh( 'me-cal-item-overlay' );
    }

    function closeItemModal( saved = false ) {
        if ( ! PCIOVisModal.close( 'me-cal-item-overlay', saved ) ) return;
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
                closeItemModal( true );
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
        const isRole = itemModalKind === 'role';
        const restEndpoint = isType ? TYPES_REST : ( isRole ? ROLES_REST : RSC_REST );
        const url    = itemModalId
            ? `${ restEndpoint }/${ itemModalId }`
            : restEndpoint;
        const method = itemModalId ? 'PUT' : 'POST';
        const body   = isType
            ? {
                name,
                access: document.getElementById( 'ci-access' )?.value,
                color:  document.getElementById( 'ci-color' )?.value,
                in_cafe_time: document.getElementById( 'ci-in-cafe-time' ).checked,
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
            closeItemModal( true );
            if ( isType ) { calEventTypes = []; loadEventTypes(); }
            else if ( isRole ) { loadEventRoles(); }
            else { loadResources(); }
            const toastMsg = itemModalId
                ? ( isType ? T.toastTypeUpdated : ( isRole ? T.toastRoleUpdated : T.toastRscUpdated ) )
                : ( isType ? T.toastTypeCreated : ( isRole ? T.toastRoleCreated : T.toastRscCreated ) );
            showToast( toastMsg || 'Saved.', 'success' );
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
        if ( kind === 'role' ) {
            try {
                const res = await fetch( `${ ROLES_REST }/${ id }`, {
                    method:  'DELETE',
                    headers: { 'X-WP-Nonce': NONCE },
                } );
                if ( ! res.ok && res.status !== 204 ) throw new Error( `HTTP ${ res.status }` );
                loadEventRoles();
                showToast( T.toastRoleDeleted || 'Deleted.', 'success' );
            } catch ( err ) {
                showToast( ( T.errDelete || 'Delete failed: ' ) + err.message, 'error' );
            }
            return;
        }
        const isType = kind === 'type';
        if ( isType && eventTypes.find( t => Number( t.id ) === id )?.system_event_type ) {
            showToast( T.systemTypeHelp, 'error' );
            return;
        }
        try {
            const res = await fetch( `${ isType ? TYPES_REST : RSC_REST }/${ id }`, {
                method:  'DELETE',
                headers: { 'X-WP-Nonce': NONCE },
            } );
            if ( ! res.ok && res.status !== 204 ) {
                const body = await res.json().catch( () => ( {} ) );
                throw new Error( body.message || `HTTP ${ res.status }` );
            }
            if ( isType ) { calEventTypes = []; loadEventTypes(); } else { loadResources(); }
            showToast( ( isType ? T.toastTypeDeleted : T.toastRscDeleted ) || 'Deleted.', 'success' );
        } catch ( err ) {
            showToast( ( T.errDelete || 'Delete failed: ' ) + err.message, 'error' );
        }
    }

} )();
