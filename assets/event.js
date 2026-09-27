/* ================================================================
   PCIO VIS Member Event — event.js
   Event detail page: tabbed sections, auto-save, delete
   ================================================================ */

( function () {
    'use strict';

    const EVENTS_REST = ( PCIO_ME.rest + '/events'      ).replace( /([^:]\/)\/+/g, '$1' );
    const TYPES_REST  = ( PCIO_ME.rest + '/event-types' ).replace( /([^:]\/)\/+/g, '$1' );
    const RSC_REST    = ( PCIO_ME.rest + '/resources'   ).replace( /([^:]\/)\/+/g, '$1' );
    const NONCE       = PCIO_ME.nonce;
    const CAN_EDIT    = PCIO_ME.canEdit;
    const EVENT_ID    = PCIO_ME.eventId;
    const CAL_URL     = PCIO_ME.calUrl;
    const T           = PCIO_ME.i18n;

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

    // Tab definitions
    const TABS = [
        { id: 'basic',       label: T.tabBasic   },
        { id: 'description', label: T.tabDesc    },
        { id: 'resources',   label: T.tabResources || 'Resources' },
        { id: 'guests',      label: T.tabGuests  },
        { id: 'photos',      label: T.tabPhotos  },
    ];
    // Publish tab is editor-only.
    if ( CAN_EDIT ) {
        TABS.push( { id: 'publish', label: T.tabPublish } );
        TABS.push( { id: 'recurrence', label: T.tabRecurrence || 'Recurrence' } );
    }

    // Extra tabs registered by extensions via the `pcio_me_event_tabs` filter.
    const EXTRA_TABS = Array.isArray( PCIO_ME.extraTabs ) ? PCIO_ME.extraTabs : [];
    EXTRA_TABS.forEach( t => {
        if ( t && t.id ) {
            TABS.push( { id: t.id, label: t.label || t.id } );
        }
    } );

    let event          = null;  // raw DB row
    let activeTab      = 'basic';
    let saveTimer      = null;
    let saveStateTimer = null;
    let toastTimer     = null;
    let isDirty        = false;

    // ── Bootstrap ─────────────────────────────────────────────────
    document.addEventListener( 'DOMContentLoaded', () => {
        if ( ! EVENT_ID ) {
            renderError( T.errNoId );
            return;
        }
        window.addEventListener( 'beforeunload', ( e ) => {
            if ( isDirty ) {
                e.preventDefault();
                e.returnValue = T.unsavedWarning;
            }
        } );
        loadEvent();
    } );

    // ── Load event ────────────────────────────────────────────────
    async function loadEvent() {
        try {
            const res = await fetch( `${ EVENTS_REST }/${ EVENT_ID }`, {
                headers: { 'X-WP-Nonce': NONCE },
            } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            event = await res.json();
            buildShell();
            renderTab( activeTab );
        } catch ( err ) {
            renderError( escHtml( T.errLoad ) + escHtml( err.message ) );
        }
    }

    // ── Page shell (runs once after event loaded) ─────────────────
    function buildShell() {
        const app = document.getElementById( 'pcio-me-app' );
        if ( ! app ) return;

        const colorDot = `<span class="me-evt-color-dot"
            style="background:${ escAttr( event.color || '#3b82f6' ) }"></span>`;

        const deleteBtn = CAN_EDIT
            ? `<button class="me-btn me-btn-danger" id="me-evt-del-btn">${ escHtml( T.btnDeleteEvt ) }</button>`
            : '';

        const tabItems = TABS.map( t =>
            `<button class="me-tab-btn${ t.id === activeTab ? ' active' : '' }"
                     data-tab="${ t.id }" type="button">${ escHtml( t.label ) }</button>`
        ).join( '' );

        app.innerHTML = `
        <div class="me-page-header">
            <div>
                <a class="me-back-link" href="${ escAttr( CAL_URL ) }">
                    <svg viewBox="0 0 24 24"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
                    ${ escHtml( T.backToCalendar ) }
                </a>
                <h1 class="me-page-title" style="margin-top:6px">
                    ${ colorDot }
                    <span id="me-evt-heading">${ escHtml( event.title ) }</span>
                </h1>
            </div>
            <div class="me-header-right" style="align-items:flex-end;gap:10px">
                <span class="me-save-indicator me-hidden" id="me-save-indicator">${ escHtml( T.stateSaving ) }</span>
                ${ deleteBtn }
            </div>
        </div>

        <!-- Tab bar -->
        <div class="me-tab-bar" role="tablist" aria-label="Event sections">
            ${ tabItems }
        </div>

        <!-- Tab content -->
        <div class="me-tab-content" id="me-tab-content"></div>

        <!-- Delete confirm modal -->
        <div class="me-overlay me-hidden" id="me-del-overlay"
             role="alertdialog" aria-modal="true">
            <div class="me-modal me-modal-sm">
                <div class="me-modal-header">
                    <h2 class="me-modal-title">${ escHtml( T.titleDeleteEvt ) }</h2>
                    <button class="me-modal-close" id="me-del-close"
                            aria-label="${ escAttr( T.closeLabel ) }">&times;</button>
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
                    <p class="me-delete-name">Delete <strong>${ escHtml( event.title ) }</strong>?</p>
                    <p class="me-delete-warning">${ escHtml( T.cannotUndo ) }</p>
                </div>
                <div class="me-delete-footer">
                    <button class="me-btn me-btn-secondary" id="me-del-cancel">${ escHtml( T.btnCancel ) }</button>
                    <button class="me-btn me-btn-danger"    id="me-del-confirm">${ escHtml( T.btnDelete ) }</button>
                </div>
            </div>
        </div>

        <div class="me-toast me-hidden" id="me-toast" role="status" aria-live="polite"></div>
        `;

        // Tab clicks
        app.querySelectorAll( '.me-tab-btn' ).forEach( btn => {
            btn.addEventListener( 'click', () => {
                // Flush any pending basic auto-save immediately before switching
                if ( saveTimer ) {
                    clearTimeout( saveTimer );
                    saveTimer = null;
                    saveBasic();
                }
                // Only warn for description's manual unsaved changes
                if ( isDirty ) {
                    if ( ! confirm( T.unsavedWarning ) ) return;
                    markClean();
                }
                setActiveTab( btn.dataset.tab );
            } );
        } );

        // Delete
        if ( CAN_EDIT ) {
            document.getElementById( 'me-evt-del-btn' )
                .addEventListener( 'click', () =>
                    document.getElementById( 'me-del-overlay' ).classList.remove( 'me-hidden' ) );
            document.getElementById( 'me-del-close'   ).addEventListener( 'click', closeDeleteModal );
            document.getElementById( 'me-del-cancel'  ).addEventListener( 'click', closeDeleteModal );
            document.getElementById( 'me-del-confirm' ).addEventListener( 'click', confirmDelete );
        }

        document.getElementById( 'me-del-overlay' )?.addEventListener( 'click', ( e ) => {
            if ( e.target === e.currentTarget ) closeDeleteModal();
        } );
        document.addEventListener( 'keydown', ( e ) => {
            if ( e.key === 'Escape' ) closeDeleteModal();
        } );
    }

    // ── Tab navigation ────────────────────────────────────────────
    function setActiveTab( tabId ) {
        activeTab = tabId;
        document.querySelectorAll( '.me-tab-btn' ).forEach( btn => {
            btn.classList.toggle( 'active', btn.dataset.tab === tabId );
        } );
        renderTab( tabId );
    }

    function renderTab( tabId ) {
        const container = document.getElementById( 'me-tab-content' );
        if ( ! container ) return;
        container.innerHTML = '';

        switch ( tabId ) {
            case 'basic':       renderBasicTab( container );       break;
            case 'description': renderDescriptionTab( container ); break;
            case 'resources':   renderResourcesTab( container );   break;
            case 'guests':      renderGuestsTab( container );      break;
            case 'photos':      renderPhotosTab( container );      break;
            case 'publish':     renderPublishTab( container );     break;
            case 'recurrence':  renderRecurrenceTab( container );  break;
            default:            renderExtraTab( container, tabId ); break;
        }
    }

    // Delegate the Recurrence tab to its own module (event-recurrence.js).
    function renderRecurrenceTab( container ) {
        if ( window.PCIO_ME_Recurrence && typeof window.PCIO_ME_Recurrence.render === 'function' ) {
            window.PCIO_ME_Recurrence.render( container, {
                eventId: EVENT_ID,
                restBase: EVENTS_REST,
                nonce: NONCE,
                i18n: T,
                calUrl: CAL_URL,
                eventBaseUrl: PCIO_ME.eventBaseUrl || '',
            } );
        } else {
            container.innerHTML = `<div class="me-tab-card">${ escHtml( T.comingSoon || '' ) }</div>`;
        }
    }

    // Render an extension-provided tab by injecting its server-supplied HTML.
    function renderExtraTab( container, tabId ) {
        const tab = EXTRA_TABS.find( t => t && t.id === tabId );
        if ( tab ) {
            container.innerHTML = tab.html || '';
        }
    }

    // ── Tab: Basic ────────────────────────────────────────────────
    function renderBasicTab( container ) {
        const swatches = PRESET_COLORS.map( c =>
            `<button type="button" class="me-color-swatch${ event.color === c.hex ? ' selected' : '' }"
                     data-color="${ c.hex }" title="${ c.name }"
                     style="background:${ c.hex }" aria-label="Color: ${ c.name }"></button>`
        ).join( '' );

        const allDay    = parseInt( event.all_day, 10 ) === 1;
        const startISO  = ( event.start_datetime || '' ).replace( ' ', 'T' );
        const endISO    = ( event.end_datetime   || '' ).replace( ' ', 'T' );
        const startDate = startISO.substring( 0, 10 );
        const startTime = allDay ? '' : startISO.substring( 11, 16 );

        // For all-day, display end as inclusive (stored as exclusive)
        let endDate = '';
        let endTime = '';
        if ( endISO ) {
            if ( allDay ) {
                endDate = endISO.substring( 0, 10 ); // stored inclusive, same day as display
            } else {
                endDate = endISO.substring( 0, 10 );
                endTime = endISO.substring( 11, 16 );
            }
        }

        const ro = CAN_EDIT ? '' : 'readonly disabled';

        // Sign-up / invitation mode options (Tickets extension may add its own).
        const currentMode = event.signup_mode || 'simple';
        const modes = Object.assign( {}, PCIO_ME.signupModes || { simple: 'Simple' } );
        if ( ! ( currentMode in modes ) ) {
            modes[ currentMode ] = currentMode;
        }
        const signupModeOptions = Object.keys( modes ).map( val =>
            `<option value="${ escAttr( val ) }"${ val === currentMode ? ' selected' : '' }>${ escHtml( modes[ val ] ) }</option>`
        ).join( '' );

        container.innerHTML = `
        <div class="me-tab-card">
            <form id="me-basic-form" novalidate>
                <div class="me-form-row full">
                    <div class="me-form-group">
                        <label class="me-form-label" for="b-title">
                            ${ escHtml( T.fieldTitle ) } <span class="req">*</span>
                        </label>
                        <input class="me-form-input" type="text" id="b-title" name="title"
                               value="${ escAttr( event.title ) }" maxlength="200" ${ ro }>
                        <span class="me-field-error" id="err-b-title"></span>
                    </div>
                </div>

                <div class="me-form-row full">
                    <div class="me-form-group">
                        <label class="me-form-label">
                            <input type="checkbox" id="b-all-day" name="all_day"
                                   ${ allDay ? 'checked' : '' }
                                   style="margin-right:6px;vertical-align:-1px" ${ ro }>
                            ${ escHtml( T.allDayEvt ) }
                        </label>
                    </div>
                </div>

                <div class="me-form-row">
                    <div class="me-form-group">
                        <label class="me-form-label" for="b-start-date">
                            ${ escHtml( T.fieldStartDate ) } <span class="req">*</span>
                        </label>
                        <input class="me-form-input" type="date" id="b-start-date"
                               name="start_date" value="${ escAttr( startDate ) }" ${ ro }>
                    </div>
                    <div class="me-form-group" id="g-b-start-time">
                        <label class="me-form-label" for="b-start-time">${ escHtml( T.fieldStartTime ) }</label>
                        <input class="me-form-input" type="time" id="b-start-time"
                               name="start_time" value="${ escAttr( startTime ) }" ${ ro }>
                    </div>
                </div>

                <div class="me-form-row">
                    <div class="me-form-group">
                        <label class="me-form-label" for="b-end-date">${ escHtml( T.fieldEndDate ) }</label>
                        <input class="me-form-input" type="date" id="b-end-date"
                               name="end_date" value="${ escAttr( endDate ) }" ${ ro }>
                        <span class="me-field-error" id="err-b-end"></span>
                    </div>
                    <div class="me-form-group" id="g-b-end-time">
                        <label class="me-form-label" for="b-end-time">${ escHtml( T.fieldEndTime ) }</label>
                        <input class="me-form-input" type="time" id="b-end-time"
                               name="end_time" value="${ escAttr( endTime ) }" ${ ro }>
                    </div>
                </div>

                <div class="me-form-row full">
                    <div class="me-form-group">
                        <label class="me-form-label" for="b-location">${ escHtml( T.fieldLocation ) }</label>
                        <input class="me-form-input" type="text" id="b-location"
                               name="location"
                               value="${ escAttr( event.location || '' ) }"
                               placeholder="${ escAttr( T.phLocation ) }" maxlength="200" ${ ro }>
                    </div>
                </div>

                <div class="me-form-row">
                    <div class="me-form-group">
                        <label class="me-form-label" for="b-event-type">${ escHtml( T.fieldEventType || 'Event type' ) }</label>
                        <select class="me-form-input" id="b-event-type" name="event_type_id" ${ ro }>
                            <option value="">${ escHtml( T.noEventType || '\u2014 None \u2014' ) }</option>
                        </select>
                    </div>
                    <div class="me-form-group">
                        <label class="me-form-label">${ escHtml( T.fieldColour ) }</label>
                        <div class="me-color-picker" id="b-color-picker">
                            ${ swatches }
                        </div>
                        <input type="hidden" id="b-color" name="color"
                               value="${ escAttr( event.color || PRESET_COLORS[0].hex ) }">
                    </div>
                </div>

                <div class="me-form-row full">
                    <div class="me-form-group">
                        <label class="me-form-label" for="b-signup-mode">${ escHtml( T.fieldSignupMode || 'Sign-up' ) }</label>
                        <select class="me-form-input" id="b-signup-mode" name="signup_mode" ${ ro }>
                            ${ signupModeOptions }
                        </select>
                    </div>
                </div>


            </form>
        </div>
        `;

        // Load event types and populate the select.
        fetch( TYPES_REST, { headers: { 'X-WP-Nonce': NONCE } } )
            .then( r => r.json() )
            .then( types => {
                const sel = document.getElementById( 'b-event-type' );
                if ( ! sel ) return;
                const current = String( event.event_type_id || '' );
                sel.innerHTML = `<option value="">${ escHtml( T.noEventType || '\u2014 None \u2014' ) }</option>` +
                    ( types || [] ).map( t => `<option value="${ t.id }"${ String( t.id ) === current ? ' selected' : '' }>${ escHtml( t.name ) }</option>` ).join( '' );
                // When type changes, suggest the type's color.
                if ( CAN_EDIT ) {
                    sel.addEventListener( 'change', () => {
                        const t = ( types || [] ).find( x => String( x.id ) === sel.value );
                        if ( t && t.color ) {
                            document.getElementById( 'b-color' ).value = t.color;
                            document.querySelectorAll( '#b-color-picker .me-color-swatch' ).forEach( sw =>
                                sw.classList.toggle( 'selected', sw.dataset.color === t.color )
                            );
                        }
                        scheduleSave();
                    } );
                }
            } )
            .catch( () => {} );

        syncBasicTimeVis();

        if ( CAN_EDIT ) {
            function onBasicChange() {
                scheduleSave();
            }

            const form = container.querySelector( '#me-basic-form' );
            form.addEventListener( 'input',  onBasicChange );
            form.addEventListener( 'change', onBasicChange );

            container.querySelector( '#b-all-day' )
                .addEventListener( 'change', syncBasicTimeVis );

            container.querySelector( '#b-color-picker' )
                .addEventListener( 'click', ( e ) => {
                    const s = e.target.closest( '.me-color-swatch' );
                    if ( s ) {
                        document.getElementById( 'b-color' ).value = s.dataset.color;
                        container.querySelectorAll( '.me-color-swatch' ).forEach( sw =>
                            sw.classList.toggle( 'selected', sw.dataset.color === s.dataset.color )
                        );
                        onBasicChange();
                    }
                } );
        }
    }

    function syncBasicTimeVis() {
        const allDay = document.getElementById( 'b-all-day' )?.checked;
        const vis    = allDay ? 'hidden' : 'visible';
        [ 'g-b-start-time', 'g-b-end-time' ].forEach( id => {
            const el = document.getElementById( id );
            if ( el ) el.style.visibility = vis;
        } );
    }

    function scheduleSave() {
        clearTimeout( saveTimer );
        saveTimer = setTimeout( saveBasic, 500 );
    }

    async function saveBasic() {
        saveTimer = null;
        const titleEl = document.getElementById( 'b-title' );
        if ( ! titleEl ) return; // basic tab no longer active

        const title = titleEl.value.trim();
        const errEl = document.getElementById( 'err-b-title' );
        if ( ! title ) {
            if ( errEl ) errEl.textContent = T.errTitleReq;
            titleEl.focus();
            return;
        }
        if ( errEl ) errEl.textContent = '';

        const allDay    = document.getElementById( 'b-all-day'    ).checked;
        const startDate = document.getElementById( 'b-start-date' ).value;
        const startTime = document.getElementById( 'b-start-time' ).value || '00:00';
        const endDate   = document.getElementById( 'b-end-date'   ).value;
        const endTime   = document.getElementById( 'b-end-time'   ).value || '00:00';

        const startDatetime = allDay
            ? startDate + ' 00:00:00'
            : startDate + ' ' + startTime + ':00';

        let endDatetime = null;
        if ( endDate ) {
            if ( allDay ) {
                endDatetime = endDate + ' 23:59:59'; // inclusive end, same day as displayed
            } else {
                endDatetime = endDate + ' ' + endTime + ':00';
            }
        }

        // Never allow the end to be before the start.
        const endErrEl = document.getElementById( 'err-b-end' );
        if ( endErrEl ) endErrEl.textContent = '';
        if ( endDatetime ) {
            const startCmp = allDay ? ( startDate + ' 00:00:00' ) : startDatetime;
            const endCmp   = allDay ? ( endDate + ' 00:00:00' )   : endDatetime;
            if ( endCmp < startCmp ) {
                if ( endErrEl ) endErrEl.textContent = T.errEndBeforeStart;
                document.getElementById( 'b-end-date' )?.focus();
                return;
            }
        }

        showSaveState( 'saving' );
        try {
            const res = await fetch( `${ EVENTS_REST }/${ EVENT_ID }`, {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify( {
                    title,
                    start_datetime: startDatetime,
                    end_datetime:   endDatetime,
                    all_day:        allDay ? 1 : 0,
                    color:          document.getElementById( 'b-color'      ).value,
                    location:       document.getElementById( 'b-location'   ).value.trim(),
                    event_type_id:  parseInt( document.getElementById( 'b-event-type' )?.value || 0, 10 ) || null,
                    signup_mode:    document.getElementById( 'b-signup-mode' )?.value || 'simple',
                } ),
            } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            event = await res.json();

            // Update heading + color dot live
            document.getElementById( 'me-evt-heading' ).textContent = event.title;
            const dot = document.querySelector( '.me-evt-color-dot' );
            if ( dot ) dot.style.background = event.color || '#3b82f6';

            showSaveState( 'saved' );
        } catch ( err ) {
            showSaveState( 'error' );
        }
    }

    // ── Tab: Description ──────────────────────────────────────────
    function renderDescriptionTab( container ) {
        if ( CAN_EDIT ) {
            container.innerHTML = `
            <div class="me-tab-card">
                <label class="me-form-label" style="display:block;margin-bottom:8px">${ escHtml( T.fieldDesc ) }</label>
                <div id="d-quill-editor"></div>
                <textarea id="d-html-source" class="me-form-textarea me-hidden" rows="14" spellcheck="false"
                          style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px"></textarea>
                <div style="margin-top:16px;display:flex;gap:8px;align-items:center">
                    <button class="me-btn me-btn-primary" id="d-save-btn" type="button" disabled>
                        ${ escHtml( T.btnSaveDesc ) }
                    </button>
                    <button class="me-btn me-btn-secondary" id="d-html-btn" type="button">
                        ${ escHtml( T.btnHtmlSource || 'Edit HTML' ) }
                    </button>
                </div>
            </div>
            `;

            const quill = new Quill( '#d-quill-editor', {
                theme:   'snow',
                modules: {
                    toolbar: [
                        [ 'bold', 'italic', 'underline' ],
                        [ { color: [] }, { background: [] } ],
                        [ { list: 'ordered' }, { list: 'bullet' } ],
                        [ 'link' ],
                        [ 'clean' ],
                    ],
                },
                placeholder: T.phDesc,
            } );

            if ( event.description ) {
                quill.clipboard.dangerouslyPasteHTML( event.description );
                quill.history.clear();
            }

            const saveBtn   = document.getElementById( 'd-save-btn' );
            const htmlBtn   = document.getElementById( 'd-html-btn' );
            const sourceTa  = document.getElementById( 'd-html-source' );
            const editorEl  = document.getElementById( 'd-quill-editor' );
            const toolbarEl = container.querySelector( '.ql-toolbar' );
            let   htmlMode  = false;

            // Current description HTML from whichever editor is active.
            const currentHtml = () => {
                const raw = htmlMode ? sourceTa.value : quill.root.innerHTML;
                return raw === '<p><br></p>' ? '' : raw;
            };

            quill.on( 'text-change', ( delta, oldDelta, source ) => {
                if ( source === 'user' ) {
                    markDirty();
                    saveBtn.disabled = false;
                }
            } );

            sourceTa.addEventListener( 'input', () => {
                markDirty();
                saveBtn.disabled = false;
            } );

            htmlBtn.addEventListener( 'click', () => {
                htmlMode = ! htmlMode;
                if ( htmlMode ) {
                    // Switch to raw HTML editing.
                    sourceTa.value = quill.root.innerHTML;
                    sourceTa.classList.remove( 'me-hidden' );
                    editorEl.classList.add( 'me-hidden' );
                    toolbarEl?.classList.add( 'me-hidden' );
                    htmlBtn.textContent = T.btnVisualEditor || 'Visual editor';
                } else {
                    // Apply the edited HTML back into the rich editor.
                    quill.setContents( [] );
                    quill.clipboard.dangerouslyPasteHTML( sourceTa.value );
                    sourceTa.classList.add( 'me-hidden' );
                    editorEl.classList.remove( 'me-hidden' );
                    toolbarEl?.classList.remove( 'me-hidden' );
                    htmlBtn.textContent = T.btnHtmlSource || 'Edit HTML';
                }
            } );

            saveBtn.addEventListener( 'click', async () => {
                saveBtn.disabled = true;
                showSaveState( 'saving' );
                try {
                    const html = currentHtml();
                    const res  = await fetch( `${ EVENTS_REST }/${ EVENT_ID }`, {
                        method:  'PUT',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                        body:    JSON.stringify( { description: html } ),
                    } );
                    if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
                    event = await res.json();
                    markClean();
                    showSaveState( 'saved' );
                } catch ( err ) {
                    saveBtn.disabled = false;
                    showSaveState( 'error' );
                }
            } );

        } else {
            container.innerHTML = `
            <div class="me-tab-card">
                <div class="me-desc-readonly">${ event.description || `<em style="color:#64748b">${ escHtml( T.noDesc ) }</em>` }</div>
            </div>
            `;
        }
    }

    // ── Tab: Photos ───────────────────────────────────────────────
    const IMAGE_SLOTS = [
        { type: 'banner', idKey: 'imageId',      urlKey: 'imageUrl',      field: 'image_id',       labelKey: 'bannerLabel', hintKey: 'bannerHint' },
        { type: 'thumb',  idKey: 'thumbImageId', urlKey: 'thumbImageUrl', field: 'thumb_image_id', labelKey: 'thumbLabel',  hintKey: 'thumbHint'  },
    ];

    function renderPhotosTab( container ) {
        container.innerHTML = `<div class="me-tab-card me-photos-grid">${ IMAGE_SLOTS.map( slotCardHtml ).join( '' ) }</div>`;
        IMAGE_SLOTS.forEach( wireSlot );
    }

    function slotMeta( type ) {
        return ( PCIO_ME.imageMeta && PCIO_ME.imageMeta[ type ] ) || { targetW: 0, targetH: 0, actualW: null, actualH: null };
    }

    function sizeStatusHtml( type ) {
        const m = slotMeta( type );
        const target = `${ m.targetW } × ${ m.targetH }`;
        let current, cls = '', note = '';
        if ( m.actualW && m.actualH ) {
            current = `${ m.actualW } × ${ m.actualH }`;
            const match = ( m.actualW === m.targetW && m.actualH === m.targetH );
            cls  = match ? 'me-size-ok' : 'me-size-bad';
            note = match ? T.sizeOk : T.sizeMismatch;
        } else {
            current = T.sizeUnknown;
        }
        return `
                <div class="me-photos-sizes">
                    <div><span class="me-size-label">${ escHtml( T.targetSize ) }:</span> ${ escHtml( target ) } px</div>
                    <div><span class="me-size-label">${ escHtml( T.actualSize ) }:</span> <span class="${ cls }">${ escHtml( current ) }${ m.actualW ? ' px' : '' }</span></div>
                    ${ note ? `<div class="me-photos-size-note ${ cls }">${ escHtml( note ) }</div>` : '' }
                </div>`;
    }

    function slotCardHtml( slot ) {
        const url = PCIO_ME[ slot.urlKey ] || null;
        const previewHtml = url
            ? `<img class="me-photos-preview" src="${ escAttr( url ) }" alt="">`
            : `<p class="me-photos-empty">${ escHtml( T.noImage ) }</p>`;
        const actions = CAN_EDIT ? `
                <div class="me-photos-actions">
                    <button class="me-btn me-btn-secondary" data-photo-choose="${ slot.type }" type="button">
                        ${ escHtml( url ? T.btnChangeImage : T.btnChooseImage ) }
                    </button>
                    <button class="me-btn me-btn-secondary" data-photo-crop="${ slot.type }" type="button">
                        ${ escHtml( T.btnCropImage ) }
                    </button>
                    ${ url ? `<button class="me-btn me-btn-danger" data-photo-remove="${ slot.type }" type="button">${ escHtml( T.btnRemoveImage ) }</button>` : '' }
                </div>` : '';
        return `
            <div class="me-photos-slot" data-photo-slot="${ slot.type }">
                <h3 class="me-photos-title">${ escHtml( T[ slot.labelKey ] ) }</h3>
                <p class="me-photos-hint">${ escHtml( T[ slot.hintKey ] ) }</p>
                <div class="me-photos-preview-wrap" data-photo-preview="${ slot.type }">${ previewHtml }</div>
                ${ sizeStatusHtml( slot.type ) }
                ${ actions }
                <p class="me-photos-save-state me-hidden" data-photo-state="${ slot.type }"></p>
            </div>`;
    }

    function wireSlot( slot ) {
        if ( ! CAN_EDIT ) return;
        const chooseBtn = document.querySelector( `[data-photo-choose="${ slot.type }"]` );
        chooseBtn?.addEventListener( 'click', () => {
            if ( ! window.wp || ! window.wp.media ) {
                showToast( T.noMediaLib, 'error' );
                return;
            }
            const frame = wp.media( {
                title:    T.mediaTitle,
                button:   { text: T.mediaBtn },
                multiple: false,
                library:  { type: 'image' },
            } );
            frame.on( 'select', async () => {
                const a = frame.state().get( 'selection' ).first().toJSON();
                await saveImage( slot, a.id, a.url, a.width, a.height );
            } );
            frame.open();
        } );
        const removeBtn = document.querySelector( `[data-photo-remove="${ slot.type }"]` );
        removeBtn?.addEventListener( 'click', async () => {
            await saveImage( slot, null, null, null, null );
        } );
        const cropBtn = document.querySelector( `[data-photo-crop="${ slot.type }"]` );
        cropBtn?.addEventListener( 'click', () => startCrop( slot ) );
    }

    async function saveImage( slot, newId, newUrl, newW, newH ) {
        const stateEl = document.querySelector( `[data-photo-state="${ slot.type }"]` );
        if ( stateEl ) {
            stateEl.textContent = T.imgSaving;
            stateEl.className   = 'me-photos-save-state';
        }
        try {
            const res = await fetch( `${ EVENTS_REST }/${ EVENT_ID }`, {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify( { [ slot.field ]: newId } ),
            } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            await res.json();

            // Persist into global state.
            PCIO_ME[ slot.idKey ]  = newId;
            PCIO_ME[ slot.urlKey ] = newUrl;
            event[ slot.field ]    = newId;
            const m = slotMeta( slot.type );
            m.actualW = newW || null;
            m.actualH = newH || null;

            // Re-render just this slot, then show the saved state.
            const slotEl = document.querySelector( `[data-photo-slot="${ slot.type }"]` );
            if ( slotEl ) {
                slotEl.outerHTML = slotCardHtml( slot );
                wireSlot( slot );
            }
            const newStateEl = document.querySelector( `[data-photo-state="${ slot.type }"]` );
            if ( newStateEl ) {
                newStateEl.textContent = T.imgSaved;
                newStateEl.className   = 'me-photos-save-state me-save-saved';
                setTimeout( () => newStateEl.classList.add( 'me-hidden' ), 2500 );
            }
        } catch ( err ) {
            if ( stateEl ) {
                stateEl.textContent = T.imgError;
                stateEl.className   = 'me-photos-save-state me-save-error';
            }
        }
    }

    // ── Crop editor (Cropper.js) ──────────────────────────────────
    let cropper = null;

    // The Cropper.js v2 UMD build exposes the class as window.Cropper.default
    // (the module namespace object), unlike v1 where window.Cropper WAS the class.
    function resolveCropper() {
        const C = window.Cropper;
        if ( ! C ) { return null; }
        return ( typeof C === 'function' ) ? C : ( C.default || null );
    }

    function startCrop( slot ) {
        if ( ! resolveCropper() ) {
            showToast( T.cropLibMissing, 'error' );
            return;
        }
        const currentUrl = PCIO_ME[ slot.urlKey ] || null;
        if ( currentUrl ) {
            openCropModal( slot, currentUrl );
        } else {
            pickSource( slot );
        }
    }

    function pickSource( slot ) {
        if ( ! window.wp || ! window.wp.media ) {
            showToast( T.noMediaLib, 'error' );
            return;
        }
        const frame = wp.media( {
            title:    T.mediaTitle,
            button:   { text: T.mediaBtn },
            multiple: false,
            library:  { type: 'image' },
        } );
        frame.on( 'select', () => {
            const a   = frame.state().get( 'selection' ).first().toJSON();
            const src = ( a.sizes && a.sizes.full && a.sizes.full.url ) || a.url;
            openCropModal( slot, src );
        } );
        frame.open();
    }

    function openCropModal( slot, srcUrl ) {
        const meta = slotMeta( slot.type );
        const ratio = meta.targetW / meta.targetH;

        const overlay = document.createElement( 'div' );
        overlay.className = 'me-crop-overlay';
        overlay.innerHTML = `
            <div class="me-crop-modal" role="dialog" aria-modal="true">
                <div class="me-crop-head">
                    <h3 class="me-crop-title">${ escHtml( T.cropTitle ) } — ${ escHtml( T[ slot.labelKey ] ) }</h3>
                    <button class="me-crop-x" type="button" aria-label="${ escAttr( T.closeLabel || 'Close' ) }">&times;</button>
                </div>
                <p class="me-crop-help">${ escHtml( T.cropHelp ) } (${ meta.targetW } × ${ meta.targetH } px)</p>
                <div class="me-crop-stage">
                    <img class="me-crop-img" alt="" src="${ escAttr( srcUrl ) }" crossorigin="anonymous">
                </div>
                <div class="me-crop-tools">
                    <button class="me-btn me-btn-secondary" data-crop-zoomin type="button">${ escHtml( T.cropZoomIn ) }</button>
                    <button class="me-btn me-btn-secondary" data-crop-zoomout type="button">${ escHtml( T.cropZoomOut ) }</button>
                    <button class="me-btn me-btn-secondary" data-crop-reset type="button">${ escHtml( T.cropReset ) }</button>
                    <button class="me-btn me-btn-secondary" data-crop-choose type="button">${ escHtml( T.cropChoose ) }</button>
                    <span class="me-crop-spacer"></span>
                    <button class="me-btn me-btn-primary" data-crop-apply type="button">${ escHtml( T.cropApply ) }</button>
                </div>
                <p class="me-photos-save-state me-hidden" data-crop-state></p>
            </div>`;
        document.body.appendChild( overlay );

        const imgEl = overlay.querySelector( '.me-crop-img' );
        const CropperClass = resolveCropper();
        if ( ! CropperClass ) {
            showToast( T.cropLibMissing, 'error' );
            overlay.remove();
            return;
        }

        // Cropper.js v2 template: drag on the canvas moves the image, and the
        // selection is locked to the target aspect ratio and covers it fully.
        const cropperTemplate =
            '<cropper-canvas background style="width:100%;height:100%">'
            + '<cropper-image rotatable scalable translatable></cropper-image>'
            + '<cropper-shade hidden></cropper-shade>'
            + '<cropper-handle action="move" plain></cropper-handle>'
            + '<cropper-selection initial-coverage="1" aspect-ratio="' + ratio + '" movable resizable outlined>'
            + '<cropper-grid role="grid" bordered covered></cropper-grid>'
            + '<cropper-crosshair centered></cropper-crosshair>'
            + '<cropper-handle action="move" theme-color="rgba(255,255,255,0.35)"></cropper-handle>'
            + '<cropper-handle action="n-resize"></cropper-handle>'
            + '<cropper-handle action="e-resize"></cropper-handle>'
            + '<cropper-handle action="s-resize"></cropper-handle>'
            + '<cropper-handle action="w-resize"></cropper-handle>'
            + '<cropper-handle action="ne-resize"></cropper-handle>'
            + '<cropper-handle action="nw-resize"></cropper-handle>'
            + '<cropper-handle action="se-resize"></cropper-handle>'
            + '<cropper-handle action="sw-resize"></cropper-handle>'
            + '</cropper-selection>'
            + '</cropper-canvas>';

        const destroy = () => {
            if ( cropper ) { try { cropper.destroy(); } catch ( e ) {} cropper = null; }
            overlay.remove();
        };

        const initCropper = () => {
            if ( cropper ) { try { cropper.destroy(); } catch ( e ) {} cropper = null; }
            // new Cropper() reads the <img> src, hides it, and renders its own
            // web-component canvas in the same parent (.me-crop-stage).
            cropper = new CropperClass( imgEl, { template: cropperTemplate } );
        };

        initCropper();
        imgEl.addEventListener( 'error', () => showToast( T.imgError, 'error' ), { once: true } );

        overlay.querySelector( '.me-crop-x' ).addEventListener( 'click', destroy );
        overlay.addEventListener( 'click', ( e ) => { if ( e.target === overlay ) destroy(); } );
        overlay.querySelector( '[data-crop-zoomin]'  ).addEventListener( 'click', () => {
            const image = cropper && cropper.getCropperImage();
            if ( image ) { image.$zoom( 0.1 ); }
        } );
        overlay.querySelector( '[data-crop-zoomout]' ).addEventListener( 'click', () => {
            const image = cropper && cropper.getCropperImage();
            if ( image ) { image.$zoom( -0.1 ); }
        } );
        overlay.querySelector( '[data-crop-reset]'   ).addEventListener( 'click', () => {
            if ( ! cropper ) { return; }
            const image     = cropper.getCropperImage();
            const selection = cropper.getCropperSelection();
            if ( image )     { image.$resetTransform(); image.$center( 'contain' ); }
            if ( selection ) { selection.$reset(); }
        } );
        overlay.querySelector( '[data-crop-choose]'  ).addEventListener( 'click', () => { destroy(); pickSource( slot ); } );
        overlay.querySelector( '[data-crop-apply]'   ).addEventListener( 'click', async () => {
            const selection = cropper && cropper.getCropperSelection();
            if ( ! selection ) { return; }
            const stateEl  = overlay.querySelector( '[data-crop-state]' );
            const applyBtn = overlay.querySelector( '[data-crop-apply]' );
            applyBtn.disabled = true;
            if ( stateEl ) { stateEl.textContent = T.imgSaving; stateEl.className = 'me-photos-save-state'; }
            try {
                const raw = await selection.$toCanvas( {
                    width:  meta.targetW,
                    height: meta.targetH,
                } );
                // Cropper.js derives the output size from the selection's live
                // (sub-pixel) aspect ratio, so it can land 1px short of the
                // requested size (e.g. 1200×524 instead of 1200×525). Redraw
                // onto an exactly-sized canvas to guarantee the target size.
                let canvas = raw;
                if ( raw.width !== meta.targetW || raw.height !== meta.targetH ) {
                    canvas        = document.createElement( 'canvas' );
                    canvas.width  = meta.targetW;
                    canvas.height = meta.targetH;
                    canvas.getContext( '2d' ).drawImage( raw, 0, 0, meta.targetW, meta.targetH );
                }
                const dataUrl = canvas.toDataURL( 'image/jpeg', 0.9 );
                const ok      = await uploadCrop( slot, dataUrl );
                if ( ok ) { destroy(); return; }
            } catch ( e ) {}
            applyBtn.disabled = false;
            if ( stateEl ) { stateEl.textContent = T.imgError; stateEl.className = 'me-photos-save-state me-save-error'; }
        } );
    }

    async function uploadCrop( slot, dataUrl ) {
        try {
            const res = await fetch( `${ EVENTS_REST }/${ EVENT_ID }/image`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                body:    JSON.stringify( { type: slot.type, data: dataUrl } ),
            } );
            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
            const out = await res.json();

            PCIO_ME[ slot.idKey ]  = out.image_id;
            PCIO_ME[ slot.urlKey ] = out.url;
            event[ slot.field ]    = out.image_id;
            const m = slotMeta( slot.type );
            m.actualW = out.width  || null;
            m.actualH = out.height || null;

            const slotEl = document.querySelector( `[data-photo-slot="${ slot.type }"]` );
            if ( slotEl ) {
                slotEl.outerHTML = slotCardHtml( slot );
                wireSlot( slot );
            }
            const newStateEl = document.querySelector( `[data-photo-state="${ slot.type }"]` );
            if ( newStateEl ) {
                newStateEl.textContent = T.imgSaved;
                newStateEl.className   = 'me-photos-save-state me-save-saved';
                setTimeout( () => newStateEl.classList.add( 'me-hidden' ), 2500 );
            }
            return true;
        } catch ( err ) {
            return false;
        }
    }

    // ── Tab: Publish ──────────────────────────────────────────────
    function renderPublishTab( container ) {
        const slug      = event.slug || '';
        const permalink = slug ? ( PCIO_ME.eventsPublicBase + slug + '/' ) : '';
        const shortcode = `[pcio_me_event id="${ EVENT_ID }"]`;

        const permalinkRow = permalink
            ? `<div class="me-copy-row">
                   <a class="me-copy-link" href="${ escAttr( permalink ) }" target="_blank" rel="noopener">${ escHtml( permalink ) }</a>
                   <button class="me-btn me-btn-secondary me-copy-btn" type="button" data-copy="${ escAttr( permalink ) }">${ escHtml( T.copyBtn ) }</button>
               </div>`
            : `<p class="me-publish-help">${ escHtml( T.permalinkHelp ) }</p>`;

        container.innerHTML = `
        <div class="me-tab-card me-publish-card">
            <h2 class="me-publish-heading">${ escHtml( T.publishHeading ) }</h2>

            <div class="me-publish-field">
                <label class="me-form-label">${ escHtml( T.permalinkLabel ) }</label>
                ${ permalinkRow }
                <p class="me-publish-help">${ escHtml( T.permalinkHelp ) }</p>
            </div>

            <div class="me-publish-field">
                <label class="me-form-label">${ escHtml( T.shortcodeLabel ) }</label>
                <div class="me-copy-row">
                    <code class="me-copy-code">${ escHtml( shortcode ) }</code>
                    <button class="me-btn me-btn-secondary me-copy-btn" type="button" data-copy="${ escAttr( shortcode ) }">${ escHtml( T.copyBtn ) }</button>
                </div>
                <p class="me-publish-help">${ escHtml( T.shortcodeHelp ) }</p>
            </div>
        </div>
        `;

        container.querySelectorAll( '.me-copy-btn' ).forEach( btn => {
            btn.addEventListener( 'click', async () => {
                const text = btn.dataset.copy || '';
                try {
                    await navigator.clipboard.writeText( text );
                } catch ( e ) {
                    // Fallback for browsers without the async clipboard API.
                    const ta = document.createElement( 'textarea' );
                    ta.value          = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity  = '0';
                    document.body.appendChild( ta );
                    ta.select();
                    try { document.execCommand( 'copy' ); } catch ( _ ) {}
                    ta.remove();
                }
                showToast( T.copiedMsg, 'success' );
            } );
        } );
    }

    // ── Tab: Resources ────────────────────────────────────────────
    function renderResourcesTab( container ) {
        container.innerHTML = `
        <div class="me-tab-card">
            <div style="margin-bottom:12px;font-weight:600">${ escHtml( T.fieldResources || 'Resources' ) }</div>
            <div id="rsc-loading" style="color:#94a3b8">${ escHtml( T.stateSaving?.replace( 'Saving', 'Loading' ) || 'Loading…' ) }</div>
            <div id="rsc-list" class="me-hidden"></div>
        </div>`;

        Promise.all( [
            fetch( RSC_REST, { headers: { 'X-WP-Nonce': NONCE } } ).then( r => r.json() ),
            fetch( `${ EVENTS_REST }/${ EVENT_ID }/resources`, { headers: { 'X-WP-Nonce': NONCE } } ).then( r => r.json() ),
        ] ).then( ( [ allResources, eventResources ] ) => {
            const sel = new Set( ( eventResources || [] ).map( r => r.id ) );
            const loading = document.getElementById( 'rsc-loading' );
            const list    = document.getElementById( 'rsc-list' );
            if ( ! list ) return;
            loading?.remove();
            list.classList.remove( 'me-hidden' );

            if ( ! allResources || ! allResources.length ) {
                list.innerHTML = `<p style="color:#94a3b8">${ escHtml( T.noResources || 'No resources defined yet. Add them in the Calendar \u2192 Resources tab.' ) }</p>`;
                return;
            }

            list.innerHTML = allResources.map( r => `
                <label class="me-check-row" style="padding:8px 0;display:flex;align-items:center;gap:10px">
                    <input type="checkbox" data-id="${ r.id }" ${ sel.has( r.id ) ? 'checked' : '' } ${ CAN_EDIT ? '' : 'disabled' }>
                    <span>${ escHtml( r.name ) }</span>
                    ${ r.description ? `<small style="color:#94a3b8">${ escHtml( r.description ) }</small>` : '' }
                </label>` ).join( '' );

            if ( ! CAN_EDIT ) return;

            let rscSaveTimer = null;
            list.addEventListener( 'change', () => {
                clearTimeout( rscSaveTimer );
                rscSaveTimer = setTimeout( saveResources, 600 );
            } );

            async function saveResources() {
                const checkedIds = [ ...list.querySelectorAll( 'input[type="checkbox"]:checked' ) ]
                    .map( cb => parseInt( cb.dataset.id, 10 ) );
                const startDT = event.start_datetime || '';
                const endDT   = event.end_datetime   || startDT;
                try {
                    const res = await fetch( `${ EVENTS_REST }/${ EVENT_ID }/resources`, {
                        method:  'PUT',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                        body:    JSON.stringify( { resource_ids: checkedIds, start_datetime: startDT, end_datetime: endDT } ),
                    } );
                    if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
                    const data = await res.json();
                    if ( data.conflicts && data.conflicts.length ) {
                        const names = data.conflicts.map( c => c.resource_name + ' \u2192 ' + c.event_title ).join( ', ' );
                        showToast( ( T.conflictWarning || 'Resource conflict: ' ) + names, 'error' );
                    }
                } catch ( err ) {
                    showToast( err.message, 'error' );
                }
            }
        } ).catch( err => {
            const loading = document.getElementById( 'rsc-loading' );
            if ( loading ) loading.textContent = err.message;
        } );
    }

    // ── Tab: Guests ───────────────────────────────────────────────
    function renderGuestsTab( container ) {
        const SIGNUPS_URL   = `${ EVENTS_REST }/${ EVENT_ID }/signups`;
        const AVAILABLE_URL = `${ EVENTS_REST }/${ EVENT_ID }/signups/available`;

        // Local state for this tab
        let guestData      = null;   // { my_status, counts, members }
        let availableList  = [];     // [{ id, name, email }]
        let gSortCol       = 'name';
        let gSortDir       = 'asc';
        let gSearchVal     = '';
        let gColFilters    = { name: '', email: '', status: '' };

        container.innerHTML = `<div class="me-tab-card"><p class="me-guests-loading">${ escHtml( T.guestLoading ) }</p></div>`;

        const fetches = [ fetch( SIGNUPS_URL, { headers: { 'X-WP-Nonce': NONCE } } ) ];
        if ( CAN_EDIT ) {
            fetches.push( fetch( AVAILABLE_URL, { headers: { 'X-WP-Nonce': NONCE } } ) );
        }

        Promise.all( fetches )
            .then( async ( responses ) => {
                for ( const r of responses ) {
                    if ( ! r.ok ) throw new Error( `HTTP ${ r.status }` );
                }
                guestData     = await responses[0].json();
                availableList = CAN_EDIT ? await responses[1].json() : [];
                buildGuestsUI();
            } )
            .catch( err => {
                container.innerHTML = `<div class="me-tab-card"><p class="me-guests-loading me-save-error">${ escHtml( T.guestLoading ) }: ${ escHtml( err.message ) }</p></div>`;
            } );

        // ── Build/rebuild the whole tab content ───────────────────
        function buildGuestsUI() {
            const { counts } = guestData;
            const addSelectHTML = CAN_EDIT ? buildAddSelectHTML() : '';

            container.innerHTML = `
            <div class="me-tab-card">
                <div class="me-toolbar me-guests-toolbar">
                    <span class="me-toolbar-info" id="me-guests-count"></span>
                    <div class="me-toolbar-actions">
                        <div class="me-search-box">
                            <input type="search" id="me-guests-search"
                                   placeholder="${ escAttr( T.guestSearchPh ) }"
                                   autocomplete="off" value="${ escAttr( gSearchVal ) }">
                        </div>
                        ${ addSelectHTML }
                    </div>
                </div>
                <table id="me-guests-table">
                    <thead>
                        <tr class="me-head-row" id="me-guests-head-row"></tr>
                        <tr class="me-filter-row" id="me-guests-filter-row"></tr>
                    </thead>
                    <tbody id="me-guests-tbody"></tbody>
                </table>
                <div id="me-guests-empty" class="me-empty-state me-hidden">
                    <p>${ escHtml( T.guestEmpty ) }</p>
                </div>
                <div class="me-guests-pills" id="me-guests-pills">
                    ${ buildPillsHTML( counts ) }
                </div>
                <div class="me-toast me-hidden" id="me-guests-toast" role="status" aria-live="polite"></div>
            </div>`;

            buildGuestsHeader();
            renderGuestsRows( guestData.members || [] );
            wireGuestsTable();
            if ( CAN_EDIT ) wireAddSelect();
        }

        // ── Column header row + sortable click ────────────────────
        const COLUMNS = [
            { key: 'name',       label: T.guestName,     sortable: true  },
            { key: 'email',      label: T.guestEmail,    sortable: true  },
            { key: 'created_at', label: T.guestJoinDate, sortable: true  },
            { key: 'status',     label: T.guestStatus,   sortable: true  },
        ];

        function buildGuestsHeader() {
            const headRow   = document.getElementById( 'me-guests-head-row' );
            const filterRow = document.getElementById( 'me-guests-filter-row' );
            if ( ! headRow || ! filterRow ) return;

            headRow.innerHTML = COLUMNS.map( col => {
                const arrow = col.sortable
                    ? ( gSortCol === col.key
                        ? ( gSortDir === 'asc' ? ' ▲' : ' ▼' )
                        : ' ⇅' )
                    : '';
                const cls = [ 'me-th', col.sortable ? 'me-th-sortable' : '' ].filter( Boolean ).join( ' ' );
                return `<th class="${ cls }" data-col="${ escAttr( col.key ) }">${ escHtml( col.label ) }${ arrow }</th>`;
            } ).join( '' );

            filterRow.innerHTML = COLUMNS.map( col => {
                const filterableKeys = [ 'name', 'email', 'status' ];
                const val = gColFilters[ col.key ] || '';
                if ( filterableKeys.includes( col.key ) ) {
                    return `<th><input type="search" class="me-col-filter"
                        data-col="${ escAttr( col.key ) }"
                        placeholder="${ escAttr( col.label ) }…"
                        value="${ escAttr( val ) }"
                        autocomplete="off"></th>`;
                }
                return '<th></th>';
            } ).join( '' );

            headRow.querySelectorAll( '.me-th-sortable' ).forEach( th => {
                th.addEventListener( 'click', () => {
                    const col = th.dataset.col;
                    if ( gSortCol === col ) {
                        gSortDir = gSortDir === 'asc' ? 'desc' : 'asc';
                    } else {
                        gSortCol = col;
                        gSortDir = 'asc';
                    }
                    buildGuestsHeader();
                    renderGuestsRows( guestData.members || [] );
                } );
            } );

            filterRow.querySelectorAll( '.me-col-filter' ).forEach( inp => {
                inp.addEventListener( 'input', () => {
                    gColFilters[ inp.dataset.col ] = inp.value;
                    renderGuestsRows( guestData.members || [] );
                } );
            } );
        }

        // ── Render rows (sort + filter) ────────────────────────────
        function renderGuestsRows( members ) {
            const tbody   = document.getElementById( 'me-guests-tbody' );
            const emptyEl = document.getElementById( 'me-guests-empty' );
            const countEl = document.getElementById( 'me-guests-count' );
            if ( ! tbody ) return;

            const q = gSearchVal.toLowerCase();
            let rows = members.filter( m => {
                // Global search
                if ( q && ! [ m.name, m.email, formatDate( m.created_at ), statusLabel( m.status ) ]
                    .some( v => ( v || '' ).toLowerCase().includes( q ) ) ) {
                    return false;
                }
                // Per-column filters
                if ( gColFilters.name   && ! ( m.name  || '' ).toLowerCase().includes( gColFilters.name.toLowerCase() ) )   return false;
                if ( gColFilters.email  && ! ( m.email || '' ).toLowerCase().includes( gColFilters.email.toLowerCase() ) )  return false;
                if ( gColFilters.status && ! statusLabel( m.status ).toLowerCase().includes( gColFilters.status.toLowerCase() ) ) return false;
                return true;
            } );

            rows = rows.slice().sort( ( a, b ) => {
                let av = ( gSortCol === 'status' ? statusLabel( a[ gSortCol ] ) : a[ gSortCol ] ) || '';
                let bv = ( gSortCol === 'status' ? statusLabel( b[ gSortCol ] ) : b[ gSortCol ] ) || '';
                av = av.toLowerCase(); bv = bv.toLowerCase();
                const cmp = av < bv ? -1 : av > bv ? 1 : 0;
                return gSortDir === 'asc' ? cmp : -cmp;
            } );

            if ( countEl ) countEl.textContent = `${ rows.length } / ${ members.length }`;

            if ( rows.length === 0 ) {
                tbody.innerHTML = '';
                emptyEl?.classList.remove( 'me-hidden' );
                return;
            }
            emptyEl?.classList.add( 'me-hidden' );

            tbody.innerHTML = rows.map( m => {
                const statusCell = CAN_EDIT
                    ? `<td><select class="me-guest-status-select me-form-input" data-member="${ m.member_id }">
                            ${ [ 'joining', 'not_joining', 'arrived' ].map( s =>
                                `<option value="${ s }"${ m.status === s ? ' selected' : '' }>${ escHtml( statusLabel( s ) ) }</option>`
                            ).join( '' ) }
                       </select></td>`
                    : `<td><span class="me-guest-badge me-badge-${ escAttr( m.status ) }">${ escHtml( statusLabel( m.status ) ) }</span></td>`;

                return `<tr>
                    <td>${ escHtml( m.name  || '' ) }</td>
                    <td>${ escHtml( m.email || '' ) }</td>
                    <td>${ escHtml( formatDate( m.created_at ) ) }</td>
                    ${ statusCell }
                </tr>`;
            } ).join( '' );
        }

        // ── Wire search / filter inputs ────────────────────────────
        function wireGuestsTable() {
            document.getElementById( 'me-guests-search' )
                ?.addEventListener( 'input', ( e ) => {
                    gSearchVal = e.target.value;
                    renderGuestsRows( guestData.members || [] );
                } );

            if ( CAN_EDIT ) {
                document.getElementById( 'me-guests-tbody' )
                    ?.addEventListener( 'change', async ( e ) => {
                        const sel = e.target.closest( '.me-guest-status-select' );
                        if ( ! sel ) return;
                        const memberId = parseInt( sel.dataset.member, 10 );
                        const status   = sel.value;
                        sel.disabled   = true;
                        try {
                            const res = await fetch(
                                `${ EVENTS_REST }/${ EVENT_ID }/signups/${ memberId }`,
                                {
                                    method:  'PUT',
                                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                                    body:    JSON.stringify( { status } ),
                                }
                            );
                            if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
                            await reloadData();
                            refreshGuestsView();
                        } catch ( err ) {
                            showGuestToast( T.guestSaveErr, 'error' );
                        } finally {
                            sel.disabled = false;
                        }
                    } );
            }
        }

        // ── Pills HTML helper ─────────────────────────────────────
        function buildPillsHTML( counts ) {
            return [
                [ 'me-pill-joining',      T.guestCntJoining,     counts.joining      || 0 ],
                [ 'me-pill-not-joining',  T.guestCntNotJoining,  counts.not_joining  || 0 ],
                [ 'me-pill-arrived',      T.guestCntArrived,     counts.arrived      || 0 ],
                [ 'me-pill-interested',   T.guestCntInterested,  counts.interested   || 0 ],
                [ 'me-pill-not-answered', T.guestCntNotAnswered, counts.not_answered || 0 ],
            ].map( ( [ cls, label, n ] ) =>
                `<span class="me-guest-pill ${ cls }">${ escHtml( label ) } <strong>${ n }</strong></span>`
            ).join( '' );
        }

        // ── Add-guest select HTML ─────────────────────────────────
        function buildAddSelectHTML() {
            const options = availableList.map( m => {
                const num   = m.member_number ? m.member_number + ' \u2013 ' : '';
                return `<option value="${ escAttr( String( m.id ) ) }">${ escHtml( num + m.name ) }</option>`;
            } ).join( '' );
            return `<select id="me-guests-add-select" class="me-guests-add-select">
                <option value="">\u2014 ${ escHtml( T.guestAddPh ) } \u2014</option>
                ${ options }
            </select>`;
        }

        function rebuildAddSelect() {
            const sel = document.getElementById( 'me-guests-add-select' );
            if ( ! sel ) return;
            const options = availableList.map( m => {
                const num   = m.member_number ? m.member_number + ' \u2013 ' : '';
                return `<option value="${ escAttr( String( m.id ) ) }">${ escHtml( num + m.name ) }</option>`;
            } ).join( '' );
            sel.innerHTML = `<option value="">\u2014 ${ escHtml( T.guestAddPh ) } \u2014</option>${ options }`;
            sel.value = '';
        }

        // ── Wire the add-guest combobox ───────────────────────────
        function wireAddSelect() {
            const sel = document.getElementById( 'me-guests-add-select' );
            if ( ! sel ) return;
            sel.addEventListener( 'change', async () => {
                const memberId = parseInt( sel.value, 10 );
                if ( ! memberId ) return;
                sel.disabled = true;
                try {
                    const res = await fetch(
                        `${ EVENTS_REST }/${ EVENT_ID }/signups/${ memberId }`,
                        {
                            method:  'PUT',
                            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                            body:    JSON.stringify( { status: 'arrived' } ),
                        }
                    );
                    if ( ! res.ok ) throw new Error( `HTTP ${ res.status }` );
                    await reloadData();
                    refreshGuestsView();
                } catch ( err ) {
                    showGuestToast( T.guestSaveErr, 'error' );
                } finally {
                    sel.disabled = false;
                    sel.value    = '';
                }
            } );
        }

        // ── Reload data from server + re-render ───────────────────
        async function reloadData() {
            const reqs = [ fetch( SIGNUPS_URL, { headers: { 'X-WP-Nonce': NONCE } } ) ];
            if ( CAN_EDIT ) {
                reqs.push( fetch( AVAILABLE_URL, { headers: { 'X-WP-Nonce': NONCE } } ) );
            }
            const responses = await Promise.all( reqs );
            for ( const r of responses ) {
                if ( ! r.ok ) throw new Error( `HTTP ${ r.status }` );
            }
            guestData     = await responses[0].json();
            availableList = CAN_EDIT ? await responses[1].json() : [];
        }

        // Re-render rows, pills and the add-select from current state.
        function refreshGuestsView() {
            renderGuestsRows( guestData.members || [] );
            const pillsEl = document.getElementById( 'me-guests-pills' );
            if ( pillsEl ) pillsEl.innerHTML = buildPillsHTML( guestData.counts || {} );
            if ( CAN_EDIT ) rebuildAddSelect();
        }

        // ── Helpers ───────────────────────────────────────────────
        function statusLabel( s ) {
            return s === 'joining'     ? T.statusJoining
                 : s === 'not_joining' ? T.statusNotJoining
                 : s === 'arrived'     ? T.statusArrived
                 : s === 'interested'  ? T.statusInterested
                 : s || '';
        }

        function formatDate( iso ) {
            if ( ! iso ) return '';
            return iso.substring( 0, 10 );
        }

        function showGuestToast( msg, type ) {
            const toast = document.getElementById( 'me-guests-toast' );
            if ( ! toast ) return;
            toast.textContent = msg;
            toast.className   = `me-toast ${ type }`;
            setTimeout( () => toast.classList.add( 'me-hidden' ), 3500 );
        }
    }

    // ── Tab: Stub ─────────────────────────────────────────────────
    function renderStubTab( container, tabId ) {
        const icons = {
            crew:    `<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>`,
            tickets: `<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v2z"/>`,
            photos:  `<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>`,
            status:  `<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>`,
        };
        container.innerHTML = `
        <div class="me-tab-card me-stub-card">
            <svg viewBox="0 0 24 24" fill="none" stroke="#cbd5e1"
                 stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                ${ icons[ tabId ] || '' }
            </svg>
            <p class="me-stub-label">${ escHtml( TABS.find( t => t.id === tabId )?.label || tabId ) }</p>
            <p class="me-stub-sub">${ escHtml( T.comingSoon ) }</p>
        </div>
        `;
    }

    // ── Dirty tracking ────────────────────────────────────────────
    function markDirty() { isDirty = true;  }
    function markClean() { isDirty = false; }

    // ── Universal save indicator ───────────────────────────────
    // state: 'saving' | 'saved' | 'error'
    function showSaveState( state ) {
        const el = document.getElementById( 'me-save-indicator' );
        if ( ! el ) return;
        if ( saveStateTimer ) clearTimeout( saveStateTimer );
        el.className  = `me-save-indicator me-save-${ state }`;
        el.textContent = state === 'saving' ? T.stateSaving
                       : state === 'saved'  ? T.stateSaved
                       :                     T.stateError;
        if ( state !== 'saving' ) {
            saveStateTimer = setTimeout( () => el.classList.add( 'me-hidden' ), 2500 );
        }
    }

    // ── Delete ────────────────────────────────────────────────────
    function closeDeleteModal() {
        document.getElementById( 'me-del-overlay' )?.classList.add( 'me-hidden' );
    }

    async function confirmDelete() {
        const btn = document.getElementById( 'me-del-confirm' );
        btn.disabled    = true;
        btn.textContent = T.stateDeleting;
        try {
            const res = await fetch( `${ EVENTS_REST }/${ EVENT_ID }`, {
                method:  'DELETE',
                headers: { 'X-WP-Nonce': NONCE },
            } );
            if ( ! res.ok && res.status !== 204 ) throw new Error( `HTTP ${ res.status }` );
            window.location.href = CAL_URL;
        } catch ( err ) {
            showToast( T.errDelete + err.message, 'error' );
            btn.disabled    = false;
            btn.textContent = T.btnDelete;
        }
    }

    // ── Toast ─────────────────────────────────────────────────────
    function showToast( msg, type ) {
        const toast = document.getElementById( 'me-toast' );
        if ( ! toast ) return;
        toast.textContent = msg;
        toast.className   = `me-toast ${ type }`;
        if ( toastTimer ) clearTimeout( toastTimer );
        toastTimer = setTimeout( () => toast.classList.add( 'me-hidden' ), 3000 );
    }

    // ── Error handler ─────────────────────────────────────────────
    function renderError( msg ) {
        const app = document.getElementById( 'pcio-me-app' );
        if ( app ) app.innerHTML = `<div class="me-empty-state" style="padding:80px 20px">
            <p>${ msg }</p></div>`;
    }

    // ── Security ──────────────────────────────────────────────────
    function escHtml( str ) {
        return String( str ?? '' )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }
    function escAttr( str ) { return escHtml( str ); }

} )();
